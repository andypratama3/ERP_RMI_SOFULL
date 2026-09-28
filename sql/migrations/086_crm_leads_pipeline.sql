-- 086_crm_leads_pipeline.sql
-- CRM Leads Pipeline (idempotent)

CREATE TABLE IF NOT EXISTS `crm_leads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_no` VARCHAR(40) NOT NULL,
  `lead_name` VARCHAR(160) NOT NULL,
  `company_name` VARCHAR(160) NULL,
  `phone` VARCHAR(40) NULL,
  `email` VARCHAR(120) NULL,
  `source_channel` VARCHAR(40) NOT NULL DEFAULT 'OTHER',
  `city` VARCHAR(80) NULL,
  `estimated_value` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `priority` VARCHAR(10) NOT NULL DEFAULT 'MEDIUM',
  `status` VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  `next_followup_date` DATE NULL,
  `notes` TEXT NULL,
  `assigned_to_user_id` INT NULL,
  `created_by` INT NULL,
  `submitted_by` INT NULL,
  `submitted_at` DATETIME NULL,
  `approved_by` INT NULL,
  `approved_at` DATETIME NULL,
  `closed_by` INT NULL,
  `closed_at` DATETIME NULL,
  `cancelled_by` INT NULL,
  `cancelled_at` DATETIME NULL,
  `cancel_reason` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crm_lead_no` (`lead_no`),
  KEY `idx_crm_leads_status` (`status`),
  KEY `idx_crm_leads_followup` (`next_followup_date`),
  KEY `idx_crm_leads_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `crm_lead_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(40) NOT NULL,
  `from_status` VARCHAR(20) NULL,
  `to_status` VARCHAR(20) NULL,
  `note` VARCHAR(255) NULL,
  `actor_user_id` INT NULL,
  `actor_username` VARCHAR(120) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_crm_lead_logs_lead_id` (`lead_id`),
  KEY `idx_crm_lead_logs_action` (`action`),
  KEY `idx_crm_lead_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @db := DATABASE();

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='crm_leads' AND column_name='cancel_reason'),
    'SELECT 1',
    'ALTER TABLE crm_leads ADD COLUMN cancel_reason VARCHAR(255) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@db AND table_name='crm_leads' AND index_name='idx_crm_leads_status_priority'),
    'SELECT 1',
    'ALTER TABLE crm_leads ADD INDEX idx_crm_leads_status_priority (status, priority)'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
