# Post Deploy Checklist

1. Upload dan extract zip ini ke docroot server/hosting.
2. Set .env produksi di server (jangan copy dari local).
3. Pastikan folder writable: storage/ dan uploads/
   chmod -R 775 storage/ uploads/
4. Import database: sql/ERP_RMI_SOFULL.sql (fresh install)
   atau jalankan migrations dari sql/migrations/ (update)
5. Buka /tools/health.php untuk sanity check.
6. Jalankan smoke: php tools/smoke_http.php

Jika ada error: cek storage/logs/ untuk detail.

