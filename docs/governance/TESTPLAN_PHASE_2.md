# Test Plan - Phase 2 (PWA)

## Manifest
- [ ] /public/manifest.json loads (200)
- [ ] name, short_name, icons, start_url present

## Service Worker
- [ ] /public/sw.js loads (200)
- [ ] SW registers in DevTools Application tab
- [ ] Offline: navigate to any page, go offline, see offline.html

## Icons
- [ ] /public/icons/icon-192.png exists
- [ ] /public/icons/icon-512.png exists

## Responsive
- [ ] wqs_dashboard.php: resize viewport, layout adapts
- [ ] wqs_stock_opname.php: table scrolls on narrow viewport
