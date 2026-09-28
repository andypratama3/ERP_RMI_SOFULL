# PWA & Install di Android — Checklist

## Yang Sudah Dikerjakan

| Item | Status |
|------|--------|
| `public/manifest.json` | ✅ |
| `public/sw.js` | ✅ |
| `public/offline.html` | ✅ |
| `public/icon-192.png`, `icon-512.png`, `icon-192.svg` | ✅ |
| `sales/scm-tracker-manifest.json` | ✅ |
| Layout: manifest link, theme-color, SW register | ✅ |
| `tools/qa/smoke_pwa.php` | ✅ |
| `tools/pwa/generate_icons.php` | ✅ |
| Cutover: smoke_pwa step | ✅ |
| README: langkah verifikasi | ✅ |

---

## Yang Harus Kamu Lakukan

### 1. Setelah perubahan file (sekarang)

**Verifikasi smoke PWA:**
```bash
cd /path/to/ERP_RMI_SOFULL
php tools/qa/smoke_pwa.php
```
- Harus: `"fail":0`, 18 checks pass
- Jika fail: periksa file yang missing

---

### 2. Sebelum deploy ke NAS

**Pastikan file ikut ter-copy:**
- `public/manifest.json`, `public/sw.js`, `public/offline.html`
- `public/icon-192.png`, `public/icon-512.png`, `public/icon-192.svg`
- `sales/scm-tracker-manifest.json`
- `_shared/rmi_layout.php` (sudah diubah)

---

### 3. Setelah deploy ke NAS

**Jalankan smoke di NAS:**
```bash
cd /volume4/web/ERP_RMI_SOFULL
./tools/nas/erp.sh php tools/qa/smoke_pwa.php
```

**Opsional — HTTP check (jika base URL sudah set):**
```bash
TOOLS_BASE_URL_INTERNAL=https://your-erp-url ./tools/nas/erp.sh php tools/qa/smoke_pwa.php
```

---

### 4. Uji install di HP Android

1. Buka ERP di Chrome Android (harus HTTPS)
2. Menu ⋮ → **Add to Home Screen** atau **Install app**
3. Pastikan ikon muncul di home screen dan app terbuka standalone

**SCM Tracker:**
1. Buka `/sales/scm_tracker_mobile.php` di Chrome Android
2. Menu ⋮ → Add to Home Screen

---

### 5. Jika perlu regenerate ikon

```bash
php tools/pwa/generate_icons.php
```
- Membuat ulang `icon-192.png` dan `icon-512.png`
- Update `manifest.json` dengan path PNG

---

## APK Native (opsional)

Untuk build APK:
```bash
cd android_app
./gradlew assembleRelease
```
- Output: `app/build/outputs/apk/release/*.apk`
