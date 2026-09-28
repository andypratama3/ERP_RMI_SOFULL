-- 013_fa_dep_lines.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `fa_dep_lines`
--

CREATE TABLE `fa_dep_lines` (
  `id` int NOT NULL,
  `run_id` int NOT NULL,
  `asset_id` int NOT NULL,
  `period_ym` varchar(7) NOT NULL,
  `opening_book` decimal(18,2) NOT NULL DEFAULT '0.00',
  `dep_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `closing_book` decimal(18,2) NOT NULL DEFAULT '0.00',
  `accum_after` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
