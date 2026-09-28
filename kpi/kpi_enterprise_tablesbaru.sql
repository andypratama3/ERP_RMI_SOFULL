-- KPI Enterprise Tables (idempotent)
-- Jalankan file ini 1x (aman berulang) untuk membuat tabel KPI Office/Employee/Snapshot/Audit.

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

CREATE TABLE IF NOT EXISTS `kpi_snapshot` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_month` VARCHAR(7) NOT NULL,
  `scope` VARCHAR(20) NOT NULL,
  `actor` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kpi_snapshot_month` (`snapshot_month`),
  KEY `idx_kpi_snapshot_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_snapshot_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_id` BIGINT UNSIGNED NOT NULL,
  `payload_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kpi_snapshot_items_sid` (`snapshot_id`),
  CONSTRAINT `fk_kpi_snapshot_items_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `kpi_snapshot` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_snapshot_approvals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_id` BIGINT UNSIGNED NULL,
  `snapshot_month` VARCHAR(7) NOT NULL,
  `scope` VARCHAR(20) NOT NULL,
  `maker_username` VARCHAR(100) NOT NULL,
  `checker_username` VARCHAR(100) NOT NULL,
  `approval_reason` TEXT NOT NULL,
  `approval_status` VARCHAR(20) NOT NULL DEFAULT 'APPROVED',
  `approved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_kpi_snap_appr_sid` (`snapshot_id`),
  KEY `idx_kpi_snap_appr_month` (`snapshot_month`),
  KEY `idx_kpi_snap_appr_checker` (`checker_username`),
  KEY `idx_kpi_snap_appr_approved` (`approved_at`),
  CONSTRAINT `fk_kpi_snapshot_approvals_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `kpi_snapshot` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
