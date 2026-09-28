-- 062_system_audit_logs.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `system_audit_logs`
--

CREATE TABLE `system_audit_logs` (
  `id` int NOT NULL,
  `module` varchar(100) NOT NULL,
  `action` varchar(50) NOT NULL,
  `record_table` varchar(100) DEFAULT NULL,
  `record_id` int DEFAULT NULL,
  `record_code` varchar(100) DEFAULT NULL,
  `description` text,
  `details` longtext,
  `user_id` int DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `level` varchar(50) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `system_audit_logs`
--

INSERT INTO `system_audit_logs` (`id`, `module`, `action`, `record_table`, `record_id`, `record_code`, `description`, `details`, `user_id`, `username`, `role`, `level`, `ip`, `user_agent`, `created_at`) VALUES
(1, 'master_manufactures', 'insert', 'master_manufactures', 1, 'YAXIN', 'Insert manufacture', '{\"manufacture_code\":\"YAXIN\",\"manufacture_name\":\"Suzhou Yaxin Medical Products Co., Ltd\"}', 2, 'admin', 'admin', 'ADMIN', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:00:42');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
