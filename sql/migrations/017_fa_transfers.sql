-- 017_fa_transfers.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `fa_transfers`
--

CREATE TABLE `fa_transfers` (
  `id` int NOT NULL,
  `asset_id` int NOT NULL,
  `transfer_date` date NOT NULL,
  `from_office` varchar(30) DEFAULT '',
  `to_office` varchar(30) DEFAULT '',
  `from_dept` varchar(30) DEFAULT '',
  `to_dept` varchar(30) DEFAULT '',
  `from_custodian` int DEFAULT NULL,
  `to_custodian` int DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
