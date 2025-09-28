// server.js
// v2.9.7
// Author: YAA

import express from "express";
import cors from "cors";
import bodyParser from "body-parser";
import { v4 as uuidv4 } from "uuid";
import fetch from "node-fetch";
import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";
import { parse as csvParse } from "csv-parse/sync"; // robust CSV parser

// ----- ESM dirname helpers -----
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();

// CORS + preflight
app.use(cors());
app.options("*", cors());

app.use(bodyParser.json({ limit: "2mb" }));

// ---- Config (env-driven) ----
const GPT_API_URL = process.env.OPENAI_API_URL || "https://api.openai.com/v1/responses";
const DEFAULT_MODEL = process.env.OPENAI_MODEL || "gpt-4o-mini";
const EMBEDDING_MODEL = process.env.OPENAI_EMBED_MODEL || "text-embedding-3-small";
const DEFAULT_API_KEY = process.env.OPENAI_API_KEY;

// ---- RAG limits (env-tunable, keep memory low) ----
const CHUNK_SIZE = Number(process.env.CHUNK_SIZE || 1000);           // chars per chunk
const CHUNK_OVERLAP = Number(process.env.CHUNK_OVERLAP || 150);      // overlap chars
const MAX_TEXT_PER_PAGE = Number(process.env.MAX_TEXT_PER_PAGE || 100_000);
const MAX_CHUNKS_PER_TENANT = Number(process.env.MAX_CHUNKS_PER_TENANT || 3000);
const EMB_BATCH = Number(process.env.EMB_BATCH || 50);               // embeddings per HTTP call

// DATA DIR so Render/Host VPS can mount a disk; fallback to CWD
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

// ---- FAQs (hot-reload + SAFE regex compilation) ----
let FAQs = [];
let _faqsMTimeMs = 0;

// SAFE: convert trigger → RegExp, supporting string, "/pattern/flags", and {pattern, flags}
function safeToRegExp(trigger) {
  if (trigger instanceof RegExp) return trigger;

  // Object form: { pattern, flags }
  if (trigger && typeof trigger === "object" && trigger.pattern) {
    try {
      return new RegExp(trigger.pattern, trigger.flags || "i");
    } catch (e) {
      console.warn("Bad FAQ regex object:", trigger, e.message);
      return /$a^/; // never matches
    }
  }

  // String form: allow "/.../flags" or plain "..." and strip inline (?i)
  if (typeof trigger === "string") {
    const s = trigger.trim();
    const match = s.match(/^\/(.+)\/([a-z]*)$/i); // "/pattern/flags"
    let pattern = match ? match[1] : s;
    let flags = (match ? match[2] : "i") || "i";
    pattern = pattern.replace(/\(\?i\)/gi, ""); // JS doesn't support inline (?i)
    try {
      if (!/i/.test(flags)) flags += "i";
      return new RegExp(pattern, flags);
    } catch (e) {
      console.warn("Bad FAQ regex string:", s, e.message);
      return /$a^/;
    }
  }

  return /$a^/;
}

function compileFAQs(raw) {
  return (raw || []).map(f => ({ trigger: safeToRegExp(f.trigger), reply: f.reply }));
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

// initial load + watch
loadFAQsIfChanged();
fs.watchFile(FAQS_FILE, { interval: 1000 }, () => loadFAQsIfChanged());

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

function chunkTextWithCaps(text, size, overlap, remaining) {
  if (remaining <= 0) return [];
  const out = [];
  let i = 0;
  while (i < text.length && out.length < remaining) {
    const end = Math.min(text.length, i + size);
    const slice = text.slice(i, end);
    out.push(slice.trim());
    i = end - overlap;
    if (i < 0) i = end;
  }
  return out.filter(Boolean);
}

// ---- Embeddings (batched to avoid huge payloads) ----
async function embedBatch(texts, batchSize = EMB_BATCH) {
  const out = [];
  for (let i = 0; i < texts.length; i += batchSize) {
    const slice = texts.slice(i, i + batchSize);
    const res = await fetch("https://api.openai.com/v1/embeddings", {
      method: "POST",
      headers: { "Authorization": `Bearer ${DEFAULT_API_KEY}`, "Content-Type": "application/json" },
      body: JSON.stringify({ model: EMBEDDING_MODEL, input: slice }),
    });
    if (!res.ok) {
      const msg = await res.text().catch(() => "");
      throw new Error(`Embeddings HTTP ${res.status}: ${msg}`);
    }
    const data = await res.json();
    out.push(...data.data.map(d => d.embedding));
    await new Promise(r => setTimeout(r, 100));
  }
  return out;
}

function toF32Normalized(vec) {
  if (!Array.isArray(vec)) return new Float32Array(0);
  let sum = 0;
  for (let i = 0; i < vec.length; i++) sum += vec[i] * vec[i];
  const norm = Math.sqrt(sum) || 1;
  const out = new Float32Array(vec.length);
  for (let i = 0; i < vec.length; i++) out[i] = vec[i] / norm;
  return out;
}
function dot(a, b) {
  const len = Math.min(a.length, b.length);
  let d = 0;
  for (let i = 0; i < len; i++) d += a[i] * b[i];
  return d; // cosine if inputs are unit-normalized
}

// ==========================
// 🔥 CACHING (tenants + query embeddings)
// ==========================
const CACHE_MAX_TENANTS   = Number(process.env.CACHE_MAX_TENANTS   ?? 20);
const CACHE_MAX_QUERY_EMB = Number(process.env.CACHE_MAX_QUERY_EMB ?? 1000);
const CACHE_QUERY_TTL_MS  = Number(process.env.CACHE_QUERY_TTL_MS  ?? 5 * 60 * 1000);

const TENANT_EMB_CACHE  = new Map(); // tenantId -> {mtime, last, chunks:[{url,text,vec:Float32Array}], dim}
const TENANT_PROD_CACHE = new Map(); // tenantId -> {mtime, last, products, emb:[Float32Array], dim}
const QUERY_EMB_CACHE   = new Map(); // key -> {vec:Float32Array, t, last}

function lruTouch(map, key, payload) {
  const now = Date.now();
  const val = payload ? { ...payload, last: now } : { ...map.get(key), last: now };
  map.set(key, val);
  if (CACHE_MAX_TENANTS > 0 && map.size > CACHE_MAX_TENANTS) {
    let oldestKey, oldest = Infinity;
    for (const [k, v] of map) if (v.last < oldest) { oldest = v.last; oldestKey = k; }
    if (oldestKey) map.delete(oldestKey);
  }
}
function lruTouchQuery(key, vec) {
  const now = Date.now();
  const entry = { vec, t: now, last: now };
  QUERY_EMB_CACHE.set(key, entry);
  if (CACHE_MAX_QUERY_EMB > 0 && QUERY_EMB_CACHE.size > CACHE_MAX_QUERY_EMB) {
    let oldestKey, oldest = Infinity;
    for (const [k, v] of QUERY_EMB_CACHE) if (v.last < oldest) { oldest = v.last; oldestKey = k; }
    if (oldestKey) QUERY_EMB_CACHE.delete(oldestKey);
  }
}

function getTenantEmbeddingsCached(tenantId) {
  const dir = tenantDir(tenantId);
  const EMB_FILE = path.join(dir, "embeddings.json");
  let st; try { st = fs.statSync(EMB_FILE); } catch { return { chunks: [], dim: 0 }; }
  const mtime = st.mtimeMs;

  const cached = TENANT_EMB_CACHE.get(tenantId);
  if (cached && cached.mtime === mtime) { lruTouch(TENANT_EMB_CACHE, tenantId); return cached; }

  const raw = readJSON(EMB_FILE, { chunks: [] });
  const dim = raw.chunks?.[0]?.vec?.length || 0;
  for (const c of raw.chunks) c.vec = toF32Normalized(c.vec || []);
  const payload = { mtime, chunks: raw.chunks, dim, last: Date.now() };
  lruTouch(TENANT_EMB_CACHE, tenantId, payload);
  return payload;
}

function getTenantProductsCached(tenantId) {
  const dir = tenantDir(tenantId);
  const PROD_FILE = path.join(dir, "products.json");
  let st; try { st = fs.statSync(PROD_FILE); } catch { return { products: [], emb: [], dim: 0 }; }
  const mtime = st.mtimeMs;

  const cached = TENANT_PROD_CACHE.get(tenantId);
  if (cached && cached.mtime === mtime) { lruTouch(TENANT_PROD_CACHE, tenantId); return cached; }

  const raw = readJSON(PROD_FILE, { products: [], emb: [] });
  const dim = raw.emb?.[0]?.length || 0;
  const emb = raw.emb.map(v => toF32Normalized(v || []));
  const payload = { mtime, products: raw.products, emb, dim, last: Date.now() };
  lruTouch(TENANT_PROD_CACHE, tenantId, payload);
  return payload;
}

async function embedQueryCached(text) {
  const key = text.slice(0, 1000).toLowerCase();
  const now = Date.now();
  const entry = QUERY_EMB_CACHE.get(key);
  if (entry && (now - entry.t) < CACHE_QUERY_TTL_MS) {
    entry.last = now; // touch
    return entry.vec;
  }
  const [vec] = await embedBatch([text]);
  const f32 = toF32Normalized(vec);
  if (CACHE_MAX_QUERY_EMB > 0 && CACHE_QUERY_TTL_MS > 0) lruTouchQuery(key, f32);
  return f32;
}

// Build or update a tenant index (streamed + capped to avoid OOM)
async function buildTenantIndex(tenantId, baseUrl, maxPages = 80) {
  const dir = tenantDir(tenantId);
  const STATUS_FILE = path.join(dir, "status.json");
  const EMB_FILE = path.join(dir, "embeddings.json");  // {"chunks":[ ... ]}
  const PROD_FILE = path.join(dir, "products.json");   // { products:[], emb:[] }

  const urls = await fetchSitemapUrls(baseUrl, maxPages);

  const embStream = fs.createWriteStream(EMB_FILE, { flags: "w" });
  embStream.write('{"chunks":[');
  let firstChunkWritten = false;
  function writeChunkEntry(entry) {
    if (!firstChunkWritten) { firstChunkWritten = true; }
    else { embStream.write(","); }
    embStream.write(JSON.stringify(entry));
  }

  let totalPages = 0;
  let totalChunks = 0;
  const products = [];

  for (const u of urls) {
    if (totalPages >= maxPages || totalChunks >= MAX_CHUNKS_PER_TENANT) break;
    try {
      const html = await fetchText(u);
      const prod = extractProductsFromHtml(html, u);
      if (prod.length) products.push(...prod);

      let text = htmlToText(html);
      if (text.length > MAX_TEXT_PER_PAGE) text = text.slice(0, MAX_TEXT_PER_PAGE);

      const remaining = Math.max(0, MAX_CHUNKS_PER_TENANT - totalChunks);
      const cs = chunkTextWithCaps(text, CHUNK_SIZE, CHUNK_OVERLAP, remaining);
      totalPages++;

      for (let i = 0; i < cs.length; i += EMB_BATCH) {
        const sliceTexts = cs.slice(i, i + EMB_BATCH);
        const vecs = await embedBatch(sliceTexts, EMB_BATCH);
        for (let j = 0; j < sliceTexts.length; j++) {
          writeChunkEntry({ url: u, text: sliceTexts[j], vec: vecs[j] });
        }
        totalChunks += sliceTexts.length;
        if (totalChunks >= MAX_CHUNKS_PER_TENANT) break;
      }
    } catch (e) {
      console.warn("Crawl/index error for", u, e.message);
    }
  }

  embStream.write("]}");
  await new Promise((resolve, reject) => {
    embStream.end(resolve);
    embStream.on("error", reject);
  });

  if (products.length) {
    const texts = products.map(p => `${p.name}\n${p.description}\n${p.brand}\n${p.price} ${p.currency}`.trim());
    const pvecs = await embedBatch(texts);
    writeJSON(PROD_FILE, { products, emb: pvecs });
  } else {
    writeJSON(PROD_FILE, { products: [], emb: [] });
  }

  TENANT_EMB_CACHE.delete(tenantId);
  TENANT_PROD_CACHE.delete(tenantId);

  const stats = { pages: totalPages, chunks: totalChunks, products: products.length };
  writeJSON(STATUS_FILE, { ...stats, baseUrl, indexedAt: new Date().toISOString() });
  return stats;
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

// Simple top-K retrieval (cached + typed arrays)
async function retrieveContext(tenantId, query, k = 8) {
  const q = await embedQueryCached(query);
  const { chunks } = getTenantEmbeddingsCached(tenantId);
  if (!chunks.length) return [];
  const scored = chunks.map(c => ({ ...c, score: dot(q, c.vec) }));
  scored.sort((a, b) => b.score - a.score);
  return scored.slice(0, k);
}

async function retrieveProducts(tenantId, query, k = 5) {
  const q = await embedQueryCached(query);
  const { products, emb } = getTenantProductsCached(tenantId);
  if (!products.length) return [];
  const scored = products.map((p, i) => ({ ...p, score: dot(q, emb[i] || new Float32Array(0)) }));
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
  if (Number.isFinite(n) && n > 0) return Math.min(n, 100000);
  return base.AI_MONTHLY_LIMIT;
}

function findFAQReply(message) {
  loadFAQsIfChanged();
  const m = FAQs.find((f) => f.trigger.test(message));
  return m?.reply || null;
}

// ===================
// CSV helpers (Woo/WordPress friendly)
// ===================
function detectDelimiter(text) {
  const semi = (text.match(/;/g) || []).length;
  const comma = (text.match(/,/g) || []).length;
  return semi > comma ? ";" : ",";
}
function firstNonEmpty(str, seps = [",", "|", ";"]) {
  if (!str) return "";
  for (const s of seps) {
    if (str.includes(s)) {
      const parts = str.split(s).map(v => v.trim()).filter(Boolean);
      if (parts.length) return parts[0];
    }
  }
  return String(str).trim();
}
function pick(row, aliases) {
  const keys = Object.keys(row);
  for (const name of aliases) {
    const k = keys.find(kk => kk.toLowerCase() === name.toLowerCase());
    if (k && row[k] != null && String(row[k]).trim() !== "") return String(row[k]).trim();
  }
  for (const name of aliases) {
    const k = keys.find(kk => kk.toLowerCase().includes(name.toLowerCase()));
    if (k && row[k] != null && String(row[k]).trim() !== "") return String(row[k]).trim();
  }
  return "";
}
function parsePriceCurrency(rawVal, explicitCurrency = "") {
  const v = String(rawVal || "").trim();
  if (!v) return { price: "", currency: explicitCurrency || "" };
  const symbolMap = {"€":"EUR","$":"USD","£":"GBP","CHF":"CHF","zł":"PLN","¥":"JPY","C$":"CAD","A$":"AUD"};
  const cleaned = v.replace(/\s/g, "");
  let cur = explicitCurrency || "";
  const isoMatch = cleaned.match(/\b(EUR|USD|GBP|CHF|PLN|JPY|CAD|AUD|NZD|SEK|NOK|DKK|CZK)\b/i);
  if (isoMatch) cur = isoMatch[1].toUpperCase();
  if (!cur) {
    const sym = Object.keys(symbolMap).find(s => cleaned.includes(s));
    if (sym) cur = symbolMap[sym];
  }
  const numMatch = cleaned.match(/-?\d{1,3}(\.\d{3})*(,\d+)?|-?\d+(?:\.\d+)?/g);
  let num = numMatch ? numMatch[numMatch.length - 1] : "";
  if (num && num.includes(",") && !num.includes(".")) {
    num = num.replace(/\./g, "").replace(",", ".");
  } else if (num) {
    const parts = num.split(".");
    if (parts.length > 2) { const last = parts.pop(); num = parts.join("") + "." + last; }
    num = num.replace(/,/g, "");
  }
  return { price: num, currency: cur };
}
function normalizeItemFromRow(row) {
  const sku         = pick(row, ["SKU", "sku", "_sku", "product code"]);
  const name        = pick(row, ["Name", "name", "title", "post_title", "product name"]);
  const description = pick(row, ["Short description", "short description", "Description", "description", "Excerpt", "excerpt", "post_content"]);
  const url         = pick(row, ["External URL", "external url", "Permalink", "permalink", "URL", "url", "Link", "link", "Product URL"]);
  const brand       = pick(row, ["Brands", "Brand", "brand", "pa_brand", "attribute:brand", "attribute 1 value"]);
  const { price, currency } = parsePriceCurrency(
    pick(row, ["Regular price", "regular price", "_regular_price", "Sale price", "sale price", "_sale_price", "Price", "price"]),
    pick(row, ["Currency", "currency"])
  );
  const image = firstNonEmpty(pick(row, ["Images", "images", "Image", "image", "Image URL", "Featured image", "thumbnail"]));
  return { name, description, url, price, currency, image, sku, brand };
}
function parseCsvSmart(csvText) {
  const delimiter = detectDelimiter(csvText);
  const records = csvParse(csvText, {
    columns: header => header.map(h => String(h).trim()),
    delimiter,
    skip_empty_lines: true,
    relax_quotes: true,
    relax_column_count: true,
    bom: true
  });
  return records.map(normalizeItemFromRow);
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

// POST /products/upload
app.post("/products/upload", async (req, res) => {
  try {
    const tenantId = (req.body?.tenantId || "").toString().trim();
    if (!tenantId) return res.status(400).json({ error: "tenantId required" });

    let items = [];
    if (Array.isArray(req.body?.items)) {
      items = req.body.items;
    } else if (typeof req.body?.csv === "string") {
      try {
        items = parseCsvSmart(req.body.csv);
      } catch (e) {
        return res.status(400).json({ error: "CSV parse failed: " + e.message });
      }
    } else {
      return res.status(400).json({ error: "Provide items[] or csv" });
    }

    const dir = tenantDir(tenantId);
    const PROD_FILE = path.join(dir, "products.json");

    const texts = items.map(p =>
      `${p.name || ""}\n${p.description || ""}\n${p.brand || ""}\n${(p.price || "")} ${(p.currency || "")}`.trim()
    );
    const vecs = texts.length ? await embedBatch(texts) : [];
    writeJSON(PROD_FILE, { products: items, emb: vecs });

    TENANT_PROD_CACHE.delete(tenantId);

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
  const tenantId = (req.headers["x-tenant-id"] || "").toString().trim();
  const message = (req.body?.message || "").toString();
  let userPlan = (req.headers["x-plan"] || "rule").toString();
  const customApiKey = (req.headers["x-api-key"] || "").toString().trim();
  const overrideLimit = req.headers["x-ai-limit"];

  if (!PLANS[userPlan]) userPlan = "rule";

  const usage = ensureMonth(userId);
  usage.plan = userPlan;
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
      const limit = clampLimit(userPlan, overrideLimit);
      if (usage.aiCalls >= limit) {
        reply = "⚠️ AI usage limit reached for this month. Please contact support to upgrade your plan.";
      } else {
        const apiKeyToUse = customApiKey || DEFAULT_API_KEY;
        if (!apiKeyToUse) {
          reply = "⚠️ No API key configured. Please contact support.";
        } else {
          let contextBlocks = [];
          let productBlocks = [];
          if (tenantId) {
            contextBlocks = await retrieveContext(tenantId, message, 8);
            const producty = /\b(product|buy|price|sizes?|colors?|catalog|shop|add to cart|order|stock|available|recommend|earrings?|ring|necklace|bracelet)\b/i.test(message);
            if (producty) {
              productBlocks = await retrieveProducts(tenantId, message, 6);
            }
          }

          const contextText = contextBlocks.map((b, i) => `#${i+1} [${b.url}]\n${b.text}`).join("\n\n");
          const productText = productBlocks.length
            ? "Products:\n" + productBlocks.map((p) =>
                `- [${p.name}](${p.url})${p.price ? " — " + p.price + (p.currency ? " " + p.currency : "") : ""}\n  ${p.description || ""}`
              ).join("\n")
            : "";

          const strictInstruction = `
Truth & Safety
- Never invent or infer facts beyond Context/Products.
- Do not guess about prices, stock, shipping, returns, sizes, or materials.
- If any required field (name, URL, price, currency) is missing or malformed, use the fallback line above.

Tone & Brevity
- Be concise, friendly, and practical. Short sentences.
- If the user’s intent is unclear or they are browsing, ask ONE clarifying question (category, budget, style, size) and stop.

Formatting Rules
- When referencing a product, format each item as:
  - [Product Name](ProductURL) — PRICE CUR
- Use a hyphen bullet per item (Markdown list). No long paragraphs around the list.
- ProductURL must be exactly the WooCommerce permalink given in Products.
- Do not add HTML attributes or extra text to links. Do NOT return <a ...> HTML.

Behavior
- Refer products when the user request matches the provided Products. Otherwise, ask for clarification.
- Ask at most 3 follow-ups if needed.
- Prefer products that match the query (category/price/style/range).
- If no exact matches, show up to 5 closest alternatives (from Products).
- Only show variants listed explicitly in Products.
- No external links or images unless provided in Context/Products.
- Greetings: one short line.

Language
- Answer in the user’s language if detectable (ES/EN). Otherwise, use EN.

Refusal (must be exact)
I’m not sure based on the site content. Please contact support or check the website.`.trim();

          const messages = [
            { role: "system", content: strictInstruction },
            { role: "user", content: `User question:\n${message}\n\n---\nContext:\n${contextText || "(no context found)"}\n\n${productText ? "---\n" + productText : ""}` }
          ];

          // Convert any HTML anchors to Markdown to keep output uniform
          function anchorsToMarkdown(txt) {
            if (!txt) return "";
            return String(txt).replace(
              /<a\s+[^>]*href="(https?:\/\/[^"]+)"[^>]*>([\s\S]*?)<\/a>/gi,
              (_m, url, text) => {
                const cleanText = String(text || "").replace(/<[^>]+>/g, "").trim();
                return cleanText ? `[${cleanText}](${url})` : url;
              }
            ).trim();
          }

          reply = await callGPT(messages, apiKeyToUse);
          reply = anchorsToMarkdown(reply);
          usedAI = true;

          // If we have product hits, enforce standard bullet list with exact permalinks
          if (productBlocks.length) {
            const bullets = productBlocks.map(p => {
              const priceStr = p.price ? ` — ${p.price}${p.currency ? ` ${p.currency}` : ""}` : "";
              return `- [${p.name}](${p.url})${priceStr}`;
            }).join("\n");

            let opener = reply.replace(/\s+/g, " ").trim();
            if (!opener || opener.length > 180) {
              opener = "Here are some options you might like:";
            }

            // Ensure a blank line before list (proper Markdown)
            reply = `${opener}\n\n${bullets}`;
          }

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

  let limit = clampLimit(userPlan, overrideLimit);
  if (usedAI) {
    usage.aiCalls++;
    const all = loadUsage();
    all[userId] = usage;
    saveUsage(all);
  }

  userMemory[userId].push({ role: "user", text: message, ts: new Date().toISOString() });
  userMemory[userId].push({ role: "assistant", text: reply, ts: new Date().toISOString() });

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

// DELETE /chat/clear
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

// (optional) GET /faqs
app.get("/faqs", (_req, res) => {
  res.json(
    FAQs.map((f) => ({ trigger: f.trigger.toString(), reply: f.reply }))
  );
});

// Healthcheck
app.get("/", (_req, res) => res.json({ status: "ok", version: "2.9.7" }));

// Start server
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`🚀 Chatbot backend v2.9.7 running on port ${PORT}`));
