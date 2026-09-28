-- 063_system_config.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `system_config`
--

CREATE TABLE `system_config` (
  `id` int NOT NULL,
  `config_group` varchar(50) DEFAULT 'general',
  `config_key` varchar(100) NOT NULL,
  `config_value` text,
  `office_code` varchar(10) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `system_config`
--

INSERT INTO `system_config` (`id`, `config_group`, `config_key`, `config_value`, `office_code`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'numbering', 'numbering_do', 'DO-[OFFICE]-[YYYY][MM][####]', NULL, 'Polanya nomor DO', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(2, 'numbering', 'numbering_invoice', 'INV-[OFFICE]-[YYYY][MM][####]', NULL, 'Polanya nomor Invoice', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(3, 'numbering', 'numbering_quotation', 'QUO-[OFFICE]-[YYYY][MM][####]', NULL, 'Polanya nomor Quotation', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(4, 'numbering', 'numbering_employee', '[DEPT][YY][MM][###]', NULL, 'Polanya kode employee', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(5, 'default', 'default_tax_all', 'PPN-GEN', NULL, 'Default profil pajak untuk transaksi umum', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(6, 'default', 'default_tax_BGR', 'PPN-GEN', 'BGR', 'Override tax untuk kantor Bogor (kalau perlu)', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(7, 'default', 'default_payment_all', 'TOP30', NULL, 'Default payment terms seluruh kantor', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(8, 'default', 'default_payment_RS_HERMINA', 'TOP30', NULL, 'Contoh default payment khusus RS Hermina', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(9, 'general', 'company_name', 'Rizqullah Mediska Indonesia', NULL, 'Nama perusahaan', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(10, 'general', 'company_domain', 'rizqullahmediska.com', NULL, 'Domain resmi perusahaan', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(11, 'MASTER_DATA', 'app_title', 'ERP RMI SOFULL', NULL, 'Judul aplikasi di header Master Data.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(12, 'MASTER_DATA', 'app_tagline', 'ERP Fullstack Rizqullah Mediska Indonesia', NULL, 'Tagline singkat di Master Data.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(13, 'THEME', 'mode', 'dark', NULL, 'Tema tampilan (dark / light). Sekarang: dark sebagai standar RMI.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(14, 'CUSTOMERS', 'code_prefix_hermina', 'H', NULL, 'Prefix kode untuk customer segment Hermina (contoh: H001).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(15, 'CUSTOMERS', 'code_prefix_nonhermina', 'NH', NULL, 'Prefix kode untuk customer segment Non Hermina (contoh: NH001).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(16, 'CUSTOMERS', 'code_prefix_rsud', 'RSUD', NULL, 'Prefix kode untuk customer segment RSUD (contoh: RSUD001).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(17, 'CUSTOMERS', 'segment_keyword_hermina', 'HERMINA', NULL, 'Jika Nama Customer mengandung kata ini → segment = Hermina.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(18, 'CUSTOMERS', 'segment_keyword_rsud', 'RSUD', NULL, 'Jika Nama Customer mengandung kata ini → segment = RSUD.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(19, 'CUSTOMERS', 'default_category_swasta', 'RS Swasta', NULL, 'Default kategori untuk RS Swasta.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(20, 'CUSTOMERS', 'default_category_pemerintah', 'RS Pemerintah', NULL, 'Default kategori untuk RS Pemerintah.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(21, 'IMPORT_CUSTOMERS', 'csv_has_header', '1', NULL, '1 = baris pertama adalah header, 0 = tidak (dipakai saat import di master_customers.php).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(22, 'IMPORT_CUSTOMERS', 'csv_max_rows', '500', NULL, 'Batas maksimum baris dalam 1 file import customers.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(23, 'IMPORT_CUSTOMERS', 'csv_columns_example', 'customers_name,city,address,phone,email,segment,category,office_code,google_maps_url', NULL, 'Contoh urutan kolom CSV untuk import customers (master_customers.php).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(24, 'KPI_OPERATIONAL', 'WORK_START', '08:00', NULL, 'Jam mulai operasional (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(25, 'KPI_OPERATIONAL', 'WORK_END', '17:00', NULL, 'Jam selesai operasional (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(26, 'KPI_OPERATIONAL', 'WORK_DAYS', 'MON,TUE,WED,THU,FRI,SAT', NULL, 'Hari kerja (GLOBAL). Format: MON,TUE,...', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(27, 'KPI_OPERATIONAL', 'CUTOFF_DO_INPUT', '16:00', NULL, 'Cutoff input DO (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(28, 'KPI_SLA', 'SLA_WQS_HOURS', '24', NULL, 'SLA WQS (jam) - GLOBAL.', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(29, 'KPI_SLA', 'SLA_SCM_HOURS', '24', NULL, 'SLA SCM (jam) - GLOBAL.', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(30, 'KPI_SLA', 'SLA_ACT_HOURS', '24', NULL, 'SLA ACT (jam) - GLOBAL.', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(31, 'KPI_SLA', 'SLA_FIN_HOURS', '24', NULL, 'SLA FIN (jam) - GLOBAL.', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(32, 'KPI_FA_POLICY', 'DEPR_METHOD', 'STRAIGHT_LINE', NULL, 'Metode depresiasi fixed asset (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(33, 'KPI_FA_POLICY', 'SALVAGE_DEFAULT_PERCENT', '0', NULL, 'Default salvage value percent (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
