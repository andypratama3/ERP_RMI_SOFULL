-- 033_master_system_login.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_system_login`
--

CREATE TABLE `master_system_login` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'staff',
  `level` varchar(20) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `holder_employee_code` varchar(50) DEFAULT NULL,
  `holder_assigned_at` datetime DEFAULT NULL,
  `holder_assigned_by` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_system_login`
--

INSERT INTO `master_system_login` (`id`, `username`, `full_name`, `password_hash`, `role`, `level`, `department`, `office_code`, `holder_employee_code`, `holder_assigned_at`, `holder_assigned_by`, `status`, `created_at`, `updated_at`, `last_login_at`) VALUES
(1, 'superadmin', 'Super Admin', '$2b$10$pfgOq0FsaHzBSbdSnVh11eTYhueWwXNZzmMaO7W3dXtlHu2/jFjAy', 'owner', 'SUPERADMIN', 'SYS', NULL, NULL, NULL, NULL, 'active', '2026-01-01 21:17:38', '2026-01-01 21:30:21', NULL),
(2, 'admin', 'Admin', '$2b$10$pfgOq0FsaHzBSbdSnVh11eTYhueWwXNZzmMaO7W3dXtlHu2/jFjAy', 'admin', 'ADMIN', 'SYS', NULL, NULL, NULL, NULL, 'active', '2026-01-01 21:17:38', '2026-01-01 21:30:38', '2026-01-01 21:30:38');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
