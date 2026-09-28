-- 061_sales_do_items.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `sales_do_items`
--

CREATE TABLE `sales_do_items` (
  `id` int NOT NULL,
  `do_id` int NOT NULL,
  `line_no` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `is_package` tinyint(1) DEFAULT '0',
  `show_package` tinyint(1) DEFAULT '0',
  `stock_at_crm` int DEFAULT NULL,
  `show_package_items` tinyint(1) DEFAULT '0',
  `products_name` varchar(255) DEFAULT NULL,
  `qty` int DEFAULT '0',
  `unit` varchar(20) DEFAULT NULL,
  `unit_price` decimal(18,2) DEFAULT '0.00',
  `disc_percent` decimal(5,2) DEFAULT '0.00',
  `exp_date` date DEFAULT NULL,
  `serial_lot` varchar(100) DEFAULT NULL,
  `subtotal` decimal(18,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `sales_do_items`
--

INSERT INTO `sales_do_items` (`id`, `do_id`, `line_no`, `product_id`, `sku`, `barcode`, `is_package`, `show_package`, `stock_at_crm`, `show_package_items`, `products_name`, `qty`, `unit`, `unit_price`, `disc_percent`, `exp_date`, `serial_lot`, `subtotal`) VALUES
(5, 15, 1, 2, 'OBT-002', NULL, 0, 0, NULL, 0, 'Obat B', 1, 'unit', 75000.00, 0.00, NULL, NULL, 75000.00),
(6, 16, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, NULL, NULL, 50000.00),
(7, 17, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, NULL, NULL, 50000.00),
(8, 18, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, NULL, NULL, 50000.00),
(9, 19, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, '2025-12-14', '66666', 50000.00),
(10, 20, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 0.00, 0.00, NULL, NULL, 0.00);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
