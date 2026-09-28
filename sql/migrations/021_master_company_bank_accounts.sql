-- 021_master_company_bank_accounts.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_company_bank_accounts`
--

CREATE TABLE `master_company_bank_accounts` (
  `id` bigint NOT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `bank_name` varchar(120) NOT NULL,
  `account_number` varchar(60) NOT NULL,
  `account_name` varchar(160) NOT NULL,
  `branch` varchar(120) DEFAULT NULL,
  `purpose` varchar(20) NOT NULL DEFAULT 'RECEIVE',
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
