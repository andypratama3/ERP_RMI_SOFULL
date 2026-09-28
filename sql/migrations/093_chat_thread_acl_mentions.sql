-- 093_chat_thread_acl_mentions.sql
-- Chat nested thread + per-channel ACL + mention unread counter support
-- Idempotent migration.

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND column_name = 'thread_root_message_id'),
    'SELECT 1',
    'ALTER TABLE chat_messages ADD COLUMN thread_root_message_id BIGINT NULL AFTER reply_to_message_id'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'idx_chat_messages_thread_root'),
    'SELECT 1',
    'ALTER TABLE chat_messages ADD INDEX idx_chat_messages_thread_root (thread_root_message_id, id)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- best-effort backfill: root of root-message is itself
UPDATE chat_messages
SET thread_root_message_id = id
WHERE thread_root_message_id IS NULL AND (reply_to_message_id IS NULL OR reply_to_message_id = 0);

CREATE TABLE IF NOT EXISTS `chat_channel_acl` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_id` BIGINT UNSIGNED NOT NULL,
  `role_code` VARCHAR(40) NOT NULL,
  `can_read` TINYINT(1) NOT NULL DEFAULT 1,
  `can_send` TINYINT(1) NOT NULL DEFAULT 1,
  `can_pin` TINYINT(1) NOT NULL DEFAULT 0,
  `can_manage` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` BIGINT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_acl_channel_role` (`channel_id`, `role_code`),
  KEY `idx_chat_acl_channel` (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'enable_channel_acl', '1'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='enable_channel_acl');

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'mention_badge_enabled', '1'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='mention_badge_enabled');

