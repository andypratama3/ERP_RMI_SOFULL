-- 142_seed_manufacturer_portal_demo.sql
-- Demo user untuk testing Manufacturer Portal
-- Username: yaxin_demo | Password: password
-- Selalu insert user; manufacture_id diisi jika YAXIN ada.

INSERT INTO manufacturer_portal_users (username, password_hash, full_name, manufacture_code, manufacture_id, status)
SELECT 'yaxin_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'YAXIN Demo User', 'YAXIN',
  (SELECT id FROM master_manufactures WHERE manufacture_code = 'YAXIN' AND (deleted_at IS NULL OR deleted_at = '') LIMIT 1),
  'active'
ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name), manufacture_id = VALUES(manufacture_id), status = 'active';
