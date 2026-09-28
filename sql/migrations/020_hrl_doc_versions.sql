-- 020_hrl_doc_versions.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `hrl_doc_versions`
--

CREATE TABLE `hrl_doc_versions` (
  `id` int NOT NULL,
  `doc_id` int NOT NULL,
  `version_no` int NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `checksum` varchar(64) DEFAULT NULL,
  `change_log` text,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `submitted_at` datetime DEFAULT NULL,
  `submitted_by` varchar(50) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(50) DEFAULT NULL,
  `rejected_note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(50) NOT NULL DEFAULT '',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `hrl_doc_versions`
--

INSERT INTO `hrl_doc_versions` (`id`, `doc_id`, `version_no`, `file_path`, `file_name`, `mime`, `file_size`, `checksum`, `change_log`, `status`, `submitted_at`, `submitted_by`, `approved_at`, `approved_by`, `rejected_note`, `created_at`, `created_by`, `deleted_at`) VALUES
(1, 1, 1, 'uploads/hrl/docs/HRL-LEGAL-CHECKLIST-20260101-EC68DE/v1_20260101_190137_876eff_Checklist_Dokumen_Labeling.docx', 'Checklist_Dokumen_Labeling.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 164320, '3772b4d38bc8eb95bf0f5f848515071201cc8716758d1b597da4a643e4784c88', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(2, 2, 1, 'uploads/hrl/docs/HRL-LEGAL-SOP-20260101-61A039/v1_20260101_190137_634396_SOP_PENGELOLAAN_DAN_PENGENDALIAN_DOKUMEN_LABELING.docx', 'SOP_PENGELOLAAN_DAN_PENGENDALIAN_DOKUMEN_LABELING.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 29493, '5f68bdcbcd0f156880657e470f84f5d67c0799e247c03a4ed2eeda758ea64c8c', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(3, 3, 1, 'uploads/hrl/docs/HRL-LEGAL-SOP-20260101-B556BB/v1_20260101_190137_d61cb6_SOP_PENGENDALIAN_DATA_SKU.docx', 'SOP_PENGENDALIAN_DATA_SKU.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 30240, '6aaadbbc73e0133bffee8966bc328eaa6ba8974c934215de0ef7b963bd3b15e4', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(4, 4, 1, 'uploads/hrl/docs/HRL-HR-FORM-20260101-1B03E4/v1_20260101_190137_081834_form_pengajuan_perjadin.docx', 'form_pengajuan_perjadin.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 64795, 'a0dc58521936c814c2d2617530ccc5dac16580065d15db6dbda5ad0bd914260b', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(5, 5, 1, 'uploads/hrl/docs/HRL-LEGAL-PP-20260101-D18029/v1_20260101_190137_66b6e0_Peraturan_Perusahaan_revisi__Desember_2025_.pdf', 'Peraturan_Perusahaan_revisi__Desember_2025_.pdf', 'application/pdf', 1187314, 'e7460050302bc82ba878a260210364ecf49c5a1b6470b29b1fefa2cf565c2220', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(6, 6, 1, 'uploads/hrl/docs/HRL-HR-FORM-20260101-46B552/v1_20260101_190137_bfdcb8_form_cuti_dan_izin__2_.docx', 'form_cuti_dan_izin__2_.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 53888, '3ee9e5466a09e1a3401c3282f889786cae834bbb5a202be4c8179063b684a867', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(7, 7, 1, 'uploads/hrl/docs/HRL-HR-FORM-20260101-244AD1/v1_20260101_190137_8df7d8_Form_lembur_SPV.docx', 'Form_lembur_SPV.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 52882, '51208983f1f0697b707f62baa1fbb5d7c81c8a3e8bee55e3f6abc68b1b43c2e3', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(8, 8, 1, 'uploads/hrl/docs/HRL-HR-FORM-20260101-625F1C/v1_20260101_190137_429cb3_Form_Lembur_Staff.docx', 'Form_Lembur_Staff.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 52837, 'cd1d244fd7d5adb2a6ebf8e312a97d7ea131d4b7873abf945a05e4f7a639f3f9', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(9, 9, 1, 'uploads/hrl/docs/HRL-HR-FORM-20260101-3D3587/v1_20260101_190137_da4453_form_kenaikan_gaji.docx', 'form_kenaikan_gaji.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 52832, '03975f163c1c70bb6cc4eb3120f1d121337c1b903e9a9298f0131242ce2f1978', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(10, 10, 1, 'uploads/hrl/docs/HRL-LEGAL-DOC-20260101-0C85CD/v1_20260101_190137_0ca6ae_Untitled.docx', 'Untitled.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 60441, 'b27147778030c95db51b7f64fb7e1f51ff123ad31dd156eb2d82c5e1eb03917a', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL),
(11, 11, 1, 'uploads/hrl/docs/HRL-HR-FORM-20260101-9A5E2A/v1_20260101_190137_ebc9c8_Form_permintaan_karyawan.docx', 'Form_permintaan_karyawan.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 73424, '81261a5dc3c33938ca95112b9f3586d1b526eeffbd7fbda5ec9f2b61e7bbc19f', 'Imported from ZIP', 'APPROVED', '2026-01-01 19:01:37', 'admin', '2026-01-01 19:01:37', 'admin', NULL, '2026-01-02 02:01:37', 'admin', NULL);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
