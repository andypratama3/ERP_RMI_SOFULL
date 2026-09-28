-- 024_master_discount_policy.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_discount_policy`
--

CREATE TABLE `master_discount_policy` (
  `id` int NOT NULL,
  `department_code` varchar(20) NOT NULL,
  `level_name` varchar(50) NOT NULL,
  `segment` varchar(50) DEFAULT NULL,
  `max_discount_percent` decimal(5,2) NOT NULL,
  `notes` text,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
