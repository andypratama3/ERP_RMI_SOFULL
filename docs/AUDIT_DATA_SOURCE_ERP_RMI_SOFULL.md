# Audit — Sumber Data ERP_RMI_SOFULL

**Tujuan:** Memastikan **Tools** dan **ERP Web** membaca database yang sama (real system).

**Tanggal audit:** 2026-03-08

---

## 1. Ringkasan

| Komponen | Sumber Config | Database |
|---------|---------------|----------|
| **ERP Web** (master_*, sales, api, dll) | config-db.php → rmi_db_config() | Satu DB |
| **Tools** (backup, smoke, migration, dll) | config-db.php → rmi_db_config() | Satu DB |
| **API Partner** (order_create, health) | _shared/db.php → rmi_db_pdo() | Satu DB |

**Kesimpulan:** Semua komponen memakai **config-db.php** sebagai sumber utama. Tools dan ERP membaca data dari **database yang sama**.

---

## 2. Alur Konfigurasi DB

```
config-db.php (root project)
        │
        ├──► _shared/db.php → rmi_db_config()
        │         │
        │         └──► rmi_db_pdo() → PDO connection
        │
        ├──► config.php → $DB_HOST, $DB_NAME, dll (legacy mysqli)
        │
        └──► master/auth.php → db_pdo() (via config.php atau rmi_db_config)
```

**Prioritas rmi_db_config():**
1. `config-db.php` (atau `config-db.example.php` jika tidak ada)
2. ENV: ERP_DB_*, DB_*
3. Constants & $GLOBALS

---

## 3. Path Project Root

Config dibaca berdasarkan **lokasi file**, bukan `getcwd()`:

```php
// _shared/db.php
$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$cfgFile = $root . '/config-db.php';
```

- **Web (NAS):** `__DIR__` = `/volume4/web/ERP_RMI_SOFULL/_shared` → root = `/volume4/web/ERP_RMI_SOFULL`
- **CLI (NAS):** sama, karena path file tidak bergantung pada cwd
- **Mac (SMB mount):** `__DIR__` = `/Volumes/web/ERP_RMI_SOFULL/_shared` → root = `/Volumes/web/ERP_RMI_SOFULL` (file sama dengan NAS jika share terhubung)

---

## 4. Tools yang Memakai DB

Semua tools berikut memakai `rmi_db_pdo()` atau `rmi_db_config()`:

| Tool | Fungsi DB |
|------|-----------|
| smoke_http.php | Validasi koneksi, seed user |
| backup_*.php, backup_now.sh | Backup DB |
| restore_*.php | Restore DB |
| migration/migrate.php | Jalankan migration |
| diag_db.php | Diagnostik koneksi |
| health.php, health_check_cli.php | Health check |
| tools_state_lib.php | State tools |
| qa/run_cutover_checks.php | Cutover validation |
| api/v1/partner/*.php | API H2H |

Tidak ada tool yang memakai config terpisah atau hardcoded DB.

---

## 5. Verifikasi

### 5.1 Script Verifikasi

Jalankan dari **NAS** (wajib untuk CLI):

```bash
cd /volume4/web/ERP_RMI_SOFULL
php tools/dev/db_diag.php
```

**Expected:** `{"ok":true,"host":"127.0.0.1:3306","db":"erp_rmi_sofull","message":"ok"}`

### 5.2 Verifikasi Web vs Tools

1. **Web:** Buka `https://10.10.60.20/ERP_RMI_SOFULL/tools/diag_db.php` (login admin)
2. **CLI:** `php tools/dev/db_diag.php`
3. Bandingkan output: `host`, `port`, `name` harus sama

### 5.3 Verifikasi Data Real

```sql
-- Cek tabel yang dipakai ERP
SELECT COUNT(*) FROM master_customers;
SELECT COUNT(*) FROM sales_do;
SELECT MAX(id) FROM sales_do;
```

Jalankan via phpMyAdmin atau:

```bash
php -r "
require 'config.php';
\$r = \$conn->query('SELECT COUNT(*) as n FROM sales_do')->fetch_assoc();
echo 'sales_do count: ' . \$r['n'] . PHP_EOL;
"
```

---

## 6. Potensi Risiko

| Risiko | Mitigasi |
|--------|----------|
| **Jalankan tools dari folder lokal** (bukan NAS) | Rules: CLI wajib di NAS. `tools/qa/volumes_guard.php` cek path. |
| **config-db.php berbeda** di deploy vs dev | config-db.php tidak ikut git; pastikan di NAS sesuai. |
| **.env override** | rmi_db_config() prioritaskan config-db.php di atas .env. |

---

## 7. Checklist Audit

- [x] Semua komponen pakai config-db.php
- [x] Path root dari __DIR__, bukan getcwd()
- [x] Tidak ada hardcoded DB host/name di tools
- [ ] Verifikasi manual: jalankan db_diag dari web dan CLI, bandingkan output
- [ ] Verifikasi data: cek sales_do / master_customers count konsisten

---

## 8. Referensi

- `_shared/db.php` — rmi_db_config(), rmi_db_pdo()
- `config.php` — $DB_* untuk mysqli
- `master/auth.php` — db_pdo()
- `.cursor/rules/volume4-paths.mdc` — Path NAS vs Mac
