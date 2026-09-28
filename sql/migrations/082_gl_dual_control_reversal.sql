-- 082_gl_dual_control_reversal.sql
-- Dual-control for manual GL reversal requests (idempotent)

CREATE TABLE IF NOT EXISTS `gl_reversal_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `header_id` BIGINT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  `requested_by` INT NULL,
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` INT NULL,
  `approved_at` DATETIME NULL,
  `rejected_by` INT NULL,
  `rejected_at` DATETIME NULL,
  `decision_note` VARCHAR(255) NULL,
  `reversal_header_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gl_rev_req_header` (`header_id`),
  KEY `idx_gl_rev_req_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
