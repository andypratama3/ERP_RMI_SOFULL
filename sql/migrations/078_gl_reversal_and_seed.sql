-- 078_gl_reversal_and_seed.sql
-- GL reversal metadata + starter COA and mapping (idempotent)

SET @db := DATABASE();

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='gl_journal_headers' AND column_name='reversal_of_header_id'
    ),
    'SELECT 1',
    'ALTER TABLE gl_journal_headers ADD COLUMN reversal_of_header_id BIGINT UNSIGNED NULL AFTER source_ref'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='gl_journal_headers' AND column_name='reverse_reason'
    ),
    'SELECT 1',
    'ALTER TABLE gl_journal_headers ADD COLUMN reverse_reason VARCHAR(255) NULL AFTER reversal_of_header_id'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='gl_journal_headers' AND column_name='reversed_at'
    ),
    'SELECT 1',
    'ALTER TABLE gl_journal_headers ADD COLUMN reversed_at DATETIME NULL AFTER reverse_reason'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1 FROM information_schema.columns
      WHERE table_schema=@db AND table_name='gl_journal_headers' AND column_name='reversed_by'
    ),
    'SELECT 1',
    'ALTER TABLE gl_journal_headers ADD COLUMN reversed_by BIGINT NULL AFTER reversed_at'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

INSERT INTO gl_accounts (code, name, account_type, parent_id, is_postable, status, created_at, updated_at)
SELECT '111001', 'Cash/Bank', 'ASSET', NULL, 1, 'ACTIVE', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM gl_accounts WHERE code='111001');

INSERT INTO gl_accounts (code, name, account_type, parent_id, is_postable, status, created_at, updated_at)
SELECT '211001', 'Account Payable', 'LIABILITY', NULL, 1, 'ACTIVE', NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM gl_accounts WHERE code='211001');

INSERT INTO gl_mappings (module_name, event_name, debit_account_id, credit_account_id, rule_json, is_active, created_at, updated_at)
SELECT
  'PURCHASES',
  'AP_INVOICE_CREATED',
  (SELECT id FROM gl_accounts WHERE code='111001' LIMIT 1),
  (SELECT id FROM gl_accounts WHERE code='211001' LIMIT 1),
  NULL,
  1,
  NOW(),
  NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM gl_mappings WHERE module_name='PURCHASES' AND event_name='AP_INVOICE_CREATED'
);
