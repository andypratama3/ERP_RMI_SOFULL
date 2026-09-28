-- 089_crm_lead_dedupe_hardening.sql
-- Purpose:
-- - Add normalized fields + index for duplicate prevention in CRM leads
-- - Add dedupe rule config table (default SKIP)
-- - Safe rerun

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'crm_leads' AND column_name = 'normalized_email'),
    'SELECT 1',
    'ALTER TABLE crm_leads ADD COLUMN normalized_email VARCHAR(190) NOT NULL DEFAULT '''' AFTER email'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'crm_leads' AND column_name = 'normalized_phone'),
    'SELECT 1',
    'ALTER TABLE crm_leads ADD COLUMN normalized_phone VARCHAR(40) NOT NULL DEFAULT '''' AFTER phone'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'crm_leads' AND index_name = 'idx_crm_leads_norm_email'),
    'SELECT 1',
    'ALTER TABLE crm_leads ADD INDEX idx_crm_leads_norm_email (normalized_email)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'crm_leads' AND index_name = 'idx_crm_leads_norm_phone'),
    'SELECT 1',
    'ALTER TABLE crm_leads ADD INDEX idx_crm_leads_norm_phone (normalized_phone)'
  )
);
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `crm_lead_dedupe_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_name` VARCHAR(80) NOT NULL,
  `on_duplicate` ENUM('SKIP','MERGE') NOT NULL DEFAULT 'SKIP',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crm_dedupe_rule_name` (`rule_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO crm_lead_dedupe_rules (rule_name, on_duplicate, is_active)
SELECT 'DEFAULT', 'SKIP', 1
WHERE NOT EXISTS (
  SELECT 1 FROM crm_lead_dedupe_rules WHERE rule_name = 'DEFAULT'
);

