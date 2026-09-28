-- 002_absensi_audit.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `absensi_audit`
--

CREATE TABLE `absensi_audit` (
  `id` bigint NOT NULL,
  `actor_user_id` bigint DEFAULT NULL,
  `actor_username` varchar(80) DEFAULT NULL,
  `action` varchar(48) NOT NULL,
  `payload_json` json DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `absensi_audit`
--

INSERT INTO `absensi_audit` (`id`, `actor_user_id`, `actor_username`, `action`, `payload_json`, `ip`, `user_agent`, `created_at`) VALUES
(1, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\": 1, \"is_hr_admin\": 0, \"office_code\": \"JGY\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:40:53'),
(2, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\": 2, \"is_hr_admin\": 0, \"office_code\": \"\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:40:54'),
(3, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\": 2, \"is_hr_admin\": 0, \"office_code\": \"JGY\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:41:00');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
