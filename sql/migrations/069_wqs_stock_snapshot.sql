-- 069_wqs_stock_snapshot.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `wqs_stock_snapshot`
--

CREATE TABLE `wqs_stock_snapshot` (
  `id` int NOT NULL,
  `locked_at` datetime NOT NULL,
  `product_id` int NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `stock_qty` decimal(18,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS=1;
