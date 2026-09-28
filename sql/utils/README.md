# SQL Utils — Script Utility One-Off

Script untuk maintenance/repair. **Jalankan manual** di phpMyAdmin atau CLI.

| File | Fungsi |
|------|--------|
| `drop_system_users.sql` | Hapus tabel system_users (legacy) |
| `restore_admin_access.sql` | Restore role admin/superadmin di master_system_login |

```bash
mysql -u root -p erp_rmi_sofull < sql/utils/restore_admin_access.sql
```
