-- 058_rbac_user_permissions.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `rbac_user_permissions`
--

CREATE TABLE `rbac_user_permissions` (
  `user_id` bigint NOT NULL,
  `perm_code` varchar(80) NOT NULL,
  `allow_flag` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
