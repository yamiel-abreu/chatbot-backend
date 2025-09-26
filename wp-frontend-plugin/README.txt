Chatbot Widget

v 1.0
Widget connected to the backend  and shows the chat all the time.


v 1.3
Added the options to have the chat closed and open
Different colors for the chat messages from the user and from the bot.
Directly injected into the web site so it is visible in all paged of the web.


V 1.6

Floating minimized chat button 💬
Expandable chat window
Messenger-style chat bubbles
Typing indicator while waiting for bot
Memory across pages using localStorage
Full memory + UX features remain intact: typing indicator, chat bubbles, minimized floating button.
Clear Chat button 🗑️ next to ✖ in the header.
Clicking it clears:
	Messages on the page
	Memory in localStorage


V 1.8
Instant FAQ responses
Preloaded welcome message


v 1.9
Features Fixed & Enhanced

Clear Chat now shows welcome message immediately
Chat open/close state preserved across page loads
Memory persists messages across pages
Floating minimized button
Expandable chat window
Messenger-style chat bubbles
Typing indicator
FAQ instant responses
Preloaded welcome message


V 1.10

Features in v1.10

Floating minimized button
Expandable chat window
Messenger-style chat bubbles
Typing indicator
Memory persists messages across pages
Clear Chat shows welcome message immediately
FAQ instant responses
Preloaded welcome message 👋
Open/close state preserved across page loads
Quick-reply buttons for common FAQs


V 1.11

v1.11 Highlights

Floating minimized button
Expandable chat window
Messenger-style chat bubbles
Typing indicator
Memory persists messages across pages
Clear Chat shows welcome message immediately
FAQ instant responses
Preloaded welcome message 👋
Open/close state preserved across page loads
Floating quick-reply tray inside messages (more natural, disappears after click)


v 1.13

v1.13 Highlights
Floating minimized button
Expandable chat window
Messenger-style chat bubbles
Typing indicator
Memory persists messages across pages
Clear Chat shows welcome message
FAQ instant responses
Preloaded welcome message
Open/close state preserved
2×2 floating quick-reply tray: 2 columns × 2 rows, buttons fill width, just above input


v 1.14

Layout fix for the quick-reply tray.


v 1.16

Frontend chatbot widget (v1.16)
Server-side memory (x-user-id)
Clear Chat
Quick-replies / 2×2 tray
GPT-4o-mini backend integration
Minimize / Maximize buttons
Analytics dashboard with charts + date filter
Injected site-wide via wp_footer



v 2.5

Missing endpoint implemented: /chat/clear (your WP widget referenced it).
Monthly caps truly safe: caps enforced on every AI call; usage persisted immediately; BYOK honored.
BYOK + per-tenant limits: header x-api-key and optional x-ai-limit supported; plans still define defaults.
FAQs hot-reload: update faqs.json without restarting; supports "trigger": "text" or {pattern, flags}.
OpenAI call robustness: handles output_text, timeouts, non-OK responses gracefully; env model override.
Data directory: DATA_DIR for Render persistent disk; usage & FAQs saved there.
Small niceties: returns plan in /chat response; optional reset=usage query on /chat/clear.


v2.6 

Widget minimize/maximize
New launcher button #chatbot-launcher (bottom-right).
Chat window #chatbot-container starts hidden.
Clicking the header or the launcher toggles visibility.
State stored in localStorage (chatbot_widget_state).
Analytics: Usage by User
New table “Usage by User (this month)” that aggregates logs and displays:
User ID, Plan, latest AI Calls, and Last Seen.
No backend changes required; uses /analytics only.



v 2.6.3

Changes made:

Fix the minimize button on the chat widget.
Removed the header badge (“Pro (AI included)”) entirely.
Footer note now reads: “Powered by Nubedy” and links to https://www.nubedy.com/chat
Kept the usage bar behavior (no “(Usage: x/y)” inside messages).
Analytics now includes:
Per-user Plan, Limit, and Remaining columns (using the most recent log entry that includes a limit).
A compact Bot Status summary card on top (users, total calls, average limit).
Raw logs table shows the Limit field per event when available.
If your /analytics endpoint doesn’t currently include a limit property in each log, I left it resilient — it will show “-” for limit/remaining. If you want, I can update the backend to include limit in each analytics log entry so this UI is always populated.


V 2.7:


Changes made:

Frontend now pulls Backend URL, Tenant ID, and Plan from WP Settings (no hard-coded values).
Frontend sends the required x-tenant-id header to ground answers to the right site.
New Settings & Indexing page lets you:
Save settings (Backend/Tenant/Base/MaxPages/Plan).
Trigger Index Now and Check Status against your backend.
Upload CSV/JSON product feeds and list stored products.
Analytics page uses the configured Backend URL and shows Plan/Limit/Remaining.


V 2.8.0

Not big updates. Just alignment with the backend side. 