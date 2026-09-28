FIXED_ASSET FINAL PATCH (combined)

Cara pakai:
1) Extract ZIP ini ke ROOT project ERP_RMI_SOFULL (folder yang berisi: master/, Fixed_Asset/, _shared/, dll).
2) Overwrite jika diminta.

Isi patch:
- Fixed_Asset: perbaikan auth/redirect basepath + enforcement require_login + RBAC guard di action write + perbaikan PDO SHOW TABLES LIKE (tanpa placeholder '?').
- master/auth.php: helper url_path/base path agar redirect login konsisten dari module apapun.

Catatan:
- Patch ini tidak mengubah DB schema.
- Patch ini aman untuk di-apply di atas repo yang sudah jalan.
