-- 006_absensi_settings.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `absensi_settings`
--

CREATE TABLE `absensi_settings` (
  `k` varchar(64) NOT NULL,
  `v` text,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `absensi_settings`
--

INSERT INTO `absensi_settings` (`k`, `v`, `updated_at`) VALUES
('geofence_enforce', '1', '2026-01-01 21:32:41');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
