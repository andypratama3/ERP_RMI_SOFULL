# RBAC tidak jalan — ceklist (jujur, tanpa disembunyikan)

## 1. Session tanpa department / role / level

**Gejala:** login sukses tapi hampir semua halaman 403, atau `can()` selalu false.

**Penyebab:** `rbac_can()` memakai **department** + **role matrix** (`rbac_dept_role_permissions`). Kalau `$_SESSION['department']` kosong, matrix **tidak pernah** cocok.

**Perbaikan di kode:** pada setiap cek izin, `rbac_hydrate_session_profile_from_login()` mengisi session dari baris **`master_system_login`** (kolom `department`, `role`, `level`, `office_code`) jika session masih kosong.

**Yang tetap harus Anda benarkan di data:**

- Di **Master / user**, pastikan kolom **department** user terisi (CRM, WQS, FIN, …), bukan string kosong.
- Setelah ubah user, **logout → login** (atau biarkan hydrate jalan otomatis).

## 2. Tabel RBAC belum ada / `auth_rbac_ready()` = false

**Gejala:** non-SYS tidak dapat apa-apa; SYS masih bisa.

**Penyebab:** `can()` memakai `rbac_can2` hanya jika tabel **`rbac_permissions`** dan **`rbac_dept_role_permissions`** ada di database yang sama dengan `db_pdo()`. Jika tidak, fallback legacy **hanya mengizinkan dept SYS**.

**Aksi:** jalankan migrasi / skema yang membuat tabel RBAC, lalu di **RBAC Center** lakukan sync/seed permission + matrix.

## 3. Matrix kosong atau permission tidak `is_active`

**Gejala:** dept & role user benar, tetap 403.

**Penyebab:**

- Baris matrix untuk pasangan `(dept_code, role_code)` tidak ada.
- Kode permission tidak ada di `rbac_permissions` atau **`is_active = 0`** (`rbac_registry_perm_active` menolak).

**Aksi:** RBAC Center → pastikan matrix terisi untuk dept×role user; pastikan permission dari `config/page_registry.php` ada di katalog dan aktif.

## 4. Policy ketat / env

- **`RMI_RBAC_POLICY_STRICT=1`:** route yang tidak ada di `_shared/rbac_policy.php` → 403 (`STRICT_UNLISTED_ROUTE`). Matikan env ini kecuali memang disengaja.
- **`RMI_RBAC_POLICY_DEPT=1`:** filter `depts` di policy aktif. Default tanpa env ini = **tidak** memakai filter dept di policy.

## 5. Lacak penolakan

- File: `storage/logs/rbac_apply.log`
- DB: `system_audit_logs` (`RBAC_DENY`)
- Detail di body 403 (dev): `APP_DEBUG=true` atau `RMI_RBAC_VERBOSE_DENY=1`

## 6. Urutan gate (bukan bug tersembunyi)

Alur lengkap: `docs/internal/RBAC_RUNTIME_LAYERS.md`.
