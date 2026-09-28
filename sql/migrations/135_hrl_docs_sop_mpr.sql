-- 135_hrl_docs_sop_mpr.sql
-- Seed SOP MPR (Marketing & Project) ke hrl_docs untuk HRL Docs module

INSERT IGNORE INTO `hrl_docs` (`doc_code`, `title`, `unit`, `category`, `scope`, `owner_dept`, `status`, `current_version`, `effective_date`, `tags`, `description`, `created_by`, `created_at`, `updated_at`, `deleted_at`)
VALUES (
  'HRL-HR-SOP-MPR-001',
  'SOP MPR (Marketing & Project) - Kunjungan Budget Ops Daily',
  'HR',
  'SOP',
  'INTERNAL',
  'HRL',
  'DRAFT',
  0,
  '2026-03-04',
  'MPR,kunjungan,budget,ops daily',
  'Prosedur kunjungan ke User/PIC Customers, budget approval FIN, ops daily untuk buat Sales. MPR fokus berkunjung ke customer untuk generate DO.',
  'admin',
  NOW(),
  NOW(),
  NULL
);
