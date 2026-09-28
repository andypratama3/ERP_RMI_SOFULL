-- Data Migration Fast Track
-- Step 4: post opening AP (hutang berjalan) into purchases_invoice_ap.
-- Idempotent by: manufacture + invoice_number + opening note marker.

SET @batch := 'CUTOVER_2026Q1';

START TRANSACTION;

INSERT INTO purchases_invoice_ap (
  ap_code,
  invoice_type,
  invoice_number,
  invoice_date,
  due_date,
  manufacture_id,
  office_code,
  currency,
  subtotal,
  tax_percent,
  tax_amount,
  total_amount,
  status,
  note,
  created_by,
  created_at,
  updated_at
)
SELECT
  CONCAT('OPEN-AP-', DATE_FORMAT(CURDATE(), '%y%m%d'), '-', LPAD(a.row_no, 4, '0')) AS ap_code,
  'FINAL' AS invoice_type,
  a.invoice_number,
  a.invoice_date,
  a.due_date,
  m.id AS manufacture_id,
  a.office_code,
  COALESCE(NULLIF(a.currency, ''), 'IDR') AS currency,
  a.balance_amount AS subtotal,
  0 AS tax_percent,
  0 AS tax_amount,
  a.balance_amount AS total_amount,
  'UNPAID' AS status,
  CONCAT('OPENING_AP batch=', a.migration_batch, ' | ', COALESCE(a.note, '')) AS note,
  'MIGRATION' AS created_by,
  NOW(),
  NOW()
FROM mig_opening_ap_stg a
JOIN master_manufactures m ON UPPER(m.manufacture_code) = UPPER(a.manufacture_code)
LEFT JOIN purchases_invoice_ap ap
  ON ap.manufacture_id = m.id
 AND UPPER(COALESCE(ap.invoice_number, '')) = UPPER(COALESCE(a.invoice_number, ''))
 AND ap.deleted_at IS NULL
 AND ap.note LIKE CONCAT('%OPENING_AP batch=', a.migration_batch, '%')
WHERE a.migration_batch = @batch
  AND a.balance_amount > 0
  AND ap.id IS NULL;

COMMIT;

-- Verify AP outstanding summary.
SELECT
  COUNT(*) AS ap_open_count,
  COALESCE(SUM(total_amount), 0) AS ap_open_total
FROM purchases_invoice_ap
WHERE deleted_at IS NULL
  AND status IN ('UNPAID', 'PARTIAL')
  AND note LIKE CONCAT('%OPENING_AP batch=', @batch, '%');

