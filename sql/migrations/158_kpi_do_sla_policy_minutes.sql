-- 158_kpi_do_sla_policy_minutes.sql
-- Seed kebijakan SLA KPI DO dalam MENIT (group KPI_DO_SLA).
-- Hanya di-insert jika belum ada baris dengan config_key yang sama (unique config_key + office_code).

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'KPI_DO_SLA', 'SLA_WQS_MINUTES', '240', NULL, 'KPI DO SLA WQS (menit) — kebijakan global, diatur SYS.', 1, NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM system_config WHERE config_key = 'SLA_WQS_MINUTES' AND office_code IS NULL);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'KPI_DO_SLA', 'SLA_SCM_MINUTES', '1440', NULL, 'KPI DO SLA SCM (menit) — kebijakan global, diatur SYS.', 1, NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM system_config WHERE config_key = 'SLA_SCM_MINUTES' AND office_code IS NULL);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'KPI_DO_SLA', 'SLA_ACT_MINUTES', '2880', NULL, 'KPI DO SLA ACT (menit) — kebijakan global, diatur SYS.', 1, NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM system_config WHERE config_key = 'SLA_ACT_MINUTES' AND office_code IS NULL);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'KPI_DO_SLA', 'SLA_FIN_MINUTES', '10080', NULL, 'KPI DO SLA FIN (menit) — kebijakan global, diatur SYS.', 1, NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM system_config WHERE config_key = 'SLA_FIN_MINUTES' AND office_code IS NULL);
