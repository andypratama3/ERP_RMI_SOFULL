# Review berkala RBAC & gate SYS

Checklist operasional (mis. bulanan atau sebelum rilis besar):

1. **Sinkron config ↔ database**
   ```bash
   cd /volume4/web/ERP_RMI_SOFULL   # atau path deploy NAS
   php tools/rbac_diff_config_db.php
   ```
   Target: **Config dan DB sudah sinkron** (hitungan permission sama, tidak ada daftar “Di DB tapi TIDAK di config”).  
   Permission lama dari migration disalin ke `config/rbac_permissions.php` di blok **DB MIRROR (legacy / migration)** — jangan hapus baris DB tanpa migrasi modul.

2. **Cek kelengkapan VIEW**
   ```bash
   php tools/qa/rbac_coverage_check.php
   ```

3. **KPI / kebijakan**  
   Pastikan user uji **non-SYS** dengan `KPI.VIEW` tidak bisa POST impor/simpan (hanya lihat + export). Mutasi = **level SYS** di session, bukan `KPI.EDIT` saja.

4. **Gate terpusat**  
   Untuk fitur baru “hanya admin sistem”: pakai `rmi_is_sys_session()` atau `auth_is_sys()` dari `_shared/rmi_sys_gate.php`, jangan menambah pola `if ($role === '...')` baru di banyak file.

5. **Audit trail**  
   Untuk perubahan konfigurasi sensitif, pastikan ada log (`master_audit`, `kpi_audit`, `system_audit_logs`, dll.).
