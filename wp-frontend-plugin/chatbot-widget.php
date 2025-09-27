<?php
/**
 * Plugin Name: Chatbot Widget + Analytics + Site Indexing
 * Description: Floating chatbot widget grounded on your website via RAG, with admin analytics, settings, site indexing, Woo sync, nightly auto-sync, category/visibility/stock filters, and hash-based incremental uploads.
 * Version: 2.9.2
 * Author: YAA
 */

if (!defined('ABSPATH')) { exit; }

/* ----------------------------------------------------------------
   Helpers & Defaults
------------------------------------------------------------------*/
function nubedy_chatbot_get_option($key, $default = '') {
  $v = get_option($key, $default);
  return is_string($v) ? trim($v) : $v;
}

register_activation_hook(__FILE__, function () {
  add_option('chatbot_backend_url', 'https://chatbot-backend-9lxr.onrender.com');
  add_option('chatbot_tenant_id', 'client-123');
  add_option('chatbot_base_url', get_site_url());
  add_option('chatbot_max_pages', '120');
  add_option('chatbot_plan', 'ai');
  add_option('chatbot_bot_name', 'Chatbot');
  add_option('chatbot_color', '#0073aa');

  // Woo sync defaults
  add_option('chatbot_auto_sync', '0');                  // push single product on save
  add_option('chatbot_cron_enabled', '0');               // nightly full incremental sync
  add_option('chatbot_sync_categories', '');             // comma-separated slugs
  add_option('chatbot_sync_only_visible', '1');          // visible in catalog
  add_option('chatbot_sync_only_instock', '1');          // exclude out-of-stock

  // schedule nightly if enabled
  if (get_option('chatbot_cron_enabled', '0') === '1' && !wp_next_scheduled('chatbot_nightly_sync_event')) {
    wp_schedule_event( strtotime('tomorrow 02:15'), 'daily', 'chatbot_nightly_sync_event' );
  }
});

register_deactivation_hook(__FILE__, function(){
  // unschedule our events
  $ts = wp_next_scheduled('chatbot_nightly_sync_event');
  if ($ts) wp_unschedule_event($ts, 'chatbot_nightly_sync_event');
});

/* ----------------------------------------------------------------
   FRONTEND CHATBOT WIDGET (unchanged UI from your last version)
------------------------------------------------------------------*/
function chatbot_widget_inject() {
  $backend = nubedy_chatbot_get_option('chatbot_backend_url', 'https://chatbot-backend-9lxr.onrender.com');
  $tenant  = nubedy_chatbot_get_option('chatbot_tenant_id', 'client-123');
  $plan    = nubedy_chatbot_get_option('chatbot_plan', 'ai');
  $botname = nubedy_chatbot_get_option('chatbot_bot_name', 'Chatbot');
  $color   = nubedy_chatbot_get_option('chatbot_color', '#0073aa');

  $backend_js = esc_js($backend);
  $tenant_js  = esc_js($tenant);
  $plan_js    = esc_js($plan);
  $botname_html = esc_html($botname);
  $color_css = esc_attr($color);
  ?>
  <style>
    :root { --chat-accent: <?php echo $color_css; ?>; }
    #chatbot-launcher{position:fixed;bottom:20px;right:20px;width:56px;height:56px;border-radius:50%;background:var(--chat-accent);color:#fff;display:grid;place-items:center;font-weight:700;font-size:22px;box-shadow:0 8px 24px rgba(0,0,0,.18);cursor:pointer;z-index:100000;user-select:none;transition:transform .2s}
    #chatbot-launcher:active{transform:scale(.98)}
    #chatbot-launcher[data-unread]:after{content:attr(data-unread);position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#ff3b30;color:#fff;font-size:12px;line-height:18px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.2)}
    #chatbot-container{position:fixed;bottom:90px;right:20px;width:340px;height:460px;background:#fff;border:1px solid #dcdcdc;border-radius:14px;display:none;flex-direction:column;font-family:system-ui,Arial,sans-serif;z-index:10001;box-shadow:0 8px 24px rgba(0,0,0,.12);overflow:hidden}
    #chatbot-container[aria-hidden=true]{display:none!important}
    .chatbot-header{display:flex;align-items:center;justify-content:space-between;background:var(--chat-accent);color:#fff;padding:10px 12px;font-weight:600}
    .chatbot-title{display:flex;align-items:center;gap:8px}
    .chatbot-icon-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;margin-left:8px;background:transparent;border:none;color:#fff;cursor:pointer;border-radius:6px}
    .chatbot-icon-btn:hover{background:rgba(255,255,255,.15)}
    #chatbot-usage-bar{height:6px;background:#f0f0f0}
    #chatbot-usage-fill{height:100%;width:0;background:var(--chat-accent);transition:width .3s}
    #chatbot-messages{flex:1;overflow-y:auto;padding:12px;background:#f9fafb;font-size:14px}
    .chatbot-footer{display:flex;gap:6px;border-top:1px solid #eee;padding:8px;background:#fff}
    .chatbot-input{flex:1;padding:10px;border:1px solid #ddd;border-radius:10px;outline:none}
    .chatbot-btn{padding:10px 12px;border:none;background:var(--chat-accent);color:#fff;border-radius:10px;cursor:pointer}
    .chatbot-btn-secondary{padding:10px;border:1px solid #ddd;background:#fff;color:#333;border-radius:10px;cursor:pointer}
    .chatbot-hint{text-align:center;font-size:11px;color:#777;padding:6px 0;background:#fafafa}
    .chatbot-hint a{color:var(--chat-accent);text-decoration:underline}
    .bubble-wrap{margin:6px 0}
    .bubble{display:inline-block;max-width:85%;padding:8px 10px;border-radius:12px;white-space:pre-wrap;word-break:break-word}
    .bubble-user{background:#1f2937;color:#fff}
    .bubble-bot{background:#e5e7eb;color:#111}
    .svg-ico{width:24px;height:24px;display:block}
  </style>

  <script>
  const BACKEND="<?php echo $backend_js; ?>";
  const API_URL=BACKEND.replace(/\/+$/,'')+"/chat";
  const CLEAR_URL=BACKEND.replace(/\/+$/,'')+"/chat/clear";
  const TENANT_ID="<?php echo $tenant_js; ?>";
  const PLAN="<?php echo $plan_js; ?>";
  const STORAGE_KEY="chatbot_conversation_v1";
  const USER_ID_KEY="chatbot_user_id";
  const WIDGET_STATE_KEY="chatbot_widget_state";
  const ENTERPRISE_API_KEY=""; const AI_LIMIT_OVERRIDE="";
  let userId=localStorage.getItem(USER_ID_KEY);
  if(!userId){ userId=(crypto?.randomUUID?.()||String(Date.now())+Math.random().toString(16).slice(2)); localStorage.setItem(USER_ID_KEY,userId); }
  function loadHistory(){ try{return JSON.parse(localStorage.getItem(STORAGE_KEY)||"[]")}catch{return[]} }
  function saveHistory(a){ localStorage.setItem(STORAGE_KEY,JSON.stringify(a.slice(-200))) }
  function getWidgetState(){ return localStorage.getItem(WIDGET_STATE_KEY)||"min" }
  function setWidgetState(s){ localStorage.setItem(WIDGET_STATE_KEY,s) }
  async function sendMessage(text){
    const headers={"Content-Type":"application/json","x-user-id":userId,"x-plan":PLAN,"x-tenant-id":TENANT_ID};
    if(PLAN==="enterprise"&&ENTERPRISE_API_KEY) headers["x-api-key"]=ENTERPRISE_API_KEY;
    if(AI_LIMIT_OVERRIDE) headers["x-ai-limit"]=AI_LIMIT_OVERRIDE;
    const res=await fetch(API_URL,{method:"POST",headers,body:JSON.stringify({message:text})});
    if(!res.ok) throw new Error("HTTP "+res.status);
    return res.json();
  }
  async function clearChat(resetUsage=false){
    const res=await fetch(CLEAR_URL+(resetUsage?"?reset=usage":""),{method:"DELETE",headers:{"x-user-id":userId}});
    return res.json();
  }
  </script>

  <div id="chatbot-launcher" aria-expanded="false" aria-label="Open chat" title="Open chat" role="button" tabindex="0">
    <svg class="svg-ico" viewBox="0 0 24 24" aria-hidden="true"><path id="chatbot-launcher-path" d="M4 4h16v12H7l-3 3V4z" fill="currentColor"></path></svg>
  </div>

  <div id="chatbot-container" role="dialog" aria-label="<?php echo esc_attr($botname_html); ?>" aria-hidden="true">
    <div class="chatbot-header" id="chatbot-header">
      <div class="chatbot-title"><span id="chatbot-title"><?php echo $botname_html; ?></span></div>
      <button id="chatbot-minimize" class="chatbot-icon-btn" aria-label="Minimize chat" title="Minimize" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M5 12h14v2H5z"/></svg>
      </button>
    </div>
    <div id="chatbot-usage-bar"><div id="chatbot-usage-fill"></div></div>
    <div id="chatbot-messages"></div>
    <div class="chatbot-footer">
      <input id="chatbot-input" type="text" placeholder="Type a message..." class="chatbot-input" aria-label="Message input">
      <button id="chatbot-send" class="chatbot-btn" type="button">Send</button>
      <button id="chatbot-clear" title="Clear chat" class="chatbot-btn-secondary" type="button">Clear</button>
    </div>
    <div id="tiny-hint" class="chatbot-hint">Powered by <a href="https://www.nubedy.com/chat" target="_blank" rel="noopener">Nubedy</a></div>
  </div>

  <script>
  (function(){
    const launcher=document.getElementById("chatbot-launcher");
    const launcherPath=document.getElementById("chatbot-launcher-path");
    const container=document.getElementById("chatbot-container");
    const header=document.getElementById("chatbot-header");
    const minimizeBtn=document.getElementById("chatbot-minimize");
    const messagesDiv=document.getElementById("chatbot-messages");
    const input=document.getElementById("chatbot-input");
    const sendBtn=document.getElementById("chatbot-send");
    const clearBtn=document.getElementById("chatbot-clear");
    const usageFill=document.getElementById("chatbot-usage-fill");
    const hint=document.getElementById("tiny-hint");
    const PATH_OPEN="M4 4h16v12H7l-3 3V4z"; const PATH_MIN="M5 12h14v2H5z";

    function setLauncherIcon(open){ launcherPath.setAttribute("d",open?PATH_MIN:PATH_OPEN); launcher.setAttribute("aria-label",open?"Minimize chat":"Open chat"); launcher.setAttribute("title",open?"Minimize chat":"Open chat"); launcher.setAttribute("aria-expanded",String(open)); if(open) launcher.removeAttribute("data-unread"); }
    function bubble(sender,text){ const wrap=document.createElement("div"); wrap.className="bubble-wrap"; const msg=document.createElement("div"); msg.textContent=text; msg.className="bubble "+(sender==="You"?"bubble-user":"bubble-bot"); if(sender==="You") wrap.style.textAlign="right"; wrap.appendChild(msg); messagesDiv.appendChild(wrap); messagesDiv.scrollTop=messagesDiv.scrollHeight; }
    function setUsage(used,limit){ if(!limit||limit<=0){usageFill.style.width="0%";return;} const pct=Math.min(100,Math.round((used/limit)*100)); usageFill.style.width=pct+"%"; }
    function typing(on){ const id="typing-indicator"; let el=document.getElementById(id); if(on){ if(el) return; el=document.createElement("div"); el.id=id; el.className="bubble-wrap"; el.innerHTML='<div class="bubble bubble-bot">…</div>'; messagesDiv.appendChild(el); messagesDiv.scrollTop=messagesDiv.scrollHeight; } else if(el){ el.remove(); } }
    function showWidget(){ container.style.display="flex"; container.setAttribute("aria-hidden","false"); setLauncherIcon(true); localStorage.setItem("chatbot_widget_state","max"); setTimeout(()=>input?.focus(),50); }
    function hideWidget(){ container.style.display="none"; container.setAttribute("aria-hidden","true"); setLauncherIcon(false); localStorage.setItem("chatbot_widget_state","min"); }
    function toggleWidget(){ const open=container.getAttribute("aria-hidden")==="false"; if(open) hideWidget(); else showWidget(); }

    if((localStorage.getItem("chatbot_widget_state")||"min")==="max") showWidget(); else hideWidget();
    header.addEventListener("dblclick",toggleWidget);
    minimizeBtn?.addEventListener("click",(e)=>{e.stopPropagation();hideWidget();});
    launcher.addEventListener("click",toggleWidget);
    launcher.addEventListener("keydown",(e)=>{if(e.key==="Enter"||e.key===" "){e.preventDefault();toggleWidget();}});

    const history=(function(){try{return JSON.parse(localStorage.getItem("chatbot_conversation_v1")||"[]")}catch{return[]}})();
    if(history.length){ history.forEach(h=>bubble(h.sender,h.text)); }

    async function handleSend(){
      const text=input.value.trim(); if(!text) return;
      bubble("You",text); const hist=(function(){try{return JSON.parse(localStorage.getItem("chatbot_conversation_v1")||"[]")}catch{return[]}})(); hist.push({sender:"You",text}); localStorage.setItem("chatbot_conversation_v1",JSON.stringify(hist.slice(-200)));
      input.value=""; input.focus(); typing(true);
      try{
        const data=await sendMessage(text); typing(false);
        const replyText=String(data.reply??"");
        if(container.getAttribute("aria-hidden")==="true"){ const current=Number(launcher.getAttribute("data-unread")||0)+1; launcher.setAttribute("data-unread",String(current)); }
        bubble("Bot",replyText);
        const h2=(function(){try{return JSON.parse(localStorage.getItem("chatbot_conversation_v1")||"[]")}catch{return[]}})(); h2.push({sender:"Bot",text:replyText}); localStorage.setItem("chatbot_conversation_v1",JSON.stringify(h2.slice(-200)));
        setUsage(data.usage,data.limit);
        if(data.limit && data.usage>=data.limit){ input.disabled=true; sendBtn.disabled=true; hint.innerHTML='Limit reached. Powered by <a href="https://www.nubedy.com/chat" target="_blank" rel="noopener">Nubedy</a>'; }
      }catch(e){ typing(false); bubble("Bot","⚠️ Error connecting to server."); const h3=(function(){try{return JSON.parse(localStorage.getItem("chatbot_conversation_v1")||"[]")}catch{return[]}})(); h3.push({sender:"Bot",text:"⚠️ Error connecting to server."}); localStorage.setItem("chatbot_conversation_v1",JSON.stringify(h3.slice(-200))); }
    }
    document.getElementById("chatbot-send").addEventListener("click",handleSend);
    document.getElementById("chatbot-input").addEventListener("keypress",(e)=>{ if(e.key==="Enter") handleSend(); });
    document.getElementById("chatbot-clear").addEventListener("click", async ()=>{ try{ await clearChat(false);}catch{} localStorage.removeItem("chatbot_conversation_v1"); messagesDiv.innerHTML=""; document.getElementById("chatbot-input").disabled=false; document.getElementById("chatbot-send").disabled=false; setUsage(0,0); bubble("Bot","Chat cleared. How can I help?"); });
    window.__chatbot_pushMessage=function(text){ if(container.getAttribute("aria-hidden")==="true"){ const current=Number(launcher.getAttribute("data-unread")||0)+1; launcher.setAttribute("data-unread",String(current)); } bubble("Bot",text); };
  })();
  </script>
  <?php
}
add_action("wp_footer", "chatbot_widget_inject");

/* ----------------------------------------------------------------
   ADMIN: MENU (Analytics + Settings/Indexing)
------------------------------------------------------------------*/
add_action('admin_menu', function() {
  add_menu_page('Chatbot Analytics', 'Chatbot', 'manage_options', 'chatbot-analytics', 'chatbot_analytics_page', 'dashicons-format-chat', 26);
  add_submenu_page('chatbot-analytics', 'Chatbot Analytics', 'Analytics', 'manage_options', 'chatbot-analytics', 'chatbot_analytics_page');
  add_submenu_page('chatbot-analytics', 'Chatbot Settings & Indexing', 'Settings & Indexing', 'manage_options', 'chatbot-settings', 'chatbot_settings_page');
});

/* ----------------------------------------------------------------
   ADMIN: SETTINGS REGISTER
------------------------------------------------------------------*/
add_action('admin_init', function () {
  register_setting('chatbot_settings', 'chatbot_backend_url', ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
  register_setting('chatbot_settings', 'chatbot_tenant_id',  ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_base_url',   ['type'=>'string','sanitize_callback'=>'esc_url_raw']);
  register_setting('chatbot_settings', 'chatbot_max_pages',  ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_plan',       ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_bot_name',   ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_color',      ['type'=>'string','sanitize_callback'=>'sanitize_hex_color']);

  // Woo sync & filters
  register_setting('chatbot_settings', 'chatbot_auto_sync',          ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_cron_enabled',       ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_sync_categories',    ['type'=>'string','sanitize_callback'=>'sanitize_text_field']); // comma slugs
  register_setting('chatbot_settings', 'chatbot_sync_only_visible',  ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
  register_setting('chatbot_settings', 'chatbot_sync_only_instock',  ['type'=>'string','sanitize_callback'=>'sanitize_text_field']);
});

/* ----------------------------------------------------------------
   ADMIN: ANALYTICS PAGE (same as before)
------------------------------------------------------------------*/
function chatbot_analytics_page() {
  $backend = nubedy_chatbot_get_option('chatbot_backend_url', 'https://chatbot-backend-9lxr.onrender.com');
  $analytics_url = esc_js(rtrim($backend, '/').'/analytics');
  ?>
  <div class="wrap">
    <h1>Chatbot Analytics</h1>
    <div id="chatbot-status" style="margin:14px 0;padding:12px;border:1px solid #e3e3e3;border-radius:8px;background:#fff;">
      <strong>Bot Status</strong>
      <div id="chatbot-status-body" style="margin-top:6px;color:#444;font-size:13px;">Loading…</div>
    </div>
    <h2 style="margin-top:10px;">Usage by User (this month)</h2>
    <p style="margin:6px 0 10px;color:#555;">Latest recorded stats per user. <em>Plan</em> and <em>Limit</em> come from the most recent log.</p>
    <table id="chatbot-usage-table" class="widefat fixed" style="width:100%;margin-bottom:24px;">
      <thead><tr><th>User ID</th><th>Plan</th><th>AI Calls</th><th>Limit</th><th>Remaining</th><th>Last Seen</th></tr></thead>
      <tbody></tbody>
    </table>
    <h2 style="margin-top:10px;">Event Logs</h2>
    <table id="chatbot-analytics-table" class="widefat fixed" style="width:100%">
      <thead><tr><th>Timestamp</th><th>User ID</th><th>Message</th><th>Bot Reply</th><th>Plan</th><th>AI Calls</th><th>Limit</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>
  <script>
  const ANALYTICS_URL='<?php echo $analytics_url; ?>';
  function _escape(s){return (s||"").toString().replace(/</g,"&lt;");}
  function _num(n){const x=Number(n);return Number.isFinite(x)?x:null;}
  async function loadAnalytics(){
    try{
      const res=await fetch(ANALYTICS_URL); if(!res.ok) throw new Error(res.status);
      const logs=await res.json();
      const tbody=document.querySelector('#chatbot-analytics-table tbody'); tbody.innerHTML='';
      logs.slice(-1000).forEach(log=>{
        const lim=log.limit??log.aiLimit??'';
        const tr=document.createElement('tr');
        tr.innerHTML=`<td>${_escape(log.timestamp)}</td><td>${_escape(log.userId)}</td><td>${_escape(log.message)}</td><td>${_escape(log.response)}</td><td>${_escape(log.plan)}</td><td>${_escape(log.aiCalls)}</td><td>${_escape(lim)}</td>`;
        tbody.appendChild(tr);
      });
      const perUser={};
      for(const l of logs){
        const u=l.userId||'unknown'; const ai=_num(l.aiCalls)??0; const lim=_num(l.limit??l.aiLimit); const ts=l.timestamp||'';
        if(!perUser[u]) perUser[u]={aiCalls:ai,plan:l.plan||'-',last:ts,limit:lim};
        else { if(ai>perUser[u].aiCalls) perUser[u].aiCalls=ai; if(ts && ts>perUser[u].last){ perUser[u].last=ts; perUser[u].plan=l.plan||perUser[u].plan; perUser[u].limit=lim??perUser[u].limit; } }
      }
      const usageBody=document.querySelector('#chatbot-usage-table tbody'); usageBody.innerHTML='';
      const entries=Object.entries(perUser).sort((a,b)=>b[1].aiCalls-a[1].aiCalls);
      entries.forEach(([uid,info])=>{
        const remaining=(Number.isFinite(info.limit)?Math.max(0,info.limit-info.aiCalls):'');
        const tr=document.createElement('tr');
        tr.innerHTML=`<td>${_escape(uid)}</td><td>${_escape(info.plan)}</td><td>${_escape(info.aiCalls)}</td><td>${_escape(Number.isFinite(info.limit)?info.limit:'-')}</td><td>${_escape(Number.isFinite(remaining)?remaining:'-')}</td><td>${_escape(info.last)}</td>`;
        usageBody.appendChild(tr);
      });
      const totals=entries.reduce((a,[,i])=>{a.users++; a.calls+=(Number(i.aiCalls)||0); if(Number.isFinite(i.limit)) a.limits.push(i.limit); return a;},{users:0,calls:0,limits:[]});
      const statusEl=document.getElementById('chatbot-status-body');
      const avg=totals.limits.length?Math.round(totals.limits.reduce((x,y)=>x+y,0)/totals.limits.length):null;
      statusEl.innerHTML=`<div>Total users this month: <strong>${totals.users}</strong></div><div>Total AI calls: <strong>${totals.calls}</strong></div><div>Average user limit: <strong>${avg??'-'}</strong></div><div style="margin-top:6px;color:#666;">Tip: Plan and Limit come from the latest event per user.</div>`;
    }catch(e){ document.getElementById('chatbot-status-body').textContent='Failed to load analytics. Check Backend URL.'; }
  }
  loadAnalytics(); setInterval(loadAnalytics,10000);
  </script>
  <?php
}

/* ----------------------------------------------------------------
   ADMIN: SETTINGS & INDEX PAGE (+ Woo sync UI & filters)
------------------------------------------------------------------*/
function chatbot_settings_page() {
  $backend = nubedy_chatbot_get_option('chatbot_backend_url', 'https://chatbot-backend-9lxr.onrender.com');
  $tenant  = nubedy_chatbot_get_option('chatbot_tenant_id', 'client-123');
  $baseurl = nubedy_chatbot_get_option('chatbot_base_url', get_site_url());
  $maxpg   = nubedy_chatbot_get_option('chatbot_max_pages', '120');
  $plan    = nubedy_chatbot_get_option('chatbot_plan', 'ai');

  $botname = nubedy_chatbot_get_option('chatbot_bot_name', 'Chatbot');
  $color   = nubedy_chatbot_get_option('chatbot_color', '#0073aa');

  $autoSync   = nubedy_chatbot_get_option('chatbot_auto_sync', '0');
  $cronEnabled= nubedy_chatbot_get_option('chatbot_cron_enabled', '0');
  $cats       = nubedy_chatbot_get_option('chatbot_sync_categories', '');
  $onlyVis    = nubedy_chatbot_get_option('chatbot_sync_only_visible', '1');
  $onlyStock  = nubedy_chatbot_get_option('chatbot_sync_only_instock', '1');

  $backend_esc = esc_attr($backend);
  $tenant_esc  = esc_attr($tenant);
  $baseurl_esc = esc_attr($baseurl);
  $maxpg_esc   = esc_attr($maxpg);
  $botname_esc = esc_attr($botname);
  $color_esc   = esc_attr($color);
  $cats_esc    = esc_attr($cats);
  ?>
  <div class="wrap">
    <h1>Chatbot Settings, Site Indexing & WooCommerce Sync</h1>

    <form method="post" action="options.php" style="margin-top:12px;">
      <?php settings_fields('chatbot_settings'); ?>
      <table class="form-table" role="presentation">
        <tr><th><label for="chatbot_backend_url">Backend URL</label></th><td><input name="chatbot_backend_url" id="chatbot_backend_url" type="url" class="regular-text" value="<?php echo $backend_esc; ?>"></td></tr>
        <tr><th><label for="chatbot_tenant_id">Tenant ID</label></th><td><input name="chatbot_tenant_id" id="chatbot_tenant_id" type="text" class="regular-text" value="<?php echo $tenant_esc; ?>"></td></tr>
        <tr><th><label for="chatbot_plan">Default Plan</label></th><td>
          <select name="chatbot_plan" id="chatbot_plan">
            <option value="rule" <?php selected($plan,'rule'); ?>>Basic (FAQ only)</option>
            <option value="ai" <?php selected($plan,'ai'); ?>>Pro (AI included)</option>
            <option value="enterprise" <?php selected($plan,'enterprise'); ?>>Enterprise</option>
          </select>
        </td></tr>

        <tr><th colspan="2"><hr></th></tr>

        <tr><th><label for="chatbot_bot_name">Bot Name</label></th><td><input name="chatbot_bot_name" id="chatbot_bot_name" type="text" class="regular-text" value="<?php echo $botname_esc; ?>"></td></tr>
        <tr><th><label for="chatbot_color">Accent Color</label></th><td><input name="chatbot_color" id="chatbot_color" type="color" value="<?php echo $color_esc; ?>"></td></tr>

        <tr><th colspan="2"><hr></th></tr>

        <tr><th><label for="chatbot_base_url">Client Base URL</label></th><td><input name="chatbot_base_url" id="chatbot_base_url" type="url" class="regular-text" value="<?php echo $baseurl_esc; ?>"></td></tr>
        <tr><th><label for="chatbot_max_pages">Max Pages to Index</label></th><td><input name="chatbot_max_pages" id="chatbot_max_pages" type="number" class="small-text" value="<?php echo $maxpg_esc; ?>" min="1" max="500"> <span class="description">Defaults to 120</span></td></tr>

        <tr><th colspan="2"><hr></th></tr>

        <tr><th><label for="chatbot_sync_categories">Sync Categories (slugs, comma-separated)</label></th><td><input name="chatbot_sync_categories" id="chatbot_sync_categories" type="text" class="regular-text" value="<?php echo $cats_esc; ?>" placeholder="rings,necklaces"></td></tr>
        <tr><th>Filters</th><td>
          <label><input type="checkbox" name="chatbot_sync_only_visible" value="1" <?php checked($onlyVis,'1'); ?>> Only products visible in catalog/search</label><br>
          <label><input type="checkbox" name="chatbot_sync_only_instock" value="1" <?php checked($onlyStock,'1'); ?>> Only in-stock products</label>
        </td></tr>
        <tr><th><label for="chatbot_auto_sync">On Save</label></th><td><label><input type="checkbox" name="chatbot_auto_sync" id="chatbot_auto_sync" value="1" <?php checked($autoSync,'1'); ?>> Auto-sync a product when it’s created/updated</label></td></tr>
        <tr><th><label for="chatbot_cron_enabled">Nightly Auto-sync</label></th><td><label><input type="checkbox" name="chatbot_cron_enabled" id="chatbot_cron_enabled" value="1" <?php checked($cronEnabled,'1'); ?>> Run an incremental sync nightly (~02:15)</label></td></tr>
      </table>
      <?php submit_button('Save Settings'); ?>
    </form>

    <hr>

    <h2>Index Site (RAG)</h2>
    <div style="display:flex;gap:8px;margin:10px 0;">
      <button class="button button-primary" id="btn-index-site">Index Now</button>
      <button class="button" id="btn-status-site">Check Status</button>
    </div>
    <pre id="index-output" style="white-space:pre-wrap;background:#fff;border:1px solid #e2e2e2;border-radius:6px;padding:10px;max-height:260px;overflow:auto;">Ready.</pre>

    <hr>

    <h2>WooCommerce ➜ Chatbot Products</h2>
    <p>Exports with your filters (categories/visibility/stock) and uploads only changed items.</p>
    <div style="display:flex;gap:8px;margin:10px 0;">
      <button class="button button-primary" id="btn-sync-woo">Sync Woo ➜ Backend</button>
      <button class="button" id="btn-preview-woo">Preview (first 10)</button>
    </div>
    <pre id="woo-output" style="white-space:pre-wrap;background:#fff;border:1px solid #e2e2e2;border-radius:6px;padding:10px;max-height:260px;overflow:auto;">Waiting…</pre>

    <hr>

    <h2>Manual Product Feed (Optional)</h2>
    <p>Paste CSV or JSON and upload to backend.</p>
    <p><label><input type="radio" name="feedType" value="csv" checked> CSV</label> &nbsp; <label><input type="radio" name="feedType" value="json"> JSON</label></p>
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
    const out=document.getElementById('index-output');
    const feedOut=document.getElementById('feed-output');
    const wooOut=document.getElementById('woo-output');

    async function doIndex(){
      const { backend, tenant, baseUrl, maxPages }=getVals();
      if(!backend||!tenant||!baseUrl){ out.textContent='Please fill Backend URL, Tenant ID and Base URL, then Save Settings.'; return; }
      out.textContent='Indexing started…';
      try{
        const res=await fetch(backend.replace(/\/+$/,'')+'/site/index',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tenantId:tenant,baseUrl,maxPages})});
        const j=await res.json(); out.textContent=JSON.stringify(j,null,2);
      }catch(e){ out.textContent='Error: '+(e&&e.message?e.message:String(e)); }
    }
    async function doStatus(){
      const { backend, tenant }=getVals();
      if(!backend||!tenant){ out.textContent='Please fill Backend URL and Tenant ID, then Save Settings.'; return; }
      out.textContent='Checking status…';
      try{
        const res=await fetch(backend.replace(/\/+$/,'')+'/site/status?tenantId='+encodeURIComponent(tenant));
        const j=await res.json(); out.textContent=JSON.stringify(j,null,2);
      }catch(e){ out.textContent='Error: '+(e&&e.message?e.message:String(e)); }
    }

    async function uploadFeed(){
      const { backend, tenant }=getVals();
      if(!backend||!tenant){ feedOut.textContent='Please fill Backend URL and Tenant ID, then Save Settings.'; return; }
      const txt=document.getElementById('feed-text').value.trim();
      const type=(document.querySelector('input[name="feedType"]:checked')?.value)||'csv';
      if(!txt){ feedOut.textContent='Paste CSV or JSON first.'; return; }
      feedOut.textContent='Uploading…';
      try{
        let payload={tenantId:tenant};
        if(type==='csv'){ payload.csv=txt; }
        else{ let parsed=JSON.parse(txt); if(Array.isArray(parsed)) payload.items=parsed; else if(parsed&&Array.isArray(parsed.items)) payload.items=parsed.items; else throw new Error('JSON must be an array or {items:[...]}'); }
        const res=await fetch(backend.replace(/\/+$/,'')+'/products/upload',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
        const j=await res.json(); feedOut.textContent=JSON.stringify(j,null,2);
      }catch(e){ feedOut.textContent='Error: '+(e&&e.message?e.message:String(e)); }
    }
    async function listProducts(){
      const { backend, tenant }=getVals();
      if(!backend||!tenant){ feedOut.textContent='Please fill Backend URL and Tenant ID, then Save Settings.'; return; }
      feedOut.textContent='Fetching products…';
      try{
        const res=await fetch(backend.replace(/\/+$/,'')+'/products?tenantId='+encodeURIComponent(tenant));
        const j=await res.json(); feedOut.textContent=JSON.stringify(j,null,2);
      }catch(e){ feedOut.textContent='Error: '+(e&&e.message?e.message:String(e)); }
    }

    async function previewWoo(){
      wooOut.textContent='Fetching preview…';
      try{
        const res=await fetch('<?php echo esc_url_raw( get_rest_url(null, "chatbot/v1/products") ); ?>?limit=10',{credentials:'same-origin'});
        const j=await res.json(); wooOut.textContent=JSON.stringify(j.items||[],null,2);
      }catch(e){ wooOut.textContent='Error: '+(e&&e.message?e.message:String(e)); }
    }
    async function syncWoo(){
      const { backend, tenant }=getVals();
      if(!backend||!tenant){ wooOut.textContent='Please fill Backend URL and Tenant ID, then Save Settings.'; return; }
      wooOut.textContent='Exporting Woo products…';
      try{
        const res=await fetch('<?php echo esc_url_raw( get_rest_url(null, "chatbot/v1/products") ); ?>',{credentials:'same-origin'});
        const j=await res.json(); const items=Array.isArray(j.items)?j.items:[];
        wooOut.textContent=`Prepared ${items.length} changed products. Uploading…`;
        const up=await fetch(backend.replace(/\/+$/,'')+'/products/upload',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({tenantId:tenant,items})});
        const uj=await up.json(); wooOut.textContent=JSON.stringify(uj,null,2);
      }catch(e){ wooOut.textContent='Error: '+(e&&e.message?e.message:String(e)); }
    }

    document.getElementById('btn-index-site').addEventListener('click', (e)=>{e.preventDefault();doIndex();});
    document.getElementById('btn-status-site').addEventListener('click', (e)=>{e.preventDefault();doStatus();});
    document.getElementById('btn-upload-feed').addEventListener('click', (e)=>{e.preventDefault();uploadFeed();});
    document.getElementById('btn-list-products').addEventListener('click', (e)=>{e.preventDefault();listProducts();});
    document.getElementById('btn-preview-woo').addEventListener('click', (e)=>{e.preventDefault();previewWoo();});
    document.getElementById('btn-sync-woo').addEventListener('click', (e)=>{e.preventDefault();syncWoo();});
  })();
  </script>
  <?php
}

/* ----------------------------------------------------------------
   REST: Export WooCommerce products with filters (+ hashing)
------------------------------------------------------------------*/
add_action('rest_api_init', function () {
  register_rest_route('chatbot/v1', '/products', [
    'methods'  => 'GET',
    'permission_callback' => function () { return current_user_can('manage_options'); },
    'callback' => function (\WP_REST_Request $req) {
      if (!class_exists('WooCommerce')) return new WP_REST_Response(['items'=>[], 'error'=>'WooCommerce not active'], 200);

      $limit = max(0, intval($req->get_param('limit') ?: 0));
      $catStr = $req->get_param('categories') ?: get_option('chatbot_sync_categories', '');
      $cats = array_filter(array_map('trim', explode(',', strtolower($catStr))));
      $onlyVisible = ($req->get_param('only_visible') ?? get_option('chatbot_sync_only_visible','1')) === '1';
      $onlyStock   = ($req->get_param('only_instock') ?? get_option('chatbot_sync_only_instock','1')) === '1';

      $args = [
        'status'    => 'publish',
        'paginate'  => true,
        'limit'     => $limit > 0 ? $limit : 100,
        'return'    => 'ids',
        'type'      => ['simple','variable','grouped','external']
      ];
      if (!empty($cats)) $args['category'] = $cats; // slugs
      if ($onlyVisible) $args['catalog_visibility'] = 'visible';
      if ($onlyStock)   $args['stock_status'] = 'instock';

      $paged=1; $collected=[];
      do {
        $args['page'] = $paged;
        $products = wc_get_products($args);
        foreach ($products->products as $pid) {
          $item = chatbot_build_item_from_product($pid, $onlyVisible, $onlyStock);
          if (!$item) continue;

          // compute product hash; compare with meta to send only changed
          $hash = chatbot_hash_item($item);
          $prev = get_post_meta($pid, '_chatbot_sync_hash', true);
          if ($hash !== $prev) {
            $item['_hash'] = $hash;
            $item['_product_id'] = $pid;
            $collected[] = $item;
          }
          if ($limit > 0 && count($collected) >= $limit) break 2;
        }
        $paged++;
      } while ($products->max_num_pages && $paged <= $products->max_num_pages && ($limit === 0));

      return new WP_REST_Response(['items' => array_values($collected)], 200);
    }
  ]);
});

/** Build a product in our schema; enforce visibility/stock if requested */
function chatbot_build_item_from_product($product_id, $onlyVisible=true, $onlyStock=true) {
  $p = wc_get_product($product_id);
  if (!$p) return null;

  // extra guards (in case REST args missed something)
  if ($onlyVisible) {
    $vis = $p->get_catalog_visibility(); // visible|catalog|search|hidden
    if ($vis === 'hidden') return null;
  }
  if ($onlyStock) {
    if (!$p->is_in_stock()) return null;
  }
  if ($p->get_status() !== 'publish') return null;

  $name = html_entity_decode( wp_strip_all_tags( $p->get_name() ), ENT_QUOTES );
  $desc = $p->get_short_description(); if (empty($desc)) $desc = $p->get_description();
  $desc = trim( wp_strip_all_tags($desc) );
  $url  = get_permalink($product_id);
  $price = $p->get_price();
  $currency = get_woocommerce_currency();

  $image_id = $p->get_image_id(); if (!$image_id) { $g = $p->get_gallery_image_ids(); if (!empty($g)) $image_id = $g[0]; }
  $image = $image_id ? wp_get_attachment_url($image_id) : '';

  $sku = $p->get_sku();
  $brand = '';
  if ($p->get_attribute('pa_brand')) $brand = $p->get_attribute('pa_brand');
  else {
    $terms = get_the_terms($product_id, 'product_brand');
    if (!is_wp_error($terms) && !empty($terms)) $brand = $terms[0]->name;
  }
  $brand = is_string($brand) ? trim(wp_strip_all_tags($brand)) : '';

  return [
    'name' => $name,
    'description' => $desc,
    'url' => $url,
    'price' => $price,
    'currency' => $currency,
    'image' => $image,
    'sku' => $sku,
    'brand' => $brand,
  ];
}

/** Stable hash for change detection */
function chatbot_hash_item($item) {
  $payload = wp_json_encode([
    'name' => $item['name'] ?? '',
    'description' => $item['description'] ?? '',
    'url' => $item['url'] ?? '',
    'price' => (string)($item['price'] ?? ''),
    'currency' => $item['currency'] ?? '',
    'image' => $item['image'] ?? '',
    'sku' => $item['sku'] ?? '',
    'brand' => $item['brand'] ?? '',
  ]);
  return md5($payload);
}

/* ----------------------------------------------------------------
   AUTO-SYNC ON PRODUCT SAVE (single item, debounced) + hash update
------------------------------------------------------------------*/
add_action('save_post_product', function($post_id, $post, $update){
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
  if (!current_user_can('edit_post', $post_id)) return;
  if (wp_is_post_revision($post_id)) return;
  if (get_option('chatbot_auto_sync','0') !== '1') return;

  if (!wp_next_scheduled('chatbot_sync_single_product_event', [$post_id])) {
    wp_schedule_single_event(time()+30, 'chatbot_sync_single_product_event', [$post_id]);
  }
}, 10, 3);

add_action('chatbot_sync_single_product_event', function($product_id){
  $backend = rtrim(nubedy_chatbot_get_option('chatbot_backend_url', ''), '/');
  $tenant  = nubedy_chatbot_get_option('chatbot_tenant_id', '');
  if (!$backend || !$tenant) return;

  $onlyVis   = get_option('chatbot_sync_only_visible','1') === '1';
  $onlyStock = get_option('chatbot_sync_only_instock','1') === '1';

  $item = chatbot_build_item_from_product($product_id, $onlyVis, $onlyStock);
  if (!$item) return;

  $hash = chatbot_hash_item($item);
  $prev = get_post_meta($product_id, '_chatbot_sync_hash', true);
  if ($hash === $prev) return; // unchanged

  $resp = wp_remote_post($backend . '/products/upload', [
    'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
    'body'    => wp_json_encode(['tenantId' => $tenant, 'items' => [$item]]),
    'timeout' => 20
  ]);
  if (!is_wp_error($resp)) {
    update_post_meta($product_id, '_chatbot_sync_hash', $hash);
  }
}, 10, 1);

/* ----------------------------------------------------------------
   NIGHTLY INCREMENTAL SYNC (respects filters & hashes)
------------------------------------------------------------------*/
add_action('update_option_chatbot_cron_enabled', function($old, $new){
  $scheduled = wp_next_scheduled('chatbot_nightly_sync_event');
  if ($new === '1' && !$scheduled) {
    wp_schedule_event( strtotime('tomorrow 02:15'), 'daily', 'chatbot_nightly_sync_event' );
  } elseif ($new !== '1' && $scheduled) {
    wp_unschedule_event($scheduled, 'chatbot_nightly_sync_event');
  }
}, 10, 2);

add_action('chatbot_nightly_sync_event', function(){
  $backend = rtrim(nubedy_chatbot_get_option('chatbot_backend_url', ''), '/');
  $tenant  = nubedy_chatbot_get_option('chatbot_tenant_id', '');
  if (!$backend || !$tenant) return;
  if (!class_exists('WooCommerce')) return;

  $catStr = get_option('chatbot_sync_categories','');
  $cats = array_filter(array_map('trim', explode(',', strtolower($catStr))));
  $onlyVisible = get_option('chatbot_sync_only_visible','1') === '1';
  $onlyStock   = get_option('chatbot_sync_only_instock','1') === '1';

  $args = [
    'status'   => 'publish',
    'paginate' => true,
    'limit'    => 200,
    'return'   => 'ids',
    'type'     => ['simple','variable','grouped','external']
  ];
  if (!empty($cats)) $args['category'] = $cats;
  if ($onlyVisible) $args['catalog_visibility'] = 'visible';
  if ($onlyStock)   $args['stock_status'] = 'instock';

  $paged=1; $batch=[]; $batchSize=200;

  do {
    $args['page']=$paged;
    $products=wc_get_products($args);
    foreach ($products->products as $pid) {
      $item = chatbot_build_item_from_product($pid, $onlyVisible, $onlyStock);
      if (!$item) continue;
      $hash = chatbot_hash_item($item);
      $prev = get_post_meta($pid, '_chatbot_sync_hash', true);
      if ($hash === $prev) continue; // unchanged

      $item['_product_id'] = $pid;
      $item['_hash'] = $hash;
      $batch[] = $item;

      if (count($batch) >= $batchSize) {
        $ok = chatbot_upload_items($backend, $tenant, $batch);
        if ($ok) { foreach ($batch as $it) update_post_meta($it['_product_id'], '_chatbot_sync_hash', $it['_hash']); }
        $batch = [];
        sleep(1); // be nice
      }
    }
    $paged++;
  } while ($products->max_num_pages && $paged <= $products->max_num_pages);

  if (!empty($batch)) {
    $ok = chatbot_upload_items($backend, $tenant, $batch);
    if ($ok) { foreach ($batch as $it) update_post_meta($it['_product_id'], '_chatbot_sync_hash', $it['_hash']); }
  }
});

/** Upload helper (batch to backend) */
function chatbot_upload_items($backend, $tenant, $items) {
  $payload = ['tenantId' => $tenant, 'items' => array_values(array_map(function($i){
    // strip internal fields
    unset($i['_product_id'], $i['_hash']); return $i;
  }, $items))];

  $resp = wp_remote_post( rtrim($backend,'/') . '/products/upload', [
    'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
    'body'    => wp_json_encode($payload),
    'timeout' => 30
  ]);
  return !is_wp_error($resp);
}
