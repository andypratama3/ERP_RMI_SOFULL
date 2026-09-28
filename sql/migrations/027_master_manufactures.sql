-- 027_master_manufactures.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_manufactures`
--

CREATE TABLE `master_manufactures` (
  `id` int NOT NULL,
  `manufactures_code` varchar(50) NOT NULL,
  `manufactures_name` varchar(150) NOT NULL,
  `manufacture_code` varchar(50) NOT NULL,
  `manufacture_name` varchar(150) NOT NULL,
  `brand_name` varchar(255) DEFAULT NULL,
  `origin_type` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` text,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `pic_name` varchar(100) DEFAULT NULL,
  `pic_position` varchar(100) DEFAULT NULL,
  `pic_phone` varchar(50) DEFAULT NULL,
  `pic_email` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_name` varchar(150) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `bank_swift_code` varchar(50) DEFAULT NULL,
  `bank_iban` varchar(50) DEFAULT NULL,
  `bank_currency` varchar(10) DEFAULT 'IDR',
  `website` varchar(150) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_manufactures`
--

INSERT INTO `master_manufactures` (`id`, `manufactures_code`, `manufactures_name`, `manufacture_code`, `manufacture_name`, `brand_name`, `origin_type`, `country`, `city`, `address`, `phone`, `email`, `pic_name`, `pic_position`, `pic_phone`, `pic_email`, `bank_name`, `bank_account_name`, `bank_account_number`, `bank_swift_code`, `bank_iban`, `bank_currency`, `website`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'YAXIN', 'Suzhou Yaxin Medical Products Co., Ltd', 'YAXIN', 'Suzhou Yaxin Medical Products Co., Ltd', 'RIZKIMED', 'Import', 'China', 'Suzhou', 'No.12, Zhongta Road, Mudu Town, Suzhou 215101, Jiangsu province, China', '+86 152 5017 8777', 'yaxin@yx-yiliao.com', 'Jim Wu', 'Sales', NULL, 'yaxin@yx-yiliao.com', 'JPMorgan Chase Bank N.A., Singapore Branch', 'Suzhou Yaxin Medical Products Co., Ltd', '1014 1740 2041 66', 'CHASSGSGXXX  or CHASSGSG', NULL, 'IDR', NULL, 1, '2026-01-01 18:00:42', '2026-01-02 01:00:42', NULL);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
