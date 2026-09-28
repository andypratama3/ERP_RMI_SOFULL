-- 114_api_partner_keys_environment.sql
-- Tambah environment (production/development) untuk API Partner Keys
-- Production key: hanya valid di APP_ENV=production
-- Development key: valid di APP_ENV=local,development,staging (tidak di production)

ALTER TABLE api_partner_keys ADD COLUMN environment ENUM('production','development') NOT NULL DEFAULT 'production' AFTER partner_name;
ALTER TABLE api_partner_keys DROP INDEX uq_partner_name;
ALTER TABLE api_partner_keys ADD UNIQUE KEY uq_partner_env (partner_name, environment);
ALTER TABLE api_partner_keys ADD KEY idx_env (environment);
