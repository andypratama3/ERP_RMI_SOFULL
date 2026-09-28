-- 077_operational_controls.sql
-- Tax payment gate config + bank recon match uniqueness (idempotent)

SET @db := DATABASE();

-- Configure tax invoice enforcement before FIN set PAID
INSERT INTO system_config (config_group, config_key, config_value, office_code, description, is_active, created_at, updated_at)
SELECT 'TAX_INVOICE', 'REQUIRE_ISSUED_BEFORE_FIN_PAID', '1', NULL,
       '1=true, FIN paid requires tax invoice ISSUED', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM system_config
  WHERE config_group='TAX_INVOICE' AND config_key='REQUIRE_ISSUED_BEFORE_FIN_PAID'
);

-- Ensure one statement line maps to at most one reconciliation match
SET @q := (
  SELECT IF(
    EXISTS (
      SELECT 1
      FROM information_schema.statistics
      WHERE table_schema=@db
        AND table_name='bank_recon_matches'
        AND index_name='uq_bank_recon_statement_line'
    ),
    'SELECT 1',
    'ALTER TABLE bank_recon_matches ADD UNIQUE KEY uq_bank_recon_statement_line (statement_line_id)'
  )
);
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
