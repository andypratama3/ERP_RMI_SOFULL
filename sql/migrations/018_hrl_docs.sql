-- 018_hrl_docs.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `hrl_docs`
--

CREATE TABLE `hrl_docs` (
  `id` int NOT NULL,
  `doc_code` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'HR',
  `category` varchar(30) NOT NULL DEFAULT 'SOP',
  `scope` varchar(20) NOT NULL DEFAULT 'INTERNAL',
  `owner_dept` varchar(10) NOT NULL DEFAULT 'HRL',
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `current_version` int NOT NULL DEFAULT '0',
  `effective_date` date DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `description` text,
  `created_by` varchar(50) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `hrl_docs`
--

INSERT INTO `hrl_docs` (`id`, `doc_code`, `title`, `unit`, `category`, `scope`, `owner_dept`, `status`, `current_version`, `effective_date`, `tags`, `description`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'HRL-LEGAL-CHECKLIST-20260101-EC68DE', 'Checklist Dokumen Labeling', 'LEGAL', 'CHECKLIST', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(2, 'HRL-LEGAL-SOP-20260101-61A039', 'SOP PENGELOLAAN DAN PENGENDALIAN DOKUMEN LABELING', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(3, 'HRL-LEGAL-SOP-20260101-B556BB', 'SOP PENGENDALIAN DATA SKU', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(4, 'HRL-HR-FORM-20260101-1B03E4', 'form pengajuan perjadin', 'HR', 'FORM', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(5, 'HRL-LEGAL-PP-20260101-D18029', 'Peraturan Perusahaan revisi (Desember 2025)', 'LEGAL', 'PP', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(6, 'HRL-HR-FORM-20260101-46B552', 'form cuti dan izin (2)', 'HR', 'FORM', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(7, 'HRL-HR-FORM-20260101-244AD1', 'Form lembur SPV', 'HR', 'FORM', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(8, 'HRL-HR-FORM-20260101-625F1C', 'Form Lembur Staff', 'HR', 'FORM', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(9, 'HRL-HR-FORM-20260101-3D3587', 'form kenaikan gaji', 'HR', 'FORM', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(10, 'HRL-LEGAL-DOC-20260101-0C85CD', 'Untitled', 'LEGAL', 'DOC', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:01:37', NULL),
(11, 'HRL-HR-FORM-20260101-9A5E2A', 'Form permintaan karyawan', 'HR', 'FORM', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:02:56', NULL);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
