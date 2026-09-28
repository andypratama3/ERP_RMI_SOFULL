-- 138_seed_customer_portal_demo.sql
-- Demo user untuk testing Customer Portal
-- Username: hermina_demo | Password: password
-- Pastikan customers_code (Hoo1) dan office_code (BGR) ada di master.

INSERT IGNORE INTO customer_portal_users (username, password_hash, full_name, customers_code, office_code, status)
VALUES ('hermina_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Hermina Demo', 'Hoo1', 'BGR', 'active');
