# PWA & Mobile UX Spec — Phase 2

**Phase:** 02  
**Date:** 2026-02-25  
**Project:** ERP_RMI_SOFULL

## 1. What / Why / How

### 1.1 PWA Basic
- **Manifest:** `public/manifest.json` — name "ERP RMI SOFULL", short_name "ERP RMI", standalone display, theme/background colors, icons 192/512
- **Service Worker:** `public/sw.js` — versioned cache `erp_pwa_v1_<build_id>`, precache offline.html + icons
- **Offline fallback:** `public/offline.html` — static page "Anda sedang offline." + "Coba lagi" button
- **Registration:** Layout `_shared/rmi_layout.php` — link manifest, meta theme-color, SW register (defer, catch error)

### 1.2 SW Fetch Rules (Safe-by-default)
| Request type | Strategy | Cache |
|--------------|----------|-------|
| `/api/*` | Network only | Never |
| `mode === navigate` | Network first | Fallback offline.html |
| script/style/image/font | Cache first | Yes |
| Other | Network only | No |

### 1.3 Mobile-Optimized Pages
- **WQS Dashboard:** Responsive KPI grid, table overflow-x, touch targets ≥44px
- **Stock Opname:** Sticky summary (total/pending), larger qty input on mobile, Enter → focus next, table-responsive
- **DO Tasks (WQS/SCM/ACT/FIN):** table-wrap -webkit-overflow-scrolling:touch, btn min-height 44px, flex-wrap

### 1.4 MFA Mobile API
- **Status:** BLOCKED — Mobile auth/login has no MFA flow. Web MFA schema not integrated. Logged in assumptions_phase02.json.

## 2. Security

- No secret/path leak in offline page or SW
- SW does NOT cache transaction HTML or API responses
- Login + CSRF guards unchanged
- Theme-color meta only (no sensitive data)

## 3. Files Touched

| File | Change |
|------|--------|
| public/manifest.json | name/short_name per spec |
| public/sw.js | Full fetch rules, versioned cache |
| public/offline.html | Copy "Anda sedang offline." |
| _shared/rmi_layout.php | theme-color meta |
| dashboards/warehouse/wqs_dashboard.php | Responsive + overflow |
| stock/wqs_stock_opname.php | Sticky summary, input UX, focus-next |
| sales/wqs_do_tasks.php | Mobile CSS |
| sales/scm_do_tasks.php | Mobile CSS |
| sales/act_do_tasks.php | Mobile CSS |
| sales/fin_do_tasks.php | Mobile CSS |
| tools/qa/smoke_pwa.php | New smoke |
