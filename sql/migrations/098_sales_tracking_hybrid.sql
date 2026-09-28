-- 098_sales_tracking_hybrid.sql
-- Hybrid tracking for Sales SCM:
-- - provider metadata on sales_do
-- - public tokenized link
-- - tracking event timeline table
-- - baseline system_config keys

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'carrier_provider'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN carrier_provider VARCHAR(30) NULL AFTER tracking_code'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'carrier_tracking_no'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN carrier_tracking_no VARCHAR(100) NULL AFTER carrier_provider'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'carrier_courier_code'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN carrier_courier_code VARCHAR(50) NULL AFTER carrier_tracking_no'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'tracking_public_token'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN tracking_public_token VARCHAR(80) NULL AFTER carrier_courier_code'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'tracking_last_sync_at'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN tracking_last_sync_at DATETIME NULL AFTER tracking_public_token'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'tracking_last_status'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN tracking_last_status VARCHAR(100) NULL AFTER tracking_last_sync_at'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'tracking_last_payload_json'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN tracking_last_payload_json LONGTEXT NULL AFTER tracking_last_status'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND column_name = 'fallback_live_location_url'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD COLUMN fallback_live_location_url VARCHAR(1000) NULL AFTER tracking_last_payload_json'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `sales_do_tracking_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `do_id` INT NOT NULL,
  `event_time` DATETIME NOT NULL,
  `provider_status` VARCHAR(100) NULL,
  `internal_status` VARCHAR(50) NULL,
  `description` VARCHAR(255) NULL,
  `location` VARCHAR(255) NULL,
  `raw_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sdt_do_time` (`do_id`, `event_time`),
  KEY `idx_sdt_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @idx := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND index_name = 'idx_sales_do_carrier_tracking'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD INDEX idx_sales_do_carrier_tracking (carrier_tracking_no)'
  )
);
PREPARE stmt FROM @idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'sales_do' AND index_name = 'uq_sales_do_tracking_public_token'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD UNIQUE KEY uq_sales_do_tracking_public_token (tracking_public_token)'
  )
);
PREPARE stmt FROM @idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'SALES_TRACKING', 'PROVIDER_DEFAULT', 'BITESHIP', NULL, 'Default provider tracking SCM', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM system_config
  WHERE config_group='SALES_TRACKING' AND config_key='PROVIDER_DEFAULT' AND office_code IS NULL
);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'SALES_TRACKING', 'BITESHIP_TRACKING_ENDPOINT', '', NULL, 'Endpoint template. Use {tracking_no} and optional {courier_code}.', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM system_config
  WHERE config_group='SALES_TRACKING' AND config_key='BITESHIP_TRACKING_ENDPOINT' AND office_code IS NULL
);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'SALES_TRACKING', 'BITESHIP_API_KEY', '', NULL, 'Optional API key fallback (prefer ENV BITESHIP_API_KEY).', 0, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM system_config
  WHERE config_group='SALES_TRACKING' AND config_key='BITESHIP_API_KEY' AND office_code IS NULL
);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'SALES_TRACKING', 'ONLINE_SYNC_ENABLED', '0', NULL, '1=enable scheduled sync to provider', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM system_config
  WHERE config_group='SALES_TRACKING' AND config_key='ONLINE_SYNC_ENABLED' AND office_code IS NULL
);

INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'SALES_TRACKING', 'PUBLIC_BASE_URL', '', NULL, 'Optional public base URL override for customer tracking link', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM system_config
  WHERE config_group='SALES_TRACKING' AND config_key='PUBLIC_BASE_URL' AND office_code IS NULL
);
