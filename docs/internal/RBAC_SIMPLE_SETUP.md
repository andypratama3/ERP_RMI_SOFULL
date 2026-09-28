# Pengaturan Role & Permission — model sederhana

Anda sudah menentukan **Dept**, **Role**, dan **Level** per user. Di ERP_RMI_SOFULL, **sumber pengaturan operasional** cukup dipahami sebagai berikut.

## 1. User (satu tempat)

Di master user / akun login, set konsisten:

| Field | Fungsi |
|--------|--------|
| **Department** | Divisi / cabang logis (CRM, WQS, FIN, …). |
| **Role** | `manager` \| `staff` \| `sys` (konvensi proyek). |
| **Level** | `STAFF` \| `MANAGER` \| `SYS` — dipakai untuk aturan khusus (mis. aksi approve, cash-out). |

Ini identitas session; tidak perlu “menebak” dept lain di file policy untuk penggunaan harian.

## 2. Permission (RBAC Center)

**`/rbac/index.php`** (RBAC Center):

- **Matrix Dept × Role** — permission default per kombinasi.
- **Override per user** (centang) — kalau user butuh izin di luar matrix.

Efek ke halaman: fungsi **`can('KODE.PERMISSION')`** — kalau user punya centang (atau matrix), boleh.

## 3. Halaman / URL

- **`config/page_registry.php`** — untuk tiap URL: kode ACCESS/VIEW (atau `route_any`) yang dipakai guard setelah login.
- **`_shared/rbac_policy.php`** — envelope teknis: method HTTP, level, `cash_out`, dll. Kolom **`depts` di sini tidak memblokir akses secara default**; itu hanya referensi. Filter dept per route **opsional**: set **`RMI_RBAC_POLICY_DEPT=1`** di `.env` jika Anda sengaja ingin lapisan tambahan selain permission.

## Ringkasnya

1. Atur **dept / role / level** di user.  
2. Atur **permission** di **RBAC Center** (matrix + centang user).  
3. URL baru: pastikan ada baris di **page registry** dengan kode permission yang sesuai.

Detail urutan runtime (login → policy → registry): `docs/internal/RBAC_RUNTIME_LAYERS.md`.
