-- 067_wqs_stock_adjustments.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `wqs_stock_adjustments`
--

CREATE TABLE `wqs_stock_adjustments` (
  `id` int NOT NULL,
  `adj_code` varchar(40) NOT NULL,
  `product_id` int NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `delta_qty` decimal(18,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
