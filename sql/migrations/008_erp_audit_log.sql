-- 008_erp_audit_log.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `erp_audit_log`
--

CREATE TABLE `erp_audit_log` (
  `id` bigint NOT NULL,
  `module` varchar(50) NOT NULL,
  `entity_key` varchar(120) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `user_id` int DEFAULT NULL,
  `username` varchar(120) DEFAULT NULL,
  `role` varchar(60) DEFAULT NULL,
  `level` varchar(60) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `payload_json` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
