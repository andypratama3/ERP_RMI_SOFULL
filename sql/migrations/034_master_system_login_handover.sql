-- 034_master_system_login_handover.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_system_login_handover`
--

CREATE TABLE `master_system_login_handover` (
  `id` bigint NOT NULL,
  `username` varchar(50) NOT NULL,
  `old_holder_employee_code` varchar(50) DEFAULT NULL,
  `new_holder_employee_code` varchar(50) DEFAULT NULL,
  `changed_by` varchar(50) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
