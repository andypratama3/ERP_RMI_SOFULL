-- 085_dashboard_detail_kpi.sql
-- Dashboard Detail KPI tables + mappings + snapshot support (idempotent)

CREATE TABLE IF NOT EXISTS `kpi_targets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `month_no` TINYINT NOT NULL,
  `year_no` SMALLINT NOT NULL,
  `segment` VARCHAR(40) NOT NULL,
  `office_id` INT NULL,
  `target_amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_targets_month_year_segment_office` (`month_no`,`year_no`,`segment`,`office_id`),
  KEY `idx_kpi_targets_segment` (`segment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_customer_segment_map` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_code` VARCHAR(80) NOT NULL,
  `segment` VARCHAR(40) NOT NULL,
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_customer_segment` (`customer_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_gl_category_map` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gl_account_id` BIGINT UNSIGNED NOT NULL,
  `category` VARCHAR(30) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_gl_category_account` (`gl_account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_corporate_rates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `segment` VARCHAR(40) NOT NULL,
  `rate_percent` DECIMAL(8,4) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_corporate_rates_segment` (`segment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_adjustments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `month_no` TINYINT NOT NULL,
  `year_no` SMALLINT NOT NULL,
  `office_id` INT NULL,
  `field` VARCHAR(40) NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
  `note` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_kpi_adjustments_period` (`month_no`,`year_no`),
  KEY `idx_kpi_adjustments_office` (`office_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `office_warehouses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `office_id` INT NOT NULL,
  `warehouse_id` VARCHAR(80) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_office_warehouse` (`office_id`,`warehouse_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `kpi_daily_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `snapshot_date` DATE NOT NULL,
  `office_id` INT NULL,
  `segment` VARCHAR(40) NOT NULL,
  `metrics_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kpi_daily_snapshot` (`snapshot_date`,`office_id`,`segment`),
  KEY `idx_kpi_daily_snapshot_segment` (`segment`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO kpi_corporate_rates (segment, rate_percent, is_active, created_at, updated_at)
SELECT 'MAIN', 10.0000, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM kpi_corporate_rates WHERE segment='MAIN');

INSERT INTO kpi_corporate_rates (segment, rate_percent, is_active, created_at, updated_at)
SELECT 'ACCUNIT', 5.0000, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM kpi_corporate_rates WHERE segment='ACCUNIT');

INSERT INTO kpi_corporate_rates (segment, rate_percent, is_active, created_at, updated_at)
SELECT 'TANGERANG', 10.0000, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM kpi_corporate_rates WHERE segment='TANGERANG');

SET @db := DATABASE();

-- Optional metadata columns for traceability in new config tables
SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_targets' AND column_name='source_key'),
    'SELECT 1',
    'ALTER TABLE kpi_targets ADD COLUMN source_key VARCHAR(120) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Performance indexes for heavy dashboard aggregations
SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@db AND table_name='sales_do' AND index_name='idx_sales_do_date_status_office'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD INDEX idx_sales_do_date_status_office (do_date, status, office_code)'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@db AND table_name='sales_do' AND index_name='idx_sales_do_customer_date'),
    'SELECT 1',
    'ALTER TABLE sales_do ADD INDEX idx_sales_do_customer_date (customers_code, do_date)'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@db AND table_name='purchases_invoice_ap' AND index_name='idx_ap_date_status_office'),
    'SELECT 1',
    'ALTER TABLE purchases_invoice_ap ADD INDEX idx_ap_date_status_office (invoice_date, status, office_code)'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@db AND table_name='purchases_payment_ap' AND index_name='idx_ap_payment_ap_date'),
    'SELECT 1',
    'ALTER TABLE purchases_payment_ap ADD INDEX idx_ap_payment_ap_date (ap_id, pay_date)'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@db AND table_name='gl_journal_headers' AND index_name='idx_gl_header_date_status'),
    'SELECT 1',
    'ALTER TABLE gl_journal_headers ADD INDEX idx_gl_header_date_status (journal_date, status)'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema=@db AND table_name='gl_journal_lines' AND index_name='idx_gl_lines_account_header'),
    'SELECT 1',
    'ALTER TABLE gl_journal_lines ADD INDEX idx_gl_lines_account_header (account_id, header_id)'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Seed minimal sample targets for current month (safe no-op on duplicates)
SET @m := MONTH(CURDATE());
SET @y := YEAR(CURDATE());

INSERT INTO kpi_targets (month_no, year_no, segment, office_id, target_amount, created_at, updated_at)
SELECT @m, @y, 'NON_HERMINA', o.id, 100000000, NOW(), NOW()
FROM master_office o
WHERE UPPER(o.office_code) IN ('BGR','BKS','SLO','BDG','SMG')
  AND NOT EXISTS (
    SELECT 1 FROM kpi_targets t
    WHERE t.month_no=@m AND t.year_no=@y AND t.segment='NON_HERMINA' AND t.office_id=o.id
  );

INSERT INTO kpi_targets (month_no, year_no, segment, office_id, target_amount, created_at, updated_at)
SELECT @m, @y, 'HERMINA', o.id, 150000000, NOW(), NOW()
FROM master_office o
WHERE UPPER(o.office_code) IN ('BGR','BKS','SLO','BDG','SMG','JGY','KAL')
  AND NOT EXISTS (
    SELECT 1 FROM kpi_targets t
    WHERE t.month_no=@m AND t.year_no=@y AND t.segment='HERMINA' AND t.office_id=o.id
  );

INSERT INTO kpi_targets (month_no, year_no, segment, office_id, target_amount, created_at, updated_at)
SELECT @m, @y, 'ACCUNIT', o.id, 25000000, NOW(), NOW()
FROM master_office o
WHERE UPPER(o.office_code) IN ('BGR','BKS','SLO','BDG','SMG')
  AND NOT EXISTS (
    SELECT 1 FROM kpi_targets t
    WHERE t.month_no=@m AND t.year_no=@y AND t.segment='ACCUNIT' AND t.office_id=o.id
  );

INSERT INTO kpi_targets (month_no, year_no, segment, office_id, target_amount, created_at, updated_at)
SELECT @m, @y, 'NON_HERMINA', o.id, 50000000, NOW(), NOW()
FROM master_office o
WHERE UPPER(o.office_code)='TGR'
  AND NOT EXISTS (
    SELECT 1 FROM kpi_targets t
    WHERE t.month_no=@m AND t.year_no=@y AND t.segment='NON_HERMINA' AND t.office_id=o.id
  );

INSERT INTO kpi_targets (month_no, year_no, segment, office_id, target_amount, created_at, updated_at)
SELECT @m, @y, 'HERMINA', o.id, 60000000, NOW(), NOW()
FROM master_office o
WHERE UPPER(o.office_code)='TGR'
  AND NOT EXISTS (
    SELECT 1 FROM kpi_targets t
    WHERE t.month_no=@m AND t.year_no=@y AND t.segment='HERMINA' AND t.office_id=o.id
  );
