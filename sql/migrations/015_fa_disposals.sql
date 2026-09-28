-- 015_fa_disposals.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `fa_disposals`
--

CREATE TABLE `fa_disposals` (
  `id` int NOT NULL,
  `asset_id` int NOT NULL,
  `disposal_date` date NOT NULL,
  `disposal_type` enum('SOLD','DAMAGED','LOST') NOT NULL,
  `proceeds` decimal(18,2) NOT NULL DEFAULT '0.00',
  `doc_ref` varchar(120) DEFAULT '',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
