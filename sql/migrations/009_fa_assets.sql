-- 009_fa_assets.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `fa_assets`
--

CREATE TABLE `fa_assets` (
  `id` int NOT NULL,
  `asset_code` varchar(50) NOT NULL,
  `asset_name` varchar(200) NOT NULL,
  `category` varchar(100) DEFAULT '',
  `office_code` varchar(30) DEFAULT '',
  `dept_code` varchar(30) DEFAULT '',
  `custodian_emp_id` int DEFAULT NULL,
  `vendor_name` varchar(200) DEFAULT '',
  `purchase_ref` varchar(100) DEFAULT '',
  `invoice_no` varchar(100) DEFAULT '',
  `acq_date` date NOT NULL,
  `acq_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `salvage_value` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_group_code` varchar(20) NOT NULL,
  `dep_method` enum('SL','DDB') NOT NULL DEFAULT 'SL',
  `status` enum('ACTIVE','INACTIVE','DISPOSED') NOT NULL DEFAULT 'ACTIVE',
  `disposed_at` date DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
