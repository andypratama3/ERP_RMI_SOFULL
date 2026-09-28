-- 055_purchases_po_items.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `purchases_po_items`
--

CREATE TABLE `purchases_po_items` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `line_no` int NOT NULL DEFAULT '1',
  `product_id` int DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `products_name` varchar(255) DEFAULT NULL,
  `qty` decimal(18,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(30) DEFAULT NULL,
  `unit_price` decimal(18,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_po_items`
--

INSERT INTO `purchases_po_items` (`id`, `po_id`, `line_no`, `product_id`, `sku`, `products_name`, `qty`, `unit`, `unit_price`, `subtotal`, `deleted_at`, `created_at`) VALUES
(1, 1, 1, 3, 'ALK-001', 'Alkes A', 10.00, 'unit', 10000.00, 100000.00, NULL, '2026-01-02 01:02:50'),
(2, 1, 2, 1, 'OBT-001', 'Obat A', 10.00, 'unit', 100.00, 1000.00, NULL, '2026-01-02 01:02:50');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
