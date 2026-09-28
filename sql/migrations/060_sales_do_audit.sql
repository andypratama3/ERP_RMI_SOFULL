-- 060_sales_do_audit.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `sales_do_audit`
--

CREATE TABLE `sales_do_audit` (
  `id` bigint UNSIGNED NOT NULL,
  `do_id` int NOT NULL,
  `status_from` varchar(50) DEFAULT NULL,
  `status_to` varchar(50) DEFAULT NULL,
  `actor_dept` varchar(50) DEFAULT NULL,
  `actor_name` varchar(100) DEFAULT NULL,
  `note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
