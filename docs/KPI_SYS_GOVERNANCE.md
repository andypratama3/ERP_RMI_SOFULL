# KPI & tata kelola SYS — ERP_RMI_SOFULL

## Dua lapisan (wajib dipahami)

| Lapisan | Fungsi | Contoh |
|--------|--------|--------|
| **RBAC** (`rbac_require`, `*.VIEW`) | Siapa boleh **membuka halaman / menu** | `KPI.VIEW` → akses KPI Center, filter, export |
| **Session level SYS** | Siapa boleh **mutasi kebijakan / data master berbahaya** | `_shared/rmi_sys_gate.php` → `rmi_is_sys_session()`, `auth_is_sys()` |

Mutasi modul KPI (impor, simpan, sync, snapshot, SLA DO, hapus) **tidak** boleh hanya mengandalkan `KPI.EDIT` / `KPI.CREATE` — gate di kode = **SYS**.

## Satu pintu di kode (session)

File **`_shared/rmi_sys_gate.php`** (dimuat dari `master/auth.php`):

- `rmi_session_canonical_level()` — `SYS` \| `MANAGER` \| `STAFF` \| `GUEST`
- `rmi_is_sys_session()` — true hanya untuk SYS
- `rmi_require_sys_session_or_json()` — untuk response JSON 403
- `rmi_require_sys_session_or_html()` — untuk pesan HTML minimal

Modul **KPI** mendelegasikan ke gate yang sama lewat `kpi/_kpi_bootstrap.php`:

- `kpi_canonical_level()` → `rmi_session_canonical_level()`
- `kpi_can_manage()` / `kpi_is_admin_plus()` → `rmi_is_sys_session()`

Di mana pun `master/auth.php` termuat, tersedia juga **`auth_is_sys()`** (alias ke `rmi_is_sys_session()`).

## Kebijakan di DB

Angka/threshold sensitif (mis. **SLA DO menit**) disimpan di **`system_config`** (group `KPI_DO_SLA`), bukan query string. Lihat `kpi/_kpi_policy.php` dan migration `158_kpi_do_sla_policy_minutes.sql`.

## Permission KPI di RBAC (legacy / deskripsi)

Di `_shared/rbac.php` dan `config/rbac_permissions.php`, deskripsi `KPI.CREATE` / `KPI.EDIT` / `KPI.DELETE` menjelaskan bahwa itu **bukan gate utama** mutasi — agar admin RBAC tidak salah mengira satu permission generik sudah cukup.

## Audit

- `kpi_audit()` pada aksi penting (sync, simpan policy, impor, dll.)
- Review berkala: `docs/RBAC_PERIODIC_REVIEW.md`

## Modul lain (RFQ, stok, …)

Untuk domain baru, pola yang sama:

1. `rbac_require($pdo, 'MODUL.VIEW')` di atas halaman.
2. Untuk POST mutasi kebijakan: `rmi_is_sys_session()` atau `auth_is_sys()` — **satu helper**, hindari cek role manual tersebar.
