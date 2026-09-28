-- 005_absensi_requests.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `absensi_requests`
--

CREATE TABLE `absensi_requests` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `username` varchar(80) NOT NULL,
  `req_type` varchar(16) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'PENDING',
  `approver_id` bigint DEFAULT NULL,
  `approver_name` varchar(80) DEFAULT NULL,
  `note` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
