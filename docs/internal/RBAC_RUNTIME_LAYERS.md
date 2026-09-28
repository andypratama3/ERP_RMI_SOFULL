# RBAC runtime — lapisan apa saja yang jalan (tanpa “sembunyi”)

Dokumen ini menjelaskan **urutan pasti** setelah user login membuka halaman `.php` yang memakai `require_login()` dari `master/auth.php`.

## Urutan dalam satu request

1. **`auth_require_login()`** — session wajib valid (belum login → redirect login).
2. **CSRF** — untuk `POST` / `PUT` / `PATCH` / `DELETE` (kecuali probe SYS khusus QA).
3. **`require_rbac()`** — policy file `_shared/rbac_policy.php`:
   - Route public → lewat.
   - **`auth_is_sys_tier()`** → bypass penuh policy ini.
   - `strict` + route tidak ada di policy → 403 `STRICT_UNLISTED_ROUTE`.
   - Match rule: cek **level**, **HTTP method**, **`rule['perms']` hanya jika URL tidak punya baris di `config/page_registry.php`**.
   - **Department di policy (`depts` di `rbac_policy.php`):** default **tidak** dipakai untuk blokir (hindari dobel aturan dengan user dept + RBAC Center). Hanya aktif jika **`RMI_RBAC_POLICY_DEPT=1`** di `.env`.
   - POST: aksi “manager” / `cash_out` → tambahan level MANAGER/SYS.
4. **`rmi_page_registry_guard_try()`** — jika URL ada di `config/page_registry.php`:
   - `route_any` → `require_any_permission(...)`
   - atau `access` / `view` → `require_permission(...)`
5. **Gate di dalam halaman** — banyak modul masih memanggil `require_permission` / `require_any_permission` lagi untuk aksi spesifik (bukan duplikasi registry; itu sengaja per fitur).

## Di mana jejak penolakan tercatat

- **File:** `storage/logs/rbac_apply.log` — setiap 403 dari `auth_rbac_forbidden_exit()` (termasuk registry).
- **DB:** `system_audit_logs` — `module=rbac`, `action=RBAC_DENY` (jika tabel/DB tersedia).

Kolom `reason` memuat kode seperti:

| Kode | Arti singkat |
|------|----------------|
| `POLICY_DEPT_BLOCK` | Session department tidak termasuk `depts` rule policy (**hanya jika** `RMI_RBAC_POLICY_DEPT=1`). |
| `USER_ACTIVE_PERM_LEVEL_METHOD_BLOCK` | Level/method/atau policy `perms` (URL tidak di registry) gagal. |
| `REGISTRY_REQUIRE_PERMISSION` | `can(perm)` false untuk satu kode (guard registry atau halaman). |
| `REGISTRY_REQUIRE_ANY_PERMISSION` | Tidak ada satu pun dari daftar `need_any_of=...` yang lolos. |
| `STAFF_BLOCKED_MANAGER_ACTION` | Staff mencoba aksi yang dibatasi MANAGER/SYS. |
| `CASH_OUT_MANAGER_OR_SYS_ONLY` | Route `cash_out` + POST tanpa MANAGER/SYS. |
| `STRICT_UNLISTED_ROUTE` | `RMI_RBAC_POLICY_STRICT` aktif dan URL tidak di policy. |
| `DO_TRANSITION_BLOCK` | Transisi status Sales DO tidak diizinkan untuk dept. |

## Membaca detail di browser (opsional)

- **`APP_DEBUG=true`** (`.env`) **atau**
- **`RMI_RBAC_VERBOSE_DENY=1`** (`.env`)

→ body respons 403 bisa berisi **baris kedua**: kode reason + detail (permission / dept / method). **Prod:** biasanya matikan atau jangan set `VERBOSE` agar body tetap generik `Forbidden`.

## File sumber kebenaran

| Lapisan | File |
|--------|------|
| Policy route (level, method, cash_out; `depts` opsional via env; perms tanpa registry) | `_shared/rbac_policy.php` |
| Izin per URL (ACCESS/VIEW/route_any) | `config/page_registry.php` |
| Centang user / DB | RBAC Center + `can()` / `rbac_can2()` di `_shared/rbac.php` |

Tidak ada lapisan “rahasia” di luar urutan di atas untuk `require_login()` standar.

Lihat juga: `CANONICAL_PATHS_AND_DUPLICATES.md` (helper, bootstrap, env opsional). **RBAC 403 / tidak jalan:** `RBAC_TROUBLESHOOTING.md`.
