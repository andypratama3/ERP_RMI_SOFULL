-- 092_chat_presence_thread_prefs.sql
-- Chat advanced UX controls: thread/presence/prefs/pin-policy/custom-emoji
-- Idempotent migration.

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'chat_channels' AND column_name = 'pin_policy'),
    'SELECT 1',
    'ALTER TABLE chat_channels ADD COLUMN pin_policy VARCHAR(20) NOT NULL DEFAULT ''ADMIN_ONLY'' AFTER is_private'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `chat_presence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ONLINE',
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_presence_user` (`user_id`),
  KEY `idx_chat_presence_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_user_channel_prefs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `channel_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT NOT NULL,
  `is_muted` TINYINT(1) NOT NULL DEFAULT 0,
  `notify_level` VARCHAR(20) NOT NULL DEFAULT 'ALL',
  `mute_until` DATETIME NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_user_channel_pref` (`channel_id`, `user_id`),
  KEY `idx_chat_pref_user` (`user_id`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_custom_emojis` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `emoji_code` VARCHAR(60) NOT NULL,
  `emoji_char` VARCHAR(16) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` BIGINT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_chat_custom_emoji_code` (`emoji_code`),
  KEY `idx_chat_custom_emoji_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `chat_config` (`config_key`, `config_value`)
SELECT 'default_pin_policy', 'ADMIN_ONLY'
WHERE NOT EXISTS (SELECT 1 FROM `chat_config` WHERE `config_key`='default_pin_policy');

UPDATE chat_channels
SET pin_policy = 'ADMIN_ONLY'
WHERE pin_policy IS NULL OR pin_policy = '';

