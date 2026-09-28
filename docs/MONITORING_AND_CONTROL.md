# Monitoring & Controlling — ERP_RMI_SOFULL

Panduan singkat: **ke mana melihat apa** agar kontrol dan monitoring operasional lebih cepat.

## Pusat di browser (UI)

| Kebutuhan | Lokasi |
|-----------|--------|
| **Hub ringkas** (statistik audit + file log + pintasan) | `/master/monitoring_center.php` |
| **Audit terpusat (filter penuh)** | `/master/audit_logs.php` → tabel `system_audit_logs` |
| **Scan keamanan / coverage (static)** | `/tools/enterprise_audit.php` (perlu permission Tools) |
| **O2C — status DO** | `/sales/sales_control_tower.php` |
| **Import / P2P** | `/purchases/purchases_import_control_tower.php` |
| **KPI & snapshot** | `/kpi/kpi_center.php` |
| **Ringkasan lintas modul** | `/dashboards/owner/exec_summary.php` |
| **Backup & jadwal** | `/tools/backup_schedule.php`, `/tools/backup_manager.php` |
| **Health check** | `/tools/health.php` |
| **ITC** | `/dashboards/itc/itc_dashboard.php` |

Panduan ini juga dibuka dari UI: **Monitoring & Control → Panduan** (`/docs/monitoring_guide.php`).

## Sumber data audit (database)

- **`system_audit_logs`** — tampilan utama di Audit Log; banyak modul dual-write ke sini.
- **`erp_audit_log`** — jejak ERP terstruktur (jika dipakai modul).
- **`kpi_audit_log`** — audit aksi KPI (jika tabel ada).

**Catatan:** Tidak semua klik/CRUD otomatis tercatat. Yang tercatat adalah titik yang memanggil `master_audit()`, `erp_audit()`, `kpi_audit()`, atau insert manual ke tabel di atas.

## File log (server)

- Folder: **`storage/logs/`**
- Modul yang memakai `rmi_log_module_error()` menulis `{module}_errors.log` dan `app-YYYY-MM-DD.log`.
- Cron/backup sering menulis log terpisah di folder yang sama.

Akses file: SSH ke NAS (`/volume4/web/ERP_RMI_SOFULL`) atau viewer di **Monitoring Center** (daftar file terbaru).

## Error global & redaksi

- Handler terpusat: `_shared/error_handler.php` (dimuat dari `master/auth.php` dan `_shared/bootstrap.php`).
- **Exception tidak tertangkap** → `rmi_log_module_error()` (pesan + konteks + **stack trace terredaksi**; tidak menyimpan raw API key format `rpk_…`).
- **PHP warning / notice** → `rmi_log_module_warn()` ke file log yang sama; **tidak** lagi mengirim body HTML "Internal Server Error" di tengah halaman untuk notice/warning.
- **`E_USER_ERROR` (trigger_error fatal)** → log ERROR lalu hentikan request (prod: pesan generik).
- Env opsional **`.env`**: `RMI_UNHANDLED_ERROR_AUDIT_DB=1` — selain file log, tulis satu baris ke **`system_audit_logs`** (modul `app_error`) untuk error yang lewat `rmi_log_module_error`. Default **off** agar tabel audit tidak penuh noise.

## Titik audit tambahan (contoh)

- **MFA:** enable / disable TOTP di `master/mfa_settings.php` → `master_audit` (`MFA_ENABLED` / `MFA_DISABLED`).
- **API Partner:** pembuatan key di `master/api_partner_keys.php` → `master_audit` `CREATE` (tanpa mencatat raw key di audit).

## CLI (NAS)

Jalankan dari `/volume4/web/ERP_RMI_SOFULL`:

- Smoke auth/runtime: `php tools/smoke_http.php`
- RBAC config ↔ DB: `php tools/rbac_diff_config_db.php`
- Final gate ringkas: lihat `storage/logs/final_gate_summary_last.md` (setelah menjalankan alur di `README.md`)

## RBAC

- Lihat / ubah siapa yang boleh **Audit Log**: permission **`SYSTEM.AUDIT_LOG_VIEW`** di RBAC Center.
- Halaman **Monitoring Center** memakai gate yang sama dengan Audit Log (plus backward-compat permission admin).

## Referensi tambahan

- Baselines & verifikasi deploy: `docs/BASELINES.md`
- KPI & SYS: `docs/KPI_SYS_GOVERNANCE.md`
- Review RBAC berkala: `docs/RBAC_PERIODIC_REVIEW.md`
