# Standar RBAC — Quartet CRUD (Accurate-like / selaras HRL Process)

Dokumen ini menjelaskan **target konsistensi** permission: **VIEW · CREATE · EDIT · DELETE** per *sumber daya* (resource), dikontrol penuh dari **RBAC Center**.

---

## Format kode (disarankan)

```
MODUL.RESOURCE_VIEW
MODUL.RESOURCE_CREATE
MODUL.RESOURCE_EDIT
MODUL.RESOURCE_DELETE
```

- **MODUL** = kolom `module` di registry (mis. `MASTER`, `WQS`, `HRL_PROCESS`).
- **RESOURCE** = sub-fitur dalam snake/UPPER (mis. `CUSTOMER`, `REQ_CUTI`, `INCOMING`).
- Contoh granular per tipe (referensi): `HRL.REQ_CUTI_VIEW`, `HRL.REQ_CUTI_CREATE`, …

---

## Arti quartet

| Aksi | Gunakan untuk |
|------|----------------|
| **VIEW** | Buka halaman, list, detail, cetak read-only, export non-privileged |
| **CREATE** | Tambah entitas / dokumen / draft baru |
| **EDIT** | Ubah data, submit ke workflow, approve/reject, posting non-final |
| **DELETE** | Hapus / void / soft-delete / batalkan (risiko tinggi) |

---

## Kode legacy yang bukan empat huruf

Saat **menyelaraskan** (bukan menghapus mendadak), petakan seperti berikut:

| Pola lama | Arah normalisasi |
|-----------|------------------|
| `*_APPROVE`, `*_POST`, `*_SUBMIT`, `*_LOCK` | **EDIT** (atau resource terpisah `*_WORKFLOW_EDIT` jika perlu) |
| `*_EXPORT`, `*_PRINT` (baca data) | **VIEW**; export sensitif → **EDIT** |
| `*_IMPORT`, upload mutasi | **CREATE** atau **EDIT** |
| `*_AUDIT`, `*_RECAP`, `*_REPORT` | **VIEW** |
| `*_MANAGE`, `*_SETTINGS`, `*_ADMIN` | **EDIT** + gate SYS/dept, atau tetap kode khusus **SYSTEM.*** |
| `*.CRUD` | Alias — ganti bertahap ke VIEW+CREATE+EDIT+DELETE |

---

## Referensi lengkap per modul

- **Katalog permission** dikelompokkan per `module` + kolom “Kelas CRUD”:  
  [`RBAC_CATALOG_BY_MODULE.md`](./RBAC_CATALOG_BY_MODULE.md) (generate: `php tools/rbac/build_rbac_catalog_md.php`)
- Panduan teks per area: [`RBAC_PERMISSION_GUIDE.md`](./RBAC_PERMISSION_GUIDE.md)
- Modul baru: [`GOVERNANCE_NEW_MODULE.md`](./GOVERNANCE_NEW_MODULE.md)
- **Pemetaan URL / endpoint → permission (inventaris QA):** `php tools/qa/rbac_action_inventory.php --write-last` + [`ERP_MENU_WORKFLOW_REFERENCE.md`](./ERP_MENU_WORKFLOW_REFERENCE.md) — melengkapi katalog per *file* secara operasional.

---

## Prinsip implementasi di PHP

1. **GET / halaman**: `require_any_permission` minimal **VIEW** (atau quartet VIEW untuk resource itu).
2. **POST mutasi**: **CREATE** / **EDIT** / **DELETE** eksplisit + `verify_csrf()` bila form.
3. **Kompatibilitas**: setelah migrasi DB, matrix memakai kode kanonik saja; `rbac_alias_candidates()` tetap memetakan pola underscore lama (mis. `PURCHASES_READ` → `PURCHASES.VIEW`), bukan duplikat HRL yang sudah dihapus migrasi **161**.
4. **SYS / privileged**: tetap bypass lewat `rbac_is_privileged_session()` — tidak mengurangi pengecekan untuk non-SYS.

---

## Helper (opsional)

`_shared/rbac_crud.php` — fungsi `rmi_rbac_crud_codes()` untuk membentuk empat kode dari prefix kanonik (mis. `HRL.REQ_CUTI`).
