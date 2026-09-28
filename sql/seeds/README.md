# SQL Seeds — Data Awal Opsional

Seed data untuk development/demo. **Jalankan setelah migrations.** Gunakan INSERT IGNORE agar aman dijalankan berulang.

| File | Prasyarat | Isi |
|------|------------|-----|
| `002_master_products_seed_min.sql` | Tabel master_products ada | 5 produk demo |
| `030_rbac_roles_seed.sql` | Tabel rbac_roles ada (migration 030A) | Role SUPERADMIN, ADMIN, MANAGER, STAFF |

**Cara jalankan:**

```bash
./tools/nas/run_seeds.sh
```

Atau manual di phpMyAdmin: pilih database → Tab SQL → paste isi file → Jalankan.
