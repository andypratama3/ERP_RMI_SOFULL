-- 037_master_vendors.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_vendors`
--

CREATE TABLE `master_vendors` (
  `id` int NOT NULL,
  `vendors_code` varchar(50) NOT NULL,
  `vendors_name` varchar(150) NOT NULL,
  `vendor_type` varchar(50) DEFAULT NULL,
  `pic_name` varchar(150) DEFAULT NULL,
  `pic_position` varchar(100) DEFAULT NULL,
  `pic_phone` varchar(50) DEFAULT NULL,
  `pic_email` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_name` varchar(150) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `bank_swift_code` varchar(50) DEFAULT NULL,
  `bank_iban` varchar(50) DEFAULT NULL,
  `bank_currency` varchar(10) DEFAULT 'IDR',
  `category` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `maps_url` text,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `npwp` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_vendors`
--

INSERT INTO `master_vendors` (`id`, `vendors_code`, `vendors_name`, `vendor_type`, `pic_name`, `pic_position`, `pic_phone`, `pic_email`, `bank_name`, `bank_account_name`, `bank_account_number`, `bank_swift_code`, `bank_iban`, `bank_currency`, `category`, `address`, `maps_url`, `city`, `phone`, `email`, `npwp`, `status`, `created_at`, `updated_at`) VALUES
(1, 'VRMI2601', 'Tokopedia', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, '', NULL, 'Jakarta', '', '', NULL, 'active', '2025-12-08 15:55:25', '2025-12-08 15:55:25');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
