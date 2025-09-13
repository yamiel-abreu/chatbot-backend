// server.js
// version 2.0
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

const GPT_API_URL = "https://api.openai.com/v1/responses";
const DEFAULT_API_KEY = process.env.OPENAI_API_KEY;

// Paths
const USAGE_FILE = path.join("./usage.json");
if (!fs.existsSync(USAGE_FILE)) {
  fs.writeFileSync(USAGE_FILE, JSON.stringify({}), "utf8");
}

// Load usage
function loadUsage() {
  return JSON.parse(fs.readFileSync(USAGE_FILE, "utf8"));
}
function saveUsage(data) {
  fs.writeFileSync(USAGE_FILE, JSON.stringify(data, null, 2), "utf8");
}

// ✅ Reset usage if new month
function ensureMonth(userId) {
  const usage = loadUsage();
  const currentMonth = new Date().toISOString().slice(0, 7); // YYYY-MM
  if (!usage[userId] || usage[userId].month !== currentMonth) {
    usage[userId] = { month: currentMonth, aiCalls: 0, plan: "rule" }; // default rule
    saveUsage(usage);
  }
  return usage[userId];
}

// ✅ Helper to call GPT
async function callGPT(message, apiKey = DEFAULT_API_KEY) {
  const payload = {
    model: "gpt-4o-mini",
    input: [{ role: "user", content: message }],
  };

  const res = await fetch(GPT_API_URL, {
    method: "POST",
    headers: {
      "Authorization": `Bearer ${apiKey}`,
      "Content-Type": "application/json"
    },
    body: JSON.stringify(payload)
  });

  const data = await res.json();
  return data?.output?.[0]?.content?.[0]?.text || null;
}

// Fallback FAQs from faqs.json
let FAQs = [];
try {
  FAQs = JSON.parse(fs.readFileSync("./faqs.json", "utf8"));
} catch {
  FAQs = [
    { trigger: /shipping/i, reply: "We offer standard, express, and overnight shipping." },
    { trigger: /return/i, reply: "You can return most items within 30 days of purchase." },
    { trigger: /payment/i, reply: "We accept Visa, MasterCard, PayPal, and Apple Pay." },
    { trigger: /contact/i, reply: "You can reach us via our Contact page or email support@example.com." }
  ];
}

// In-memory conversation storage
const userMemory = {};
const logs = [];

// ===================
// PLANS & LIMITS
// ===================
const PLANS = {
  rule: { mode: "rule", AI_MONTHLY_LIMIT: 0 },        // Basic
  ai: { mode: "ai", AI_MONTHLY_LIMIT: 1000 },         // Pro
  enterprise: { mode: "ai", AI_MONTHLY_LIMIT: 10000 } // Enterprise
};

// POST /chat
app.post("/chat", async (req, res) => {
  const userId = req.headers["x-user-id"] || uuidv4();
  const message = req.body.message || "";
  const userPlan = req.headers["x-plan"] || "rule"; // frontend passes plan
  const customApiKey = req.headers["x-api-key"] || null; // enterprise BYOK

  // Ensure usage record
  const usage = ensureMonth(userId);
  usage.plan = userPlan;

  if (!userMemory[userId]) userMemory[userId] = [];

  let reply = "";

  try {
    if (PLANS[userPlan].mode === "rule") {
      // Rule-based only
      const faqMatch = FAQs.find(f => f.trigger.test(message));
      reply = faqMatch ? faqMatch.reply : "⚠️ Sorry, I only handle FAQs in this plan.";
    } else {
      // AI mode → check limits
      if (usage.aiCalls >= PLANS[userPlan].AI_MONTHLY_LIMIT) {
        reply = "⚠️ AI usage limit reached for this month. Please contact support to upgrade your plan.";
      } else {
        reply = await callGPT(message, customApiKey || DEFAULT_API_KEY);
        usage.aiCalls++;
        const allUsage = loadUsage();
        allUsage[userId] = usage;
        saveUsage(allUsage);
      }
    }
  } catch (e) {
    console.error("❌ GPT call failed:", e.message);
    const faqMatch = FAQs.find(f => f.trigger.test(message));
    reply = faqMatch ? faqMatch.reply : "⚠️ Sorry, I could not generate a response.";
  }

  // Save memory
  userMemory[userId].push({ role: "user", text: message, timestamp: new Date().toISOString() });
  userMemory[userId].push({ role: "assistant", text: reply, timestamp: new Date().toISOString() });

  // Log analytics
  logs.push({ timestamp: new Date().toISOString(), userId, message, response: reply, plan: userPlan, aiCalls: usage.aiCalls });

  res.json({ reply, usage: usage.aiCalls, limit: PLANS[userPlan].AI_MONTHLY_LIMIT });
});

// GET /usage/:userId
app.get("/usage/:userId", (req, res) => {
  const usage = ensureMonth(req.params.userId);
  res.json(usage);
});

// GET /analytics
app.get("/analytics", (req, res) => res.json(logs));

// Healthcheck
app.get("/", (req, res) => res.json({ status: "ok" }));

// Start server
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`🚀 Chatbot backend running on port ${PORT}`));
