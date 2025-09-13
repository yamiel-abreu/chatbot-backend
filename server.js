// server.js
// v2.5
// Author: YAA

import express from "express";
import cors from "cors";
import bodyParser from "body-parser";
import { v4 as uuidv4 } from "uuid";
import fetch from "node-fetch";
import fs from "fs";
import path from "path";

const app = express();
app.use(cors());
app.use(bodyParser.json());

// ---- Config (env-driven) ----
const GPT_API_URL = process.env.OPENAI_API_URL || "https://api.openai.com/v1/responses";
const DEFAULT_MODEL = process.env.OPENAI_MODEL || "gpt-4o-mini";
const DEFAULT_API_KEY = process.env.OPENAI_API_KEY;

// DATA DIR so Render can mount a disk; fallback to CWD
const DATA_DIR = process.env.DATA_DIR || ".";
const USAGE_FILE = path.resolve(DATA_DIR, "usage.json");
const FAQS_FILE = path.resolve(DATA_DIR, "faqs.json");

// Ensure data files exist
if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
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
  try {
    return JSON.parse(fs.readFileSync(USAGE_FILE, "utf8"));
  } catch {
    return {};
  }
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
  rule: { mode: "rule", AI_MONTHLY_LIMIT: 0 },           // Basic Plan
  ai: { mode: "ai", AI_MONTHLY_LIMIT: 1000 },            // Pro Plan (managed key)
  enterprise: { mode: "ai", AI_MONTHLY_LIMIT: 10000 },   // Enterprise (higher cap or BYOK)
};

// ---- FAQs (hot-reload + regex compilation) ----
let FAQs = [];
let _faqsMTimeMs = 0;

function compileFAQs(raw) {
  // Accepts [{trigger: "shipping" | {"pattern":"..","flags":"i"}, reply:"..."}]
  return raw.map((f) => {
    if (f.trigger instanceof RegExp) return f; // already compiled
    if (typeof f.trigger === "string") {
      return { trigger: new RegExp(f.trigger, "i"), reply: f.reply };
    }
    if (typeof f.trigger === "object" && f.trigger?.pattern) {
      return { trigger: new RegExp(f.trigger.pattern, f.trigger.flags || "i"), reply: f.reply };
    }
    return { trigger: /$a^/i, reply: f.reply }; // never matches if malformed
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

// initial load + watch
loadFAQsIfChanged();
fs.watchFile(FAQS_FILE, { interval: 1000 }, loadFAQsIfChanged);

// ---- Memory + logs (in-memory) ----
const userMemory = {};
const logs = [];

// ---- OpenAI helper (Responses API; robust parsing) ----
async function callGPT(message, apiKey, model = DEFAULT_MODEL, timeoutMs = 25000) {
  const controller = new AbortController();
  const t = setTimeout(() => controller.abort(), timeoutMs);

  const payload = {
    model,
    input: [{ role: "user", content: message }],
  };

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

  // Prefer output_text when available; fallback to content walker
  if (data.output_text) return String(data.output_text);

  const maybe = data?.output?.[0]?.content;
  if (Array.isArray(maybe)) {
    const firstText = maybe.find((c) => c?.type === "output_text" || c?.text)?.text;
    if (firstText) return String(firstText);
  }

  // Ultimate fallback
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

// POST /chat
app.post("/chat", async (req, res) => {
  const userId = (req.headers["x-user-id"] || "").toString() || uuidv4();
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
          reply = await callGPT(message, apiKeyToUse);
          usedAI = true;
        }
      }
    }
  } catch (e) {
    console.error("❌ GPT call failed:", e.message);
    reply = findFAQReply(message) || "⚠️ Sorry, I could not generate a response.";
  }

  // Increment and persist usage if AI was used
  if (usedAI) {
    usage.aiCalls++;
    const all = loadUsage();
    all[userId] = usage;
    saveUsage(all);
  }

  // Save memory
  userMemory[userId].push({ role: "user", text: message, ts: new Date().toISOString() });
  userMemory[userId].push({ role: "assistant", text: reply, ts: new Date().toISOString() });

  // Log analytics (in-memory)
  logs.push({
    timestamp: new Date().toISOString(),
    userId,
    message,
    response: reply,
    plan: userPlan,
    aiCalls: usage.aiCalls,
  });

  const limit = clampLimit(userPlan, overrideLimit);
  res.json({ reply, usage: usage.aiCalls, limit, plan: userPlan });
});

// DELETE /chat/clear — clear memory for a user (and optionally reset usage for the month)
app.delete("/chat/clear", (req, res) => {
  const userId = (req.headers["x-user-id"] || "").toString();
  if (!userId) return res.status(400).json({ error: "Missing x-user-id header" });

  userMemory[userId] = [];

  // Do NOT reset the month counter; keep aiCalls (caps matter). If you want to reset:
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
    FAQs.map((f) => ({
      trigger: f.trigger.toString(),
      reply: f.reply,
    }))
  );
});

// Healthcheck
app.get("/", (_req, res) => res.json({ status: "ok" }));

// Start server
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`🚀 Chatbot backend running on port ${PORT}`));
