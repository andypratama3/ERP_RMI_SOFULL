-- 141_reg_alkes_portal_source.sql
-- Kolom tracking untuk case & docs dari Manufacturer Portal

SET @db = DATABASE();

-- hrl_reg_alkes_cases: source
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='hrl_reg_alkes_cases' AND column_name='source')=0,
  'ALTER TABLE hrl_reg_alkes_cases ADD COLUMN source VARCHAR(30) DEFAULT ''pqp'' COMMENT ''pqp|portal''',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- hrl_reg_alkes_cases: portal_user_id
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='hrl_reg_alkes_cases' AND column_name='portal_user_id')=0,
  'ALTER TABLE hrl_reg_alkes_cases ADD COLUMN portal_user_id INT DEFAULT NULL COMMENT ''FK manufacturer_portal_users.id''',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- hrl_reg_alkes_case_docs: uploaded_via
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='hrl_reg_alkes_case_docs' AND column_name='uploaded_via')=0,
  'ALTER TABLE hrl_reg_alkes_case_docs ADD COLUMN uploaded_via VARCHAR(20) DEFAULT ''erp'' COMMENT ''erp|portal''',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- hrl_reg_alkes_case_docs: portal_user_id
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@db AND table_name='hrl_reg_alkes_case_docs' AND column_name='portal_user_id')=0,
  'ALTER TABLE hrl_reg_alkes_case_docs ADD COLUMN portal_user_id INT DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
