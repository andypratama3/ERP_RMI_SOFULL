# Audit Keseluruhan ERP_RMI_SOFULL — Jangka Panjang & Konsistensi

**Tanggal:** 2026-03-08  
**Scope:** Backend, Frontend, API, Keamanan, Maintainability  
**Tujuan:** Memastikan ERP siap dipakai jangka panjang, Frontend dan Backend sejalan dan konsisten.

---

## 1. Ringkasan Eksekutif

| Aspek | Status | Prioritas |
|-------|--------|------------|
| **Backend (DB, Auth, Bootstrap)** | ⚠️ Baik dengan tech debt | Sedang |
| **Frontend (Layout, XSS, UI)** | ⚠️ Baik dengan inkonsistensi | Sedang |
| **API (v1, Partner, Mobile)** | ✅ Selaras | — |
| **Keamanan (Auth, CSRF, SQL)** | ✅ Baik | — |
| **Maintainability** | ⚠️ Perlu standarisasi | Tinggi |

**Kesimpulan:** ERP layak jangka panjang. Ada beberapa tech debt (mysqli, dual h(), deploy artifacts) yang sebaiknya diperbaiki bertahap.

---

## 2. Backend Audit

### 2.1 Database

| Item | Status | Detail |
|------|--------|--------|
| **PDO** | ✅ Preferred | `rmi_db_pdo()` di `_shared/db.php`; mayoritas modul pakai PDO |
| **mysqli** | ⚠️ Legacy | `config.php` buat `$conn`; `master/master_user.php` pakai `rmi_db_mysqli()` |
| **Prepared statements** | ✅ Dominan | 200+ file pakai `prepare()` + `execute()` |

**File yang masih pakai mysqli (business pages):**
- `master/master_user.php` — MPR/customer sync, DDL, beberapa query
- `config.php` — koneksi mysqli untuk modul purchases dll
- `tools/qa/run_cutover_checks.php` — DB checks

**Rekomendasi:** Migrasi `master_user.php` ke PDO bertahap; jangan tambah mysqli di kode baru.

### 2.2 Auth & RBAC

| Item | Status |
|------|--------|
| `require_login()` | ✅ Modul utama ter-guard |
| `require_any_permission()` | ✅ Dipakai konsisten |
| RBAC roles | ✅ `manager`, `staff`, `sys` (per `.cursor/rules/rbac-roles.mdc`) |
| MFA | ✅ Tersedia |
| Password hashing | ✅ `password_hash()` / `password_verify()` |

### 2.3 Bootstrap & Config

| Bootstrap | Dipakai oleh |
|-----------|--------------|
| `_shared/bootstrap.php` | Mayoritas modul (sales, stock, dashboards, api) |
| `_shared/bootstrap.php` + `RMI_BOOTSTRAP_WITH_CONFIG` | Modul yang butuh mysqli |
| `dashboards/_dashboard_bootstrap.php` | Dashboards |
| `_purchases_bootstrap.php` | Purchases |
| `_wqs_bootstrap.php` | Stock/WQS |
| `api/v1/mobile/_bootstrap.php` | Mobile API |
| `api/_lib/partner_auth.php` | Partner API |

**Config:** `config-db.php` > `.env` > default. Credential tidak di-commit (`.gitignore`).

---

## 3. Frontend Audit

### 3.1 Layout

| Item | Status |
|------|--------|
| **Sumber kebenaran** | `_shared/rmi_layout.php` |
| **rmi_header() / rmi_footer()** | Dipakai di master, sales, stock, dashboards, dll |
| **Topbar + menu drawer** | Satu pola untuk semua modul |
| **Theme** | Dark (Bootstrap 5.3.3), toggle light/dark |

### 3.2 XSS Escaping (Inkonsistensi)

| Helper | Lokasi | Penggunaan |
|--------|--------|-----------|
| `rmi_h()` | `_shared/helpers.php` | **Standar** — 50+ file |
| `rmi_ui_h()` | `rmi_layout.php` | Alias htmlspecialchars |
| `h()` | `config.php` | **Legacy** — 100+ file masih pakai |
| `htmlspecialchars` | Langsung | ~200+ file |

**Aturan (`.cursor/rules/coding-standards.mdc`):** Pakai `rmi_h()`, jangan define `h()` lokal.

**Rekomendasi:** Bertahap ganti `h()` → `rmi_h()` di file baru; file lama bisa tetap pakai `h()` selama konsisten.

### 3.3 UI Patterns

| Pattern | Status |
|---------|--------|
| Form | `form-control`, `form-select` |
| Table | `table table-sm table-hover rmi-table` |
| Card | `rmi-card` |
| Alert | `alert alert-{type}` |

---

## 4. API Audit

### 4.1 Struktur

```
api/
├── v1/                    # Canonical
│   ├── partner/           # API Key auth
│   ├── mobile/            # JWT auth
│   ├── internal/          # Internal/session
│   ├── chat/
│   └── webhooks/
├── mobile/                # Legacy path (redirect ke v1)
└── _lib/partner_auth.php
```

### 4.2 Auth per Tipe

| Tipe | Auth | Implementasi |
|------|------|--------------|
| Partner | API Key | `X-API-Key` / `Authorization: Bearer` |
| Mobile | JWT | `mobile_jwt_verify()`, HS256 |
| Internal | Session / IP | `_internal_api_bootstrap.php` |

### 4.3 Response Format

- JSON: `ok`, `code`, `message`, `data`
- Error: `http_response_code(401/403/400/405)` + JSON

---

## 5. Critical Issues & Tech Debt

### 5.1 Prioritas Tinggi

| # | Issue | Lokasi | Rekomendasi |
|---|-------|--------|--------------|
| 1 | **mysqli di master_user.php** | `master/master_user.php` | Migrasi ke PDO; beberapa `$mysqli->query($sql)` pakai variabel — review SQL injection |
| 2 | **Dual escaping h() vs rmi_h()** | 100+ file pakai `h()` | Standarisasi: file baru pakai `rmi_h()`; migrasi bertahap |

### 5.2 Prioritas Sedang

| # | Issue | Rekomendasi |
|---|-------|-------------|
| 3 | **Deploy artifacts** | `exports/deploy/2026-02/` — jangan dipakai sebagai source; pastikan deploy dari root project |
| 4 | **CSRF coverage** | Jalankan `tools/enterprise_audit.php` berkala; perbaiki form yang belum verify_csrf |
| 5 | **Bootstrap paths** | Dokumentasikan di README: modul mana pakai bootstrap mana |

### 5.3 Prioritas Rendah

| # | Issue | Rekomendasi |
|---|-------|-------------|
| 6 | **display_errors** | Pastikan production: `display_errors=0` |
| 7 | **declare(strict_types=1)** | Tambah ke file baru |
| 8 | **Rate limit Partner API** | Kolom `rate_limit_per_hour` di `api_partner_keys` — pastikan dipakai |

---

## 6. Rekomendasi Jangka Panjang

### 6.1 Backend

1. **PDO only** — Jangan tambah mysqli di kode baru; migrasi `master_user.php` ke PDO.
2. **Bootstrap standar** — Modul baru: `require_once _shared/bootstrap.php` + auth.
3. **Config** — Pakai `config-db.php` atau `.env`; hindari hardcode credential.
4. **Prepared statements** — Selalu pakai `prepare()` + `execute()`; jangan interpolasi user input ke SQL.

### 6.2 Frontend

1. **rmi_h()** — Output dinamis: selalu `rmi_h($value)`.
2. **Layout** — Modul baru: `rmi_header()` + `rmi_footer()`.
3. **Asset version** — Update `RMI_ASSET_VERSION` di `assets.php` saat release.

### 6.3 API

1. **Path** — Pakai `api/v1/` sebagai canonical.
2. **Response** — Format konsisten: `{ok, code, message, data}`.
3. **Error** — Selalu set `http_response_code` + JSON body.

### 6.4 Rutinitas

| Frekuensi | Aksi |
|-----------|------|
| **Setiap release** | Jalankan `tools/enterprise_audit.php`; perbaiki gate FAIL |
| **Bulanan** | Review `tools/audit/audit_center.php`; cek migration lint |
| **3–6 bulan** | Audit mysqli usage; prioritaskan migrasi ke PDO |

---

## 7. Checklist Verifikasi

- [ ] `php tools/enterprise_audit.php` — Gate PASS (missing_login, mysqli, h_unguarded)
- [ ] `php tools/audit/audit_center.php` — Migration SQL lint OK
- [ ] Form POST punya `verify_csrf()`
- [ ] Output dinamis pakai `rmi_h()` atau `h()`
- [ ] API response format konsisten
- [ ] Production: `display_errors=0`, HTTPS

---

## 8. Referensi

- `.cursor/rules/coding-standards.mdc` — Standar kode
- `.cursor/rules/rbac-roles.mdc` — Role RBAC
- `docs/AUDIT_PENGECEKAN_MENYELURUH_2026.md` — Security audit
- `docs/CEK_KEAMANAN_JANGKA_PANJANG.md` — Rutinitas keamanan
- `tools/enterprise_audit.php` — Static scan
- `tools/audit/audit_center.php` — Audit center

---

*Dokumen ini dihasilkan dari audit menyeluruh codebase ERP_RMI_SOFULL. Update berkala disarankan.*
