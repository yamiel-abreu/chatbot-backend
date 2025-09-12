#!/bin/bash

# ===== CONFIG =====
BACKEND_URL="https://YOUR_BACKEND_URL"   # <-- replace with your Render backend URL
USER_ID="test-user-1"
# ===================

echo "🔍 1. Checking backend health..."
curl -s -X GET $BACKEND_URL/
echo -e "\n-----------------------------\n"

echo "💬 2. Sending first message..."
curl -s -X POST $BACKEND_URL/chat \
  -H "Content-Type: application/json" \
  -d "{\"user_id\":\"$USER_ID\",\"message\":\"Hello, who are you?\"}"
echo -e "\n-----------------------------\n"

echo "🧠 3. Testing memory (follow-up)..."
curl -s -X POST $BACKEND_URL/chat \
  -H "Content-Type: application/json" \
  -d "{\"user_id\":\"$USER_ID\",\"message\":\"What did I just say?\"}"
echo -e "\n-----------------------------\n"

echo "🗑️ 4. Clearing memory..."
curl -s -X POST $BACKEND_URL/chat/clear \
  -H "Content-Type: application/json" \
  -d "{\"user_id\":\"$USER_ID\"}"
echo -e "\n-----------------------------\n"

echo "❓ 5. Testing fallback FAQ..."
curl -s -X POST $BACKEND_URL/chat \
  -H "Content-Type: application/json" \
  -d "{\"user_id\":\"$USER_ID\",\"message\":\"What time does your shop close?\"}"
echo -e "\n-----------------------------\n"

echo "📊 6. Fetching analytics..."
curl -s -X GET $BACKEND_URL/analytics
echo -e "\n✅ Test run complete!"
