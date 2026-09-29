<?php
declare(strict_types=1);
require_once __DIR__ . '/../../_shared/rmi_icons.php';

if (!function_exists('chat_h')) {
    function chat_h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}
?>
<?php rmi_header('Internal Chat', [
    'active' => 'chat',
    'subtitle' => 'Enterprise internal messaging',
    'breadcrumbs' => [
        ['label' => 'Dashboard', 'url' => $base . '/dashboards/index.php'],
        'Chat',
    ],
]); ?>

<?php if (!empty($chatFlashError)): ?>
<div class="alert alert-danger mx-2 mt-2 mb-0" role="alert"><?= chat_h((string)$chatFlashError) ?></div>
<?php endif; ?>

<?php if (!empty($chatContextOffer) && is_array($chatContextOffer)): ?>
<div class="alert alert-info mx-2 mt-2 mb-0 d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
  <div>
    Channel chat untuk dokumen <strong><?= chat_h((string)($chatContextOffer['label'] ?? '')) ?></strong> belum ada.
    Klik tombol untuk membuat channel konteks dan membuka percakapan (aksi ini tercatat di sistem).
  </div>
  <form method="post" action="<?= chat_h($base . '/chat/index.php') ?>" class="d-flex flex-wrap gap-2 align-items-center mb-0">
    <input type="hidden" name="csrf_token" value="<?= chat_h($csrf) ?>">
    <input type="hidden" name="chat_open_context" value="1">
    <input type="hidden" name="entity_type" value="<?= chat_h((string)($chatContextOffer['entity_type'] ?? '')) ?>">
    <input type="hidden" name="entity_id" value="<?= (int)($chatContextOffer['entity_id'] ?? 0) ?>">
    <button type="submit" class="btn btn-sm btn-primary">Buka chat</button>
    <a class="btn btn-sm btn-outline-secondary" href="<?= chat_h($base . '/chat/index.php') ?>">Batal</a>
  </form>
</div>
<?php endif; ?>

<div class="chat-page-header">
  <div class="d-flex align-items-center gap-2">
    <button class="btn btn-sm btn-outline-light chat-sidebar-toggle" id="chatSidebarToggle">☰</button>
    <div class="fw-semibold">Chat</div>
  </div>
  <div class="d-flex align-items-center gap-2">
    <input class="form-control form-control-sm" id="chatGlobalSearch" placeholder="Search user / channel">
    <button class="btn btn-sm btn-outline-light" id="chatMarkAllReadBtn" title="Mark all channels as read">Mark all read</button>
    <button class="btn btn-sm btn-primary" id="chatNewDmBtn">New DM</button>
    <?php if ($canDelete): ?>
      <a class="btn btn-sm btn-outline-light" href="<?= chat_h($base . '/chat/admin/channels.php') ?>">Admin Channels</a>
      <a class="btn btn-sm btn-outline-light" href="<?= chat_h($base . '/chat/admin/audit.php') ?>">Admin Audit</a>
      <a class="btn btn-sm btn-outline-light" href="<?= chat_h($base . '/chat/admin_exports.php') ?>">Admin Exports</a>
      <a class="btn btn-sm btn-outline-light" href="<?= chat_h($base . '/chat/admin_settings.php') ?>">Admin Settings</a>
    <?php endif; ?>
  </div>
</div>

<div class="chat-page" id="chatPage">
  <aside class="chat-sidebar" id="chatSidebar">
    <div class="chat-sidebar-section">
      <div class="chat-sidebar-section-title">Channels</div>
      <div class="chat-sidebar-group" id="chatChannelList"></div>
    </div>
    <div class="chat-sidebar-section">
      <div class="chat-sidebar-section-title">Direct Messages</div>
      <div class="chat-sidebar-group" id="chatDmList"></div>
    </div>
    <div class="d-flex gap-2">
      <input class="form-control form-control-sm" id="chatJoinId" placeholder="Join channel by ID">
      <button class="btn btn-sm btn-outline-light" id="chatJoinBtn">Join</button>
    </div>
    <?php if ($canDelete): ?>
    <div class="d-flex gap-2">
      <input class="form-control form-control-sm" id="chatNewChannel" placeholder="create-channel">
      <button class="btn btn-sm btn-warning" id="chatCreateChannelBtn">Create</button>
    </div>
    <?php endif; ?>
  </aside>

  <section class="chat-main">
    <div class="chat-topbar">
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm btn-outline-light chat-back-btn" id="chatBackBtn">← Back</button>
        <div>
          <div class="fw-semibold" id="chatTitle">Select conversation</div>
          <div class="small text-secondary" id="chatSub">Choose a channel or DM</div>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-light" id="chatMuteBtn" disabled>Mute</button>
        <?php if ($canDelete): ?><button class="btn btn-sm btn-outline-light" id="chatExportBtn" disabled>Export</button><?php endif; ?>
        <?php if ($canDelete): ?><button class="btn btn-sm btn-outline-warning" id="chatPinPolicyBtn" disabled>Pin Policy</button><?php endif; ?>
        <?php if ($canDelete): ?><button class="btn btn-sm btn-outline-warning" id="chatAclBtn" disabled>ACL</button><?php endif; ?>
        <input class="form-control form-control-sm" id="chatSearchInput" placeholder="Search in this chat">
        <button class="btn btn-sm btn-outline-light" id="chatSearchBtn">Search</button>
        <button class="btn btn-sm btn-outline-light" id="chatSearchClearBtn">Clear</button>
        <button class="btn btn-sm btn-outline-light" id="chatLoadOlderBtn" disabled>Older</button>
      </div>
    </div>

    <div class="chat-pinned-panel" id="chatPinnedPanel" hidden>
      <div class="chat-pinned-title">Pinned Messages</div>
      <div class="chat-pinned-list" id="chatPinnedList"></div>
    </div>
    <div class="chat-thread-panel" id="chatThreadPanel" hidden>
      <div class="chat-pinned-title d-flex justify-content-between align-items-center">
        <span>Thread View</span>
        <button class="btn btn-sm btn-outline-light" id="chatThreadCloseBtn">Close</button>
      </div>
      <div class="chat-thread-list" id="chatThreadList"></div>
    </div>

    <div class="chat-messages" id="chatMessages">
      <div class="text-secondary">Pilih channel atau DM untuk mulai chat.</div>
    </div>
    <div class="chat-typing-indicator small text-secondary" id="chatTypingIndicator"></div>
    <div class="chat-read-receipt small text-secondary" id="chatReadReceipt"></div>

    <div class="chat-composer">
      <div class="chat-replybar" id="chatReplyBar" hidden>
        <span id="chatReplyText">Replying...</span>
        <button class="btn btn-sm btn-outline-light" id="chatReplyCancelBtn">Cancel</button>
      </div>
      <div class="chat-mention-box">
        <div class="chat-mention-menu" id="chatMentionMenu"></div>
        <div class="chat-compose-row">
          <textarea class="form-control" id="chatComposer" rows="2" maxlength="5000" placeholder="Write a message... (@username)"></textarea>
          <input type="file" id="chatAttachInput" multiple hidden accept=".pdf,.png,.jpg,.jpeg,.docx,.xlsx">
          <button class="btn btn-outline-light btn-icon" id="chatAttachBtn" title="Attach file"><?= rmi_icon('doc') ?></button>
          <button class="btn btn-primary" id="chatSendBtn" disabled>Send</button>
        </div>
      </div>
      <div class="chat-attach-list" id="chatAttachList"></div>
      <div class="small text-secondary" id="chatStatus">Ready</div>
    </div>
  </section>
</div>

<script>
(function(){
  const BASE = <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>;
  const CSRF = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
  const CAN_ADMIN = <?= $canDelete ? 'true' : 'false' ?>;
  const API = {
    channels: BASE + '/api/v1/chat/channels.php',
    messages: BASE + '/api/v1/chat/messages.php',
    read: BASE + '/api/v1/chat/read.php',
    markAllRead: BASE + '/api/v1/chat/mark_all_read.php',
    mute: BASE + '/api/v1/chat/mute.php',
    unmute: BASE + '/api/v1/chat/unmute.php',
    users: BASE + '/api/v1/chat/users.php',
    del: BASE + '/api/v1/chat/message_delete.php',
    reaction: BASE + '/api/v1/chat/reaction.php',
    typing: BASE + '/api/v1/chat/typing.php',
    presence: BASE + '/api/v1/chat/presence.php',
    presencePing: BASE + '/api/v1/chat/presence/ping.php',
    pin: BASE + '/api/v1/chat/pin.php',
    pinPolicy: BASE + '/api/v1/chat/pin_policy.php',
    acl: BASE + '/api/v1/chat/acl.php',
    pins: BASE + '/api/v1/chat/pins.php',
    prefs: BASE + '/api/v1/chat/prefs.php',
    emojis: BASE + '/api/v1/chat/emojis.php',
    thread: BASE + '/api/v1/chat/thread.php',
    download: BASE + '/api/v1/chat/attachment_download.php',
    preview: BASE + '/api/v1/chat/attachment_preview.php',
    search: BASE + '/api/v1/chat/search.php',
    context: BASE + '/api/v1/chat/message_context.php',
    events: BASE + '/api/v1/chat/events.php',
    exports: BASE + '/api/v1/chat/exports.php'
  };

  const el = {
    page: document.getElementById('chatPage'),
    channelList: document.getElementById('chatChannelList'),
    dmList: document.getElementById('chatDmList'),
    msgs: document.getElementById('chatMessages'),
    title: document.getElementById('chatTitle'),
    sub: document.getElementById('chatSub'),
    status: document.getElementById('chatStatus'),
    send: document.getElementById('chatSendBtn'),
    composer: document.getElementById('chatComposer'),
    older: document.getElementById('chatLoadOlderBtn'),
    search: document.getElementById('chatSearchInput'),
    globalSearch: document.getElementById('chatGlobalSearch'),
    attachInput: document.getElementById('chatAttachInput'),
    attachList: document.getElementById('chatAttachList'),
    mentionMenu: document.getElementById('chatMentionMenu'),
    pinnedPanel: document.getElementById('chatPinnedPanel'),
    pinnedList: document.getElementById('chatPinnedList'),
    threadPanel: document.getElementById('chatThreadPanel'),
    threadList: document.getElementById('chatThreadList'),
    typing: document.getElementById('chatTypingIndicator'),
    readReceipt: document.getElementById('chatReadReceipt'),
    replyBar: document.getElementById('chatReplyBar'),
    replyText: document.getElementById('chatReplyText'),
    muteBtn: document.getElementById('chatMuteBtn'),
    pinPolicyBtn: document.getElementById('chatPinPolicyBtn'),
    aclBtn: document.getElementById('chatAclBtn'),
    exportBtn: document.getElementById('chatExportBtn')
  };

  const st = {
    channels: [],
    selected: 0,
    selectedType: 'CHANNEL',
    selectedLabel: '',
    selectedSearch: '',
    oldest: 0,
    newest: 0,
    files: [],
    poll: null,
    pollPending: false,
    pollStopped: false,
    presenceLastAt: 0,
    typingLastSentAt: 0,
    markReadLastAt: 0,
    currentUserId: 0,
    replyTo: null,
    dmReceipt: null,
    emojiSet: ['👍','❤️','😂','🎉','✅','🔥'],
    channelPref: {is_muted:0, notify_level:'ALL', mute_until:null},
    presenceMap: {},
    context: null
  };

  const esc = (s) => (s == null ? '' : String(s));
  const fmt = (t) => { try { return new Date(String(t).replace(' ','T')).toLocaleString(); } catch(e) { return String(t || ''); } };
  const fmtDate = (t) => { try { return new Date(String(t).replace(' ','T')).toLocaleDateString(); } catch(e) { return ''; } };
  /** Username + nama karyawan (API: sender_display_name) */
  function senderLabel(username, userId, displayName){
    const u = (username || '').trim() || ('user#' + String(Number(userId) || 0));
    const d = (displayName || '').trim();
    if (d === '') return u;
    if (d.toLowerCase() === u.toLowerCase()) return u;
    return u + ' (' + d + ')';
  }
  const setStatus = (t) => { el.status.textContent = t; };

  async function api(url, opts){
    const res = await fetch(url, Object.assign({credentials:'same-origin'}, opts || {}));
    let json = null; try { json = await res.json(); } catch(e) {}
    if (!res.ok || !json || json.success !== true) {
      throw new Error((json && json.error && json.error.message) ? json.error.message : ('HTTP ' + res.status));
    }
    return json.data || {};
  }

  function channelLabel(c){ return c.display_name || (c.type === 'DM' ? ('DM #' + c.id) : ('#' + (c.name || 'channel'))); }
  function mentionifyText(txt){ return esc(txt).replace(/@([a-zA-Z0-9._-]{2,50})/g, '<span class="chat-mention">@$1</span>'); }

  function renderSidebarGroup(target, rows){
    target.innerHTML = '';
    if (!rows.length) {
      target.innerHTML = '<div class="text-secondary small">No data</div>';
      return;
    }
    rows.forEach(c => {
      const active = st.selected === Number(c.id);
      const unread = Number(c.unread_count || 0);
      const mentionUnread = Number(c.mention_unread_count || 0);
      const it = document.createElement('div');
      it.className = 'chat-sidebar-item' + (active ? ' chat-sidebar-item--active' : '');
      const muted = Number(c.is_muted || 0) === 1 ? ' 🔕' : '';
      const onlineDot = (c.type === 'DM' && st.presenceMap[String(c.dm_partner_user_id || '')] === 'ONLINE') ? ' 🟢' : '';
      it.innerHTML = '<div class="chat-sidebar-item-main"><div class="fw-semibold">'+esc(channelLabel(c))+ muted + onlineDot + '</div>'
        + '<div class="small text-secondary">'+esc(c.last_message_at ? fmt(c.last_message_at) : 'No message')+'</div></div>'
        + '<div class="d-flex align-items-center gap-1">'
        + '<span class="badge bg-warning text-dark chat-unread-badge">'+(mentionUnread>0?('@'+mentionUnread):'')+'</span>'
        + '<span class="badge bg-secondary chat-unread-badge">'+(unread>0?unread:'')+'</span>'
        + '</div>';
      it.addEventListener('click', () => selectChannel(Number(c.id), channelLabel(c), c.type || 'CHANNEL'));
      target.appendChild(it);
    });
  }

  function renderSidebar(){
    const q = (el.globalSearch.value || '').trim().toLowerCase();
    const rows = !q ? st.channels : st.channels.filter(c => String(channelLabel(c)).toLowerCase().includes(q) || String(c.dm_partner_username || '').toLowerCase().includes(q));
    renderSidebarGroup(el.channelList, rows.filter(c => c.type !== 'DM'));
    renderSidebarGroup(el.dmList, rows.filter(c => c.type === 'DM'));
  }

  function reactionButtons(m){
    const picks = (st.emojiSet || []).slice(0, 8);
    const bar = document.createElement('div');
    bar.className = 'chat-reactions';
    (m.reactions || []).forEach(r => {
      const b = document.createElement('button');
      b.className = 'chat-reaction-chip' + (r.mine ? ' is-mine' : '');
      b.textContent = `${r.emoji} ${r.count}`;
      b.addEventListener('click', async () => { try { await toggleReaction(m.id, r.emoji); } catch(e){ setStatus(e.message);} });
      bar.appendChild(b);
    });
    picks.forEach(p => {
      const b = document.createElement('button');
      b.className = 'chat-reaction-add';
      b.textContent = p;
      b.title = 'React';
      b.addEventListener('click', async () => { try { await toggleReaction(m.id, p); } catch(e){ setStatus(e.message);} });
      bar.appendChild(b);
    });
    return bar;
  }

  function actionButtons(m){
    const row = document.createElement('div');
    row.className = 'chat-action-row';
    const reply = document.createElement('button');
    reply.className = 'btn btn-sm btn-outline-light';
    reply.textContent = 'Reply';
    reply.addEventListener('click', () => setReply(m));
    row.appendChild(reply);
    const thr = document.createElement('button');
    thr.className = 'btn btn-sm btn-outline-light';
    thr.textContent = 'Thread';
    thr.addEventListener('click', () => showThread(m.id));
    row.appendChild(thr);
    if (CAN_ADMIN && !m.is_deleted) {
      const del = document.createElement('button');
      del.className = 'btn btn-sm btn-outline-danger';
      del.textContent = 'Delete';
      del.addEventListener('click', () => deleteMessage(m.id));
      row.appendChild(del);
      const pin = document.createElement('button');
      pin.className = 'btn btn-sm btn-outline-warning';
      pin.textContent = 'Pin';
      pin.addEventListener('click', () => pinMessage(m.id));
      row.appendChild(pin);
    }
    return row;
  }

  function appendMessageNode(m, prevDate){
    const d = fmtDate(m.created_at);
    if (d !== prevDate) {
      const sep = document.createElement('div');
      sep.className = 'chat-date-sep';
      sep.textContent = d;
      el.msgs.appendChild(sep);
    }
    const self = Number(m.user_id) === Number(st.currentUserId);
    const wrap = document.createElement('div');
    wrap.className = 'chat-message' + (self ? ' chat-message--self' : '');
    const av = document.createElement('div');
    av.className = 'chat-avatar';
    av.textContent = (m.sender_username || 'U').slice(0,2).toUpperCase();
    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble' + (m.is_deleted ? ' chat-deleted' : '');
    const meta = document.createElement('div');
    meta.className = 'chat-meta';
    meta.innerHTML = '<span>'+esc(senderLabel(m.sender_username, m.user_id, m.sender_display_name))+' · '+esc(fmt(m.created_at))+'</span>';
    bubble.appendChild(meta);
    if (m.reply && m.reply.message_id) {
      const q = document.createElement('div');
      q.className = 'chat-reply-preview';
      q.innerHTML = '<b>'+esc(senderLabel(m.reply.sender_username, m.reply.user_id || 0, m.reply.sender_display_name) || 'user')+':</b> '+mentionifyText(m.reply.message_text || '');
      bubble.appendChild(q);
    }
    const txt = document.createElement('div');
    txt.className = 'chat-text';
    txt.innerHTML = mentionifyText(m.message_text || '');
    bubble.appendChild(txt);
    if (Array.isArray(m.attachments) && m.attachments.length) {
      const aWrap = document.createElement('div');
      aWrap.className = 'chat-attach-list mt-2';
      m.attachments.forEach(a => {
        const line = document.createElement('div');
        const x = document.createElement('a');
        x.className = 'chat-attach-item';
        x.href = API.download + '?attachment_id=' + encodeURIComponent(String(a.id));
        x.textContent = (a.original_filename || 'file') + ' (' + Math.round((Number(a.size_bytes || 0) / 1024)) + ' KB)';
        line.appendChild(x);
        const p = document.createElement('a');
        p.className = 'chat-attach-item ms-2';
        p.href = API.preview + '?attachment_id=' + encodeURIComponent(String(a.id));
        p.textContent = 'Preview';
        line.appendChild(p);
        aWrap.appendChild(line);
      });
      bubble.appendChild(aWrap);
    }
    bubble.appendChild(reactionButtons(m));
    bubble.appendChild(actionButtons(m));
    if (self) { wrap.appendChild(bubble); wrap.appendChild(av); } else { wrap.appendChild(av); wrap.appendChild(bubble); }
    el.msgs.appendChild(wrap);
  }

  function renderMessages(rows){
    el.msgs.innerHTML = '';
    if (!rows.length) { el.msgs.innerHTML = '<div class="text-secondary">Belum ada pesan.</div>'; return; }
    let prev = '';
    rows.forEach(m => { appendMessageNode(m, prev); prev = fmtDate(m.created_at); });
    el.msgs.scrollTop = el.msgs.scrollHeight;
  }

  function renderReadReceipt(){
    if (!st.dmReceipt || st.selectedType !== 'DM') {
      el.readReceipt.textContent = '';
      return;
    }
    const r = st.dmReceipt;
    if (Number(r.partner_last_read_message_id || 0) > 0) {
      el.readReceipt.textContent = 'Seen by ' + (r.partner_username || 'partner') + (r.partner_last_read_at ? (' at ' + fmt(r.partner_last_read_at)) : '');
    } else {
      el.readReceipt.textContent = '';
    }
  }

  function setReply(m){
    st.replyTo = { id: m.id, sender: senderLabel(m.sender_username, m.user_id, m.sender_display_name), text: m.message_text || '' };
    el.replyText.textContent = 'Replying to ' + st.replyTo.sender + ': ' + st.replyTo.text.slice(0, 100);
    el.replyBar.hidden = false;
    el.composer.focus();
  }

  function clearReply(){ st.replyTo = null; el.replyBar.hidden = true; el.replyText.textContent = ''; }

  async function loadPinned(){
    if (!st.selected) { el.pinnedPanel.hidden = true; return; }
    const d = await api(API.pins + '?channel_id=' + st.selected);
    const rows = d.rows || [];
    if (!rows.length) { el.pinnedPanel.hidden = true; el.pinnedList.innerHTML = ''; return; }
    el.pinnedPanel.hidden = false;
    el.pinnedList.innerHTML = '';
    rows.forEach(r => {
      const it = document.createElement('div');
      it.className = 'chat-pinned-item';
      it.innerHTML = '<b>'+esc(senderLabel(r.sender_username, r.user_id || 0, r.sender_display_name))+'</b>: '+mentionifyText(r.message_text || '');
      if (CAN_ADMIN) {
        const b = document.createElement('button');
        b.className = 'btn btn-sm btn-outline-danger';
        b.textContent = 'Unpin';
        b.addEventListener('click', () => unpinMessage(r.message_id));
        it.appendChild(b);
      }
      el.pinnedList.appendChild(it);
    });
  }

  async function showThread(messageId){
    const d = await api(API.thread + '?message_id=' + messageId);
    const rows = d.rows || [];
    if (!rows.length) {
      el.threadPanel.hidden = true;
      el.threadList.innerHTML = '';
      return;
    }
    el.threadPanel.hidden = false;
    el.threadList.innerHTML = '';
    rows.forEach(r => {
      const it = document.createElement('div');
      it.className = 'chat-thread-item';
      const pre = Number(r.reply_to_message_id || 0) > 0 ? '↳ ' : '';
      it.innerHTML = '<b>' + esc(senderLabel(r.sender_username, r.user_id, r.sender_display_name)) + ':</b> ' + pre + mentionifyText(r.message_text || '') + ' <span class="small text-secondary">(' + esc(fmt(r.created_at)) + ')</span>';
      el.threadList.appendChild(it);
    });
  }

  async function loadEmojiSet(){
    try {
      const d = await api(API.emojis);
      const rows = d.rows || [];
      if (rows.length) {
        st.emojiSet = rows.map(r => String(r.emoji || '')).filter(v => v !== '');
      }
    } catch (e) {}
  }

  async function loadPrefs(){
    if (!st.selected) return;
    const d = await api(API.prefs + '?channel_id=' + st.selected);
    st.channelPref = d.pref || {is_muted:0, notify_level:'ALL', mute_until:null};
    el.muteBtn.disabled = false;
    el.muteBtn.textContent = Number(st.channelPref.is_muted || 0) === 1 ? 'Unmute' : 'Mute';
    if (el.pinPolicyBtn) {
      el.pinPolicyBtn.disabled = false;
    }
  }

  async function toggleMute(){
    if (!st.selected) return;
    const next = Number(st.channelPref.is_muted || 0) === 1 ? 0 : 1;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('channel_id', String(st.selected));
    if (next === 1) {
      const pick = window.prompt('Mute duration in seconds (3600/28800/86400):', '3600') || '3600';
      fd.append('duration', String(parseInt(pick, 10) || 3600));
      await api(API.mute, {method:'POST', body:fd});
    } else {
      await api(API.unmute, {method:'POST', body:fd});
    }
    await loadPrefs();
  }

  async function markAllRead(){
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    await api(API.markAllRead, {method:'POST', body:fd});
    await loadChannels();
    setStatus('All channels marked as read');
  }

  async function updatePinPolicy(){
    if (!st.selected) return;
    const current = st.selectedType === 'DM' ? 'MEMBER' : 'ADMIN_ONLY';
    const x = (window.prompt('Pin policy (ADMIN_ONLY/MEMBER):', current) || '').toUpperCase().trim();
    if (!x) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('channel_id', String(st.selected));
    fd.append('pin_policy', x);
    await api(API.pinPolicy, {method:'POST', body:fd});
    setStatus('Pin policy updated: ' + x);
  }

  async function editAcl(){
    if (!st.selected) return;
    const d = await api(API.acl + '?channel_id=' + st.selected);
    const rows = d.rows || [];
    const template = rows.length
      ? rows.map(r => `${r.role_code}:${r.can_read},${r.can_send},${r.can_pin},${r.can_manage}`).join('\n')
      : "USER:1,1,0,0\nADMIN:1,1,1,1\nSUPERADMIN:1,1,1,1";
    const raw = window.prompt('Edit ACL (format ROLE:read,send,pin,manage per line)', template) || '';
    if (!raw.trim()) return;
    const out = [];
    raw.split(/\r?\n/).forEach(line => {
      const x = line.trim();
      if (!x) return;
      const parts = x.split(':');
      if (parts.length !== 2) return;
      const roleCode = parts[0].trim().toUpperCase();
      const vals = parts[1].split(',').map(v => parseInt(v.trim(), 10) ? 1 : 0);
      out.push({
        role_code: roleCode,
        can_read: vals[0] || 0,
        can_send: vals[1] || 0,
        can_pin: vals[2] || 0,
        can_manage: vals[3] || 0
      });
    });
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('channel_id', String(st.selected));
    fd.append('rows_json', JSON.stringify(out));
    await api(API.acl, {method:'POST', body:fd});
    await loadChannels();
    setStatus('ACL updated');
  }

  async function loadTyping(){
    if (!st.selected) return;
    const d = await api(API.typing + '?channel_id=' + st.selected);
    const rows = d.rows || [];
    if (!rows.length) { el.typing.textContent = ''; return; }
    const names = rows.map(r => r.username || r.full_name || ('user#' + r.id));
    el.typing.textContent = names.join(', ') + ' typing...';
  }

  async function loadPresence(){
    if (!st.selected) return;
    const d = await api(API.presence + '?channel_id=' + st.selected);
    const rows = d.rows || [];
    if (st.selectedType === 'DM') {
      const partner = rows.find(r => Number(r.id) !== Number(st.currentUserId));
      if (partner) {
        const stat = String(partner.status || 'OFFLINE');
        el.sub.textContent = st.selectedLabel + ' · ' + stat;
      }
    }
  }

  async function loadPresenceBatch(){
    const dmIds = st.channels
      .filter(c => c.type === 'DM' && Number(c.dm_partner_user_id || 0) > 0)
      .map(c => Number(c.dm_partner_user_id))
      .filter(v => v > 0);
    if (!dmIds.length) return;
    const d = await api(API.presence + '?user_ids=' + encodeURIComponent(dmIds.join(',')));
    const rows = d.rows || [];
    st.presenceMap = {};
    rows.forEach(r => { st.presenceMap[String(r.user_id)] = String(r.status || 'OFFLINE'); });
  }

  async function loadChannels(){
    const d = await api(API.channels);
    st.channels = d.channels || [];
    st.currentUserId = Number((d.current_user && d.current_user.id) || 0);
    await loadPresenceBatch();
    renderSidebar();
    if (!st.selected && st.channels.length) {
      const c = st.channels[0];
      await selectChannel(Number(c.id), channelLabel(c), c.type || 'CHANNEL');
    }
  }

  async function loadMessages(mode){
    if (!st.selected) return;
    let qs = '?channel_id=' + st.selected + '&limit=30';
    if (mode === 'older' && st.oldest > 0) qs += '&before_id=' + st.oldest;
    if (mode === 'new' && st.newest > 0 && !st.selectedSearch) qs += '&after_id=' + st.newest;
    if (st.selectedSearch) qs += '&q=' + encodeURIComponent(st.selectedSearch);
    const d = await api(API.messages + qs);
    const rows = d.messages || [];
    if (mode === 'new') {
      if (!rows.length) return;
      rows.forEach(m => appendMessageNode(m, fmtDate(m.created_at)));
      st.newest = Number(d.newest_id || st.newest);
      el.msgs.scrollTop = el.msgs.scrollHeight;
    } else {
      renderMessages(rows);
      st.oldest = Number(d.oldest_id || 0);
      st.newest = Number(d.newest_id || 0);
      st.dmReceipt = d.dm_read_receipt || null;
      st.channelPref = d.channel_pref || st.channelPref;
      renderReadReceipt();
      el.muteBtn.disabled = false;
      el.muteBtn.textContent = Number(st.channelPref.is_muted || 0) === 1 ? ('Unmute (' + esc(st.channelPref.mute_until || '') + ')') : 'Mute';
      if (el.exportBtn) {
        el.exportBtn.disabled = !CAN_ADMIN;
      }
      await markRead(true);
      await loadChannels();
      await loadPinned();
      await loadPresence();
    }
    el.older.disabled = st.oldest <= 0;
  }

  async function markRead(force){
    if (!st.selected || !st.newest) return;
    const now = Date.now();
    if (!force && (now - st.markReadLastAt) < 5000) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('channel_id', String(st.selected));
    fd.append('last_read_message_id', String(st.newest));
    await api(API.read, {method:'POST', body:fd});
    st.markReadLastAt = now;
  }

  async function selectChannel(id, label, type){
    st.selected = id;
    st.selectedType = type || 'CHANNEL';
    st.oldest = 0; st.newest = 0;
    st.selectedLabel = label;
    el.title.textContent = label;
    el.sub.textContent = 'channel_id=' + id + ' · ' + st.selectedType;
    const c = st.channels.find(x => Number(x.id) === Number(id)) || null;
    const canSend = c ? Number(c.perm_send || 0) === 1 : true;
    el.send.disabled = !canSend;
    el.composer.disabled = !canSend;
    if (!canSend) {
      el.composer.placeholder = 'You do not have send permission in this channel.';
    } else {
      el.composer.placeholder = 'Write a message... (@username)';
    }
    if (el.pinPolicyBtn) {
      el.pinPolicyBtn.disabled = !CAN_ADMIN;
    }
    if (el.aclBtn) {
      el.aclBtn.disabled = !CAN_ADMIN;
    }
    el.threadPanel.hidden = true;
    el.threadList.innerHTML = '';
    el.page.classList.remove('chat-mobile-show-sidebar');
    renderSidebar();
    clearReply();
    await loadMessages('reset');
    await loadPrefs();
  }

  async function sendMessage(){
    const text = (el.composer.value || '').trim();
    if (!st.selected || !text) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('channel_id', String(st.selected));
    fd.append('message_text', text);
    if (st.replyTo && st.replyTo.id) fd.append('reply_to_message_id', String(st.replyTo.id));
    if (st.context) {
      fd.append('context_entity_type', String(st.context.type || ''));
      fd.append('context_entity_id', String(st.context.id || ''));
      fd.append('context_entity_code', String(st.context.code || ''));
      fd.append('context_entity_url', String(st.context.url || ''));
    }
    fd.append('idempotency_key', (window.crypto && window.crypto.randomUUID) ? window.crypto.randomUUID() : String(Date.now()) + '_' + Math.random().toString(16).slice(2));
    st.files.forEach(f => fd.append('attachments[]', f));
    await api(API.messages, {method:'POST', body:fd});
    el.composer.value = '';
    st.files = [];
    renderAttachList();
    clearReply();
    await loadMessages('new');
    setStatus('Message sent');
  }

  async function deleteMessage(id){
    const reason = window.prompt('Delete reason (required):', '') || '';
    if (!reason.trim()) { setStatus('Delete reason is required'); return; }
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('message_id', String(id));
    fd.append('reason', reason);
    await api(API.del, {method:'POST', body:fd});
    await loadMessages('reset');
    setStatus('Message soft-deleted');
  }

  async function toggleReaction(id, emoji){
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('message_id', String(id));
    fd.append('emoji', emoji);
    await api(API.reaction, {method:'POST', body:fd});
    await loadMessages('reset');
  }

  async function pinMessage(id){
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'pin');
    fd.append('message_id', String(id));
    await api(API.pin, {method:'POST', body:fd});
    await loadPinned();
    setStatus('Message pinned');
  }

  async function unpinMessage(id){
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'unpin');
    fd.append('message_id', String(id));
    await api(API.pin, {method:'POST', body:fd});
    await loadPinned();
    setStatus('Message unpinned');
  }

  async function typingPing(){
    if (!st.selected) return;
    const now = Date.now();
    if ((now - st.typingLastSentAt) < 2500) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('channel_id', String(st.selected));
    try { await api(API.typing, {method:'POST', body:fd}); } catch(e) {}
    const pd = new FormData();
    pd.append('csrf_token', CSRF);
    try { await api(API.presencePing, {method:'POST', body:pd}); } catch(e) {}
    st.typingLastSentAt = now;
  }

  async function exportChannel(){
    if (!st.selected) return;
    const start = window.prompt('Export start date (YYYY-MM-DD):', '');
    if (!start) return;
    const end = window.prompt('Export end date (YYYY-MM-DD):', start);
    if (!end) return;
    const fmt = (window.prompt('Format csv/json:', 'csv') || 'csv').toLowerCase();
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('channel_id', String(st.selected));
    fd.append('start', start);
    fd.append('end', end);
    fd.append('format', fmt === 'json' ? 'json' : 'csv');
    const ret = await api(API.exports, {method:'POST', body:fd});
    setStatus('Export created. rows=' + (ret.row_count || 0));
  }

  async function joinChannel(){
    const id = parseInt((document.getElementById('chatJoinId').value || '').trim(), 10);
    if (!id) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'join');
    fd.append('channel_id', String(id));
    await api(API.channels, {method:'POST', body:fd});
    await loadChannels();
    setStatus('Joined channel #' + id);
  }

  async function createChannel(){
    const name = (document.getElementById('chatNewChannel').value || '').trim();
    if (!name) return;
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'create_channel');
    fd.append('name', name);
    const d = await api(API.channels, {method:'POST', body:fd});
    await loadChannels();
    if (d.channel_id) await selectChannel(Number(d.channel_id), '#' + name, 'CHANNEL');
  }

  async function newDmFlow(){
    const q = window.prompt('Username / keyword untuk DM:', '') || '';
    if (!q.trim()) return;
    const d = await api(API.users + '?q=' + encodeURIComponent(q.trim()) + '&limit=8');
    const rows = d.rows || [];
    if (!rows.length) { setStatus('User not found'); return; }
    const chosen = rows[0];
    const fd = new FormData();
    fd.append('csrf_token', CSRF);
    fd.append('action', 'create_dm');
    fd.append('target_user_id', String(chosen.id));
    const r = await api(API.channels, {method:'POST', body:fd});
    await loadChannels();
    if (r.channel_id) await selectChannel(Number(r.channel_id), '@' + (chosen.username || ('user#' + chosen.id)), 'DM');
  }

  async function mentionLookup(query){
    if (!query || query.length < 1) { el.mentionMenu.style.display='none'; el.mentionMenu.innerHTML=''; return; }
    const d = await api(API.users + '?q=' + encodeURIComponent(query) + '&limit=8');
    const rows = d.rows || [];
    if (!rows.length) { el.mentionMenu.style.display='none'; return; }
    el.mentionMenu.innerHTML = '';
    rows.forEach(u => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = '@' + u.username + ' - ' + (u.full_name || '');
      b.addEventListener('click', () => insertMention(u.username));
      el.mentionMenu.appendChild(b);
    });
    el.mentionMenu.style.display = 'block';
  }

  function insertMention(username){
    const t = el.composer;
    const val = t.value || '';
    const pos = t.selectionStart || val.length;
    const left = val.slice(0, pos);
    const right = val.slice(pos);
    const m = left.match(/@([a-zA-Z0-9._-]*)$/);
    if (!m) return;
    const start = pos - m[0].length;
    t.value = val.slice(0, start) + '@' + username + ' ' + right;
    t.focus();
    const caret = start + username.length + 2;
    t.setSelectionRange(caret, caret);
    el.mentionMenu.style.display = 'none';
  }

  function renderAttachList(){
    el.attachList.innerHTML = '';
    st.files.forEach((f, i) => {
      const x = document.createElement('span');
      x.className = 'chat-attach-item';
      x.textContent = f.name + ' (' + Math.round(f.size / 1024) + ' KB) ×';
      x.style.cursor = 'pointer';
      x.addEventListener('click', () => { st.files.splice(i, 1); renderAttachList(); });
      el.attachList.appendChild(x);
    });
  }

  async function pollOnce(){
    if (st.pollStopped) return;
    if (!st.selected) {
      st.poll = setTimeout(pollOnce, 1500);
      return;
    }
    if (st.pollPending) {
      st.poll = setTimeout(pollOnce, 1000);
      return;
    }
    st.pollPending = true;
    try {
      const ev = await api(API.events + '?channel_id=' + st.selected + '&since_id=' + st.newest + '&timeout=20');
      if (Array.isArray(ev.events) && ev.events.length) {
        await loadMessages('new');
        await markRead(false);
      }
      await loadTyping();
      const now = Date.now();
      if ((now - st.presenceLastAt) > 15000) {
        await loadPresence();
        st.presenceLastAt = now;
      }
    } catch(e) {}
    st.pollPending = false;
    st.poll = setTimeout(pollOnce, 1200);
  }

  function startPoll(){
    if (st.poll) clearTimeout(st.poll);
    st.pollStopped = false;
    st.pollPending = false;
    st.poll = setTimeout(pollOnce, 500);
  }

  document.getElementById('chatJoinBtn').addEventListener('click', async () => { try { await joinChannel(); } catch(e){ setStatus(e.message); } });
  const createBtn = document.getElementById('chatCreateChannelBtn');
  if (createBtn) createBtn.addEventListener('click', async () => { try { await createChannel(); } catch(e){ setStatus(e.message); } });
  document.getElementById('chatSearchBtn').addEventListener('click', async () => { st.selectedSearch = (el.search.value || '').trim(); try { await loadMessages('reset'); } catch(e){ setStatus(e.message);} });
  document.getElementById('chatSearchClearBtn').addEventListener('click', async () => { st.selectedSearch=''; el.search.value=''; try { await loadMessages('reset'); } catch(e){ setStatus(e.message);} });
  document.getElementById('chatLoadOlderBtn').addEventListener('click', async () => { try { await loadMessages('older'); } catch(e){ setStatus(e.message);} });
  document.getElementById('chatSendBtn').addEventListener('click', async () => { try { await sendMessage(); } catch(e){ setStatus('Send failed: '+e.message); } });
  document.getElementById('chatAttachBtn').addEventListener('click', () => el.attachInput.click());
  document.getElementById('chatNewDmBtn').addEventListener('click', async () => { try { await newDmFlow(); } catch(e){ setStatus(e.message);} });
  document.getElementById('chatMarkAllReadBtn').addEventListener('click', async () => { try { await markAllRead(); } catch(e){ setStatus(e.message);} });
  document.getElementById('chatSidebarToggle').addEventListener('click', () => el.page.classList.toggle('chat-mobile-show-sidebar'));
  document.getElementById('chatBackBtn').addEventListener('click', () => el.page.classList.add('chat-mobile-show-sidebar'));
  document.getElementById('chatReplyCancelBtn').addEventListener('click', clearReply);
  document.getElementById('chatThreadCloseBtn').addEventListener('click', () => { el.threadPanel.hidden = true; el.threadList.innerHTML = ''; });
  el.muteBtn.addEventListener('click', async () => { try { await toggleMute(); } catch(e){ setStatus(e.message); } });
  if (el.pinPolicyBtn) {
    el.pinPolicyBtn.addEventListener('click', async () => { try { await updatePinPolicy(); } catch(e){ setStatus(e.message); } });
  }
  if (el.aclBtn) {
    el.aclBtn.addEventListener('click', async () => { try { await editAcl(); } catch(e){ setStatus(e.message); } });
  }
  if (el.exportBtn) {
    el.exportBtn.addEventListener('click', async () => { try { await exportChannel(); } catch(e){ setStatus(e.message); } });
  }

  el.globalSearch.addEventListener('input', renderSidebar);
  el.attachInput.addEventListener('change', () => {
    const files = Array.from(el.attachInput.files || []);
    st.files = st.files.concat(files);
    el.attachInput.value = '';
    renderAttachList();
  });
  el.composer.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); el.send.click(); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.ctrlKey && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      el.search.focus();
    }
  });
  el.composer.addEventListener('input', async () => {
    const v = el.composer.value || '';
    const m = v.slice(0, el.composer.selectionStart || v.length).match(/@([a-zA-Z0-9._-]{1,50})$/);
    try { await mentionLookup(m ? m[1] : ''); } catch(e) {}
    typingPing();
  });
  document.addEventListener('click', (e) => {
    if (!el.mentionMenu.contains(e.target) && e.target !== el.composer) el.mentionMenu.style.display = 'none';
  });

  (async function init(){
    try {
      const params = new URLSearchParams(window.location.search || '');
      const ctx = (params.get('context') || '').trim();
      if (ctx) {
        const [t, id] = ctx.split(':');
        st.context = { type: (t || '').toUpperCase(), id: id || '', code: ctx, url: window.location.href };
        el.sub.textContent = 'Context: ' + ctx;
      }
      const forcedCid = parseInt(params.get('cid') || '0', 10);
      el.page.classList.add('chat-mobile-show-sidebar');
      await loadEmojiSet();
      await loadChannels();
      if (forcedCid > 0) {
        const forced = st.channels.find(c => Number(c.id) === forcedCid);
        if (forced) {
          await selectChannel(Number(forced.id), channelLabel(forced), forced.type || 'CHANNEL');
        }
      }
      startPoll();
      setStatus('Ready');
    } catch(e) {
      setStatus('Init failed: ' + e.message);
    }
  })();

  window.addEventListener('beforeunload', () => {
    st.pollStopped = true;
    if (st.poll) clearTimeout(st.poll);
  });
})();
</script>

<?php rmi_footer(); ?>

