-- 095_chat_enterprise_upgrade.sql
-- Enterprise chat upgrade: readiness, compliance, search, quotas, exports, idempotency, ERP context.
-- Idempotent migration (safe rerun).

-- Presence source-of-truth (batch status + online window)
CREATE TABLE IF NOT EXISTS `chat_user_presence` (
  `user_id` INT NOT NULL,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_ip_masked` VARCHAR(64) NULL,
  `last_user_agent` VARCHAR(255) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_chat_user_presence_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per user-channel settings (mute / notify). Keep old prefs table for backward compatibility.
CREATE TABLE IF NOT EXISTS `chat_user_channel_settings` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `channel_id` BIGINT NOT NULL,
  `muted_until` DATETIME NULL,
  `notification_level` VARCHAR(20) NOT NULL DEFAULT 'ALL',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_user_channel_settings` (`user_id`,`channel_id`),
  KEY `idx_chat_user_channel_settings_mute` (`muted_until`),
  KEY `idx_chat_user_channel_settings_channel` (`channel_id`,`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Export job metadata
CREATE TABLE IF NOT EXISTS `chat_exports` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `channel_id` BIGINT NOT NULL,
  `requested_by` INT NULL,
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `range_start` DATETIME NOT NULL,
  `range_end` DATETIME NOT NULL,
  `format` VARCHAR(10) NOT NULL DEFAULT 'csv',
  `status` VARCHAR(20) NOT NULL DEFAULT 'READY',
  `file_path` VARCHAR(500) NULL,
  `sha256` VARCHAR(64) NULL,
  `row_count` INT NOT NULL DEFAULT 0,
  `meta_json` LONGTEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_exports_channel` (`channel_id`,`requested_at`),
  KEY `idx_chat_exports_status` (`status`,`requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attachment quota config (single-row logical config)
CREATE TABLE IF NOT EXISTS `chat_quota_config` (
  `id` TINYINT NOT NULL DEFAULT 1,
  `max_attachment_mb_per_user_per_day` INT NOT NULL DEFAULT 100,
  `max_attachment_mb_per_channel_per_day` INT NOT NULL DEFAULT 500,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `chat_quota_config` (`id`,`max_attachment_mb_per_user_per_day`,`max_attachment_mb_per_channel_per_day`)
SELECT 1, 100, 500
WHERE NOT EXISTS (SELECT 1 FROM `chat_quota_config` WHERE `id`=1);

-- Quota usage tracker
CREATE TABLE IF NOT EXISTS `chat_quota_usage` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `usage_date` DATE NOT NULL,
  `user_id` INT NOT NULL,
  `channel_id` BIGINT NOT NULL,
  `used_bytes` BIGINT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_quota_usage` (`usage_date`,`user_id`,`channel_id`),
  KEY `idx_chat_quota_usage_channel` (`usage_date`,`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Idempotency for send message
CREATE TABLE IF NOT EXISTS `chat_message_idempotency` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `channel_id` BIGINT NOT NULL,
  `idempotency_key` VARCHAR(80) NOT NULL,
  `message_id` BIGINT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_msg_idemp` (`user_id`,`channel_id`,`idempotency_key`),
  KEY `idx_chat_msg_idemp_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ERP context mapping
CREATE TABLE IF NOT EXISTS `chat_message_context` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL,
  `entity_id` VARCHAR(80) NULL,
  `entity_code` VARCHAR(120) NULL,
  `entity_url` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_message_context_message` (`message_id`),
  KEY `idx_chat_message_context_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Granular ACL subjects (role/dept/user/office)
SET @__has_subject_type := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'chat_channel_acl' AND column_name = 'subject_type'
);
SET @__ddl_subject_type := IF(@__has_subject_type > 0, 'SELECT 1', 'ALTER TABLE chat_channel_acl ADD COLUMN subject_type VARCHAR(20) NOT NULL DEFAULT ''ROLE'' AFTER channel_id');
PREPARE __stmt1 FROM @__ddl_subject_type; EXECUTE __stmt1; DEALLOCATE PREPARE __stmt1;

SET @__has_subject_key := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'chat_channel_acl' AND column_name = 'subject_key'
);
SET @__ddl_subject_key := IF(@__has_subject_key > 0, 'SELECT 1', 'ALTER TABLE chat_channel_acl ADD COLUMN subject_key VARCHAR(120) NOT NULL DEFAULT '''' AFTER subject_type');
PREPARE __stmt2 FROM @__ddl_subject_key; EXECUTE __stmt2; DEALLOCATE PREPARE __stmt2;

SET @__has_role_code := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'chat_channel_acl' AND column_name = 'role_code'
);
SET @__ddl_role_code := IF(@__has_role_code > 0, 'SELECT 1', 'ALTER TABLE chat_channel_acl ADD COLUMN role_code VARCHAR(50) NULL AFTER subject_key');
PREPARE __stmt3 FROM @__ddl_role_code; EXECUTE __stmt3; DEALLOCATE PREPARE __stmt3;

SET @__has_acl_unique := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'chat_channel_acl' AND index_name = 'uq_chat_acl_subject'
);
SET @__ddl_acl_unique := IF(@__has_acl_unique > 0, 'SELECT 1', 'ALTER TABLE chat_channel_acl ADD UNIQUE KEY uq_chat_acl_subject (channel_id, subject_type, subject_key)');
PREPARE __stmt4 FROM @__ddl_acl_unique; EXECUTE __stmt4; DEALLOCATE PREPARE __stmt4;

-- Channel retention policy per channel
SET @__has_retention_days := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'chat_channels' AND column_name = 'retention_days'
);
SET @__ddl_retention_days := IF(@__has_retention_days > 0, 'SELECT 1', 'ALTER TABLE chat_channels ADD COLUMN retention_days INT NULL AFTER pin_policy');
PREPARE __stmt5 FROM @__ddl_retention_days; EXECUTE __stmt5; DEALLOCATE PREPARE __stmt5;

SET @__has_retention_mode := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'chat_channels' AND column_name = 'retention_mode'
);
SET @__ddl_retention_mode := IF(@__has_retention_mode > 0, 'SELECT 1', 'ALTER TABLE chat_channels ADD COLUMN retention_mode VARCHAR(20) NOT NULL DEFAULT ''none'' AFTER retention_days');
PREPARE __stmt6 FROM @__ddl_retention_mode; EXECUTE __stmt6; DEALLOCATE PREPARE __stmt6;

-- Compliance delete reason (explicit field, keep old delete_reason compatibility)
SET @__has_deleted_reason := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND column_name = 'deleted_reason'
);
SET @__ddl_deleted_reason := IF(@__has_deleted_reason > 0, 'SELECT 1', 'ALTER TABLE chat_messages ADD COLUMN deleted_reason VARCHAR(255) NULL AFTER delete_reason');
PREPARE __stmt7 FROM @__ddl_deleted_reason; EXECUTE __stmt7; DEALLOCATE PREPARE __stmt7;

-- Helpful indexes
SET @__has_idx_msg_channel_created := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'idx_chat_messages_channel_created'
);
SET @__ddl_idx_msg_channel_created := IF(@__has_idx_msg_channel_created > 0, 'SELECT 1', 'ALTER TABLE chat_messages ADD INDEX idx_chat_messages_channel_created (channel_id, created_at, id)');
PREPARE __stmt8 FROM @__ddl_idx_msg_channel_created; EXECUTE __stmt8; DEALLOCATE PREPARE __stmt8;

SET @__has_ft_msg_text := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'ft_chat_messages_text'
);
SET @__ddl_ft_msg_text := IF(@__has_ft_msg_text > 0, 'SELECT 1', 'ALTER TABLE chat_messages ADD FULLTEXT INDEX ft_chat_messages_text (message_text)');
PREPARE __stmt9 FROM @__ddl_ft_msg_text; EXECUTE __stmt9; DEALLOCATE PREPARE __stmt9;

-- Seed chat config knobs used by enterprise features
INSERT INTO `chat_config` (`config_key`,`config_value`)
SELECT 'presence_online_window_seconds', '90'
WHERE NOT EXISTS (SELECT 1 FROM chat_config WHERE config_key='presence_online_window_seconds');

INSERT INTO `chat_config` (`config_key`,`config_value`)
SELECT 'enable_long_poll', '1'
WHERE NOT EXISTS (SELECT 1 FROM chat_config WHERE config_key='enable_long_poll');

INSERT INTO `chat_config` (`config_key`,`config_value`)
SELECT 'enable_message_idempotency', '1'
WHERE NOT EXISTS (SELECT 1 FROM chat_config WHERE config_key='enable_message_idempotency');

INSERT INTO `chat_config` (`config_key`,`config_value`)
SELECT 'export_default_format', 'csv'
WHERE NOT EXISTS (SELECT 1 FROM chat_config WHERE config_key='export_default_format');
