-- Data Migration Fast Track
-- Step 2: validate staging data before posting.
-- Replace batch code as needed.

SET @batch := 'CUTOVER_2026Q1';

-- STOCK: missing SKU in master_products
SELECT s.row_no, s.sku
FROM mig_opening_stock_stg s
LEFT JOIN master_products p ON UPPER(p.sku) = UPPER(s.sku)
WHERE s.migration_batch = @batch
  AND p.id IS NULL
ORDER BY s.row_no;

-- STOCK: duplicate SKU in same batch
SELECT sku, COUNT(*) AS cnt
FROM mig_opening_stock_stg
WHERE migration_batch = @batch
GROUP BY sku
HAVING COUNT(*) > 1;

-- AP: missing principal/manufacture code
SELECT a.row_no, a.invoice_number, a.manufacture_code
FROM mig_opening_ap_stg a
LEFT JOIN master_manufactures m ON UPPER(m.manufacture_code) = UPPER(a.manufacture_code)
WHERE a.migration_batch = @batch
  AND m.id IS NULL
ORDER BY a.row_no;

-- AP: invalid amount
SELECT row_no, invoice_number, balance_amount
FROM mig_opening_ap_stg
WHERE migration_batch = @batch
  AND balance_amount <= 0
ORDER BY row_no;

-- AR: missing customer code
SELECT r.row_no, r.invoice_number, r.customers_code
FROM mig_opening_ar_stg r
LEFT JOIN master_customers c ON UPPER(c.customers_code) = UPPER(r.customers_code)
WHERE r.migration_batch = @batch
  AND c.id IS NULL
ORDER BY r.row_no;

-- AR: invalid amount
SELECT row_no, invoice_number, balance_amount
FROM mig_opening_ar_stg
WHERE migration_batch = @batch
  AND balance_amount <= 0
ORDER BY row_no;

-- Gate summary: should all be 0 before posting.
SELECT
  (SELECT COUNT(*) FROM mig_opening_stock_stg s LEFT JOIN master_products p ON UPPER(p.sku)=UPPER(s.sku) WHERE s.migration_batch=@batch AND p.id IS NULL) AS stock_missing_sku,
  (SELECT COUNT(*) FROM mig_opening_ap_stg a LEFT JOIN master_manufactures m ON UPPER(m.manufacture_code)=UPPER(a.manufacture_code) WHERE a.migration_batch=@batch AND m.id IS NULL) AS ap_missing_manufacture,
  (SELECT COUNT(*) FROM mig_opening_ar_stg r LEFT JOIN master_customers c ON UPPER(c.customers_code)=UPPER(r.customers_code) WHERE r.migration_batch=@batch AND c.id IS NULL) AS ar_missing_customer,
  (SELECT COUNT(*) FROM mig_opening_ap_stg WHERE migration_batch=@batch AND balance_amount<=0) AS ap_invalid_amount,
  (SELECT COUNT(*) FROM mig_opening_ar_stg WHERE migration_batch=@batch AND balance_amount<=0) AS ar_invalid_amount;

