# RBAC Policy — Canonical (ERP_RMI_SOFULL)

**Alur keputusan akses (diagram):** `docs/internal/ACCESS_DECISION_FLOW.md` — gabungan `master_system_login` → session → route policy → matrix / override.

Ringkasan operasional untuk developer & SYS. Detail modul: `docs/ERP_MENU_WORKFLOW_REFERENCE.md`, `docs/governance/RBAC_ALL_MODULES_V1.md`, `config/rbac_permissions.php`.

1. **Nav = UI saja** — `master/nav_manager.php` hanya mengatur sidebar/landing; **tidak** membuka URL tanpa policy + permission.
2. **URL / route** — `_shared/rbac_policy.php` (dept, level, method, `cash_out`). **Tidak ada** overlay wildcard per folder. Setelah login, permission per URL: **`config/page_registry.php`** + **`rmi_page_registry_guard_try()`** + matrix RBAC Center.
3. **Fitur dalam halaman** — tombol/aksi memakai **permission codes** (`require_any_permission`, `rbac_guard_*`); gate server-side wajib, bukan hanya hide UI.
4. **Default deny (strict)** — set `RMI_RBAC_POLICY_STRICT=1` agar route tak terdaftar ditolak; tanpa env, route tak terdaftar diizinkan lewat `require_rbac` (legacy).
5. **SYS / sys** — privileged penuh di `require_rbac` untuk **dept SYS** + admin; tetap di-audit lewat `auth_rbac_audit_deny` / `system_audit_logs`.
6. **ITC** — tidak ada bypass khusus; perlakuan sama seperti dept operasional lain di policy.
7. **FIN / pengeluaran** — approve/post pembayaran & aksi setara: **handler** wajib `auth_require_fin_central_approver()` / `rbac_guard_require_fin_central_approver()` (**MgrFIN_BGR** atau **SYS**). Daftar kode terkait: `_shared/rbac_fin_outflow_permissions.php`.
8. **CSRF + POST** — mutasi memakai POST + `verify_csrf` (atau `rbac_guard_require_csrf_post`).
9. **Probe QA** — `X-RBAC-PROBE: 1` pada POST: user **dept SYS** + admin melewati CSRF di `require_login` hanya untuk probe; `rbac_guard_require_fin_central_approver()` mengembalikan JSON `{ "probe": true, "fin_central_would_allow": ... }` lalu **exit** (tanpa mutasi).
10. **Non-goals** — tidak mengubah alur bisnis (PR→PO→GR→AP, SO→DO→stok); hanya lapisan RBAC/guard.
11. **Modul baru** — route di `_shared/rbac_policy.php`; baris halaman + kolom ACCESS/CREATE/… di `config/page_registry.php`; seed kode di `config/rbac_permissions.php`; matrix di RBAC Center.
12. **DB override route (opsional masa depan)** — belum ada; jika ditambahkan: audit + export JSON + tombol “reset ke policy kode”.
