-- 104_chat_channels_legacy_compat.sql
-- Tambah kolom legacy (type, is_private, dm_user_low, dm_user_high) jika pakai skema 096
-- agar ChatService tetap berfungsi tanpa refactor besar.

-- type: alias untuk channel_type (CHANNEL/DM vs PUBLIC/PRIVATE/DM)
SET @has_type := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='type');
SET @has_channel_type := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='channel_type');
SET @ddl_type := IF(@has_type > 0, 'SELECT 1', 
  IF(@has_channel_type > 0, 
    "ALTER TABLE chat_channels ADD COLUMN type VARCHAR(20) NULL AFTER id",
    'SELECT 1'));
PREPARE stmt FROM @ddl_type; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Jika type baru ditambah, isi dari channel_type
UPDATE chat_channels SET type = CASE 
  WHEN channel_type IN ('PUBLIC','PRIVATE') THEN 'CHANNEL' 
  WHEN channel_type = 'DM' THEN 'DM' 
  ELSE 'CHANNEL' 
END WHERE type IS NULL AND channel_type IS NOT NULL;

-- is_private
SET @has_ip := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='is_private');
SET @ddl_ip := IF(@has_ip > 0, 'SELECT 1', 'ALTER TABLE chat_channels ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0');
PREPARE stmt FROM @ddl_ip; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE chat_channels SET is_private = CASE WHEN channel_type IN ('PRIVATE','DM') THEN 1 ELSE 0 END 
WHERE is_private = 0 AND channel_type IN ('PRIVATE','DM');

-- dm_user_low, dm_user_high (untuk DM: ambil dari anggota)
SET @has_dm_low := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='dm_user_low');
SET @ddl_dm_low := IF(@has_dm_low > 0, 'SELECT 1', 'ALTER TABLE chat_channels ADD COLUMN dm_user_low BIGINT NULL');
PREPARE stmt FROM @ddl_dm_low; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_dm_high := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='dm_user_high');
SET @ddl_dm_high := IF(@has_dm_high > 0, 'SELECT 1', 'ALTER TABLE chat_channels ADD COLUMN dm_user_high BIGINT NULL');
PREPARE stmt FROM @ddl_dm_high; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Isi dm_user_low/high dari chat_channel_members untuk channel DM
UPDATE chat_channels c
SET dm_user_low = (SELECT MIN(user_id) FROM chat_channel_members WHERE channel_id = c.id),
    dm_user_high = (SELECT MAX(user_id) FROM chat_channel_members WHERE channel_id = c.id)
WHERE (c.channel_type = 'DM' OR c.type = 'DM') AND (dm_user_low IS NULL OR dm_user_high IS NULL)
  AND (SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = c.id) >= 2;
