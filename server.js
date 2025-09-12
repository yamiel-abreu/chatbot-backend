const express = require("express");
const cors = require("cors");

const app = express();
app.use(cors());
app.use(express.json());

// Simple test endpoint
app.get("/", (req, res) => {
  res.json({ message: "Chatbot backend is running!" });
});

// Fake chatbot endpoint
app.post("/chat", (req, res) => {
  const { message } = req.body;
  res.json({ reply: `You said: ${message}` });
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Server running on port ${PORT}`);
});
