-- 051_purchases_import_control.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `purchases_import_control`
--

CREATE TABLE `purchases_import_control` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `production_start_date` date DEFAULT NULL,
  `production_done_date` date DEFAULT NULL,
  `pickup_date` date DEFAULT NULL,
  `etd` date DEFAULT NULL,
  `eta` date DEFAULT NULL,
  `arrived_id_date` date DEFAULT NULL,
  `arrived_warehouse_date` date DEFAULT NULL,
  `note` text,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_import_control`
--

INSERT INTO `purchases_import_control` (`id`, `po_id`, `production_start_date`, `production_done_date`, `pickup_date`, `etd`, `eta`, `arrived_id_date`, `arrived_warehouse_date`, `note`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, '2026-02-02', NULL, NULL, NULL, NULL, NULL, '', 'admin', '2026-01-02 01:03:30', '2026-01-02 01:06:26');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
