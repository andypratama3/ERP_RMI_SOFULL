-- 032_master_products.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_products`
--

CREATE TABLE `master_products` (
  `id` int NOT NULL,
  `sku` varchar(50) NOT NULL,
  `products_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `product_group` varchar(20) DEFAULT NULL,
  `no_akl` varchar(100) DEFAULT NULL,
  `manufacture_id` int DEFAULT NULL,
  `vendor_id` int DEFAULT NULL,
  `stock_qty` int NOT NULL DEFAULT '0',
  `price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(50) NOT NULL DEFAULT 'unit',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `photo_front` varchar(255) DEFAULT NULL,
  `photo_back` varchar(255) DEFAULT NULL,
  `photo_box` varchar(255) DEFAULT NULL,
  `photo_unpacked` varchar(255) DEFAULT NULL,
  `video_front` varchar(255) DEFAULT NULL,
  `video_back` varchar(255) DEFAULT NULL,
  `video_box` varchar(255) DEFAULT NULL,
  `video_unpacked` varchar(255) DEFAULT NULL,
  `general_name` varchar(200) DEFAULT NULL,
  `licence_number` varchar(100) DEFAULT NULL,
  `min_price` int NOT NULL DEFAULT '0',
  `max_price` int NOT NULL DEFAULT '0',
  `listing_level` int DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `akl_reg_no` varchar(150) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `current_stock` int NOT NULL DEFAULT '0',
  `product_type` enum('SINGLE','PAKET') NOT NULL DEFAULT 'SINGLE',
  `package_items` longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_products`
--

INSERT INTO `master_products` (`id`, `sku`, `products_name`, `category`, `product_group`, `no_akl`, `manufacture_id`, `vendor_id`, `stock_qty`, `price`, `unit`, `status`, `created_at`, `updated_at`, `photo_front`, `photo_back`, `photo_box`, `photo_unpacked`, `video_front`, `video_back`, `video_box`, `video_unpacked`, `general_name`, `licence_number`, `min_price`, `max_price`, `listing_level`, `barcode`, `akl_reg_no`, `exp_date`, `current_stock`, `product_type`, `package_items`) VALUES
(1, 'OBT-001', 'Obat A', NULL, NULL, NULL, NULL, NULL, 100, 50000.00, 'unit', 'active', '2025-12-08 17:33:28', '2025-12-08 17:33:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, '8991234567890', 'AKL.12345678901', NULL, 120, 'SINGLE', NULL),
(2, 'OBT-002', 'Obat B', NULL, NULL, NULL, NULL, NULL, 150, 75000.00, 'unit', 'active', '2025-12-08 17:33:28', '2025-12-08 17:33:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, 'SINGLE', NULL),
(3, 'ALK-001', 'Alkes A', NULL, NULL, NULL, NULL, NULL, 50, 150000.00, 'unit', 'active', '2025-12-08 17:33:28', '2025-12-08 17:33:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, 'SINGLE', NULL);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
