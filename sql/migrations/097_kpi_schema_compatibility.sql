-- 097_kpi_schema_compatibility.sql
-- Tujuan:
-- 1) Hardening kompatibilitas schema KPI pada instalasi yang tabel kpi_* sudah dipakai struktur lain.
-- 2) Menambahkan kolom yang dibutuhkan halaman KPI tanpa merusak data existing.
-- 3) Menambahkan tabel approval maker-checker untuk finalisasi snapshot.

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- kpi_office compatibility columns
-- ---------------------------------------------------------------------------
SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_office' AND column_name='month_ym'),
    'SELECT 1',
    'ALTER TABLE kpi_office ADD COLUMN month_ym VARCHAR(7) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_office' AND column_name='status'),
    'SELECT 1',
    'ALTER TABLE kpi_office ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT ''DRAFT'''
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_office' AND column_name='metrics_json'),
    'SELECT 1',
    'ALTER TABLE kpi_office ADD COLUMN metrics_json LONGTEXT NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_office' AND column_name='note'),
    'SELECT 1',
    'ALTER TABLE kpi_office ADD COLUMN note TEXT NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_office' AND column_name='deleted_at'),
    'SELECT 1',
    'ALTER TABLE kpi_office ADD COLUMN deleted_at DATETIME NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_office' AND column_name='created_by'),
    'SELECT 1',
    'ALTER TABLE kpi_office ADD COLUMN created_by VARCHAR(100) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_office' AND column_name='updated_by'),
    'SELECT 1',
    'ALTER TABLE kpi_office ADD COLUMN updated_by VARCHAR(100) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- kpi_employee compatibility columns
-- ---------------------------------------------------------------------------
SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_employee' AND column_name='month_ym'),
    'SELECT 1',
    'ALTER TABLE kpi_employee ADD COLUMN month_ym VARCHAR(7) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_employee' AND column_name='dept_code'),
    'SELECT 1',
    'ALTER TABLE kpi_employee ADD COLUMN dept_code VARCHAR(50) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_employee' AND column_name='employee_code'),
    'SELECT 1',
    'ALTER TABLE kpi_employee ADD COLUMN employee_code VARCHAR(100) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_employee' AND column_name='status'),
    'SELECT 1',
    'ALTER TABLE kpi_employee ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT ''DRAFT'''
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_employee' AND column_name='metrics_json'),
    'SELECT 1',
    'ALTER TABLE kpi_employee ADD COLUMN metrics_json LONGTEXT NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_employee' AND column_name='note'),
    'SELECT 1',
    'ALTER TABLE kpi_employee ADD COLUMN note TEXT NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_employee' AND column_name='deleted_at'),
    'SELECT 1',
    'ALTER TABLE kpi_employee ADD COLUMN deleted_at DATETIME NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_employee' AND column_name='created_by'),
    'SELECT 1',
    'ALTER TABLE kpi_employee ADD COLUMN created_by VARCHAR(100) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_employee' AND column_name='updated_by'),
    'SELECT 1',
    'ALTER TABLE kpi_employee ADD COLUMN updated_by VARCHAR(100) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- kpi_snapshot compatibility columns
-- ---------------------------------------------------------------------------
SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_snapshot' AND column_name='snapshot_month'),
    'SELECT 1',
    'ALTER TABLE kpi_snapshot ADD COLUMN snapshot_month VARCHAR(7) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_snapshot' AND column_name='scope'),
    'SELECT 1',
    'ALTER TABLE kpi_snapshot ADD COLUMN scope VARCHAR(20) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_snapshot' AND column_name='actor'),
    'SELECT 1',
    'ALTER TABLE kpi_snapshot ADD COLUMN actor VARCHAR(100) NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- kpi_snapshot_items compatibility column
-- ---------------------------------------------------------------------------
SET @q := (
  SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=@db AND table_name='kpi_snapshot_items' AND column_name='payload_json'),
    'SELECT 1',
    'ALTER TABLE kpi_snapshot_items ADD COLUMN payload_json LONGTEXT NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- Snapshot approval table (maker-checker)
-- ---------------------------------------------------------------------------
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
  KEY `idx_kpi_snap_appr_approved` (`approved_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional FK only if kpi_snapshot.id exists and is compatible
SET @q := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.key_column_usage
      WHERE table_schema=@db
        AND table_name='kpi_snapshot_approvals'
        AND constraint_name='fk_kpi_snapshot_approvals_snapshot'
    ),
    'SELECT 1',
    'ALTER TABLE kpi_snapshot_approvals ADD CONSTRAINT fk_kpi_snapshot_approvals_snapshot FOREIGN KEY (snapshot_id) REFERENCES kpi_snapshot(id) ON DELETE SET NULL'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- ---------------------------------------------------------------------------
-- Backfill compatibility columns from old columns (safe no-op if already filled)
-- ---------------------------------------------------------------------------
UPDATE kpi_employee
SET dept_code = COALESCE(NULLIF(dept_code,''), dept),
    employee_code = COALESCE(NULLIF(employee_code,''), employee_id)
WHERE (dept_code IS NULL OR dept_code='') OR (employee_code IS NULL OR employee_code='');

UPDATE kpi_snapshot
SET snapshot_month = COALESCE(NULLIF(snapshot_month,''), period_yyyymm),
    actor = COALESCE(NULLIF(actor,''), created_by)
WHERE (snapshot_month IS NULL OR snapshot_month='') OR (actor IS NULL OR actor='');

-- Default scope for legacy snapshot rows
UPDATE kpi_snapshot
SET scope = COALESCE(NULLIF(scope,''), 'BOTH')
WHERE scope IS NULL OR scope='';
