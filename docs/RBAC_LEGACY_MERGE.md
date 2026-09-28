# RBAC — merge kode legacy / mirror → kanonik

## Ringkas

| File | Fungsi |
|------|--------|
| `config/rbac_legacy_merge_map.php` | Pasangan **lama → baru** (satu target per kode lama) |
| `_shared/rbac.php` | `rbac_alias_candidates()` memuat kandidat legacy **dan** kanonik agar `can()` aman pra/pasca migrasi |
| `sql/migrations/162_rbac_merge_legacy_mirror.sql` | Memindahkan `allow_flag=1` ke kode kanonik, lalu hapus baris lama dari matrix + registry |
| `tools/rbac/gen_162_rbac_merge_legacy_migration.php` | Regenerate SQL dari merge map jika map diubah |

Migrasi **161** (`161_rbac_hrl_drop_duplicate_perms.sql`) khusus duplikat HRL; jalankan **dulu** bila DB masih punya `HRL.REQUEST_*` / `REG_ALKES_*` lama.

## Cutover di NAS

1. Deploy kode + `config/rbac_permissions.php` terbaru.
2. RBAC Center → **Sync Permissions**.
3. `mysql … < sql/migrations/161_rbac_hrl_drop_duplicate_perms.sql` (jika belum).
4. `mysql … < sql/migrations/162_rbac_merge_legacy_mirror.sql`
5. Opsional: **Sync Permissions** lagi; cek `php tools/rbac_diff_config_db.php`.
6. Jika masih ada baris di DB yang tidak ada di `config/rbac_permissions.php` (orphan registry): review daftar diff, lalu **Hapus orphan registry** di RBAC Center (tab Sync) atau `php tools/rbac/prune_rbac_orphan_permissions.php --dry-run` → `--execute`.

## Yang tidak ikut merge map

- **`MASTER_WRITE`** — tetap di registry; tidak ada target kanonik tunggal yang setara; tinjau manual per role.
- Blok **ACT / FIN / SCM / SALES task / WF / WQS.DO_TASKS** — kode sudah kanonik; hanya dipangkas dari daftar “mirror” berlebihan di config.

## Rapikan lanjutan (merge map P2)

- **`MASTER.*_CRUD`**, **`PURCHASES.*_CRUD`**, **`WQS.*_CRUD`**, alias **`WQS.ALLOCATION`**, **`PURCHASES.PAYMENT_AP_VIEW`**, **`HRL.*` legacy underscore**, **`SALES.DO`**, **`PURCHASES.PO`**, **`MPR.ACCESS`**, **`SYSTEM.RBAC_VIEW`** → target di `rbac_legacy_merge_map.php` (bagian bawah file).
- **Catatan `*_CRUD` → `*_EDIT`:** setelah migrasi, user yang sebelumnya mengandalkan CRUD untuk **hapus** mungkin perlu **`*_DELETE`** di matrix — tinjau per dept/role.
- **`SYSTEM.RBAC_VIEW` → `RBAC.VIEW`:** halaman RBAC Center tetap memeriksa string `SYSTEM.RBAC_VIEW` di kode; `can()` menganggap setara `RBAC.VIEW` lewat merge map.

## Menyesuaikan pemetaan

1. Edit `config/rbac_legacy_merge_map.php`.
2. `php tools/rbac/gen_162_rbac_merge_legacy_migration.php` — **hati-hati** jika 162 sudah pernah dijalankan (jangan double-apply tanpa review DB).
