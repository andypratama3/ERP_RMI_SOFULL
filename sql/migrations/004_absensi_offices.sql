-- 004_absensi_offices.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `absensi_offices`
--

CREATE TABLE `absensi_offices` (
  `office_code` varchar(32) NOT NULL,
  `office_name` varchar(120) NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `radius_m` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `absensi_offices`
--

INSERT INTO `absensi_offices` (`office_code`, `office_name`, `lat`, `lng`, `radius_m`, `is_active`, `updated_at`) VALUES
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-01 21:32:41');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
