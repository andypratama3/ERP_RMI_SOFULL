-- 068_wqs_stock_baseline_lock.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `wqs_stock_baseline_lock`
--

CREATE TABLE `wqs_stock_baseline_lock` (
  `id` tinyint NOT NULL,
  `locked_at` datetime NOT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
