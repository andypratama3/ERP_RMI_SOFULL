# ERP RMI SOFULL — aturan kerja agent

## Deploy lokal VPS (wajib)

Setiap selesai mengubah kode, config, atau aturan Nginx:

```bash
systemctl restart php8.3-fpm
nginx -t && systemctl reload nginx
```

Lalu verifikasi minimal:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://erp.andypratama.studio/master/login.php   # harus 200
```

Fokus repo ini saja: `/var/www/ERP_RMI_SOFULL`. Proyek lain di `/var/www`
(`app_sdmuhammadiyah3smd`, `sdmuhammadiyah3smd.com`, `html`) **tidak boleh**
disentuh.

## Rahasia

`/tmp/.erp_admin_pass` (login admin), `/tmp/.erp_db_pass` (password DB),
`.env`, `config-db.php` — tidak pernah di-commit.

## Migrasi

Setiap migration wajib **idempotent** (aman dijalankan ulang), karena
`run_full_suite.php` dan CI bisa mengeksekusinya berulang. Setelah apply ke DB,
**retest** halaman terkait, lalu catat evidence di
`tools/qa/ERP_MASTER_TASK_TRACKER.md` sesuai aturan tracker owner.

## Tracker

- `tools/qa/ERP_MASTER_TASK_TRACKER.md` — status manual + evidence (sumber
  kebenaran untuk status manusia).
- `tools/qa/ERP_FULL_TRACKER.md` — **generated**, jangan diedit manual.
  Di-regenerate: `php tools/qa/feature_inventory_scan.php --self-test --tracker`
- `tools/qa/HANDOFF_DATA_TABLES.md` — baca bagian "JANGAN" dulu sebelum
  menyentuh CSS/theme.
- `PENDING-05` (actor unlinked) BLOCKED — butuh keputusan owner, **dilarang
  menebak**.
- `tools/qa/audit_theme_contrast.py` **tidak boleh** dipakai sebagai gate:
  salah memasangkan background sehingga melaporkan ~35.703 false positive.
