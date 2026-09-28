-- 065_wqs_pr_items.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `wqs_pr_items`
--

CREATE TABLE `wqs_pr_items` (
  `id` int NOT NULL,
  `pr_id` int NOT NULL,
  `line_no` int NOT NULL DEFAULT '1',
  `product_id` int DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `products_name` varchar(255) DEFAULT NULL,
  `qty` decimal(18,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(30) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `wqs_pr_items`
--

INSERT INTO `wqs_pr_items` (`id`, `pr_id`, `line_no`, `product_id`, `sku`, `products_name`, `qty`, `unit`, `deleted_at`, `created_at`) VALUES
(1, 1, 1, 3, 'ALK-001', 'Alkes A', 10.00, 'unit', NULL, '2026-01-02 00:35:42'),
(2, 1, 2, 1, 'OBT-001', 'Obat A', 10.00, 'unit', NULL, '2026-01-02 00:35:42');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
