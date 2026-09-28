-- 081_governance_controls.sql
-- Rate-limit policies + MFA bypass maker-checker columns (idempotent)

CREATE TABLE IF NOT EXISTS `api_rate_limit_policies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope_key` VARCHAR(80) NOT NULL,
  `window_seconds` INT NOT NULL DEFAULT 60,
  `max_hits` INT NOT NULL DEFAULT 20,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rate_limit_scope` (`scope_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO api_rate_limit_policies (scope_key, window_seconds, max_hits, is_active, created_at, updated_at)
SELECT 'GL_ENQUEUE_POSTING', 60, 20, 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM api_rate_limit_policies WHERE scope_key='GL_ENQUEUE_POSTING'
);

SET @db := DATABASE();

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='auth_mfa_bypass_tickets' AND column_name='status'
    ),
    'SELECT 1',
    'ALTER TABLE auth_mfa_bypass_tickets ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT ''PENDING'' AFTER expires_at'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='auth_mfa_bypass_tickets' AND column_name='requested_by'
    ),
    'SELECT 1',
    'ALTER TABLE auth_mfa_bypass_tickets ADD COLUMN requested_by INT NULL AFTER created_by'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='auth_mfa_bypass_tickets' AND column_name='approved_by'
    ),
    'SELECT 1',
    'ALTER TABLE auth_mfa_bypass_tickets ADD COLUMN approved_by INT NULL AFTER requested_by'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='auth_mfa_bypass_tickets' AND column_name='approved_at'
    ),
    'SELECT 1',
    'ALTER TABLE auth_mfa_bypass_tickets ADD COLUMN approved_at DATETIME NULL AFTER approved_by'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
