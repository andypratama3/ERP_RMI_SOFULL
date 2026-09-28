-- 091_chat_collab_features.sql
-- Internal Chat advanced collaboration features (P3-ish, safe incremental)
-- Idempotent migration.

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND column_name = 'reply_to_message_id'),
    'SELECT 1',
    'ALTER TABLE chat_messages ADD COLUMN reply_to_message_id BIGINT NULL AFTER sender_username'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'idx_chat_messages_reply_to'),
    'SELECT 1',
    'ALTER TABLE chat_messages ADD INDEX idx_chat_messages_reply_to (reply_to_message_id)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chat_channel_members' AND column_name = 'last_read_at'),
    'SELECT 1',
    'ALTER TABLE chat_channel_members ADD COLUMN last_read_at DATETIME NULL AFTER last_read_message_id'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `chat_message_reactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT NOT NULL,
  `emoji` VARCHAR(16) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_reactions` (`message_id`, `user_id`, `emoji`),
  KEY `idx_chat_reactions_message` (`message_id`),
  KEY `idx_chat_reactions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_typing_status` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_typing` (`channel_id`, `user_id`),
  KEY `idx_chat_typing_recent` (`channel_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_pins` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_id` BIGINT UNSIGNED NOT NULL,
  `message_id` BIGINT UNSIGNED NOT NULL,
  `pinned_by` BIGINT NOT NULL,
  `pinned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `unpin_by` BIGINT NULL,
  `unpin_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_pin_active` (`channel_id`, `message_id`, `is_active`),
  KEY `idx_chat_pins_channel_active` (`channel_id`, `is_active`, `pinned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'allow_reactions', '1'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='allow_reactions');

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'allow_typing_indicator', '1'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='allow_typing_indicator');

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'allow_message_pin', '1'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='allow_message_pin');

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'allow_dm_read_receipt', '1'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='allow_dm_read_receipt');

