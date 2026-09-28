-- 025_master_emailcompany.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_emailcompany`
--

CREATE TABLE `master_emailcompany` (
  `id` int NOT NULL,
  `scope_type` varchar(20) NOT NULL DEFAULT 'department',
  `dept_code` varchar(20) DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `email_local` varchar(100) NOT NULL,
  `email_domain` varchar(100) NOT NULL DEFAULT 'rizqullahmediska.com',
  `email_full` varchar(200) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '1',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_emailcompany`
--

INSERT INTO `master_emailcompany` (`id`, `scope_type`, `dept_code`, `office_code`, `email_local`, `email_domain`, `email_full`, `note`, `is_primary`, `status`, `created_at`, `updated_at`) VALUES
(1, 'office', NULL, NULL, 'rmi', 'rizqullahmediska.com', 'rmi@rizqullahmediska.com', '', 1, 'active', '2025-12-10 11:13:48', '2025-12-10 11:13:48'),
(2, 'office', NULL, NULL, 'info', 'rizqullahmediska.com', 'info@rizqullahmediska.com', 'Email induk kantor / pusat RMI', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36'),
(3, 'department', 'ACT', NULL, 'act', 'rizqullahmediska.com', 'act@rizqullahmediska.com', 'Email resmi Departemen Accounting & Tax', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36'),
(4, 'department', 'CRM', NULL, 'crm', 'rizqullahmediska.com', 'crm@rizqullahmediska.com', 'Email resmi Departemen Customer Relationship Management', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36'),
(5, 'department', 'FIN', NULL, 'fin', 'rizqullahmediska.com', 'fin@rizqullahmediska.com', 'Email resmi Departemen Finance', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36'),
(6, 'department', 'HRL', NULL, 'hrl', 'rizqullahmediska.com', 'hrl@rizqullahmediska.com', 'Email resmi Departemen Human Resource & Legal', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36'),
(7, 'department', 'ITC', NULL, 'itc', 'rizqullahmediska.com', 'itc@rizqullahmediska.com', 'Email resmi Departemen IT & Cloud', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36'),
(8, 'department', 'MPR', NULL, 'mpr', 'rizqullahmediska.com', 'mpr@rizqullahmediska.com', 'Email resmi Departemen Marketing & Project', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36'),
(9, 'department', 'PQP', NULL, 'pqp', 'rizqullahmediska.com', 'pqp@rizqullahmediska.com', 'Email resmi Departemen Product Quality & Purchasing', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36'),
(10, 'department', 'SCM', NULL, 'scm', 'rizqullahmediska.com', 'scm@rizqullahmediska.com', 'Email resmi Departemen Supply Chain Management', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36'),
(11, 'department', 'WQS', NULL, 'wqs', 'rizqullahmediska.com', 'wqs@rizqullahmediska.com', 'Email resmi Departemen Warehouse & Quantity', 1, 'active', '2025-12-10 11:22:36', '2025-12-10 11:22:36');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
