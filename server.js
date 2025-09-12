// backend/index.js
import express from "express";
import cors from "cors";
import bodyParser from "body-parser";
import { v4 as uuidv4 } from "uuid";
import fetch from "node-fetch";

const app = express();
app.use(cors());
app.use(bodyParser.json());

// In-memory storage for demonstration
// You can later replace with DB for persistence
const userMemory = {}; // { userId: [ {message, reply, timestamp} ] }
const logs = []; // global log for analytics

const GPT_API_URL = "https://api.openai.com/v1/responses"; // GPT-4o-mini endpoint
const GPT_API_KEY = process.env.OPENAI_API_KEY;

// Helper to call GPT-4o-mini
async function callGPT(userId, message) {
    // Optional: include previous messages for context
    const conversation = userMemory[userId] || [];
    const contextMessages = conversation.map(m => ({ role: m.role, content: m.text }));

    const payload = {
        model: "gpt-4o-mini",
        input: [
            ...contextMessages,
            { role: "user", content: message }
        ],
    };

    const res = await fetch(GPT_API_URL, {
        method: "POST",
        headers: {
            "Authorization": `Bearer ${GPT_API_KEY}`,
            "Content-Type": "application/json"
        },
        body: JSON.stringify(payload)
    });

    const data = await res.json();
    // Assuming response format: data.output_text
    return data.output_text || "Sorry, I could not generate a response.";
}

// POST /chat
app.post("/chat", async (req, res) => {
    const userId = req.headers["x-user-id"] || uuidv4();
    const message = req.body.message || "";

    if (!userMemory[userId]) userMemory[userId] = [];

    // Check fallback FAQs if GPT fails
    const FAQs = [
        { trigger: /shipping/i, reply: "We offer standard, express, and overnight shipping." },
        { trigger: /return/i, reply: "You can return most items within 30 days of purchase." },
        { trigger: /payment/i, reply: "We accept Visa, MasterCard, PayPal, and Apple Pay." },
        { trigger: /contact/i, reply: "You can reach us via our Contact page or email support@example.com." }
    ];

    let reply = "";
    try {
        reply = await callGPT(userId, message);
        if(!reply) throw new Error("Empty GPT reply");
    } catch(e) {
        // fallback FAQs
        const faqMatch = FAQs.find(f => f.trigger.test(message));
        reply = faqMatch ? faqMatch.reply : "⚠️ Sorry, I could not generate a response.";
    }

    // Store memory
    userMemory[userId].push({ role: "user", text: message, timestamp: new Date().toISOString() });
    userMemory[userId].push({ role: "assistant", text: reply, timestamp: new Date().toISOString() });

    // Log for analytics
    logs.push({ timestamp: new Date().toISOString(), userId, message, response: reply });

    res.json({ reply });
});

// POST /chat/clear
app.post("/chat/clear", (req, res) => {
    const userId = req.headers["x-user-id"];
    if (userId) delete userMemory[userId];
    res.json({ status: "ok" });
});

// GET /analytics
app.get("/analytics", (req, res) => {
    res.json(logs);
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Chatbot backend running on port ${PORT}`));
