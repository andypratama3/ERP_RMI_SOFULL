-- 099_sales_tracking_sync_controls.sql
-- Add sync/rate-limit controls for sales tracking

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'SALES_TRACKING', 'BITESHIP_TIMEOUT_SECONDS', '8', NULL, 'Timeout per request to Biteship API in seconds', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM system_config
  WHERE config_group='SALES_TRACKING' AND config_key='BITESHIP_TIMEOUT_SECONDS' AND office_code IS NULL
);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'SALES_TRACKING', 'CRON_SYNC_LIMIT', '50', NULL, 'Default row limit per scheduled sync run', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM system_config
  WHERE config_group='SALES_TRACKING' AND config_key='CRON_SYNC_LIMIT' AND office_code IS NULL
);

INSERT INTO api_rate_limit_policies (scope_key, window_seconds, max_hits, is_active, created_at, updated_at)
SELECT 'SALES_TRACKING_SYNC', 60, 30, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM api_rate_limit_policies WHERE scope_key='SALES_TRACKING_SYNC'
);

INSERT INTO api_rate_limit_policies (scope_key, window_seconds, max_hits, is_active, created_at, updated_at)
SELECT 'TRACKING_PUBLIC_VIEW', 60, 45, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM api_rate_limit_policies WHERE scope_key='TRACKING_PUBLIC_VIEW'
);

