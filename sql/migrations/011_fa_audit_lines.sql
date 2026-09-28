-- 011_fa_audit_lines.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `fa_audit_lines`
--

CREATE TABLE `fa_audit_lines` (
  `id` int NOT NULL,
  `audit_id` int NOT NULL,
  `asset_id` int NOT NULL,
  `physical_status` enum('OK','MISSING','DAMAGED','NOT_FOUND') NOT NULL DEFAULT 'NOT_FOUND',
  `note` varchar(255) DEFAULT '',
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
