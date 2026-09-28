-- 096_chat_enterprise.sql
-- Locked enterprise chat schema for ERP_RMI_SOFULL (idempotent).

CREATE TABLE IF NOT EXISTS `chat_channels` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `channel_key` VARCHAR(120) UNIQUE,
  `channel_type` ENUM('PUBLIC','PRIVATE','DM','SYSTEM','DOC_CONTEXT') NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `description` TEXT NULL,
  `office_code` VARCHAR(20) NULL,
  `dept_code` VARCHAR(20) NULL,
  `context_entity_type` VARCHAR(20) NULL,
  `context_entity_id` BIGINT NULL,
  `created_by` VARCHAR(50) NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `retention_mode` ENUM('none','purge') DEFAULT 'none',
  `retention_days` INT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat_channels_type` (`channel_type`),
  KEY `idx_chat_channels_ctx` (`context_entity_type`, `context_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_channel_members` (
  `channel_id` BIGINT NOT NULL,
  `user_id` INT NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `joined_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_read_message_id` BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (`channel_id`, `user_id`),
  KEY `idx_chat_members_user` (`user_id`),
  KEY `idx_chat_members_channel_read` (`channel_id`, `last_read_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_user_channel_settings` (
  `channel_id` BIGINT NOT NULL,
  `user_id` INT NOT NULL,
  `muted_until` DATETIME NULL,
  PRIMARY KEY (`channel_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `channel_id` BIGINT NOT NULL,
  `sender_user_id` INT NULL,
  `sender_username` VARCHAR(50) NULL,
  `message_text` TEXT NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `has_attachments` TINYINT(1) DEFAULT 0,
  `has_mentions` TINYINT(1) DEFAULT 0,
  `is_deleted` TINYINT(1) DEFAULT 0,
  `deleted_at` DATETIME NULL,
  `deleted_by` VARCHAR(50) NULL,
  `deleted_reason` VARCHAR(255) NULL,
  `idempotency_key` VARCHAR(64) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_idem` (`channel_id`, `sender_user_id`, `idempotency_key`),
  KEY `idx_chat_messages_channel_id` (`channel_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_message_mentions` (
  `message_id` BIGINT NOT NULL,
  `mentioned_user_id` INT NOT NULL,
  `mentioned_username` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`message_id`, `mentioned_user_id`),
  KEY `idx_chat_message_mentions_user` (`mentioned_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_attachments` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `message_id` BIGINT NOT NULL,
  `channel_id` BIGINT NOT NULL,
  `uploader_user_id` INT NULL,
  `original_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `storage_path` VARCHAR(255) NOT NULL,
  `mime` VARCHAR(120) NOT NULL,
  `size_bytes` BIGINT NOT NULL,
  `sha256` VARCHAR(64) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chat_attachments_message` (`message_id`),
  KEY `idx_chat_attachments_channel` (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_user_presence` (
  `user_id` INT PRIMARY KEY,
  `last_seen_at` DATETIME NOT NULL,
  `last_ip` VARCHAR(64) NULL,
  `last_user_agent` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_exports` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `channel_id` BIGINT NOT NULL,
  `requested_by` VARCHAR(50) NOT NULL,
  `requested_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `range_start` DATETIME NOT NULL,
  `range_end` DATETIME NOT NULL,
  `format` ENUM('CSV','JSON') NOT NULL DEFAULT 'CSV',
  `status` ENUM('READY','FAILED') NOT NULL DEFAULT 'READY',
  `file_path` VARCHAR(255) NOT NULL,
  `sha256` VARCHAR(64) NOT NULL,
  `row_count` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_chat_exports_channel` (`channel_id`, `requested_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_acl` (
  `channel_id` BIGINT NOT NULL,
  `subject_type` ENUM('LEVEL','DEPT','OFFICE','USER') NOT NULL,
  `subject_key` VARCHAR(80) NOT NULL,
  `perm_read` TINYINT(1) NOT NULL DEFAULT 1,
  `perm_send` TINYINT(1) NOT NULL DEFAULT 1,
  `perm_pin` TINYINT(1) NOT NULL DEFAULT 0,
  `perm_manage` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`channel_id`, `subject_type`, `subject_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @__has_msg_ft := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'ft_chat_messages_text'
);
SET @__ddl_msg_ft := IF(@__has_msg_ft > 0, 'SELECT 1', 'ALTER TABLE chat_messages ADD FULLTEXT INDEX ft_chat_messages_text (message_text)');
PREPARE __stmt_ft FROM @__ddl_msg_ft; EXECUTE __stmt_ft; DEALLOCATE PREPARE __stmt_ft;

SET @__has_msg_created := (
  SELECT COUNT(*) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND index_name = 'idx_chat_messages_created'
);
SET @__ddl_msg_created := IF(@__has_msg_created > 0, 'SELECT 1', 'ALTER TABLE chat_messages ADD INDEX idx_chat_messages_created (channel_id, created_at)');
PREPARE __stmt_idx FROM @__ddl_msg_created; EXECUTE __stmt_idx; DEALLOCATE PREPARE __stmt_idx;

-- Backfill/align legacy tables with locked columns.
SET @__has_channel_key := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='channel_key');
SET @__ddl_channel_key := IF(@__has_channel_key > 0, 'SELECT 1', 'ALTER TABLE chat_channels ADD COLUMN channel_key VARCHAR(120) NULL');
PREPARE __stmt_ck FROM @__ddl_channel_key; EXECUTE __stmt_ck; DEALLOCATE PREPARE __stmt_ck;

SET @__has_channel_type := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='channel_type');
SET @__ddl_channel_type := IF(@__has_channel_type > 0, 'SELECT 1', 'ALTER TABLE chat_channels ADD COLUMN channel_type ENUM(''PUBLIC'',''PRIVATE'',''DM'',''SYSTEM'',''DOC_CONTEXT'') NULL');
PREPARE __stmt_ct FROM @__ddl_channel_type; EXECUTE __stmt_ct; DEALLOCATE PREPARE __stmt_ct;

UPDATE chat_channels
SET channel_type = CASE
  WHEN UPPER(COALESCE(type,''))='DM' THEN 'DM'
  WHEN COALESCE(is_private,0)=1 THEN 'PRIVATE'
  ELSE 'PUBLIC'
END
WHERE channel_type IS NULL;

SET @__has_members_username := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channel_members' AND column_name='username');
SET @__ddl_members_username := IF(@__has_members_username > 0, 'SELECT 1', 'ALTER TABLE chat_channel_members ADD COLUMN username VARCHAR(50) NULL');
PREPARE __stmt_mu FROM @__ddl_members_username; EXECUTE __stmt_mu; DEALLOCATE PREPARE __stmt_mu;

UPDATE chat_channel_members cm
LEFT JOIN master_system_login u ON u.id = cm.user_id
SET cm.username = COALESCE(cm.username, u.username, CONCAT('user', cm.user_id))
WHERE cm.username IS NULL OR cm.username='';

SET @__has_sender_user_id := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_messages' AND column_name='sender_user_id');
SET @__ddl_sender_user_id := IF(@__has_sender_user_id > 0, 'SELECT 1', 'ALTER TABLE chat_messages ADD COLUMN sender_user_id INT NULL');
PREPARE __stmt_su FROM @__ddl_sender_user_id; EXECUTE __stmt_su; DEALLOCATE PREPARE __stmt_su;

UPDATE chat_messages SET sender_user_id = COALESCE(sender_user_id, user_id) WHERE sender_user_id IS NULL;

SET @__has_idemp := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_messages' AND column_name='idempotency_key');
SET @__ddl_idemp := IF(@__has_idemp > 0, 'SELECT 1', 'ALTER TABLE chat_messages ADD COLUMN idempotency_key VARCHAR(64) NULL');
PREPARE __stmt_idm FROM @__ddl_idemp; EXECUTE __stmt_idm; DEALLOCATE PREPARE __stmt_idm;

SET @__has_has_att := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_messages' AND column_name='has_attachments');
SET @__ddl_has_att := IF(@__has_has_att > 0, 'SELECT 1', 'ALTER TABLE chat_messages ADD COLUMN has_attachments TINYINT(1) NOT NULL DEFAULT 0');
PREPARE __stmt_ha FROM @__ddl_has_att; EXECUTE __stmt_ha; DEALLOCATE PREPARE __stmt_ha;

SET @__has_has_men := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_messages' AND column_name='has_mentions');
SET @__ddl_has_men := IF(@__has_has_men > 0, 'SELECT 1', 'ALTER TABLE chat_messages ADD COLUMN has_mentions TINYINT(1) NOT NULL DEFAULT 0');
PREPARE __stmt_hm FROM @__ddl_has_men; EXECUTE __stmt_hm; DEALLOCATE PREPARE __stmt_hm;

SET @__has_att_channel := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_attachments' AND column_name='channel_id');
SET @__ddl_att_channel := IF(@__has_att_channel > 0, 'SELECT 1', 'ALTER TABLE chat_attachments ADD COLUMN channel_id BIGINT NULL');
PREPARE __stmt_ac FROM @__ddl_att_channel; EXECUTE __stmt_ac; DEALLOCATE PREPARE __stmt_ac;

UPDATE chat_attachments a
JOIN chat_messages m ON m.id=a.message_id
SET a.channel_id = m.channel_id
WHERE a.channel_id IS NULL;

