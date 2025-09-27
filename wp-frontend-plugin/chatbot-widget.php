<?php
/**
 * Plugin Name: Chatbot Widget + Analytics + Site Indexing
 * Description: Floating chatbot widget grounded on your website via RAG, with admin analytics, settings, site indexing, and product feed upload.
 * Version: 2.9.0
 * Author: YAA
 */

// -----------------------
// Helpers & Defaults
// -----------------------
function nubedy_chatbot_get_option($key, $default = '') {
  $v = get_option($key, $default);
  return is_string($v) ? trim($v) : $v;
}

// Set sensible defaults on activation (optional)
register_activation_hook(__FILE__, function () {
  add_option('chatbot_backend_url', 'https://chatbot-backend-9lxr.onrender.com');
  add_option('chatbot_tenant_id', 'client-123');
  add_option('chatbot_base_url', get_site_url());
  add_option('chatbot_max_pages', '120');
  add_option('chatbot_plan', 'ai'); // rule | ai | enterprise
  // NEW: theming defaults
  add_option('chatbot_bot_name', 'Chatbot');
  add_option('chatbot_color', '#0073aa');
});

// -----------------------
// FRONTEND CHATBOT WIDGET
// -----------------------
function chatbot_widget_inject() {
  $backend = nubedy_chatbot_get_option('chatbot_backend_url', 'https://chatbot-backend-9lxr.onrender.com');
  $tenant  = nubedy_chatbot_get_option('chatbot_tenant_id', 'client-123');
  $plan    = nubedy_chatbot_get_option('chatbot_plan', 'ai');
  $botname = nubedy_chatbot_get_option('chatbot_bot_name', 'Chatbot');
  $color   = nubedy_chatbot_get_option('chatbot_color', '#0073aa');

  // Escape for inline JS/HTML
  $backend_js = esc_js($backend);
  $tenant_js  = esc_js($tenant);
  $plan_js    = esc_js($plan);
  $botname_html = esc_html($botname);
  $color_css = esc_attr($color);
  ?>
    <style>
      :root { --chat-accent: <?php echo $color_css; ?>; }
      /* Base styles for launcher + container */
      #chatbot-launcher {
        position: fixed; bottom: 20px; right: 20px; width: 56px; height: 56px;
        border-radius: 50%; background: var(--chat-accent); color: #fff; display: grid;
        place-items: center; font-weight: 700; font-size: 22px;
        box-shadow: 0 8px 24px rgba(0,0,0,.18); cursor: pointer; z-index: 100000;
        user-select: none; transition: transform .2s ease;
      }
      #chatbot-launcher:active { transform: scale(0.98); }
      #chatbot-launcher[data-unread]:after{
        content: attr(data-unread);
        position:absolute; top:-6px; right:-6px; min-width:18px; height:18px;
        padding:0 5px; border-radius:999px; background:#ff3b30; color:#fff;
        font-size:12px; line-height:18px; text-align:center; box-shadow:0 1px 4px rgba(0,0,0,.2);
      }

      #chatbot-container {
        position: fixed; bottom: 90px; right: 20px; width: 340px; height: 460px; background: #fff;
        border: 1px solid #dcdcdc; border-radius: 14px; display: none; flex-direction: column;
        font-family: system-ui, Arial, sans-serif; z-index: 10001; box-shadow: 0 8px 24px rgba(0,0,0,.12);
        overflow: hidden;
      }
      #chatbot-container[aria-hidden="true"] { display: none !important; }

      .chatbot-header {
        display:flex; align-items:center; justify-content:space-between; background: var(--chat-accent); color:#fff;
        padding:10px 12px; font-weight:600; position: relative;
      }
      .chatbot-title { display:flex; align-items:center; gap:8px; }
      .chatbot-icon-btn {
        display:inline-flex; align-items:center; justify-content:center;
        width:28px; height:28px; margin-left:8px; background: transparent; border: none; color:#fff;
        cursor:pointer; border-radius:6px; flex: 0 0 auto;
      }
      .chatbot-icon-btn:hover { background: rgba(255,255,255,.15); }

      #chatbot-usage-bar { height:6px;background:#f0f0f0; }
      #chatbot-usage-fill { height:100%;width:0%;background:var(--chat-accent);transition:width .3s; }

      #chatbot-messages { flex:1; overflow-y:auto; padding:12px; background:#f9fafb; font-size:14px; }
      .chatbot-footer { display:flex; gap:6px; border-top:1px solid #eee; padding:8px; background:#fff; }
      .chatbot-input { flex:1; padding:10px; border:1px solid #ddd; border-radius:10px; outline:none; }
      .chatbot-btn { padding:10px 12px; border:none; background:var(--chat-accent); color:#fff; border-radius:10px; cursor:pointer; }
      .chatbot-btn-secondary { padding:10px; border:1px solid #ddd; background:#fff; color:#333; border-radius:10px; cursor:pointer; }
      .chatbot-hint { text-align:center; font-size:11px; color:#777; padding:6px 0; background:#fafafa; }
      .chatbot-hint a { color:var(--chat-accent); text-decoration:underline; }

      .bubble-wrap { margin: 6px 0; }
      .bubble { display:inline-block; max-width:85%; padding:8px 10px; border-radius:12px; white-space: pre-wrap; word-break: break-word; }
      .bubble-user { background:#1f2937; color:#fff; }
      .bubble-bot { background:#e5e7eb; color:#111; }

      /* Tiny inline SVG in launcher controlled by JS (paths) */
      .svg-ico { width:24px; height:24px; display:block; }
    </style>

    <script>
    // ---- Config (from WP Settings)
    const BACKEND = "<?php echo $backend_js; ?>";
    const API_URL = BACKEND.replace(/\/+$/,'') + "/chat";
    const CLEAR_URL = BACKEND.replace(/\/+$/,'') + "/chat/clear";
    const ANALYTICS_URL = BACKEND.replace(/\/+$/,'') + "/analytics";

    const TENANT_ID = "<?php echo $tenant_js; ?>";
    const PLAN = "<?php echo $plan_js; ?>"; // "rule" | "ai" | "enterprise"

    // ---- Client state
    const STORAGE_KEY = "chatbot_conversation_v1";
    const USER_ID_KEY = "chatbot_user_id";
    const WIDGET_STATE_KEY = "chatbot_widget_state"; // "min" | "max"

    const ENTERPRISE_API_KEY = ""; // (optional) BYOK header; leave empty unless necessary
    const AI_LIMIT_OVERRIDE = "";  // (optional) per-tenant limit override

    let userId = localStorage.getItem(USER_ID_KEY);
    if(!userId){ userId = (crypto?.randomUUID?.() || String(Date.now()) + Math.random().toString(16).slice(2)); localStorage.setItem(USER_ID_KEY,userId); }

    function loadHistory(){ try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || "[]"); } catch { return []; } }
    function saveHistory(arr){ localStorage.setItem(STORAGE_KEY, JSON.stringify(arr.slice(-200))); }

    function getWidgetState() { return localStorage.getItem(WIDGET_STATE_KEY) || "min"; }
    function setWidgetState(state) { localStorage.setItem(WIDGET_STATE_KEY, state); }

    async function sendMessage(text){
        const headers = {
            "Content-Type":"application/json",
            "x-user-id": userId,
            "x-plan": PLAN,
            "x-tenant-id": TENANT_ID
        };
        if (PLAN === "enterprise" && ENTERPRISE_API_KEY) headers["x-api-key"] = ENTERPRISE_API_KEY;
        if (AI_LIMIT_OVERRIDE) headers["x-ai-limit"] = AI_LIMIT_OVERRIDE;

        const res = await fetch(API_URL,{ method:"POST", headers, body: JSON.stringify({ message: text }) });
        if(!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
    }

    async function clearChat(resetUsage=false){
        const res = await fetch(CLEAR_URL + (resetUsage ? "?reset=usage" : ""), { method: "DELETE", headers: { "x-user-id": userId } });
        return res.json();
    }
    </script>

    <!-- Launcher (always visible) -->
    <div id="chatbot-launcher" aria-expanded="false" aria-label="Open chat" title="Open chat" role="button" tabindex="0">
      <svg class="svg-ico" viewBox="0 0 24 24" aria-hidden="true">
        <!-- JS swaps this path: bubble (open) ↔ bar (minimize) -->
        <path id="chatbot-launcher-path" d="M4 4h16v12H7l-3 3V4z" fill="currentColor"></path>
      </svg>
    </div>

    <!-- Widget -->
    <div id="chatbot-container" role="dialog" aria-label="<?php echo esc_attr($botname_html); ?>" aria-hidden="true">
        <div class="chatbot-header" id="chatbot-header">
            <div class="chatbot-title">
              <span id="chatbot-title"><?php echo $botname_html; ?></span>
            </div>
            <button id="chatbot-minimize" class="chatbot-icon-btn" aria-label="Minimize chat" title="Minimize" type="button">
              <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="currentColor" d="M5 12h14v2H5z"/>
              </svg>
            </button>
        </div>
        <div id="chatbot-usage-bar"><div id="chatbot-usage-fill"></div></div>
        <div id="chatbot-messages"></div>
        <div class="chatbot-footer">
            <input id="chatbot-input" type="text" placeholder="Type a message..." class="chatbot-input" aria-label="Message input">
            <button id="chatbot-send" class="chatbot-btn" type="button">Send</button>
            <button id="chatbot-clear" title="Clear chat" class="chatbot-btn-secondary" type="button">Clear</button>
        </div>
        <div id="tiny-hint" class="chatbot-hint">
          Powered by <a href="https://www.nubedy.com/chat" target="_blank" rel="noopener">Nubedy</a>
        </div>
    </div>

    <script>
    (function(){
      const launcher = document.getElementById("chatbot-launcher");
      const launcherPath = document.getElementById("chatbot-launcher-path");
      const container = document.getElementById("chatbot-container");
      const header = document.getElementById("chatbot-header");
      const minimizeBtn = document.getElementById("chatbot-minimize");

      const messagesDiv = document.getElementById("chatbot-messages");
      const input = document.getElementById("chatbot-input");
      const sendBtn = document.getElementById("chatbot-send");
      const clearBtn = document.getElementById("chatbot-clear");
      const usageFill = document.getElementById("chatbot-usage-fill");
      const hint = document.getElementById("tiny-hint");
      const titleEl = document.getElementById("chatbot-title");

      // icons
      const PATH_OPEN = "M4 4h16v12H7l-3 3V4z"; // bubble = means "open chat"
      const PATH_MIN  = "M5 12h14v2H5z";        // bar = means "minimize"

      function setLauncherIcon(openState){
        launcherPath.setAttribute("d", openState ? PATH_MIN : PATH_OPEN);
        launcher.setAttribute("aria-label", openState ? "Minimize chat" : "Open chat");
        launcher.setAttribute("title", openState ? "Minimize chat" : "Open chat");
        launcher.setAttribute("aria-expanded", String(openState));
        if (openState) launcher.removeAttribute("data-unread");
      }

      function bubble(sender, text){
          const wrap = document.createElement("div");
          wrap.className = "bubble-wrap";
          const msg = document.createElement("div");
          msg.textContent = text;
          msg.className = "bubble " + (sender === "You" ? "bubble-user" : "bubble-bot");
          if (sender === "You") wrap.style.textAlign = "right";
          wrap.appendChild(msg);
          messagesDiv.appendChild(wrap);
          messagesDiv.scrollTop = messagesDiv.scrollHeight;
      }

      function setUsage(used, limit){
          if (!limit || limit <= 0) { usageFill.style.width = "0%"; return; }
          const pct = Math.min(100, Math.round((used / limit) * 100));
          usageFill.style.width = pct + "%";
      }

      function typing(on){
          const id = "typing-indicator";
          let el = document.getElementById(id);
          if (on) {
              if (el) return;
              el = document.createElement("div");
              el.id = id;
              el.className = "bubble-wrap";
              el.innerHTML = '<div class="bubble bubble-bot">…</div>';
              messagesDiv.appendChild(el);
              messagesDiv.scrollTop = messagesDiv.scrollHeight;
          } else if (el) {
              el.remove();
          }
      }

      function showWidget() {
        container.style.display = "flex";
        container.setAttribute("aria-hidden", "false");
        setLauncherIcon(true); // launcher stays visible, shows "minimize" icon
        setWidgetState("max");
        setTimeout(() => input?.focus(), 50);
      }
      function hideWidget() {
        container.style.display = "none";
        container.setAttribute("aria-hidden", "true");
        setLauncherIcon(false); // launcher shows "open" icon
        setWidgetState("min");
      }

      function toggleWidget(){
        const open = container.getAttribute("aria-hidden") === "false";
        if (open) hideWidget(); else showWidget();
      }

      // Initialize min/max based on saved state (default: minimized)
      if (getWidgetState() === "max") { showWidget(); } else { hideWidget(); }

      // Toggle from header double-click (keep header single click inert to avoid accidental closes)
      header.addEventListener("dblclick", toggleWidget);
      // explicit minimize button
      minimizeBtn?.addEventListener("click", (e) => { e.stopPropagation(); hideWidget(); });
      // launcher: click or keyboard
      launcher.addEventListener("click", toggleWidget);
      launcher.addEventListener("keydown", (e)=>{ if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggleWidget(); } });

      // ESC to minimize when open
      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && container.getAttribute("aria-hidden") === "false") hideWidget();
      });

      // Load persisted conversation
      const history = loadHistory();
      if (history.length) { history.forEach(h => bubble(h.sender, h.text)); }

      async function handleSend(){
          const text = input.value.trim();
          if (!text) return;
          bubble("You", text);
          const hist = loadHistory(); hist.push({ sender:"You", text }); saveHistory(hist);
          input.value = ""; input.focus();
          typing(true);
          try {
              const data = await sendMessage(text);
              typing(false);

              const replyText = String(data.reply ?? "");
              // If minimized (rare while sending), badge it
              if (container.getAttribute("aria-hidden") === "true") {
                const current = Number(launcher.getAttribute("data-unread") || 0) + 1;
                launcher.setAttribute("data-unread", String(current));
              }
              bubble("Bot", replyText);

              const h2 = loadHistory(); h2.push({ sender:"Bot", text: replyText }); saveHistory(h2);

              setUsage(data.usage, data.limit);
              if (data.limit && data.usage >= data.limit) {
                  input.disabled = true; sendBtn.disabled = true;
                  hint.innerHTML = 'Limit reached. Powered by <a href="https://www.nubedy.com/chat" target="_blank" rel="noopener">Nubedy</a>';
              }
          } catch (e) {
              typing(false);
              bubble("Bot", "⚠️ Error connecting to server.");
              const h3 = loadHistory(); h3.push({ sender:"Bot", text:"⚠️ Error connecting to server." }); saveHistory(h3);
          }
      }

      sendBtn.addEventListener("click", handleSend);
      input.addEventListener("keypress", (e) => { if (e.key === "Enter") handleSend(); });

      clearBtn.addEventListener("click", async () => {
          try { await clearChat(false); } catch {}
          localStorage.removeItem(STORAGE_KEY);
          messagesDiv.innerHTML = "";
          input.disabled = false; sendBtn.disabled = false;
          setUsage(0, 0);
          bubble("Bot", "Chat cleared. How can I help?");
      });

      // Expose a small hook so you can push messages externally if needed
      window.__chatbot_pushMessage = function(text){
        if (container.getAttribute("aria-hidden") === "true") {
          const current = Number(launcher.getAttribute("data-unread") || 0) + 1;
          launcher.setAttribute("data-unread", String(current));
        }
        bubble("Bot", text);
      };
    })();
    </script>
  <?php
}
add_action("wp_footer", "chatbot_widget_inject");

// ------------------------------
// ADMIN: MENU (Analytics + Settings/Indexing)
// ------------------------------
add_action('admin_menu', function() {
  // Top-level (Analytics as default)
  add_menu_page('Chatbot Analytics', 'Chatbot', 'manage_options', 'chatbot-analytics', 'chatbot_analytics_page', 'dashicons-format-chat', 26);
  // Submenu: Analytics
  add_submenu_page('chatbot-analytics', 'Chatbot Analytics', 'Analytics', 'manage_options', 'chatbot-analytics', 'chatbot_analytics_page');
  // Submenu: Settings & Index
  add_submenu_page('chatbot-analytics', 'Chatbot Settings & Indexing', 'Settings & Indexing', 'manage_options', 'chatbot-settings', 'chatbot_settings_page');
});

// ------------------------------
// ADMIN: SETTINGS REGISTER
// ------------------------------
add_action('admin_init', function () {
  register_setting('chatbot_settings', 'chatbot_backend_url', ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
  register_setting('chatbot_settings', 'chatbot_tenant_id',  ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_base_url',   ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
  register_setting('chatbot_settings', 'chatbot_max_pages',  ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_plan',       ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  // NEW: per-site theming
  register_setting('chatbot_settings', 'chatbot_bot_name',   ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_color',      ['type'=>'string','sanitize_callback'=>'sanitize_hex_color']);
});

// ------------------------------
// ADMIN: ANALYTICS PAGE
// ------------------------------
function chatbot_analytics_page() {
  $backend = nubedy_chatbot_get_option('chatbot_backend_url', 'https://chatbot-backend-9lxr.onrender.com');
  $analytics_url = esc_js(rtrim($backend, '/').'/analytics');
  ?>
    <div class="wrap">
        <h1>Chatbot Analytics</h1>

        <div id="chatbot-status" style="margin:14px 0;padding:12px;border:1px solid #e3e3e3;border-radius:8px;background:#fff;">
          <strong>Bot Status</strong>
          <div id="chatbot-status-body" style="margin-top:6px;color:#444;font-size:13px;">
            Loading…
          </div>
        </div>

        <h2 style="margin-top:10px;">Usage by User (this month)</h2>
        <p style="margin:6px 0 10px;color:#555;">
          Latest recorded stats per user. <em>Plan</em> and <em>Limit</em> are taken from the most recent log available.
        </p>
        <table id="chatbot-usage-table" class="widefat fixed" style="width:100%;margin-bottom:24px;">
            <thead>
              <tr>
                <th>User ID</th>
                <th>Plan</th>
                <th>AI Calls</th>
                <th>Limit</th>
                <th>Remaining</th>
                <th>Last Seen</th>
              </tr>
            </thead>
            <tbody></tbody>
        </table>

        <h2 style="margin-top:10px;">Event Logs</h2>
        <table id="chatbot-analytics-table" class="widefat fixed" style="width:100%">
            <thead>
              <tr>
                <th>Timestamp</th><th>User ID</th><th>Message</th>
                <th>Bot Reply</th><th>Plan</th><th>AI Calls</th><th>Limit</th>
              </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

    <script>
    const ANALYTICS_URL = '<?php echo $analytics_url; ?>';

    function _escape(s){ return (s||"").toString().replace(/</g,"&lt;"); }
    function _num(n){ const x = Number(n); return Number.isFinite(x) ? x : null; }

    async function loadAnalytics(){
        try {
            const res = await fetch(ANALYTICS_URL);
            if (!res.ok) throw new Error(res.status);
            const logs = await res.json();

            // ---- Fill raw logs table
            const tbody = document.querySelector('#chatbot-analytics-table tbody');
            tbody.innerHTML = '';
            logs.slice(-1000).forEach(log => {
                const limitVal = log.limit ?? log.aiLimit ?? '';
                const tr = document.createElement('tr');
                tr.innerHTML = `<td>${_escape(log.timestamp)}</td><td>${_escape(log.userId)}</td>
                                <td>${_escape(log.message)}</td><td>${_escape(log.response)}</td>
                                <td>${_escape(log.plan)}</td><td>${_escape(log.aiCalls)}</td>
                                <td>${_escape(limitVal)}</td>`;
                tbody.appendChild(tr);
            });

            // ---- Build usage summary per user (latest aiCalls + plan + last seen + limit)
            const perUser = {};
            for (const l of logs) {
              const u = l.userId || 'unknown';
              const ai = _num(l.aiCalls) ?? 0;
              const lim = _num(l.limit ?? l.aiLimit);
              const ts = l.timestamp || '';
              if (!perUser[u]) {
                perUser[u] = { aiCalls: ai, plan: l.plan || '-', last: ts, limit: lim };
              } else {
                if (ai > perUser[u].aiCalls) perUser[u].aiCalls = ai;
                if (ts && ts > perUser[u].last) {
                  perUser[u].last = ts;
                  perUser[u].plan = l.plan || perUser[u].plan;
                  perUser[u].limit = lim ?? perUser[u].limit;
                }
              }
            }

            const usageBody = document.querySelector('#chatbot-usage-table tbody');
            usageBody.innerHTML = '';
            const entries = Object.entries(perUser).sort((a,b)=> (b[1].aiCalls - a[1].aiCalls));
            entries.forEach(([uid, info]) => {
                const remaining = (Number.isFinite(info.limit) ? Math.max(0, info.limit - info.aiCalls) : '');
                const tr = document.createElement('tr');
                tr.innerHTML = `<td>${_escape(uid)}</td>
                                <td>${_escape(info.plan)}</td>
                                <td>${_escape(info.aiCalls)}</td>
                                <td>${_escape(Number.isFinite(info.limit)? info.limit : '-')}</td>
                                <td>${_escape(Number.isFinite(remaining)? remaining : '-')}</td>
                                <td>${_escape(info.last)}</td>`;
                usageBody.appendChild(tr);
            });

            // ---- Status card totals
            const totals = entries.reduce((acc, [,i]) => {
              acc.users += 1;
              acc.calls += (Number(i.aiCalls)||0);
              if (Number.isFinite(i.limit)) acc.limits.push(i.limit);
              return acc;
            }, { users:0, calls:0, limits:[] });

            const statusEl = document.getElementById('chatbot-status-body');
            const avgLimit = totals.limits.length ? Math.round(totals.limits.reduce((a,b)=>a+b,0)/totals.limits.length) : null;

            statusEl.innerHTML = `
              <div>Total users this month: <strong>${totals.users}</strong></div>
              <div>Total AI calls (sum of per-user current counts): <strong>${totals.calls}</strong></div>
              <div>Average user limit (if provided): <strong>${avgLimit ?? '-'}</strong></div>
              <div style="margin-top:6px;color:#666;">Tip: Per-user <em>Plan</em> and <em>Limit</em> are pulled from the latest event for that user.</div>
            `;
        } catch (e) {
            console.error(e);
            const statusEl = document.getElementById('chatbot-status-body');
            statusEl.textContent = 'Failed to load analytics. Check your Backend URL in Settings.';
        }
    }
    loadAnalytics();
    setInterval(loadAnalytics, 10000);
    </script>
  <?php
}

// ------------------------------
// ADMIN: SETTINGS & INDEX PAGE
// ------------------------------
function chatbot_settings_page() {
  $backend = nubedy_chatbot_get_option('chatbot_backend_url', 'https://chatbot-backend-9lxr.onrender.com');
  $tenant  = nubedy_chatbot_get_option('chatbot_tenant_id', 'client-123');
  $baseurl = nubedy_chatbot_get_option('chatbot_base_url', get_site_url());
  $maxpg   = nubedy_chatbot_get_option('chatbot_max_pages', '120');
  $plan    = nubedy_chatbot_get_option('chatbot_plan', 'ai');

  $botname = nubedy_chatbot_get_option('chatbot_bot_name', 'Chatbot');
  $color   = nubedy_chatbot_get_option('chatbot_color', '#0073aa');

  $backend_esc = esc_attr($backend);
  $tenant_esc  = esc_attr($tenant);
  $baseurl_esc = esc_attr($baseurl);
  $maxpg_esc   = esc_attr($maxpg);
  $botname_esc = esc_attr($botname);
  $color_esc   = esc_attr($color);
  ?>
  <div class="wrap">
    <h1>Chatbot Settings & Site Indexing</h1>

    <form method="post" action="options.php" style="margin-top:12px;">
      <?php settings_fields('chatbot_settings'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="chatbot_backend_url">Backend URL</label></th>
          <td><input name="chatbot_backend_url" id="chatbot_backend_url" type="url" class="regular-text" value="<?php echo $backend_esc; ?>" placeholder="https://your-backend.onrender.com"></td>
        </tr>
        <tr>
          <th scope="row"><label for="chatbot_tenant_id">Tenant ID</label></th>
          <td><input name="chatbot_tenant_id" id="chatbot_tenant_id" type="text" class="regular-text" value="<?php echo $tenant_esc; ?>" placeholder="client-123"></td>
        </tr>
        <tr>
          <th scope="row"><label for="chatbot_plan">Default Plan</label></th>
          <td>
            <select name="chatbot_plan" id="chatbot_plan">
              <option value="rule" <?php selected($plan, 'rule'); ?>>Basic (FAQ only)</option>
              <option value="ai" <?php selected($plan, 'ai'); ?>>Pro (AI included)</option>
              <option value="enterprise" <?php selected($plan, 'enterprise'); ?>>Enterprise</option>
            </select>
          </td>
        </tr>

        <tr><th colspan="2"><hr></th></tr>

        <tr>
          <th scope="row"><label for="chatbot_bot_name">Bot Name</label></th>
          <td><input name="chatbot_bot_name" id="chatbot_bot_name" type="text" class="regular-text" value="<?php echo $botname_esc; ?>" placeholder="Nuby Assistant"></td>
        </tr>
        <tr>
          <th scope="row"><label for="chatbot_color">Accent Color</label></th>
          <td><input name="chatbot_color" id="chatbot_color" type="color" value="<?php echo $color_esc; ?>"></td>
        </tr>

        <tr><th colspan="2"><hr></th></tr>

        <tr>
          <th scope="row"><label for="chatbot_base_url">Client Base URL</label></th>
          <td><input name="chatbot_base_url" id="chatbot_base_url" type="url" class="regular-text" value="<?php echo $baseurl_esc; ?>" placeholder="https://clientsite.com"></td>
        </tr>
        <tr>
          <th scope="row"><label for="chatbot_max_pages">Max Pages to Index</label></th>
          <td><input name="chatbot_max_pages" id="chatbot_max_pages" type="number" class="small-text" value="<?php echo $maxpg_esc; ?>" min="1" max="500"> <span class="description">Defaults to 120</span></td>
        </tr>
      </table>
      <?php submit_button('Save Settings'); ?>
    </form>

    <hr>

    <h2>Index Site (RAG)</h2>
    <p>Use this to (re)build the vector index from your website content and product schema.</p>
    <p><em>Note:</em> Indexing runs on your backend and stores data on its disk. Ensure CORS is enabled (it is in the provided server).</p>
    <div style="display:flex;gap:8px;margin:10px 0;">
      <button class="button button-primary" id="btn-index-site">Index Now</button>
      <button class="button" id="btn-status-site">Check Status</button>
    </div>
    <pre id="index-output" style="white-space:pre-wrap;background:#fff;border:1px solid #e2e2e2;border-radius:6px;padding:10px;max-height:260px;overflow:auto;">Ready.</pre>

    <hr>

    <h2>Product Feed Uploader (Optional)</h2>
    <p>Paste <strong>CSV</strong> (<code>name,description,url,price,currency,image,sku,brand</code>) or <strong>JSON</strong> (<code>{ items:[...] }</code> or <code>[...]</code> array) and upload.</p>
    <p>
      <label><input type="radio" name="feedType" value="csv" checked> CSV</label>
      &nbsp;&nbsp;
      <label><input type="radio" name="feedType" value="json"> JSON</label>
    </p>
    <textarea id="feed-text" rows="8" style="width:100%;max-width:900px;"></textarea>
    <div style="margin:10px 0;display:flex;gap:8px;">
      <button class="button button-primary" id="btn-upload-feed">Upload Feed</button>
      <button class="button" id="btn-list-products">List Products</button>
    </div>
    <pre id="feed-output" style="white-space:pre-wrap;background:#fff;border:1px solid #e2e2e2;border-radius:6px;padding:10px;max-height:260px;overflow:auto;">Waiting for input…</pre>
  </div>

  <script>
  (function(){
    function getVals(){
      return {
        backend: document.getElementById('chatbot_backend_url').value.trim(),
        tenant:  document.getElementById('chatbot_tenant_id').value.trim(),
        baseUrl: document.getElementById('chatbot_base_url').value.trim(),
        maxPages: Number(document.getElementById('chatbot_max_pages').value || 120)
      };
    }

    const out = document.getElementById('index-output');
    const feedOut = document.getElementById('feed-output');

    async function doIndex(){
      const { backend, tenant, baseUrl, maxPages } = getVals();
      if (!backend || !tenant || !baseUrl) {
        out.textContent = 'Please fill Backend URL, Tenant ID and Base URL, then Save Settings.';
        return;
      }
      out.textContent = 'Indexing started…';
      try {
        const res = await fetch(backend.replace(/\/+$/,'') + '/site/index', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ tenantId: tenant, baseUrl, maxPages })
        });
        const j = await res.json();
        out.textContent = JSON.stringify(j, null, 2);
      } catch (e) {
        out.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
      }
    }

    async function doStatus(){
      const { backend, tenant } = getVals();
      if (!backend || !tenant) {
        out.textContent = 'Please fill Backend URL and Tenant ID, then Save Settings.';
        return;
      }
      out.textContent = 'Checking status…';
      try {
        const res = await fetch(backend.replace(/\/+$/,'') + '/site/status?tenantId=' + encodeURIComponent(tenant));
        const j = await res.json();
        out.textContent = JSON.stringify(j, null, 2);
      } catch (e) {
        out.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
      }
    }

    async function uploadFeed(){
      const { backend, tenant } = getVals();
      if (!backend || !tenant) {
        feedOut.textContent = 'Please fill Backend URL and Tenant ID, then Save Settings.';
        return;
      }
      const txt = document.getElementById('feed-text').value.trim();
      const type = (document.querySelector('input[name="feedType"]:checked')?.value) || 'csv';
      if (!txt) {
        feedOut.textContent = 'Paste CSV or JSON feed first.';
        return;
      }
      feedOut.textContent = 'Uploading…';
      try {
        let payload = { tenantId: tenant };
        if (type === 'csv') {
          payload.csv = txt;
        } else {
          // Allow raw array or {items:[...]}
          let parsed = JSON.parse(txt);
          if (Array.isArray(parsed)) payload.items = parsed;
          else if (parsed && Array.isArray(parsed.items)) payload.items = parsed.items;
          else throw new Error('JSON must be an array or {items:[...]}');
        }
        const res = await fetch(backend.replace(/\/+$/,'') + '/products/upload', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const j = await res.json();
        feedOut.textContent = JSON.stringify(j, null, 2);
      } catch (e) {
        feedOut.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
      }
    }

    async function listProducts(){
      const { backend, tenant } = getVals();
      if (!backend || !tenant) {
        feedOut.textContent = 'Please fill Backend URL and Tenant ID, then Save Settings.';
        return;
      }
      feedOut.textContent = 'Fetching products…';
      try {
        const res = await fetch(backend.replace(/\/+$/,'') + '/products?tenantId=' + encodeURIComponent(tenant));
        const j = await res.json();
        feedOut.textContent = JSON.stringify(j, null, 2);
      } catch (e) {
        feedOut.textContent = 'Error: ' + (e && e.message ? e.message : String(e));
      }
    }

    document.getElementById('btn-index-site').addEventListener('click', (e)=>{ e.preventDefault(); doIndex(); });
    document.getElementById('btn-status-site').addEventListener('click', (e)=>{ e.preventDefault(); doStatus(); });
    document.getElementById('btn-upload-feed').addEventListener('click', (e)=>{ e.preventDefault(); uploadFeed(); });
    document.getElementById('btn-list-products').addEventListener('click', (e)=>{ e.preventDefault(); listProducts(); });
  })();
  </script>
  <?php
}
