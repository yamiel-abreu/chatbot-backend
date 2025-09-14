// server.js
// v2.6
// Author: YAA

import express from "express";
import cors from "cors";
import bodyParser from "body-parser";
import { v4 as uuidv4 } from "uuid";
import fetch from "node-fetch";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";

// ----- ESM dirname helpers -----
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
app.use(cors());
app.use(bodyParser.json({ limit: "2mb" }));

// ---- Config (env-driven) ----
const GPT_API_URL = process.env.OPENAI_API_URL || "https://api.openai.com/v1/responses";
const DEFAULT_MODEL = process.env.OPENAI_MODEL || "gpt-4o-mini";
const EMBEDDING_MODEL = process.env.OPENAI_EMBED_MODEL || "text-embedding-3-small";
const DEFAULT_API_KEY = process.env.OPENAI_API_KEY;

// DATA DIR so Render can mount a disk; fallback to CWD
const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, ".");
if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });

const USAGE_FILE = path.resolve(DATA_DIR, "usage.json");
const FAQS_FILE = path.resolve(DATA_DIR, "faqs.json");

// NEW: per-tenant site indices
const TENANTS_DIR = path.resolve(DATA_DIR, "tenants");
if (!fs.existsSync(TENANTS_DIR)) fs.mkdirSync(TENANTS_DIR, { recursive: true });

// Ensure data files exist
if (!fs.existsSync(USAGE_FILE)) fs.writeFileSync(USAGE_FILE, JSON.stringify({}), "utf8");
if (!fs.existsSync(FAQS_FILE)) {
  fs.writeFileSync(
    FAQS_FILE,
    JSON.stringify(
      [
        { trigger: "shipping", reply: "We offer standard, express, and overnight shipping." },
        { trigger: "return", reply: "You can return most items within 30 days of purchase." },
        { trigger: "payment", reply: "We accept Visa, MasterCard, PayPal, and Apple Pay." },
        { trigger: "contact", reply: "You can reach us via our Contact page or email support@example.com." },
      ],
      null,
      2
    ),
    "utf8"
  );
}

// ---- Usage helpers ----
function loadUsage() {
  try { return JSON.parse(fs.readFileSync(USAGE_FILE, "utf8")); } catch { return {}; }
}
function saveUsage(data) {
  fs.writeFileSync(USAGE_FILE, JSON.stringify(data, null, 2), "utf8");
}

// Reset usage per user if new month
function ensureMonth(userId) {
  const usage = loadUsage();
  const currentMonth = new Date().toISOString().slice(0, 7); // YYYY-MM
  if (!usage[userId] || usage[userId].month !== currentMonth) {
    usage[userId] = { month: currentMonth, aiCalls: 0, plan: "rule", lastResetAt: new Date().toISOString() };
    saveUsage(usage);
  }
  return usage[userId];
}

// ---- Plans ----
const PLANS = {
  rule: { mode: "rule", AI_MONTHLY_LIMIT: 0, strict: true },           // Basic Plan
  ai: { mode: "ai", AI_MONTHLY_LIMIT: 1000, strict: true },            // Pro Plan
  enterprise: { mode: "ai", AI_MONTHLY_LIMIT: 10000, strict: true },   // Enterprise
};

// ---- FAQs (hot-reload + regex compilation) ----
let FAQs = [];
let _faqsMTimeMs = 0;

function compileFAQs(raw) {
  return raw.map((f) => {
    if (f.trigger instanceof RegExp) return f;
    if (typeof f.trigger === "string") {
      return { trigger: new RegExp(f.trigger, "i"), reply: f.reply };
    }
    if (typeof f.trigger === "object" && f.trigger?.pattern) {
      return { trigger: new RegExp(f.trigger.pattern, f.trigger.flags || "i"), reply: f.reply };
    }
    return { trigger: /$a^/i, reply: f.reply };
  });
}
function loadFAQsIfChanged() {
  try {
    const stat = fs.statSync(FAQS_FILE);
    if (stat.mtimeMs !== _faqsMTimeMs) {
      const raw = JSON.parse(fs.readFileSync(FAQS_FILE, "utf8"));
      FAQs = compileFAQs(raw);
      _faqsMTimeMs = stat.mtimeMs;
      console.log(`🔁 FAQs reloaded (${new Date(stat.mtime).toISOString()})`);
    }
  } catch (e) {
    console.warn("⚠️ Failed to read faqs.json; keeping previous FAQs.", e.message);
  }
}
loadFAQsIfChanged();
fs.watchFile(FAQS_FILE, { interval: 1000 }, loadFAQsIfChanged());

// ---- Memory + logs (in-memory) ----
const userMemory = {};
const logs = [];

// ==========================
// RAG: crawl + embeddings
// ==========================

function tenantDir(tenantId) {
  const safe = tenantId.replace(/[^a-z0-9\-_]/gi, "_").toLowerCase();
  const dir = path.join(TENANTS_DIR, safe);
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  return dir;
}

function readJSON(file, fallback) {
  try { return JSON.parse(fs.readFileSync(file, "utf8")); } catch { return fallback; }
}
function writeJSON(file, obj) {
  fs.writeFileSync(file, JSON.stringify(obj, null, 2), "utf8");
}

function normalizeUrl(u) {
  try { 
    const url = new URL(u);
    url.hash = "";
    return url.toString().replace(/\/+$/, ""); 
  } catch { return null; }
}

async function fetchText(url, timeoutMs = 15000) {
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(url, { headers: { "User-Agent": "NubedyBot/1.0" }, signal: controller.signal });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const text = await res.text();
    return text;
  } finally {
    clearTimeout(t);
  }
}

// Try to parse sitemap.xml to get list of URLs
async function fetchSitemapUrls(baseUrl, max = 120) {
  try {
    const u = new URL(baseUrl);
    const sitemapUrl = `${u.origin}/sitemap.xml`;
    const xml = await fetchText(sitemapUrl);
    const urls = Array.from(xml.matchAll(/<loc>([^<]+)<\/loc>/gi)).map(m => m[1]).slice(0, max);
    if (urls.length) return urls;
  } catch {}
  // Fallback: just start from baseUrl
  return [baseUrl];
}

// ultra-light HTML -> text (remove scripts/styles, tags)
function htmlToText(html) {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, " ")
    .replace(/<style[\s\S]*?<\/style>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

// Parse schema.org Product JSON-LD blocks
function extractProductsFromHtml(html, pageUrl) {
  const products = [];
  const blocks = Array.from(html.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi));
  for (const b of blocks) {
    try {
      const json = JSON.parse(b[1].trim());
      const items = Array.isArray(json) ? json : [json];
      for (const it of items) {
        const type = (it["@type"] || it.type || "").toString().toLowerCase();
        if (type.includes("product")) {
          products.push({
            url: it.url || pageUrl,
            name: it.name || "",
            description: it.description || "",
            sku: it.sku || "",
            brand: (it.brand && (it.brand.name || it.brand)) || "",
            price: (it.offers && (it.offers.price || it.offers.lowPrice)) || "",
            currency: (it.offers && it.offers.priceCurrency) || "",
            image: Array.isArray(it.image) ? it.image[0] : it.image || "",
          });
        }
      }
    } catch {}
  }
  return products;
}

// Chunk a large text
function chunkText(text, chunkSize = 1200, overlap = 200) {
  const chunks = [];
  let i = 0;
  while (i < text.length) {
    const end = Math.min(text.length, i + chunkSize);
    const slice = text.slice(i, end);
    chunks.push(slice);
    i = end - overlap;
    if (i < 0) i = end;
  }
  return chunks.map((c) => c.trim()).filter(Boolean);
}

async function embedBatch(texts) {
  const res = await fetch("https://api.openai.com/v1/embeddings", {
    method: "POST",
    headers: { "Authorization": `Bearer ${DEFAULT_API_KEY}`, "Content-Type": "application/json" },
    body: JSON.stringify({ model: EMBEDDING_MODEL, input: texts }),
  });
  if (!res.ok) throw new Error(`Embeddings HTTP ${res.status}`);
  const data = await res.json();
  return data.data.map((d) => d.embedding);
}

function cosineSim(a, b) {
  let dot = 0, na = 0, nb = 0;
  for (let i = 0; i < a.length; i++) {
    dot += a[i] * b[i];
    na += a[i] * a[i];
    nb += b[i] * b[i];
  }
  return dot / (Math.sqrt(na) * Math.sqrt(nb) + 1e-12);
}

// Build or update a tenant index
async function buildTenantIndex(tenantId, baseUrl, maxPages = 80) {
  const dir = tenantDir(tenantId);
  const SITE_FILE = path.join(dir, "site.json");            // { baseUrl, pages:[{url,text}] }
  const EMB_FILE = path.join(dir, "embeddings.json");       // { chunks:[{url, text, vec}] }
  const PROD_FILE = path.join(dir, "products.json");        // { products:[...], emb:[...] }

  const urls = await fetchSitemapUrls(baseUrl, maxPages);
  const pages = [];

  const products = [];
  for (const u of urls) {
    try {
      const html = await fetchText(u);
      const text = htmlToText(html);
      pages.push({ url: u, text });
      const prod = extractProductsFromHtml(html, u);
      if (prod.length) products.push(...prod);
    } catch (e) {
      console.warn("Crawl failed for", u, e.message);
    }
  }
  writeJSON(SITE_FILE, { baseUrl, pages });

  // Chunk & embed site pages
  const chunks = [];
  for (const p of pages) {
    const cs = chunkText(p.text);
    cs.forEach((c) => chunks.push({ url: p.url, text: c }));
  }
  const chunkTexts = chunks.map((c) => c.text);
  const vecs = chunkTexts.length ? await embedBatch(chunkTexts) : [];
  const chunkIndex = chunks.map((c, i) => ({ ...c, vec: vecs[i] || [] }));
  writeJSON(EMB_FILE, { chunks: chunkIndex });

  // Product embeddings
  if (products.length) {
    const prodTexts = products.map(p => `${p.name}\n${p.description}\n${p.brand}\n${p.price} ${p.currency}`.trim());
    const pvecs = await embedBatch(prodTexts);
    writeJSON(PROD_FILE, { products, emb: pvecs });
  } else {
    writeJSON(PROD_FILE, { products: [], emb: [] });
  }

  return { pages: pages.length, chunks: chunkIndex.length, products: products.length };
}

function loadTenantEmbeddings(tenantId) {
  const dir = tenantDir(tenantId);
  const EMB_FILE = path.join(dir, "embeddings.json");
  return readJSON(EMB_FILE, { chunks: [] });
}
function loadTenantProducts(tenantId) {
  const dir = tenantDir(tenantId);
  const PROD_FILE = path.join(dir, "products.json");
  return readJSON(PROD_FILE, { products: [], emb: [] });
}

// Simple top-K retrieval
async function retrieveContext(tenantId, query, k = 8) {
  const emb = await embedBatch([query]);
  const qvec = emb[0];
  const { chunks } = loadTenantEmbeddings(tenantId);
  if (!chunks.length) return [];
  const scored = chunks.map((c) => ({ ...c, score: cosineSim(qvec, c.vec || []) }));
  scored.sort((a, b) => b.score - a.score);
  return scored.slice(0, k);
}

async function retrieveProducts(tenantId, query, k = 5) {
  const emb = await embedBatch([query]);
  const qvec = emb[0];
  const { products, emb: prodVecs } = loadTenantProducts(tenantId);
  if (!products.length) return [];
  const scored = products.map((p, i) => ({ ...p, score: cosineSim(qvec, prodVecs[i] || []) }));
  scored.sort((a, b) => b.score - a.score);
  return scored.slice(0, k);
}

// ---- OpenAI helper (Responses API; with system + context) ----
async function callGPT(messages, apiKey, model = DEFAULT_MODEL, timeoutMs = 30000) {
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort(), timeoutMs);

  const payload = { model, input: messages };

  const res = await fetch(GPT_API_URL, {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
    signal: controller.signal,
  }).catch((e) => {
    clearTimeout(t);
    throw e;
  });

  clearTimeout(t);

  if (!res.ok) {
    const text = await res.text().catch(() => "");
    throw new Error(`OpenAI error ${res.status}: ${text}`);
  }

  const data = await res.json();

  if (data.output_text) return String(data.output_text);

  const maybe = data?.output?.[0]?.content;
  if (Array.isArray(maybe)) {
    const firstText = maybe.find((c) => c?.type === "output_text" || c?.text)?.text;
    if (firstText) return String(firstText);
  }
  return "Sorry, I couldn't generate a response right now.";
}

// ---- Utilities ----
function clampLimit(planName, headerLimit) {
  const base = PLANS[planName] || PLANS.rule;
  if (!headerLimit) return base.AI_MONTHLY_LIMIT;
  const n = Number(headerLimit);
  if (Number.isFinite(n) && n > 0) return Math.min(n, 100000); // sanity cap
  return base.AI_MONTHLY_LIMIT;
}

function findFAQReply(message) {
  loadFAQsIfChanged();
  const m = FAQs.find((f) => f.trigger.test(message));
  return m?.reply || null;
}

// ===================
// Routes
// ===================

// POST /site/index  { tenantId, baseUrl, maxPages? }
app.post("/site/index", async (req, res) => {
  try {
    const tenantId = (req.body?.tenantId || "").toString().trim();
    const baseUrlRaw = (req.body?.baseUrl || "").toString().trim();
    const maxPages = Number(req.body?.maxPages) || 80;
    if (!tenantId || !baseUrlRaw) return res.status(400).json({ error: "tenantId and baseUrl required" });

    const baseUrl = normalizeUrl(baseUrlRaw);
    if (!baseUrl) return res.status(400).json({ error: "Invalid baseUrl" });

    const stats = await buildTenantIndex(tenantId, baseUrl, maxPages);
    const dir = tenantDir(tenantId);
    writeJSON(path.join(dir, "status.json"), { ...stats, baseUrl, indexedAt: new Date().toISOString() });

    res.json({ ok: true, ...stats });
  } catch (e) {
    console.error("Indexing failed:", e);
    res.status(500).json({ error: e.message });
  }
});

// GET /site/status?tenantId=xxx
app.get("/site/status", (req, res) => {
  const tenantId = (req.query?.tenantId || "").toString();
  if (!tenantId) return res.status(400).json({ error: "tenantId required" });
  const dir = tenantDir(tenantId);
  const file = path.join(dir, "status.json");
  if (!fs.existsSync(file)) return res.json({ ok: true, indexed: false });
  return res.json({ ok: true, indexed: true, ...readJSON(file, {}) });
});

// POST /products/upload  (optional) — JSON or CSV feed
// Body: { tenantId, items:[{name,description,url,price,currency,image,sku,brand}] }  OR  { csv:string }
app.post("/products/upload", async (req, res) => {
  try {
    const tenantId = (req.body?.tenantId || "").toString().trim();
    if (!tenantId) return res.status(400).json({ error: "tenantId required" });

    let items = [];
    if (Array.isArray(req.body?.items)) {
      items = req.body.items;
    } else if (typeof req.body?.csv === "string") {
      // minimal CSV parser: name,description,url,price,currency,image,sku,brand
      const lines = req.body.csv.split(/\r?\n/).filter(Boolean);
      const header = lines.shift()?.split(",").map(s => s.trim().toLowerCase()) || [];
      for (const line of lines) {
        const cols = line.split(",").map(s => s.trim());
        const obj = {};
        header.forEach((h, i) => obj[h] = cols[i] || "");
        items.push(obj);
      }
    } else {
      return res.status(400).json({ error: "Provide items[] or csv" });
    }

    const dir = tenantDir(tenantId);
    const PROD_FILE = path.join(dir, "products.json");
    // embed products
    const texts = items.map(p => `${p.name}\n${p.description}\n${p.brand}\n${p.price} ${p.currency}`.trim());
    const vecs = texts.length ? await embedBatch(texts) : [];
    writeJSON(PROD_FILE, { products: items, emb: vecs });

    res.json({ ok: true, products: items.length });
  } catch (e) {
    console.error(e);
    res.status(500).json({ error: e.message });
  }
});

// GET /products?tenantId=xxx
app.get("/products", (req, res) => {
  const tenantId = (req.query?.tenantId || "").toString();
  if (!tenantId) return res.status(400).json({ error: "tenantId required" });
  const { products } = loadTenantProducts(tenantId);
  res.json({ ok: true, count: products.length, products });
});

// POST /chat
app.post("/chat", async (req, res) => {
  const userId = (req.headers["x-user-id"] || "").toString() || uuidv4();
  const tenantId = (req.headers["x-tenant-id"] || "").toString().trim(); // NEW: which site to ground on
  const message = (req.body?.message || "").toString();
  let userPlan = (req.headers["x-plan"] || "rule").toString();
  const customApiKey = (req.headers["x-api-key"] || "").toString().trim(); // enterprise BYOK
  const overrideLimit = req.headers["x-ai-limit"]; // optional per-tenant limit

  // Validate plan
  if (!PLANS[userPlan]) userPlan = "rule";

  // Ensure usage record & attach plan
  const usage = ensureMonth(userId);
  usage.plan = userPlan;

  // Persist plan immediately (even for rule mode)
  const allUsage = loadUsage();
  allUsage[userId] = usage;
  saveUsage(allUsage);

  if (!userMemory[userId]) userMemory[userId] = [];

  let reply = "";
  let usedAI = false;

  try {
    if (PLANS[userPlan].mode === "rule") {
      reply = findFAQReply(message) || "⚠️ Sorry, I only handle FAQs in this plan.";
    } else {
      // AI mode — enforce caps
      const limit = clampLimit(userPlan, overrideLimit);
      if (usage.aiCalls >= limit) {
        reply = "⚠️ AI usage limit reached for this month. Please contact support to upgrade your plan.";
      } else {
        const apiKeyToUse = customApiKey || DEFAULT_API_KEY;
        if (!apiKeyToUse) {
          reply = "⚠️ No API key configured. Please contact support.";
        } else {
          // ==== Strict grounded answering using RAG ====
          // Gather site context for this tenant
          let contextBlocks = [];
          let productBlocks = [];
          if (tenantId) {
            contextBlocks = await retrieveContext(tenantId, message, 8);
            // If user asks about products or intent looks producty, fetch product candidates
            const producty = /\b(product|buy|price|sizes?|colors?|catalog|shop|add to cart|order|stock|available|recommend)\b/i.test(message);
            if (producty) {
              productBlocks = await retrieveProducts(tenantId, message, 6);
            }
          }

          const contextText = contextBlocks.map((b, i) => `#${i+1} [${b.url}]\n${b.text}`).join("\n\n");
          const productText = productBlocks.length
            ? "Products:\n" + productBlocks.map((p, i) => `• ${p.name} — ${p.price ? p.price + (p.currency ? " " + p.currency : "") : ""}\n  Link: ${p.url}\n  ${p.description || ""}`).join("\n")
            : "";

          const strictInstruction = `You are a helpful website assistant for the client's site.
You MUST answer ONLY using the information in "Context" and optionally "Products".
If the answer is not present in Context, reply exactly with: "I’m not sure based on the site content. Please contact support or check the website."
Never invent facts. Prefer short, direct answers. Include links only if they are present in Context or Products.`;

          const messages = [
            { role: "system", content: strictInstruction },
            { role: "user", content: `User question:\n${message}\n\n---\nContext:\n${contextText || "(no context found)"}\n\n${productText ? "---\n" + productText : ""}` }
          ];

          reply = await callGPT(messages, apiKeyToUse);
          usedAI = true;

          // If still nothing helpful and we had no context, fall back softly to FAQs (optional)
          if ((!reply || /I’m not sure based on the site content/i.test(reply)) && !contextBlocks.length) {
            const faq = findFAQReply(message);
            if (faq) reply = faq;
          }
        }
      }
    }
  } catch (e) {
    console.error("❌ GPT call failed:", e.message);
    reply = findFAQReply(message) || "⚠️ Sorry, I could not generate a response.";
  }

  // Increment and persist usage if AI was used
  let limit = clampLimit(userPlan, overrideLimit);
  if (usedAI) {
    usage.aiCalls++;
    const all = loadUsage();
    all[userId] = usage;
    saveUsage(all);
  }

  // Save memory
  userMemory[userId].push({ role: "user", text: message, ts: new Date().toISOString() });
  userMemory[userId].push({ role: "assistant", text: reply, ts: new Date().toISOString() });

  // Log analytics (now includes limit)
  logs.push({
    timestamp: new Date().toISOString(),
    userId,
    message,
    response: reply,
    plan: userPlan,
    aiCalls: usage.aiCalls,
    limit
  });

  res.json({ reply, usage: usage.aiCalls, limit, plan: userPlan });
});

// DELETE /chat/clear — clear memory for a user (and optionally reset usage for the month)
app.delete("/chat/clear", (req, res) => {
  const userId = (req.headers["x-user-id"] || "").toString();
  if (!userId) return res.status(400).json({ error: "Missing x-user-id header" });

  userMemory[userId] = [];

  if (req.query?.reset === "usage") {
    const all = loadUsage();
    const currentMonth = new Date().toISOString().slice(0, 7);
    all[userId] = { month: currentMonth, aiCalls: 0, plan: all[userId]?.plan || "rule", lastResetAt: new Date().toISOString() };
    saveUsage(all);
  }

  res.json({ ok: true });
});

// GET /usage/:userId
app.get("/usage/:userId", (req, res) => {
  const usage = ensureMonth(req.params.userId.toString());
  res.json(usage);
});

// GET /analytics
app.get("/analytics", (_req, res) => res.json(logs));

// (optional) GET /faqs — debug/view current FAQs
app.get("/faqs", (_req, res) => {
  res.json(
    FAQs.map((f) => ({ trigger: f.trigger.toString(), reply: f.reply }))
  );
});

// Healthcheck
app.get("/", (_req, res) => res.json({ status: "ok" }));

// Start server
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`🚀 Chatbot backend running on port ${PORT}`));
