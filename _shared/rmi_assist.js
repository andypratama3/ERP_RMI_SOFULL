/* _shared/rmi_assist.js
   UI helpers:
   - Theme toggle (dark/light) via <html data-rmi-theme>
   - Help panel (F1) from /docs/help_sop_map.json
   - Role-based menu filter in offcanvas drawer
   Safe: DOM only, no backend calls except JSON fetch.
*/
(function(){
  'use strict';

  function q(sel, root){ return (root||document).querySelector(sel); }
  function qa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  function esc(s){
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }
  function normRole(s){ return String(s||'').trim().toUpperCase(); }
  function parseRoles(attr){
    if(!attr) return ['ALL'];
    return String(attr).split(',').map(normRole).filter(Boolean);
  }

  // ── Base project from body data attribute ──
  function getBaseProject(){
    var bp = (document.body && document.body.dataset) ? (document.body.dataset.baseProject || '') : '';
    if(bp === '/') bp = '';
    if(bp.endsWith('/')) bp = bp.slice(0,-1);
    return bp;
  }
  function getRelPath(){
    var bp = getBaseProject();
    var p = location.pathname || '/';
    if(bp && p.indexOf(bp) === 0){
      p = p.slice(bp.length);
      if(p.charAt(0) !== '/') p = '/' + p;
    }
    return p;
  }
  function normalizeUrl(u){
    u = String(u||'').trim();
    if(!u || /^https?:\/\//i.test(u)) return u;
    var bp = getBaseProject();
    if(bp && u.charAt(0) === '/' && u.indexOf(bp + '/') !== 0) return bp + u;
    return u;
  }

  // ═══════════════════════════════════════════
  // Theme toggle
  // ═══════════════════════════════════════════
  function getSavedTheme(){
    try{
      var a = localStorage.getItem('rmi_theme');
      if(a === 'light' || a === 'dark') return a;
      var b = localStorage.getItem('rmiTheme'); // legacy key compatibility
      if(b === 'light' || b === 'dark') return b;
    }catch(e){}
    return '';
  }
  function saveTheme(mode){
    try{
      localStorage.setItem('rmi_theme', mode);
      localStorage.setItem('rmiTheme', mode); // keep legacy readers/writers synced
    }catch(e){}
  }
  function resolveTheme(){
    var saved = getSavedTheme();
    if(saved) return saved;
    try{
      var fromDom = document.documentElement.getAttribute('data-theme') || document.documentElement.getAttribute('data-rmi-theme');
      if(fromDom === 'light' || fromDom === 'dark') return fromDom;
    }catch(e){}
    try{ if(window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) return 'light'; }catch(e){}
    return 'dark';
  }
  function applyTheme(mode){
    document.documentElement.setAttribute('data-theme', mode);
    document.documentElement.setAttribute('data-rmi-theme', mode);
  }
  function initThemeToggle(){
    var mode = resolveTheme();
    applyTheme(mode);
    var btn = q('#rmiThemeToggle');
    if(btn) btn.textContent = mode === 'light' ? '\u2600\uFE0F' : '\uD83C\uDF19';
  }
  function onThemeToggleClick(){
    var next = document.documentElement.getAttribute('data-rmi-theme') === 'light' ? 'dark' : 'light';
    applyTheme(next);
    saveTheme(next);
    var btn = q('#rmiThemeToggle');
    if(btn) btn.textContent = next === 'light' ? '\u2600\uFE0F' : '\uD83C\uDF19';
  }

  function applyContrast(mode){
    var body = document.body;
    if(!body) return;
    if(mode === 'high'){
      body.classList.add('theme-contrast');
    } else {
      body.classList.remove('theme-contrast');
    }
  }

  function initContrastToggle(){
    var mode = 'normal';
    try{
      var saved = localStorage.getItem('ui_contrast');
      if(saved === 'high' || saved === 'normal') mode = saved;
    }catch(e){}
    applyContrast(mode);
    var btn = q('#rmiContrastToggle');
    if(btn){
      btn.textContent = mode === 'high' ? '\uD83D\uDD06' : '\u25D0';
      btn.setAttribute('aria-pressed', mode === 'high' ? 'true' : 'false');
    }
  }
  function onContrastToggleClick(){
    var mode = (document.body && document.body.classList.contains('theme-contrast')) ? 'normal' : 'high';
    applyContrast(mode);
    try{ localStorage.setItem('ui_contrast', mode); }catch(e){}
    var btn = q('#rmiContrastToggle');
    if(btn){
      btn.textContent = mode === 'high' ? '\uD83D\uDD06' : '\u25D0';
      btn.setAttribute('aria-pressed', mode === 'high' ? 'true' : 'false');
    }
  }

  // ═══════════════════════════════════════════
  // Role-based menu filter (in drawer)
  // ═══════════════════════════════════════════
  function firstNonAdminRole(roles){
    for(var i=0;i<roles.length;i++){
      var r = roles[i];
      if(r && r!=='ALL' && r!=='ADMIN' && r!=='SUPERADMIN') return r;
    }
    return '';
  }
  function detectPageRole(nav){
    try{
      var active = q('a.nav-link.active', nav);
      if(active){ var r = firstNonAdminRole(parseRoles(active.getAttribute('data-roles'))); if(r) return r; }
    }catch(e){}
    var rel = getRelPath().toLowerCase();
    var map = [
      ['/dashboards/sales','CRM'],['/dashboards/warehouse','WQS'],['/dashboards/procurement','PQP'],
      ['/dashboards/finance','FIN'],['/dashboards/regulatory','HRL'],['/dashboards/quality','ITC'],
      ['/dashboards/owner','MPR'],['/dashboards','ALL'],['/kpi','HRL'],['/sales','CRM'],
      ['/purchases','PQP'],['/stock','WQS'],['/mpr','MPR'],['/Fixed_Asset','ACT'],['/payroll','FIN'],
      ['/hrl','HRL'],['/hrl_process','HRL'],['/hrl_reg_alkes','HRL'],['/rbac','ITC'],
      ['/tools','ITC'],['/master','ITC'],['/docs','ALL']
    ];
    for(var i=0;i<map.length;i++){ if(rel.indexOf(map[i][0])===0) return map[i][1]; }
    return '';
  }

  function applyRoleFilter(){
    var nav = q('.rmi-nav');
    var sel = q('#rmiRoleView');
    if(!nav || !sel) return;
    // Prioritas: dept (department) untuk filter per departemen, fallback role
    var ud = (document.body.dataset.userDept||'').toUpperCase().trim()
          || (document.body.dataset.userRoleRaw||'').toUpperCase().trim()
          || (document.body.dataset.userRole||'').toUpperCase().trim();
    if(ud === 'SYS') ud = 'ADMIN';
    var view = normRole(sel.value || 'AUTO');
    var pageRole = detectPageRole(nav);
    if(view === 'AUTO') view = ud || 'ALL';
    if(view === 'ALL') view = '';

    qa('a.nav-link', nav).forEach(function(a){
      var roles = parseRoles(a.getAttribute('data-roles'));
      var show = !view || roles.indexOf('ALL')>=0 || roles.indexOf(view)>=0;
      if(!show && pageRole) show = roles.indexOf(pageRole)>=0;
      if(!show && a.classList.contains('active')) show = true;
      a.style.display = show ? '' : 'none';
    });
    // Hide empty section headers
    var children = Array.prototype.slice.call(nav.children);
    var hdr = null, vis = false;
    function flush(){ if(hdr) hdr.style.display = vis ? '' : 'none'; }
    children.forEach(function(el){
      if(el.classList && el.classList.contains('rmi-nav-section')){ flush(); hdr=el; vis=false; return; }
      if(el.tagName==='A' && el.classList.contains('nav-link') && el.style.display!=='none') vis=true;
    });
    flush();
    try{ localStorage.setItem('rmiRoleView', sel.value); }catch(e){}
  }

  function initRoleFilter(){
    var sel = q('#rmiRoleView');
    if(!sel) return;
    try{
      var saved = localStorage.getItem('rmiRoleView');
      if(saved) sel.value = saved;
    }catch(e){}
    applyRoleFilter();
    sel.addEventListener('change', applyRoleFilter);
  }

  // ═══════════════════════════════════════════
  // Help offcanvas (F1)
  // ═══════════════════════════════════════════
  function renderHelp(help){
    var body = q('#rmiHelpBody');
    if(!body) return;
    var t = (help && help.title) || 'Bantuan';
    var p = (help && help.purpose) || 'Panduan singkat untuk halaman ini.';
    var html = '<h6>'+esc(t)+'</h6><div class="rmi-muted small mb-2">'+esc(p)+'</div>';

    if(help && help.diagram){
      var du = normalizeUrl(help.diagram);
      var diagramLabel = (help.diagram_title && String(help.diagram_title).trim()) ? help.diagram_title : 'Alur proses';
      if(du) html += '<div class="small fw-semibold mb-1">'+esc(diagramLabel)+'</div><div class="rmi-help-diagram-link" data-diagram-url="'+esc(du)+'" role="button" tabindex="0" title="Klik untuk buka diagram full size"><img class="rmi-help-diagram" src="'+esc(du)+'" alt="'+esc(diagramLabel)+'"></div><a class="rmi-help-link mt-2 d-inline-block" href="'+esc(du)+'" target="_blank" rel="noopener">↗ Buka diagram full size</a>';
    }
    if(help && help.steps && help.steps.length){
      html += '<div class="small fw-semibold mt-2 mb-1">Langkah kerja</div><ul class="small mb-0">';
      help.steps.forEach(function(s){ html += '<li>'+esc(s)+'</li>'; });
      html += '</ul>';
    }
    if(help && help.tips && help.tips.length){
      html += '<div class="small fw-semibold mt-2 mb-1">Tips</div><ul class="small mb-0">';
      help.tips.forEach(function(s){ html += '<li>'+esc(s)+'</li>'; });
      html += '</ul>';
    }
    if(help && help.links && help.links.length){
      html += '<div class="small fw-semibold mt-2 mb-1">Dokumen lengkap</div><div class="d-flex flex-column gap-1">';
      help.links.forEach(function(l){
        if(!l||!l.url) return;
        var url = normalizeUrl(l.url);
        html += '<a class="rmi-help-link" href="'+esc(url)+'" target="_blank" rel="noopener">\uD83D\uDD17 '+esc(l.label||l.url)+'</a>';
      });
      html += '</div>';
    }
    html += '<div class="rmi-muted small mt-3">Halaman: <code>'+esc(getRelPath())+'</code></div>';
    body.innerHTML = html;
    // Diagram click: buka full size (backup untuk link)
    body.querySelectorAll('.rmi-help-diagram-link[data-diagram-url]').forEach(function(el){
      var url = el.getAttribute('data-diagram-url');
      if(!url) return;
      function openDiagram(){ try{ window.open(url, '_blank', 'noopener'); }catch(e){ location.href=url; } }
      el.addEventListener('click', openDiagram);
      el.addEventListener('keydown', function(e){ if(e.key==='Enter'||e.key===' ') { e.preventDefault(); openDiagram(); } });
    });
  }

  function entryToHelp(entry){
    if(!entry) return null;
    var links = Array.isArray(entry.links) ? entry.links : [];
    if(!links.length){
      if(entry.sop) links.push({label:'SOP', url:entry.sop});
      if(entry.manual) links.push({label:'Manual', url:entry.manual});
      if(entry.quick_start) links.push({label:'Quick Start', url:entry.quick_start});
      if(entry.sop_keluhan) links.push({label:'SOP Keluhan', url:entry.sop_keluhan});
    }
    return {
      title: entry.title || ('Bantuan ' + (entry.module || '')),
      purpose: entry.purpose || entry.description || 'Panduan dan dokumen terkait halaman ini.',
      steps: entry.steps || [],
      tips: entry.tips || [],
      links: links,
      diagram: entry.diagram || null,
      diagram_title: entry.diagram_title || null
    };
  }

  function matchHelp(map){
    var rel = getRelPath();
    if(!map) return null;
    // Normalize: strip trailing slash for consistent matching
    var relNorm = (rel || '').replace(/\/+$/, '') || '/';
    // Format: map.map = [{path, title, purpose, sop, manual, ...}]
    var arr = Array.isArray(map.map) ? map.map : [];
    var best = null;
    // Try exact/prefix match first
    for(var i=0;i<arr.length;i++){
      var e = arr[i]; if(!e||!e.path) continue;
      var p = String(e.path).trim().replace(/\/+$/, '');
      var match = (rel === p || relNorm === p || rel.indexOf(p) === 0 || relNorm.indexOf(p) === 0);
      if(match){
        if(!best || p.length > (String(best.path||'').replace(/\/+$/, '').length)) best = e;
      }
    }
    // Fallback: if rel has base prefix (e.g. /ERP_RMI_SOFULL/dashboards/...) but data-base-project empty/wrong,
    // try matching path after first segment. Only strip when first segment is NOT a known module.
    var knownModules = ['dashboards','sales','purchases','stock','master','kpi','hrl','hrl_process','hrl_reg_alkes','tools','chat','docs','api','absensi','payroll','mpr','rbac'];
    if(!best && rel.indexOf('/') >= 0){
      var parts = rel.split('/').filter(Boolean);
      if(parts.length > 1 && knownModules.indexOf(parts[0].toLowerCase()) < 0){
        var relAlt = '/' + parts.slice(1).join('/');
        for(var j=0;j<arr.length;j++){
          var e2 = arr[j]; if(!e2||!e2.path) continue;
          var p2 = String(e2.path).trim().replace(/\/+$/, '');
          if(relAlt === p2 || relAlt.indexOf(p2) === 0){
            if(!best || p2.length > (String(best.path||'').length)) best = e2;
          }
        }
      }
    }
    // Fallback: path contains 'mpr' -> use MPR entry (dashboard as default)
    if(!best && /\/mpr(\/|$)/i.test(rel)){
      for(var k=0;k<arr.length;k++){
        var ek = arr[k];
        if(!ek || ek.module !== 'MPR') continue;
        var pk = String(ek.path||'').trim();
        if(rel === pk || rel.indexOf(pk) === 0){
          if(!best || pk.length > (String(best.path||'').length)) best = ek;
        }
      }
      if(!best){
        best = arr.filter(function(x){ return x && x.module === 'MPR'; })[0] || null;
      }
    }
    if(best) return entryToHelp(best);
    // Legacy: map.patterns + map.default
    var pats = Array.isArray(map.patterns) ? map.patterns : [];
    for(var j=0;j<pats.length;j++){
      var p = pats[j]; if(!p||!p.value) continue;
      var t = (p.type||'prefix').toLowerCase();
      try{
        if(t==='exact' && rel===p.value) return entryToHelp(p) || p;
        else if(t==='regex' && new RegExp(p.value).test(rel)) return entryToHelp(p) || p;
        else if(rel.indexOf(p.value)===0) return entryToHelp(p) || p;
      }catch(err){}
    }
    return map['default'] ? entryToHelp(map['default']) || map['default'] : null;
  }

  function initHelp(){
    document.addEventListener('keydown', function(e){
      if(e.key === 'F1'){
        e.preventDefault();
        var el = q('#rmiHelpCanvas');
        if(el && window.bootstrap && window.bootstrap.Offcanvas){
          window.bootstrap.Offcanvas.getOrCreateInstance(el).toggle();
        } else {
          var url = normalizeUrl('/docs/help_center.php');
          if(url) try{ window.location.href = url; }catch(err){}
        }
      }
    });

    // Auto-highlight matching nav link
    try{
      qa('.rmi-nav a.nav-link').forEach(function(a){
        try{ if(new URL(a.href, location.origin).pathname === location.pathname) a.classList.add('active'); }catch(e){}
      });
    }catch(e){}

    // Diagram click: buka full size (konten Help di-render di server)
    qa('#rmiHelpBody .rmi-help-diagram-link[data-diagram-url]').forEach(function(el){
      var url = el.getAttribute('data-diagram-url');
      if(!url) return;
      function openDiagram(){ try{ window.open(url, '_blank', 'noopener'); }catch(err){ location.href = url; } }
      el.addEventListener('click', openDiagram);
      el.addEventListener('keydown', function(ev){ if(ev.key === 'Enter' || ev.key === ' '){ ev.preventDefault(); openDiagram(); } });
    });
  }

  // ═══════════════════════════════════════════
  // Boot — jalankan saat DOM siap (handle script load order)
  // Event delegation: tombol bisa di-render setelah script load
  // ═══════════════════════════════════════════
  function boot(){
    // Theme + Contrast: ditangani inline di rmi_layout.php (agar pasti jalan)
    initRoleFilter();
    initHelp();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
