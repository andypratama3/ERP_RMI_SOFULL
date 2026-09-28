-- 044_purchases_audit_log.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `purchases_audit_log`
--

CREATE TABLE `purchases_audit_log` (
  `id` int NOT NULL,
  `module` varchar(20) NOT NULL,
  `ref_code` varchar(80) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `details_json` longtext,
  `user_name` varchar(120) DEFAULT NULL,
  `user_role` varchar(50) DEFAULT NULL,
  `user_level` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_audit_log`
--

INSERT INTO `purchases_audit_log` (`id`, `module`, `ref_code`, `action`, `details_json`, `user_name`, `user_role`, `user_level`, `created_at`) VALUES
(1, 'PR', 'RMI-PR-BGR-260101-001', 'CREATE', '{\"pr_id\":1}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 00:35:42'),
(2, 'PR', 'RMI-PR-BGR-260101-001', 'UPDATE_HEADER', '{\"id\":1}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 00:35:57'),
(3, 'PR', 'RMI-PR-BGR-260101-001', 'UPDATE_ITEMS', '{\"count\":2}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 00:36:22'),
(4, 'PR', 'RMI-PR-BGR-260101-001', 'SUBMIT', '{\"id\":1}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 00:36:27'),
(5, 'PO', 'RMI-PO-BGR-260101-001', 'CREATE', '{\"po_id\":1,\"pr_id\":1,\"manufacture_id\":1}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 01:02:50'),
(6, 'PO', 'RMI-PO-BGR-260101-001', 'UPDATE_ITEMS', '{\"count\":2}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 01:02:58'),
(7, 'PO', 'RMI-PO-BGR-260101-001', 'UPDATE_HEADER', '{\"id\":1,\"status\":\"IN_PRODUCTION\"}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 01:03:16'),
(8, 'IMPORT_CTRL', 'RMI-PO-BGR-260101-001', 'UPDATE_PRODUCTION', '{\"start\":null,\"done\":\"2026-02-02\"}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 01:06:26'),
(9, 'AP', 'RMI-AP-BGR-260101-001', 'CREATE', '{\"po_id\":1,\"type\":\"PROFORMA\",\"total\":0}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 01:08:13'),
(10, 'FWD_QUOTE', '1', 'CREATE', '{\"vendor_id\":1,\"total_cost\":100000000,\"currency\":\"IDR\"}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 01:53:51'),
(11, 'FWD_QUOTE', '1', 'SELECT', '{\"quote_id\":1,\"vendor_id\":1}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 01:53:56'),
(12, 'PO', 'RMI-PO-BGR-260101-001', 'UPDATE_ITEMS', '{\"count\":2}', 'admin', 'ADMIN', 'ADMIN', '2026-01-02 07:15:49');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
