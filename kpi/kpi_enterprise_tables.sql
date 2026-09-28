-- KPI Enterprise Tables — Snapshot maker/checker two-step
-- Aman untuk instalasi baru. Untuk instalasi lama, bagian ALTER menambah kolom yang belum ada.

CREATE TABLE IF NOT EXISTS `kpi_office` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `month_ym` VARCHAR(7) NOT NULL,
  `office_code` VARCHAR(50) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  `metrics_json` LONGTEXT NULL,
  `note` TEXT NULL,
  `deleted_at` DATETIME NULL,
  `created_by` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(100) NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_office_month_office` (`month_ym`,`office_code`),
  KEY `idx_kpi_office_month` (`month_ym`),
  KEY `idx_kpi_office_office` (`office_code`),
  KEY `idx_kpi_office_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_employee` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `month_ym` VARCHAR(7) NOT NULL,
  `dept_code` VARCHAR(50) NOT NULL,
  `employee_code` VARCHAR(100) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  `metrics_json` LONGTEXT NULL,
  `note` TEXT NULL,
  `deleted_at` DATETIME NULL,
  `created_by` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` VARCHAR(100) NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_emp_month_dept_emp` (`month_ym`,`dept_code`,`employee_code`),
  KEY `idx_kpi_emp_month` (`month_ym`),
  KEY `idx_kpi_emp_dept` (`dept_code`),
  KEY `idx_kpi_emp_emp` (`employee_code`),
  KEY `idx_kpi_emp_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module` VARCHAR(50) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `ref_code` VARCHAR(120) NULL,
  `detail` LONGTEXT NULL,
  `actor` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kpi_audit_module` (`module`),
  KEY `idx_kpi_audit_action` (`action`),
  KEY `idx_kpi_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_snapshot_approvals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_id` BIGINT UNSIGNED NULL,
  `snapshot_month` VARCHAR(7) NOT NULL,
  `scope` VARCHAR(20) NOT NULL,
  `maker_username` VARCHAR(100) NOT NULL,
  `checker_username` VARCHAR(100) NOT NULL,
  `approval_reason` TEXT NOT NULL,
  `rejection_reason` TEXT NULL,
  `approval_status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  `approved_at` DATETIME NULL,
  `rejected_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kpi_snap_appr_sid` (`snapshot_id`),
  KEY `idx_kpi_snap_appr_month_scope` (`snapshot_month`,`scope`),
  KEY `idx_kpi_snap_appr_checker_status` (`checker_username`,`approval_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_snapshot` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_month` VARCHAR(7) NOT NULL,
  `scope` VARCHAR(20) NOT NULL,
  `actor` VARCHAR(100) NULL,
  `approval_id` BIGINT UNSIGNED NULL,
  `payload_hash` CHAR(64) NULL,
  `row_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_snapshot_month_scope` (`snapshot_month`,`scope`),
  KEY `idx_kpi_snapshot_created` (`created_at`),
  KEY `idx_kpi_snapshot_approval` (`approval_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_snapshot_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_id` BIGINT UNSIGNED NOT NULL,
  `payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_snapshot_items_sid` (`snapshot_id`),
  CONSTRAINT `fk_kpi_snapshot_items_snapshot`
    FOREIGN KEY (`snapshot_id`) REFERENCES `kpi_snapshot` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MariaDB 10.x supports ADD COLUMN IF NOT EXISTS.
ALTER TABLE `kpi_snapshot_approvals`
  ADD COLUMN IF NOT EXISTS `rejection_reason` TEXT NULL AFTER `approval_reason`,
  ADD COLUMN IF NOT EXISTS `rejected_at` DATETIME NULL AFTER `approved_at`;

ALTER TABLE `kpi_snapshot_approvals`
  MODIFY `approval_status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
  MODIFY `approved_at` DATETIME NULL;

ALTER TABLE `kpi_snapshot`
  ADD COLUMN IF NOT EXISTS `approval_id` BIGINT UNSIGNED NULL AFTER `actor`,
  ADD COLUMN IF NOT EXISTS `payload_hash` CHAR(64) NULL AFTER `approval_id`,
  ADD COLUMN IF NOT EXISTS `row_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `payload_hash`;

-- Foreign key approval -> snapshot sengaja tidak dipasang dua arah agar urutan create approval lalu snapshot tetap sederhana.
