-- 003_absensi_logs.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `absensi_logs`
--

CREATE TABLE `absensi_logs` (
  `id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `username` varchar(80) DEFAULT NULL,
  `action_type` varchar(16) NOT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `distance_m` int DEFAULT NULL,
  `geo_lat` decimal(10,7) DEFAULT NULL,
  `geo_lng` decimal(10,7) DEFAULT NULL,
  `geo_acc` int DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
