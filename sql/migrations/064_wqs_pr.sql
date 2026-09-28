-- 064_wqs_pr.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `wqs_pr`
--

CREATE TABLE `wqs_pr` (
  `id` int NOT NULL,
  `pr_code` varchar(60) NOT NULL,
  `pr_date` date NOT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `note` text,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `wqs_pr`
--

INSERT INTO `wqs_pr` (`id`, `pr_code`, `pr_date`, `office_code`, `status`, `note`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'RMI-PR-BGR-260101-001', '2026-01-01', 'BGR', 'PO_CREATED', '', 'admin', '2026-01-02 00:35:42', '2026-01-02 01:02:50', NULL);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
