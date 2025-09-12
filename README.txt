Testing instructions


Notes

Requires jq for pretty-printing JSON. Install it on Windows (WSL) or Linux:

sudo apt-get install jq


Replace YOUR_BACKEND_URL with your actual Render service URL.


- Run

chmod +x test_backend.sh
./test_backend.sh



- Instructions to Update the Bakend jor on the cloud (Render or other VPS)

Commit the updated backend to your existing GitHub repo
git add .
git commit -m "Update backend: GPT-4o-mini, server-side memory, analytics, clear chat"
git push origin main

2️⃣ Trigger a redeploy on Render

Go to your Render dashboard → Your Web Service

Click Manual Deploy → select main branch (or your branch)

Render will pull the latest commit, install dependencies, and start the updated server

3️⃣ Check after deployment

Open the live URL: https://<your-render-service>.onrender.com

Test endpoints:

POST /chat → chatbot responses

POST /chat/clear → clears user memory

GET /analytics → returns JSON logs

Update your WordPress plugin if the API URL changed.


