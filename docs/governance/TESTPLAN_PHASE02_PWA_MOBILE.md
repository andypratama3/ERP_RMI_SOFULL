# Test Plan — Phase 02 PWA & Mobile

**Phase:** 02  
**Date:** 2026-02-25

## Manual Steps

### 1. PWA Assets
- [ ] GET `/public/manifest.json` → 200, valid JSON, `name` + `icons` present
- [ ] GET `/public/sw.js` → 200, contains `erp_pwa_v1_`
- [ ] GET `/public/offline.html` → 200, contains "Anda sedang offline"
- [ ] GET `/public/icons/icon-192.png` → 200

### 2. Service Worker
- [ ] Open app in browser (Chrome/Edge)
- [ ] DevTools → Application → Service Workers → SW registered, no fatal
- [ ] DevTools → Network → Offline → reload → offline.html shown
- [ ] Confirm no caching of `/api/*` or transaction pages

### 3. Layout
- [ ] Page has `<link rel="manifest" href=".../public/manifest.json">`
- [ ] Page has `<meta name="theme-color" content="#1e293b">`

### 4. Mobile Visual
- [ ] WQS Dashboard at 375px width — KPI cards wrap, table scrolls horizontal
- [ ] Stock Opname at 375px — sticky summary visible, qty input large, Enter → next field
- [ ] DO Tasks (WQS/SCM/ACT/FIN) at 375px — table scrolls, buttons ≥44px touch target

### 5. CLI Smoke
```bash
APP_BASE_URL=http://127.0.0.1 php tools/qa/smoke_pwa.php
```
- [ ] Exit 0, overall_ok=true
- [ ] storage/logs/smoke_pwa_last.json written

## Expected Results

- PHP lint: 0 errors on touched files
- smoke_pwa: overall_ok=true
- No blank/error pages
- No transaction page cached by SW
