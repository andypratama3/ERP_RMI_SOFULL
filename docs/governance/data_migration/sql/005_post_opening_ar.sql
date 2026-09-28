-- Data Migration Fast Track
-- Step 5: post opening AR (piutang berjalan) into sales_do as receivable open items.
-- Idempotent by note marker + invoice_number + customer + date.

SET @batch := 'CUTOVER_2026Q1';

START TRANSACTION;

INSERT INTO sales_do (
  do_code,
  tracking_code,
  do_date,
  customer_id,
  customers_code,
  office_code,
  status,
  status_wqs,
  status_scm,
  status_act,
  status_fin,
  wqs_status,
  scm_status,
  act_status,
  fin_status,
  note,
  total_amount,
  tax_code,
  tax_included,
  tax_rate_percent,
  tax_amount,
  grand_total,
  price_include_tax,
  crm_status,
  flow_status,
  act_due_date,
  act_amount,
  created_at,
  updated_at
)
SELECT
  CONCAT('OPEN-AR-', DATE_FORMAT(CURDATE(), '%y%m%d'), '-', LPAD(r.row_no, 4, '0')) AS do_code,
  CONCAT('OPEN-AR-', DATE_FORMAT(CURDATE(), '%y%m%d'), '-', LPAD(r.row_no, 4, '0')) AS tracking_code,
  r.invoice_date AS do_date,
  c.id AS customer_id,
  r.customers_code,
  r.office_code,
  'act_done' AS status,
  'done' AS status_wqs,
  'done' AS status_scm,
  'done' AS status_act,
  'pending' AS status_fin,
  'done' AS wqs_status,
  'done' AS scm_status,
  'done' AS act_status,
  'pending' AS fin_status,
  CONCAT('OPENING_AR batch=', r.migration_batch, ' | inv=', r.invoice_number, ' | ', COALESCE(r.note, '')) AS note,
  r.balance_amount AS total_amount,
  '' AS tax_code,
  0 AS tax_included,
  0 AS tax_rate_percent,
  0 AS tax_amount,
  r.balance_amount AS grand_total,
  0 AS price_include_tax,
  'crm_to_wqs' AS crm_status,
  'CRM' AS flow_status,
  r.due_date AS act_due_date,
  r.balance_amount AS act_amount,
  NOW(),
  NOW()
FROM mig_opening_ar_stg r
JOIN master_customers c ON UPPER(c.customers_code) = UPPER(r.customers_code)
LEFT JOIN sales_do d
  ON d.customers_code = r.customers_code
 AND d.do_date = r.invoice_date
 AND d.note LIKE CONCAT('%OPENING_AR batch=', r.migration_batch, '%')
 AND d.note LIKE CONCAT('%inv=', r.invoice_number, '%')
WHERE r.migration_batch = @batch
  AND r.balance_amount > 0
  AND d.id IS NULL;

COMMIT;

-- Verify AR outstanding summary.
SELECT
  COUNT(*) AS ar_open_count,
  COALESCE(SUM(act_amount), 0) AS ar_open_total
FROM sales_do
WHERE note LIKE CONCAT('%OPENING_AR batch=', @batch, '%')
  AND fin_status IN ('pending', 'open', 'unpaid');

