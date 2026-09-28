-- 016_fa_maintenance.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `fa_maintenance`
--

CREATE TABLE `fa_maintenance` (
  `id` int NOT NULL,
  `asset_id` int NOT NULL,
  `maint_date` date NOT NULL,
  `vendor` varchar(200) DEFAULT '',
  `cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `downtime_hours` decimal(10,2) NOT NULL DEFAULT '0.00',
  `description` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
