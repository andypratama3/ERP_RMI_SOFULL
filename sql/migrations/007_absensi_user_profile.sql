-- 007_absensi_user_profile.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `absensi_user_profile`
--

CREATE TABLE `absensi_user_profile` (
  `user_id` bigint NOT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `is_hr_admin` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `absensi_user_profile`
--

INSERT INTO `absensi_user_profile` (`user_id`, `office_code`, `is_hr_admin`, `updated_at`) VALUES
(1, 'JGY', 0, '2026-01-02 01:40:53'),
(2, 'JGY', 0, '2026-01-02 01:41:00');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
