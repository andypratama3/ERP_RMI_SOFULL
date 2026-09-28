-- 090_internal_chat_module.sql
-- Internal Chat module (P0)
-- Idempotent migration.

CREATE TABLE IF NOT EXISTS `chat_channels` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('CHANNEL','DM') NOT NULL DEFAULT 'CHANNEL',
  `name` VARCHAR(120) NULL,
  `created_by` BIGINT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_private` TINYINT(1) NOT NULL DEFAULT 0,
  `dm_user_low` BIGINT NULL,
  `dm_user_high` BIGINT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_channels_type` (`type`),
  KEY `idx_chat_channels_private` (`is_private`),
  KEY `idx_chat_channels_created` (`created_at`),
  UNIQUE KEY `uq_chat_channels_dm_pair` (`type`, `dm_user_low`, `dm_user_high`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_channel_members` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT NOT NULL,
  `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_read_message_id` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_channel_members` (`channel_id`, `user_id`),
  KEY `idx_chat_member_user_channel` (`user_id`, `channel_id`),
  KEY `idx_chat_member_joined` (`joined_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT NOT NULL,
  `sender_username` VARCHAR(120) NULL,
  `message_text` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `edited_at` DATETIME NULL,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `deleted_at` DATETIME NULL,
  `deleted_by` BIGINT NULL,
  `delete_reason` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_messages_channel_id` (`channel_id`, `id`),
  KEY `idx_chat_messages_created` (`created_at`),
  KEY `idx_chat_messages_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_mentions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `mentioned_user_id` BIGINT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_mentions_user_message` (`mentioned_user_id`, `message_id`),
  KEY `idx_chat_mentions_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `original_filename` VARCHAR(255) NOT NULL,
  `stored_filename` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(120) NOT NULL,
  `size_bytes` BIGINT NOT NULL DEFAULT 0,
  `sha256` CHAR(64) NOT NULL,
  `storage_path` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_attachments_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_config` (
  `config_key` VARCHAR(100) NOT NULL,
  `config_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_rate_limits` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NULL,
  `ip_address` VARCHAR(64) NOT NULL,
  `window_minute` VARCHAR(16) NOT NULL,
  `send_count` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_rate_window` (`user_id`, `ip_address`, `window_minute`),
  KEY `idx_chat_rate_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `chat_channels` (`type`, `name`, `created_by`, `is_private`)
SELECT 'CHANNEL', 'general', NULL, 0
WHERE NOT EXISTS (
  SELECT 1 FROM `chat_channels` WHERE `type`='CHANNEL' AND LOWER(COALESCE(`name`, ''))='general'
);

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'retention_mode', 'purge_soft_deleted'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='retention_mode');

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'purge_soft_deleted_after_days', '90'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='purge_soft_deleted_after_days');

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'attachment_purge_after_days', '180'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='attachment_purge_after_days');

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'max_attachment_size_mb', '10'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='max_attachment_size_mb');

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'allowed_attachment_mime', '["application/pdf","image/png","image/jpeg","application/vnd.openxmlformats-officedocument.wordprocessingml.document","application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"]'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='allowed_attachment_mime');

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND column_name = 'sender_username'),
    'SELECT 1',
    'ALTER TABLE chat_messages ADD COLUMN sender_username VARCHAR(120) NULL AFTER user_id'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chat_channel_members' AND column_name = 'last_read_message_id'),
    'SELECT 1',
    'ALTER TABLE chat_channel_members ADD COLUMN last_read_message_id BIGINT NOT NULL DEFAULT 0 AFTER joined_at'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'idx_chat_messages_user_created'),
    'SELECT 1',
    'ALTER TABLE chat_messages ADD INDEX idx_chat_messages_user_created (user_id, created_at)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'ft_chat_messages_text'),
    'SELECT 1',
    'ALTER TABLE chat_messages ADD FULLTEXT INDEX ft_chat_messages_text (message_text)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

