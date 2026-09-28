-- phpMyAdmin SQL Dump
-- version 6.0.0-dev+20251118.dfcf3dd949
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Waktu pembuatan: 02 Jan 2026 pada 01.10
-- Versi server: 8.0.44
-- Versi PHP: 8.5.0

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Basis data: `ERP_RMI_SOFULL`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_audit`
--

CREATE TABLE `absensi_audit` (
  `id` bigint NOT NULL,
  `actor_user_id` bigint DEFAULT NULL,
  `actor_username` varchar(80) DEFAULT NULL,
  `action` varchar(48) NOT NULL,
  `payload_json` json DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `absensi_audit`
--

INSERT INTO `absensi_audit` (`id`, `actor_user_id`, `actor_username`, `action`, `payload_json`, `ip`, `user_agent`, `created_at`) VALUES
(1, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\": 1, \"is_hr_admin\": 0, \"office_code\": \"JGY\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:40:53'),
(2, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\": 2, \"is_hr_admin\": 0, \"office_code\": \"\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:40:54'),
(3, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\": 2, \"is_hr_admin\": 0, \"office_code\": \"JGY\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:41:00');

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_logs`
--

CREATE TABLE `absensi_logs` (
  `id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `username` varchar(80) DEFAULT NULL,
  `action_type` varchar(16) NOT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `distance_m` int DEFAULT NULL,
  `geo_lat` decimal(10,7) DEFAULT NULL,
  `geo_lng` decimal(10,7) DEFAULT NULL,
  `geo_acc` int DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_offices`
--

CREATE TABLE `absensi_offices` (
  `office_code` varchar(32) NOT NULL,
  `office_name` varchar(120) NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `radius_m` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `absensi_offices`
--

INSERT INTO `absensi_offices` (`office_code`, `office_name`, `lat`, `lng`, `radius_m`, `is_active`, `updated_at`) VALUES
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-01 21:32:41');

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_requests`
--

CREATE TABLE `absensi_requests` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `username` varchar(80) NOT NULL,
  `req_type` varchar(16) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'PENDING',
  `approver_id` bigint DEFAULT NULL,
  `approver_name` varchar(80) DEFAULT NULL,
  `note` text,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_settings`
--

CREATE TABLE `absensi_settings` (
  `k` varchar(64) NOT NULL,
  `v` text,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `absensi_settings`
--

INSERT INTO `absensi_settings` (`k`, `v`, `updated_at`) VALUES
('geofence_enforce', '1', '2026-01-01 21:32:41');

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_user_profile`
--

CREATE TABLE `absensi_user_profile` (
  `user_id` bigint NOT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `is_hr_admin` tinyint(1) NOT NULL DEFAULT '0',
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `absensi_user_profile`
--

INSERT INTO `absensi_user_profile` (`user_id`, `office_code`, `is_hr_admin`, `updated_at`) VALUES
(1, 'JGY', 0, '2026-01-02 01:40:53'),
(2, 'JGY', 0, '2026-01-02 01:41:00');

-- --------------------------------------------------------

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

--
-- Struktur dari tabel `fa_assets`
--

CREATE TABLE `fa_assets` (
  `id` int NOT NULL,
  `asset_code` varchar(50) NOT NULL,
  `asset_name` varchar(200) NOT NULL,
  `category` varchar(100) DEFAULT '',
  `office_code` varchar(30) DEFAULT '',
  `dept_code` varchar(30) DEFAULT '',
  `custodian_emp_id` int DEFAULT NULL,
  `vendor_name` varchar(200) DEFAULT '',
  `purchase_ref` varchar(100) DEFAULT '',
  `invoice_no` varchar(100) DEFAULT '',
  `acq_date` date NOT NULL,
  `acq_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `salvage_value` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_group_code` varchar(20) NOT NULL,
  `dep_method` enum('SL','DDB') NOT NULL DEFAULT 'SL',
  `status` enum('ACTIVE','INACTIVE','DISPOSED') NOT NULL DEFAULT 'ACTIVE',
  `disposed_at` date DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_audits`
--

CREATE TABLE `fa_audits` (
  `id` int NOT NULL,
  `audit_code` varchar(50) NOT NULL,
  `office_code` varchar(30) DEFAULT '',
  `audit_date` date NOT NULL,
  `status` enum('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  `created_by` int DEFAULT NULL,
  `closed_by` int DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_audit_lines`
--

CREATE TABLE `fa_audit_lines` (
  `id` int NOT NULL,
  `audit_id` int NOT NULL,
  `asset_id` int NOT NULL,
  `physical_status` enum('OK','MISSING','DAMAGED','NOT_FOUND') NOT NULL DEFAULT 'NOT_FOUND',
  `note` varchar(255) DEFAULT '',
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_audit_log`
--

CREATE TABLE `fa_audit_log` (
  `id` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` int DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity` varchar(50) NOT NULL DEFAULT 'SYSTEM',
  `entity_id` int NOT NULL DEFAULT '0',
  `meta_json` longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_dep_lines`
--

CREATE TABLE `fa_dep_lines` (
  `id` int NOT NULL,
  `run_id` int NOT NULL,
  `asset_id` int NOT NULL,
  `period_ym` varchar(7) NOT NULL,
  `opening_book` decimal(18,2) NOT NULL DEFAULT '0.00',
  `dep_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `closing_book` decimal(18,2) NOT NULL DEFAULT '0.00',
  `accum_after` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_dep_runs`
--

CREATE TABLE `fa_dep_runs` (
  `id` int NOT NULL,
  `period_ym` varchar(7) NOT NULL,
  `run_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `run_by` int DEFAULT NULL,
  `total_assets` int NOT NULL DEFAULT '0',
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_disposals`
--

CREATE TABLE `fa_disposals` (
  `id` int NOT NULL,
  `asset_id` int NOT NULL,
  `disposal_date` date NOT NULL,
  `disposal_type` enum('SOLD','DAMAGED','LOST') NOT NULL,
  `proceeds` decimal(18,2) NOT NULL DEFAULT '0.00',
  `doc_ref` varchar(120) DEFAULT '',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_maintenance`
--

CREATE TABLE `fa_maintenance` (
  `id` int NOT NULL,
  `asset_id` int NOT NULL,
  `maint_date` date NOT NULL,
  `vendor` varchar(200) DEFAULT '',
  `cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `downtime_hours` decimal(10,2) NOT NULL DEFAULT '0.00',
  `description` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_transfers`
--

CREATE TABLE `fa_transfers` (
  `id` int NOT NULL,
  `asset_id` int NOT NULL,
  `transfer_date` date NOT NULL,
  `from_office` varchar(30) DEFAULT '',
  `to_office` varchar(30) DEFAULT '',
  `from_dept` varchar(30) DEFAULT '',
  `to_dept` varchar(30) DEFAULT '',
  `from_custodian` int DEFAULT NULL,
  `to_custodian` int DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

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

--
-- Struktur dari tabel `hrl_doc_acks`
--

CREATE TABLE `hrl_doc_acks` (
  `id` int NOT NULL,
  `doc_id` int NOT NULL,
  `version_no` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `employee_code` varchar(50) DEFAULT NULL,
  `department` varchar(10) DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `ack_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ack_ip` varchar(45) DEFAULT NULL,
  `ack_user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

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

--
-- Struktur dari tabel `master_company_bank_accounts`
--

CREATE TABLE `master_company_bank_accounts` (
  `id` bigint NOT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `bank_name` varchar(120) NOT NULL,
  `account_number` varchar(60) NOT NULL,
  `account_name` varchar(160) NOT NULL,
  `branch` varchar(120) DEFAULT NULL,
  `purpose` varchar(20) NOT NULL DEFAULT 'RECEIVE',
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_customers`
--

CREATE TABLE `master_customers` (
  `id` int NOT NULL,
  `customers_code` varchar(50) NOT NULL,
  `customers_name` varchar(200) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `name` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `npwp` varchar(50) DEFAULT NULL,
  `segment` varchar(100) DEFAULT NULL,
  `customer_type` varchar(50) DEFAULT NULL,
  `staff_mpr_code` varchar(50) DEFAULT NULL,
  `staff_crm_code` varchar(50) DEFAULT NULL,
  `staff_scm_code` varchar(50) DEFAULT NULL,
  `staff_act_code` varchar(50) DEFAULT NULL,
  `staff_fin_code` varchar(50) DEFAULT NULL,
  `customer_group` varchar(20) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `cover_area` varchar(150) DEFAULT NULL,
  `maps_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_customers`
--

INSERT INTO `master_customers` (`id`, `customers_code`, `customers_name`, `category`, `name`, `phone`, `email`, `npwp`, `segment`, `customer_type`, `staff_mpr_code`, `staff_crm_code`, `staff_scm_code`, `staff_act_code`, `staff_fin_code`, `customer_group`, `status`, `created_at`, `updated_at`, `address`, `city`, `office_code`, `cover_area`, `maps_url`) VALUES
(1, 'Hoo1', 'RSU Hermina Depok', '', 'RS Sehat Selalu', '021123456', 'rs@demo.local', '00000', '', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2025-12-10 16:55:02', 'Jl. Kesehatan No.1', '', 'bgr', '', ''),
(2, '', 'Klinik Medika', NULL, 'Klinik Medika', '021654321', 'klinik@demo.local', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Klinik No.2', NULL, NULL, NULL, NULL),
(3, 'H001;RS HERMINA ACEH;RS Swasta;Hermina;Kab Aceh Be', 'Aje Cut Ingin Jaya Kab Aceh Besar Aceh;https://maps.app.goo.gl/km1ixb5i9omdrRhs5;06518072525;marketing.aceh@herminahospitals.com;42.455.114.1.108.000;active', NULL, NULL, '', '', '', '', '', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', '', NULL, NULL, NULL),
(4, 'H002;RS HERMINA ARCAMANIK;RS Swasta;Hermina;Kota B', 'Kel. Antapani Wetan', NULL, NULL, '', '', '', 'Kec. Antapani', 'kota Bandung;https://maps.app.goo.gl/AEb2eoqkQY7fV', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', '', NULL, NULL, NULL),
(5, 'H003;RS HERMINA BALIKPAPAN;RS Swasta;Hermina;Kota ', 'Sepingan Baru Balikpapan', NULL, NULL, '', '', '', 'Kalimantan Timur', 'Indonesia;https://maps.app.goo.gl/8NRU37LAZGZKtn7X', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', '', NULL, NULL, NULL),
(6, 'H004;RS HERMINA BANYUMANIK;RS Swasta;Hermina;Kota ', 'Srondol Wetan', NULL, NULL, '', '', '', 'Kec. Banyumanik', 'Kota Semarang', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Tengah 50263;https://maps.app.goo.gl/5eRQ6r8LN2jT7qS38;02476488989;marketing.banyumanik@hermina', NULL, NULL, NULL),
(7, 'H005;RS HERMINA BEKASI;RS Swasta;Hermina;Kota Beka', 'RT.004/RW.003', NULL, NULL, '', '', '', 'Marga Jaya', 'Kec. Bekasi Sel.', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Jawa Barat 17141;https://maps.app.goo.gl/QDNmGJ5srtDkhBrU8;0218842121;marketing.bekasi@herminahospitals.com;01.783.421.9-007.000;active', 'Kota Bks', NULL, NULL, NULL),
(8, 'H006;RS HERMINA BITUNG;RS Swasta;Hermina;Kabupaten', 'Jl. Raya Serang No.10', NULL, NULL, '', '', '', 'Kadu', 'Kec. Curug', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Banten 15810;https://maps.app.goo.gl/4BhpzLZQ1c1B334C7;02159497525;marketing.bitung@herminahospitals.com;73.379.780.7-451.000;active', 'Kabupaten Tangerang', NULL, NULL, NULL),
(9, 'H007;RS HERMINA BOGOR;RS Swasta;Hermina;Kota Bogor', '25', NULL, NULL, 'Kota Bogor', 'Jawa Barat 16113;https://maps.app.goo.gl/Rmiz4aLbpWnK8kx17;02518382525;marketing.bogor@herminahospitals.com;02.073.142.8.404.001;active', '', '27', 'RT.08/RW.08', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Kec. Bogor Bar.', 'Curugmekar', NULL, NULL, NULL),
(10, 'H008;RS HERMINA CIAWI;RS Swasta;Hermina;Kabupaten ', 'Pandansari', NULL, NULL, '', '', '', 'Kec. Ciawi', 'Kabupaten Bogor', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Barat 16720;https://maps.app.goo.gl/BSPkTMnLCLVK7AHE8;02518407575;marketing.ciawi@herminahospit', NULL, NULL, NULL),
(11, 'H009;RS HERMINA CILEDUG;RS Swasta;Hermina;Kota Tan', 'Ciledug', NULL, NULL, 'Banten 15151;https://maps.app.goo.gl/FV1pWnuzmmhkR', '', '', 'RT.003/RW.004', 'East Sudimara', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Tangerang City', 'Tangerang', NULL, NULL, NULL),
(12, 'H010;RS HERMINA CILEGON;RS Swasta;Hermina;Kota Cil', 'Jl. Terusan Jl. Bonakarta', NULL, NULL, 'Banten 42414;https://maps.app.goo.gl/eykeAimUcenjd', '', '', 'RT.01/RW.01', 'Masigit', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Kota Cilegon', 'Kec. Jombang', NULL, NULL, NULL),
(13, 'H011;RS HERMINA CIPUTAT;RS Swasta;Hermina;Kota Tan', 'Ciputat', NULL, NULL, '', '', '', 'Ciputat Timur', 'South Tangerang City', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Banten 15419;https://maps.app.goo.gl/15rDuKr746oU2MeYA;02174702525;marketing.ciputat@herminahospital', NULL, NULL, NULL),
(14, 'H012;RS HERMINA CIRUAS;RS Swasta;Hermina;Kabupaten', 'Ranjeng', NULL, NULL, '', '', '', 'Kec. Ciruas', 'Kabupaten Serang', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Banten 42182;https://maps.app.goo.gl/B1b8xskRYAnUCQ2r5;0254281829;marketing.ciruas@herminahospitals.', NULL, NULL, NULL),
(15, 'H013;RS HERMINA DAAN MOGOT;RS Swasta;Hermina;Jakar', 'RT.1/RW.12', NULL, NULL, '', '', '', 'Kalideres', 'Kec. Kalideres', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Daerah Khusus Ibukota Jakarta 11840;https://maps.app.goo.gl/PywBthw61yjr5mki9;0215408989;marketing.daanmogot@herminahospitals.com;02.073.114.7-038.000;active', 'Kota Jakarta Barat', NULL, NULL, NULL),
(16, 'H014;RS HERMINA DEPOK;RS Swasta;Hermina;Kota Depok', 'Depok', NULL, NULL, '', '', '', 'Kec. Pancoran Mas', 'Kota Depok', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Barat 16431;https://maps.app.goo.gl/9qYcBXEXUEmoYxj86;0211500488;marketing.depok@herminahospita', NULL, NULL, NULL),
(17, 'H015;RS HERMINA GALAXY;RS Swasta;Hermina;Kabupaten', 'Jl. Boulevar Raya Bar.', NULL, NULL, 'Jawa Barat 17147;https://maps.app.goo.gl/Y3c2kmuCC', '', '', 'RT.003/RW.017', 'Jaka Setia', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Kabupaten Bekasi', 'Kec. Bekasi Sel.', NULL, NULL, NULL),
(18, 'H016;RS HERMINA GRAND WISATA;RS Swasta;Hermina;Kab', 'Lambangsari', NULL, NULL, '', '', '', 'Kec. Tambun Sel.', 'Kabupaten Bekasi', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Barat 17510;https://maps.app.goo.gl/goSrGuHJtZXTQXMU8;02182651212;marketing.grandwisata@hermina', NULL, NULL, NULL),
(19, 'H017;RS HERMINA NUSANTARA;RS Swasta;Hermina;Kabupa', 'Bumi Harapan', NULL, NULL, '', '', '', 'Kec. Sepaku', 'Kabupaten Penajam Paser Utara', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Kalimantan Timur;https://maps.app.goo.gl/JffieQntbUBqCujB6;05428252520;;00.192.011.5.100.7.000;activ', NULL, NULL, NULL),
(20, 'H018;RS HERMINA JATINEGARA;RS Swasta;Hermina;Jakar', 'DKI Jakarta', NULL, NULL, '', '', '', 'Indonesia 13320;https://maps.app.goo.gl/8beVnXdhbd', '', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', '', NULL, NULL, NULL),
(21, 'H019;RS HERMINA KARAWANG;RS Swasta;Hermina;Kabupat', 'Blok Gg. Sukasari No.386A', NULL, NULL, 'Jawa Barat 41314;https://maps.app.goo.gl/NcKSs2VfC', '', '', 'RT.2/RW.4', 'Karawang Wetan', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Karawang', 'Kec. Karawang Tim.', NULL, NULL, NULL),
(22, 'H020;RS HERMINA KEMAYORAN;RS Swasta;Hermina;Kota J', 'RW.10', NULL, NULL, '', '', '', 'Gn. Sahari Sel.', 'Kec. Kemayoran', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Daerah Khusus Ibukota Jakarta 10620;https://maps.app.goo.gl/Q8gpMvP4vnZpe4Th8;02122602525;marketing.kemayoran@herminahospitals.com;01.364.468.7-046.000;active', 'Kota Jakarta Pusat', NULL, NULL, NULL),
(23, 'H021;RS HERMINA KENDARI;RS Swasta;Hermina;Kota Ken', 'RT.06/RW.03', NULL, NULL, '', '', '', 'Wundudopi', 'Kec. Baruga', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Sulawesi Tenggara 93117;https://maps.app.goo.gl/cSKseyJtifP3LZYX7;04013192525;marketing.kendari@herminahospitals.com;84.671.659.5.811.000;active', 'Kota Kendari', NULL, NULL, NULL),
(24, 'H022;RS HERMINA KUTABUMI / PERIUK TANGERANG;RS Swa', 'RT.001/RW.007', NULL, NULL, '', '', '', 'Nagrak', 'Kec. Periuk', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Banten 15131;https://maps.app.goo.gl/smaYDyeZFLGApxGc7;02129432525;marketing.periuktangerang@herminahospitals.com;85.048.181.3-402.000;active', 'Kota Tangerang', NULL, NULL, NULL),
(25, 'H023;RS HERMINA LAMPUNG;RS Swasta;Hermina;Kota Ban', 'Enggal', NULL, NULL, '', '', '', 'Engal', 'Kota Bandar Lampung', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Lampung 35213;https://maps.app.goo.gl/Zqf1Bbuoo452K3y76;0721242525;marketing.lampung@herminahospital', NULL, NULL, NULL),
(26, 'H024;RS HERMINA MADIUN;RS Swasta;Hermina;Kota Madi', 'Manguharjo', NULL, NULL, '', '', '', 'Kec. Manguharjo', 'Kota Madiun', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Timur 63127;https://maps.app.goo.gl/1fnMAQiinAPWZqy79;03514108585;marketing.madiun@herminahospi', NULL, NULL, NULL),
(27, 'H025;RS HERMINA MAKASSAR;RS Swasta;Hermina;Kota Ma', 'Borong', NULL, NULL, '', '', '', 'Kec. Manggala', 'Kota Makassar', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Sulawesi Selatan 90231;https://maps.app.goo.gl/CHE7SzT9gr2K4AEP9;04114091817;marketing.makassar@herm', NULL, NULL, NULL),
(28, 'H026;RS HERMINA MANADO;RS Swasta;Hermina;Kota Mana', 'Lingkungan I', NULL, NULL, '', '', '', 'Paniki Bawah', 'Kec. Mapanget', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Sulawesi Utara;https://maps.app.goo.gl/iZ1G4huqJitAjabV6;04317242525;marketing.manado@herminahospitals.com;82.392.404.882.1.000;active', 'Kota Manado', NULL, NULL, NULL),
(29, 'H027;RS HERMINA MEDAN;RS Swasta;Hermina;Kota Medan', 'Sei Sikambing C. II', NULL, NULL, '', '', '', 'Kec. Medan Helvetia', 'Kota Medan', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Sumatera Utara 20123;https://maps.app.goo.gl/Q9WMYfEzyJQ3uHmp6;06180862525;marketing.medan@herminaho', NULL, NULL, NULL),
(30, 'H028;RS HERMINA MEKARSARI;RS Swasta;Hermina;Kabupa', 'Cileungsi Kidul', NULL, NULL, '', '', '', 'Kec. Cileungsi', 'Kabupaten Bogor', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Barat 16820;https://maps.app.goo.gl/mWGaL4Y8WATCkwAh7;02129232525;marketing.mekarsari@herminaho', NULL, NULL, NULL),
(31, 'H029;RS HERMINA METLAND CIBITUNG;RS Swasta;Hermina', 'Jl. Metland Cibitung', NULL, NULL, '', '', '', 'Telagamurni', 'Kec. Cikarang Bar.', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Jawa Barat 17530;https://maps.app.goo.gl/38J7BDz6wTHXcVXC7;02188362626;marketing.metlandcibitung@herminahospitals.com;91.400.950.1-413.000;active', 'Kabupaten Bekasi', NULL, NULL, NULL),
(32, 'H030;RS HERMINA MUTIARA BUNDA SALATIGA;RS Swasta;H', 'Mangunsari', NULL, NULL, '', '', '', 'Kec. Sidomukti', 'Kota Salatiga', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Tengah 50721;https://maps.app.goo.gl/jBwfGveybso8FBi59;0298329500;marketing.salatiga@herminahos', NULL, NULL, NULL),
(33, 'H031;RS HERMINA OPI JAKABARING;RS Swasta;Hermina;K', 'RT.010/RW.005', NULL, NULL, '', '', '', 'Kelurahan Jakabaring Selatan', 'Kec. Rambutan', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Sumatera Selatan 30257;https://maps.app.goo.gl/SsjawVhQE34Jhgrj6;07113031520;marketing.opijakabaring@herminahospitals.com;08.209.521.1.7314.000;active', 'Kab. Banyuasin', NULL, NULL, NULL),
(34, 'H032;RS HERMINA PALEMBANG;RS Swasta;Hermina;Kota P', 'Pahlawan', NULL, NULL, '', '', '', 'Kec. Kemuning', 'Kota Palembang', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Sumatera Selatan 30127;https://maps.app.goo.gl/26BXznJVLmCucJXE7;1500488;marketing.palembang@hermina', NULL, NULL, NULL),
(35, 'H033;RS HERMINA PANDANARAN;RS Swasta;Hermina;Kota ', 'Pekunden', NULL, NULL, '', '', '', 'Kec. Semarang Tengah', 'Kota Semarang', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Tengah 50134;https://maps.app.goo.gl/9K3nhUKmKjCSysaJ8;0248442525;marketing.pandanaran@herminah', NULL, NULL, NULL),
(36, 'H034;RS HERMINA PASTEUR;RS Swasta;Hermina;Kota Ban', 'Pasteur', NULL, NULL, '', '', '', 'Kec. Cicendo', 'Kota Bandung', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Barat 40173;https://maps.app.goo.gl/WtWVYRAAhBM8HtWT9;0226072525;marketing.pasteur@herminahospi', NULL, NULL, NULL),
(37, 'H035;RS HERMINA PASURUAN;RS Swasta;Hermina;Kabupat', 'Probolinggo Km 5 RT 01 RW 01', NULL, NULL, '', '', '', 'Desa Sambirejo Kec Rejoso Kab Pasuruan-Jawa timur ', '', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', '', NULL, NULL, NULL),
(38, 'H036;RS HERMINA PEKALONGAN;RS Swasta;Hermina;Kota ', 'RW.6', NULL, NULL, '', '', '', 'Podosugih', 'Kec. Pekalongan Bar.', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Jawa Tengah 51112;https://maps.app.goo.gl/RcCu6e9Bq8ZmgUEcA;0285432525;marketing.pekalongan@herminahospitals.com;916448483502000;active', 'Kota Pekalongan', NULL, NULL, NULL),
(39, 'H037;RS HERMINA PEKANBARU;RS Swasta;Hermina;Kota P', 'Delima', NULL, NULL, '', '', '', 'Kec. Tampan', 'Kota Pekanbaru', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Riau 28292;https://maps.app.goo.gl/or8TkYahUEySWBKRA;07618411919;marketing.pekanbaru@herminahospital', NULL, NULL, NULL),
(40, 'H038;RS HERMINA PIK DUA;RS Swasta;Hermina;Kabupate', 'Salembaran', NULL, NULL, '', '', '', 'Kosambi', 'Tangerang Regency', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Banten 15214;https://maps.app.goo.gl/Mvfo8tWBnux4VSMq7;1500488;rsherminapik2@gmail.com;04.218.818.2.', NULL, NULL, NULL),
(41, 'H039;RS HERMINA PODOMORO;RS Swasta;Hermina;Kota Ja', 'Jl. Danau Agung 2 No.28 - 30', NULL, NULL, 'Daerah Khusus Ibukota Jakarta 14350;https://maps.a', '', '', 'RT.3/RW.16', 'Sunter Agung', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Jkt Utara', 'Kec. Tj. Priok', NULL, NULL, NULL),
(42, 'H040;RS HERMINA PURWOKERTO;RS Swasta;Hermina;Kabup', 'RT.03/RW.01', NULL, NULL, '', '', '', 'Karanglewas Lor', 'Kec. Purwokerto Bar.', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Jawa Tengah 53136;https://maps.app.goo.gl/qsGKpW5FBHhZ7kEr7;02817772525;marketing.purwokerto@herminahospitals.com;75.830.148.5-521.000;active', 'Kabupaten Banyumas', NULL, NULL, NULL),
(43, 'H041;RS HERMINA SAMARINDA;RS Swasta;Hermina;Kota S', 'Karang Asam Ilir', NULL, NULL, '', '', '', 'Kec. Sungai Kunjang', 'Kota Samarinda', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Kalimantan Timur 75126;https://maps.app.goo.gl/cygF642mjnaGrF1AA;05412090707;marketing.samarinda@her', NULL, NULL, NULL),
(44, 'H042;RS HERMINA SERPONG;RS Swasta;Hermina;Kota Tan', 'Buaran', NULL, NULL, '', '', '', 'Kec. Serpong', 'Kota Tangerang Selatan', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Banten 15310;https://maps.app.goo.gl/76Xq7r96PPb2xEtB7;02175884999;marketing.serpong@herminahospital', NULL, NULL, NULL),
(45, 'H043;RS HERMINA SOLO;RS Swasta;Hermina;Kota Suraka', 'Jebres', NULL, NULL, '', '', '', 'Kec. Jebres', 'Kota Surakarta', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Tengah 57126;https://maps.app.goo.gl/Eukb6pGMGQyMcxqA7;0271638989;marketing.solo@herminahospita', NULL, NULL, NULL),
(46, 'H044;RS HERMINA SOREANG;RS Swasta;Hermina;Kabupate', 'Soreang', NULL, NULL, '', '', '', 'Kec. Soreang', 'Kabupaten Bandung', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Barat 40911;https://maps.app.goo.gl/w1A5138QiuBspSPY7;0225892525;marketing.soreang@herminahospi', NULL, NULL, NULL),
(47, 'H045;RS HERMINA SUKABUMI;RS Swasta;Hermina;Kabupat', 'RT.003/RW.003', NULL, NULL, '', '', '', 'Sukaraja', 'Kec. Sukaraja', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Jawa Barat 43192;https://maps.app.goo.gl/PnoqtX86ZuVRKVtT6;02666252525;marketing.sukabumi@herminahospitals.com;02.522.444.5.405.000;active', 'Kabupaten Sukabumi', NULL, NULL, NULL),
(48, 'H046;RS HERMINA TANGERANG;RS Swasta;Hermina;Kota T', 'Ps. Baru', NULL, NULL, '', '', '', 'Kec. Karawaci', 'Kota Tangerang', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Banten 15112;https://maps.app.goo.gl/QnfY14xrsNRe1RR96;02155772525;marketing.tangerang@herminahospit', NULL, NULL, NULL),
(49, 'H047;RS HERMINA TANGKUBANPRAHU;RS Swasta;Hermina;K', 'Kauman', NULL, NULL, '', '', '', 'Kec. Klojen', 'Kota Malang', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Timur 65119;https://maps.app.goo.gl/JzHuyq7aeDs8AuwAA;0341322525;marketing.tangkubanprahu@hermi', NULL, NULL, NULL),
(50, 'H048;RS HERMINA TASIKMALAYA;RS Swasta;Hermina;Kabu', 'RT.03/RW.14', NULL, NULL, '', '', '', 'Cipedes', 'Kec. Cipedes', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Jawa Barat 46133;https://maps.app.goo.gl/vE6NzkxYWxR6LQYf7;02653172525;marketing.tasikmalaya@herminahospitals.com;43.317.218.6-425.000;active', 'Kab. Tasikmalaya', NULL, NULL, NULL),
(51, 'H049;RS  UBAYA;RS Swasta;Hermina;Kota Surabaya;JS;', 'Panjang Jiwo', NULL, NULL, '', '', '', 'Kec. Tenggilis Mejoyo', 'Surabaya', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', '', 'Jawa Timur 60299;https://maps.app.goo.gl/AoAfkbj3Q2mtaCCZ9;03199211515;sekretariat@rs.ubaya.ac.id;91', NULL, NULL, NULL),
(52, 'H050;RS HERMINA WONOGIRI;RS Swasta;Hermina;Kabupat', 'Jatibedug', NULL, NULL, '', '', '', 'Purworejo', 'Kec. Wonogiri', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Jawa Tengah 57612;https://maps.app.goo.gl/yUfALJACAyjHSriUA;02735327365;marketing.wonogiri@herminahospitals.com;94.070.566.8-532.000;active', 'Purworejo', NULL, NULL, NULL),
(53, 'H051;RS HERMINA YOGYA;RS Swasta;Hermina;Kabupaten ', 'RT.06/RW.50', NULL, NULL, 'Daerah Istimewa Yogyakarta 55282;https://maps.app.', '', '', 'Meguwo', 'Maguwoharjo', NULL, NULL, NULL, NULL, NULL, NULL, 'active', '2025-12-11 23:40:15', '2025-12-12 00:17:44', 'Kabupaten Sleman', 'Kec. Depok', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_departements`
--

CREATE TABLE `master_departements` (
  `id` int NOT NULL,
  `dept_code` varchar(20) NOT NULL,
  `dept_name` varchar(150) NOT NULL,
  `level_type` varchar(20) NOT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_departements`
--

INSERT INTO `master_departements` (`id`, `dept_code`, `dept_name`, `level_type`, `office_code`, `status`, `created_at`, `updated_at`) VALUES
(1, 'MPR', 'Marketing & Project', 'Manager', 'bgr', 'active', '2025-12-10 05:21:08', '2025-12-10 05:21:08'),
(2, 'CRM', 'Customer Relationship Management', 'Manager', 'bgr', 'active', '2025-12-10 05:21:08', '2025-12-10 05:21:08'),
(3, 'WQS', 'Warehouse & Quantity', 'Manager', 'bgr', 'active', '2025-12-10 05:21:08', '2025-12-10 05:21:08'),
(4, 'SCM', 'Supply Chain Management', 'Manager', 'bgr', 'active', '2025-12-10 05:21:08', '2025-12-10 05:21:08'),
(5, 'ACT', 'Accounting & Tax', 'Manager', 'bgr', 'active', '2025-12-10 05:21:08', '2026-01-02 06:44:16'),
(6, 'FIN', 'Finance', 'Manager', 'bgr', 'active', '2025-12-10 05:21:08', '2025-12-10 05:21:08'),
(7, 'HRL', 'Human Resource & Legal', 'Manager', 'bgr', 'active', '2025-12-10 05:21:08', '2025-12-10 05:21:08'),
(8, 'PQP', 'Product Quality & Purchasing', 'Manager', 'bgr', 'active', '2025-12-10 05:21:08', '2025-12-10 05:21:08'),
(9, 'ITC', 'IT & Cloud', 'Manager', 'bgr', 'active', '2025-12-10 05:21:08', '2025-12-10 05:21:08'),
(1054, 'MPR', 'Marketing & Project', 'Staff', 'kal', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1055, 'MPR', 'Marketing & Project', 'Staff', 'jgy', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1056, 'MPR', 'Marketing & Project', 'Staff', 'bgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1057, 'MPR', 'Marketing & Project', 'Staff', 'bdg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1058, 'MPR', 'Marketing & Project', 'Staff', 'bks', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1059, 'MPR', 'Marketing & Project', 'Staff', 'slo', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1060, 'MPR', 'Marketing & Project', 'Staff', 'smg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1061, 'MPR', 'Marketing & Project', 'Staff', 'tgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1062, 'CRM', 'Customer Relationship Management', 'Staff', 'kal', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1063, 'CRM', 'Customer Relationship Management', 'Staff', 'jgy', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1064, 'CRM', 'Customer Relationship Management', 'Staff', 'bgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1065, 'CRM', 'Customer Relationship Management', 'Staff', 'bdg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1066, 'CRM', 'Customer Relationship Management', 'Staff', 'bks', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1067, 'CRM', 'Customer Relationship Management', 'Staff', 'slo', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1068, 'CRM', 'Customer Relationship Management', 'Staff', 'smg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1069, 'CRM', 'Customer Relationship Management', 'Staff', 'tgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1070, 'WQS', 'Warehouse & Quantity', 'Staff', 'kal', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1071, 'WQS', 'Warehouse & Quantity', 'Staff', 'jgy', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1072, 'WQS', 'Warehouse & Quantity', 'Staff', 'bgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1073, 'WQS', 'Warehouse & Quantity', 'Staff', 'bdg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1074, 'WQS', 'Warehouse & Quantity', 'Staff', 'bks', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1075, 'WQS', 'Warehouse & Quantity', 'Staff', 'slo', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1076, 'WQS', 'Warehouse & Quantity', 'Staff', 'smg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1077, 'WQS', 'Warehouse & Quantity', 'Staff', 'tgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1078, 'SCM', 'Supply Chain Management', 'Staff', 'kal', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1079, 'SCM', 'Supply Chain Management', 'Staff', 'jgy', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1080, 'SCM', 'Supply Chain Management', 'Staff', 'bgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1081, 'SCM', 'Supply Chain Management', 'Staff', 'bdg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1082, 'SCM', 'Supply Chain Management', 'Staff', 'bks', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1083, 'SCM', 'Supply Chain Management', 'Staff', 'slo', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1084, 'SCM', 'Supply Chain Management', 'Staff', 'smg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1085, 'SCM', 'Supply Chain Management', 'Staff', 'tgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1086, 'ACT', 'Accounting & Tax', 'Staff', 'kal', 'active', '2025-12-10 14:31:56', '2026-01-02 06:43:53'),
(1087, 'ACT', 'Accounting & Tax', 'Staff', 'jgy', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1088, 'ACT', 'Accounting & Tax', 'Staff', 'bgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1089, 'ACT', 'Accounting & Tax', 'Staff', 'bdg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1090, 'ACT', 'Accounting & Tax', 'Staff', 'bks', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1091, 'ACT', 'Accounting & Tax', 'Staff', 'slo', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1092, 'ACT', 'Accounting & Tax', 'Staff', 'smg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1093, 'ACT', 'Accounting & Tax', 'Staff', 'tgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1094, 'FIN', 'Finance', 'Staff', 'kal', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1095, 'FIN', 'Finance', 'Staff', 'jgy', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1096, 'FIN', 'Finance', 'Staff', 'bgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1097, 'FIN', 'Finance', 'Staff', 'bdg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1098, 'FIN', 'Finance', 'Staff', 'bks', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1099, 'FIN', 'Finance', 'Staff', 'slo', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1100, 'FIN', 'Finance', 'Staff', 'smg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1101, 'FIN', 'Finance', 'Staff', 'tgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1102, 'HRL', 'Human Resource & Legal', 'Staff', 'kal', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1103, 'HRL', 'Human Resource & Legal', 'Staff', 'jgy', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1104, 'HRL', 'Human Resource & Legal', 'Staff', 'bgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1105, 'HRL', 'Human Resource & Legal', 'Staff', 'bdg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1106, 'HRL', 'Human Resource & Legal', 'Staff', 'bks', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1107, 'HRL', 'Human Resource & Legal', 'Staff', 'slo', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1108, 'HRL', 'Human Resource & Legal', 'Staff', 'smg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1109, 'HRL', 'Human Resource & Legal', 'Staff', 'tgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1110, 'PQP', 'Product Quality & Purchasing', 'Staff', 'kal', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1111, 'PQP', 'Product Quality & Purchasing', 'Staff', 'jgy', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1112, 'PQP', 'Product Quality & Purchasing', 'Staff', 'bgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1113, 'PQP', 'Product Quality & Purchasing', 'Staff', 'bdg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1114, 'PQP', 'Product Quality & Purchasing', 'Staff', 'bks', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1115, 'PQP', 'Product Quality & Purchasing', 'Staff', 'slo', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1116, 'PQP', 'Product Quality & Purchasing', 'Staff', 'smg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1117, 'PQP', 'Product Quality & Purchasing', 'Staff', 'tgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1118, 'ITC', 'IT & Cloud', 'Staff', 'kal', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1119, 'ITC', 'IT & Cloud', 'Staff', 'jgy', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1120, 'ITC', 'IT & Cloud', 'Staff', 'bgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1121, 'ITC', 'IT & Cloud', 'Staff', 'bdg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1122, 'ITC', 'IT & Cloud', 'Staff', 'bks', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1123, 'ITC', 'IT & Cloud', 'Staff', 'slo', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1124, 'ITC', 'IT & Cloud', 'Staff', 'smg', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1125, 'ITC', 'IT & Cloud', 'Staff', 'tgr', 'active', '2025-12-10 14:31:56', '2025-12-10 14:31:56'),
(1126, 'SYS', 'SYS', 'Manager', 'bgr', 'active', '2026-01-01 23:55:47', '2026-01-01 23:55:47'),
(1127, 'SYS', 'SYS', 'Staff', 'kal', 'active', '2026-01-01 23:55:47', '2026-01-01 23:55:47'),
(1128, 'SYS', 'SYS', 'Staff', 'jgy', 'active', '2026-01-01 23:55:47', '2026-01-01 23:55:47'),
(1129, 'SYS', 'SYS', 'Staff', 'bgr', 'active', '2026-01-01 23:55:47', '2026-01-01 23:55:47'),
(1130, 'SYS', 'SYS', 'Staff', 'bdg', 'active', '2026-01-01 23:55:47', '2026-01-01 23:55:47'),
(1131, 'SYS', 'SYS', 'Staff', 'bks', 'active', '2026-01-01 23:55:47', '2026-01-01 23:55:47'),
(1135, 'SYS', 'SYS', 'SYS', NULL, 'active', '2026-01-02 01:39:42', '2026-01-02 01:39:42'),
(1136, 'MPR', 'Marketing & Project', 'Staff', 'sys', 'inactive', '2026-01-02 05:58:35', '2026-01-02 06:45:04'),
(1139, 'SCM', 'Supply Chain Management', 'Staff', 'sys', 'inactive', '2026-01-02 05:58:35', '2026-01-02 06:43:44'),
(1141, 'FIN', 'Finance', 'Staff', 'sys', 'inactive', '2026-01-02 05:58:35', '2026-01-02 06:44:33'),
(1142, 'HRL', 'Human Resource & Legal', 'Staff', 'sys', 'inactive', '2026-01-02 05:58:35', '2026-01-02 06:44:50'),
(1143, 'PQP', 'Product Quality & Purchasing', 'Staff', 'sys', 'inactive', '2026-01-02 05:58:35', '2026-01-02 06:45:12'),
(1144, 'ITC', 'IT & Cloud', 'Staff', 'sys', 'inactive', '2026-01-02 05:58:35', '2026-01-02 06:44:56'),
(1149, 'CRM', 'Customer Relationship Management', 'Staff', 'sys', 'inactive', '2026-01-02 06:42:22', '2026-01-02 06:44:28'),
(1151, 'ACT', 'Accounting & Tax', 'Staff', 'sys', 'inactive', '2026-01-02 06:43:28', '2026-01-02 06:44:02'),
(1152, 'WQS', 'Warehouse & Quantity', 'Staff', 'sys', 'inactive', '2026-01-02 06:46:03', '2026-01-02 06:46:16');

-- --------------------------------------------------------

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

--
-- Struktur dari tabel `master_employees`
--

CREATE TABLE `master_employees` (
  `id` int NOT NULL,
  `employee_code` varchar(50) NOT NULL,
  `employee_name` varchar(150) NOT NULL,
  `dept_code` varchar(20) DEFAULT NULL,
  `level_type` varchar(20) DEFAULT 'Staff',
  `grade` varchar(5) DEFAULT NULL,
  `payroll_status` varchar(20) DEFAULT NULL,
  `payroll_level` varchar(5) DEFAULT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `nik` varchar(20) DEFAULT NULL,
  `npwp` varchar(30) DEFAULT NULL,
  `bpjs_tk_no` varchar(30) DEFAULT NULL,
  `bpjs_kes_no` varchar(30) DEFAULT NULL,
  `education` varchar(100) DEFAULT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `join_year` varchar(4) DEFAULT NULL,
  `join_month` varchar(2) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_branch` varchar(100) DEFAULT NULL,
  `bank_account_name` varchar(150) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_employees`
--

INSERT INTO `master_employees` (`id`, `employee_code`, `employee_name`, `dept_code`, `level_type`, `grade`, `payroll_status`, `payroll_level`, `job_title`, `nik`, `npwp`, `bpjs_tk_no`, `bpjs_kes_no`, `education`, `office_code`, `join_year`, `join_month`, `phone`, `email`, `bank_name`, `bank_branch`, `bank_account_name`, `bank_account_number`, `status`, `note`, `created_at`, `updated_at`) VALUES
(3, 'SYS150901', 'Admin', 'SYS', 'Staff', 'A', '', '', NULL, '', '', '', '', '', 'sys', '2015', '09', '', '', NULL, NULL, NULL, NULL, 'active', '', '2026-01-01 22:57:16', '2026-01-02 05:57:16'),
(4, 'SYS150902', 'SUPERADMIN', 'SYS', 'SYS', '', '', '', NULL, '', '', '', '', '', 'sys', '2015', '09', '', '', NULL, NULL, NULL, NULL, 'active', '', '2026-01-01 22:57:57', '2026-01-02 05:57:57');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_manufactures`
--

CREATE TABLE `master_manufactures` (
  `id` int NOT NULL,
  `manufactures_code` varchar(50) NOT NULL,
  `manufactures_name` varchar(150) NOT NULL,
  `manufacture_code` varchar(50) NOT NULL,
  `manufacture_name` varchar(150) NOT NULL,
  `brand_name` varchar(255) DEFAULT NULL,
  `origin_type` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` text,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `pic_name` varchar(100) DEFAULT NULL,
  `pic_position` varchar(100) DEFAULT NULL,
  `pic_phone` varchar(50) DEFAULT NULL,
  `pic_email` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_name` varchar(150) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `bank_swift_code` varchar(50) DEFAULT NULL,
  `bank_iban` varchar(50) DEFAULT NULL,
  `bank_currency` varchar(10) DEFAULT 'IDR',
  `website` varchar(150) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_manufactures`
--

INSERT INTO `master_manufactures` (`id`, `manufactures_code`, `manufactures_name`, `manufacture_code`, `manufacture_name`, `brand_name`, `origin_type`, `country`, `city`, `address`, `phone`, `email`, `pic_name`, `pic_position`, `pic_phone`, `pic_email`, `bank_name`, `bank_account_name`, `bank_account_number`, `bank_swift_code`, `bank_iban`, `bank_currency`, `website`, `status`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'YAXIN', 'Suzhou Yaxin Medical Products Co., Ltd', 'YAXIN', 'Suzhou Yaxin Medical Products Co., Ltd', 'RIZKIMED', 'Import', 'China', 'Suzhou', 'No.12, Zhongta Road, Mudu Town, Suzhou 215101, Jiangsu province, China', '+86 152 5017 8777', 'yaxin@yx-yiliao.com', 'Jim Wu', 'Sales', NULL, 'yaxin@yx-yiliao.com', 'JPMorgan Chase Bank N.A., Singapore Branch', 'Suzhou Yaxin Medical Products Co., Ltd', '1014 1740 2041 66', 'CHASSGSGXXX  or CHASSGSG', NULL, 'IDR', NULL, 1, '2026-01-01 18:00:42', '2026-01-02 01:00:42', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_mpr`
--

CREATE TABLE `master_mpr` (
  `id` int NOT NULL,
  `customers_code` varchar(50) NOT NULL,
  `customer_id` int NOT NULL,
  `contact_name` varchar(150) NOT NULL,
  `role_title` varchar(100) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_office`
--

CREATE TABLE `master_office` (
  `id` int NOT NULL,
  `office_code` varchar(10) NOT NULL,
  `office_name` varchar(100) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` varchar(100) DEFAULT 'SYSTEM',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `office_lat` decimal(10,7) DEFAULT NULL,
  `office_lng` decimal(10,7) DEFAULT NULL,
  `office_radius_m` int DEFAULT '120'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_office`
--

INSERT INTO `master_office` (`id`, `office_code`, `office_name`, `city`, `address`, `phone`, `is_active`, `created_by`, `created_at`, `updated_at`, `office_lat`, `office_lng`, `office_radius_m`) VALUES
(1, 'bgr', 'Rizqullah Mediska Indonesia', 'Kab. Bogor', 'Jalan Pondok Rajeg, Ruko Sentra Pondok Rajeg No. 7 & 8, Kel. Pondok Rajeg, Kec. Cibinong, Kabupaten Bogor, Provinsi Jawa Barat, 16914', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.5971470, 106.8060390, 150),
(2, 'bks', 'Rizqullah Mediska Indonesia Bekasi', 'Kota Bekasi', 'Perumahan The East View Residence Blok F 17, Jl. Raya Mustika Sari, Kel. Mustikasari, Kec. Mustikajaya, Kota Bekasi, Provinsi Jawa Barat, 17157', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.2382700, 106.9755700, 150),
(3, 'tgr', 'Rizqullah Mediska Indonesia Tangerang', 'Kota Tangerang', 'Jalan Muhamad Toha No. B26 Km. 0,6, Kel. Periuk, Kec. Periuk, Kota Tangerang, Provinsi Banten, 15131', '0813-8425-1574', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.1783060, 106.6318890, 150),
(4, 'bdg', 'Rizqullah Mediska Indonesia Bandung', 'Kota Bandung', 'Ruko Puri Dago Mas Unit 428, Jalan Terusan Jakarta, Kota Bandung, Provinsi Jawa Barat, 40293', '0812-1067-5863', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.9174640, 107.6191230, 150),
(5, 'slo', 'Rizqullah Mediska Indonesia Jawa Tengah (Solo)', 'Kota Surakarta', 'Jalan Pakel No. 06, Kel. Banyuanyar, Kec. Banjarsari, Kota Surakarta, Provinsi Jawa Tengah, 57137', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -7.5666670, 110.8166670, 150),
(6, 'smg', 'Rizqullah Mediska Indonesia Semarang', 'Kota Semarang', 'Ruko Tlogo Timun Mas No. 1A Kav. C, Kel. Tlogosari Kulon, Kec. Pedurungan, Kota Semarang, Provinsi Jawa Tengah, 50196', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.9666670, 110.4166670, 150),
(7, 'kal', 'Depo Kalimantan', 'Kalimantan', '', NULL, 1, 'SYSTEM', '2025-12-08 09:22:59', '2025-12-08 09:22:59', NULL, NULL, 120),
(8, 'jgy', 'Depo Yogyakarta', 'Yogyakarta', '', NULL, 1, 'SYSTEM', '2025-12-08 09:22:59', '2025-12-08 09:22:59', NULL, NULL, 120),
(9, 'sys', 'SYS', 'SYS', '', '', 0, 'SYSTEM', '2026-01-02 05:55:45', '2026-01-02 07:22:59', NULL, NULL, 120);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_payment_terms`
--

CREATE TABLE `master_payment_terms` (
  `id` int NOT NULL,
  `payment_terms_code` varchar(30) NOT NULL,
  `payment_terms_name` varchar(150) NOT NULL,
  `days_due` int DEFAULT '0',
  `description` text,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_payment_terms`
--

INSERT INTO `master_payment_terms` (`id`, `payment_terms_code`, `payment_terms_name`, `days_due`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'COD', 'Cash on Delivery', 0, 'Pembayaran saat barang diterima', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(2, 'CBD', 'Cash Before Delivery', 0, 'Harus lunas sebelum pengiriman', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(3, 'TOP7', 'TOP 7 Hari', 7, 'Jatuh tempo 7 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(4, 'TOP14', 'TOP 14 Hari', 14, 'Jatuh tempo 14 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(5, 'TOP30', 'TOP 30 Hari', 30, 'Jatuh tempo 30 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(6, 'TOP45', 'TOP 45 Hari', 45, 'Jatuh tempo 45 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(7, 'TOP60', 'TOP 60 Hari', 60, 'Jatuh tempo 60 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_pricelist`
--

CREATE TABLE `master_pricelist` (
  `id` int NOT NULL,
  `sku` varchar(50) NOT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `customers_code` varchar(30) DEFAULT NULL,
  `buy_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `markup_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `sell_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_products`
--

CREATE TABLE `master_products` (
  `id` int NOT NULL,
  `sku` varchar(50) NOT NULL,
  `products_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `product_group` varchar(20) DEFAULT NULL,
  `no_akl` varchar(100) DEFAULT NULL,
  `manufacture_id` int DEFAULT NULL,
  `vendor_id` int DEFAULT NULL,
  `stock_qty` int NOT NULL DEFAULT '0',
  `price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(50) NOT NULL DEFAULT 'unit',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `photo_front` varchar(255) DEFAULT NULL,
  `photo_back` varchar(255) DEFAULT NULL,
  `photo_box` varchar(255) DEFAULT NULL,
  `photo_unpacked` varchar(255) DEFAULT NULL,
  `video_front` varchar(255) DEFAULT NULL,
  `video_back` varchar(255) DEFAULT NULL,
  `video_box` varchar(255) DEFAULT NULL,
  `video_unpacked` varchar(255) DEFAULT NULL,
  `general_name` varchar(200) DEFAULT NULL,
  `licence_number` varchar(100) DEFAULT NULL,
  `min_price` int NOT NULL DEFAULT '0',
  `max_price` int NOT NULL DEFAULT '0',
  `listing_level` int DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `akl_reg_no` varchar(150) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `current_stock` int NOT NULL DEFAULT '0',
  `product_type` enum('SINGLE','PAKET') NOT NULL DEFAULT 'SINGLE',
  `package_items` longtext
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_products`
--

INSERT INTO `master_products` (`id`, `sku`, `products_name`, `category`, `product_group`, `no_akl`, `manufacture_id`, `vendor_id`, `stock_qty`, `price`, `unit`, `status`, `created_at`, `updated_at`, `photo_front`, `photo_back`, `photo_box`, `photo_unpacked`, `video_front`, `video_back`, `video_box`, `video_unpacked`, `general_name`, `licence_number`, `min_price`, `max_price`, `listing_level`, `barcode`, `akl_reg_no`, `exp_date`, `current_stock`, `product_type`, `package_items`) VALUES
(1, 'OBT-001', 'Obat A', NULL, NULL, NULL, NULL, NULL, 100, 50000.00, 'unit', 'active', '2025-12-08 17:33:28', '2025-12-08 17:33:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, '8991234567890', 'AKL.12345678901', NULL, 120, 'SINGLE', NULL),
(2, 'OBT-002', 'Obat B', NULL, NULL, NULL, NULL, NULL, 150, 75000.00, 'unit', 'active', '2025-12-08 17:33:28', '2025-12-08 17:33:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, 'SINGLE', NULL),
(3, 'ALK-001', 'Alkes A', NULL, NULL, NULL, NULL, NULL, 50, 150000.00, 'unit', 'active', '2025-12-08 17:33:28', '2025-12-08 17:33:28', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, 'SINGLE', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_system_login`
--

CREATE TABLE `master_system_login` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'staff',
  `level` varchar(20) DEFAULT NULL,
  `department` varchar(50) DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `holder_employee_code` varchar(50) DEFAULT NULL,
  `holder_assigned_at` datetime DEFAULT NULL,
  `holder_assigned_by` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_system_login`
--

INSERT INTO `master_system_login` (`id`, `username`, `full_name`, `password_hash`, `role`, `level`, `department`, `office_code`, `holder_employee_code`, `holder_assigned_at`, `holder_assigned_by`, `status`, `created_at`, `updated_at`, `last_login_at`) VALUES
(1, 'superadmin', 'Super Admin', '$2b$10$pfgOq0FsaHzBSbdSnVh11eTYhueWwXNZzmMaO7W3dXtlHu2/jFjAy', 'owner', 'SUPERADMIN', 'SYS', NULL, NULL, NULL, NULL, 'active', '2026-01-01 21:17:38', '2026-01-01 21:30:21', NULL),
(2, 'admin', 'Admin', '$2b$10$pfgOq0FsaHzBSbdSnVh11eTYhueWwXNZzmMaO7W3dXtlHu2/jFjAy', 'admin', 'ADMIN', 'SYS', NULL, NULL, NULL, NULL, 'active', '2026-01-01 21:17:38', '2026-01-01 21:30:38', '2026-01-01 21:30:38');

-- --------------------------------------------------------

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

--
-- Struktur dari tabel `master_tax`
--

CREATE TABLE `master_tax` (
  `id` int NOT NULL,
  `tax_code` varchar(20) NOT NULL,
  `tax_name` varchar(100) NOT NULL,
  `tax_type` varchar(20) NOT NULL,
  `rate_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `level_type` varchar(20) NOT NULL DEFAULT 'Transaction',
  `office_scope` varchar(50) DEFAULT 'All',
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_tax`
--

INSERT INTO `master_tax` (`id`, `tax_code`, `tax_name`, `tax_type`, `rate_percent`, `level_type`, `office_scope`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'NONPPN', 'Non PPN', 'PPN', 0.00, 'Transaction', 'All', 'Tanpa PPN', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(2, 'PPN11', 'PPN 11%', 'PPN', 11.00, 'Transaction', 'All', 'PPN 11%', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(3, 'PPN12', 'PPN 12%', 'PPN', 12.00, 'Transaction', 'All', 'Siap jika aturan berubah', 'inactive', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(4, 'PPh21_ALL', 'PPh 21 (All Office)', 'PPh', 0.00, 'Other', 'All', 'Untuk payroll / gaji karyawan', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(5, 'PPN_ALL', 'PPN (All Office)', 'PPN', 0.00, 'Other', 'All', 'Umum PPN by office', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(6, 'PPhFINAL_SS', 'PPh Final (Semarang & Solo)', 'PPh', 0.00, 'Other', 'Semarang,Solo', 'PPh Final cabang tertentu', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(7, 'PPh25_BBB', 'PPh 25 (Bogor, Bekasi, Bandung)', 'PPh', 0.00, 'Other', 'Bogor,Bekasi,Bandung', 'PPh 25 bulanan', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(8, 'PPh23_BOG', 'PPh 23 (Bogor)', 'PPh', 2.00, 'Other', 'Bogor', 'Contoh PPh 23 cabang Bogor', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(9, 'SPT_TAHUNAN', 'SPT Tahunan (All Cabang)', 'SPT', 0.00, 'Other', 'All', 'SPT Tahunan perusahaan', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_user`
--

CREATE TABLE `master_user` (
  `id` int NOT NULL,
  `customers_code` varchar(50) NOT NULL,
  `customer_id` int NOT NULL,
  `contact_name` varchar(150) NOT NULL,
  `role_title` varchar(100) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_vendors`
--

CREATE TABLE `master_vendors` (
  `id` int NOT NULL,
  `vendors_code` varchar(50) NOT NULL,
  `vendors_name` varchar(150) NOT NULL,
  `vendor_type` varchar(50) DEFAULT NULL,
  `pic_name` varchar(150) DEFAULT NULL,
  `pic_position` varchar(100) DEFAULT NULL,
  `pic_phone` varchar(50) DEFAULT NULL,
  `pic_email` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_name` varchar(150) DEFAULT NULL,
  `bank_account_number` varchar(100) DEFAULT NULL,
  `bank_swift_code` varchar(50) DEFAULT NULL,
  `bank_iban` varchar(50) DEFAULT NULL,
  `bank_currency` varchar(10) DEFAULT 'IDR',
  `category` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `maps_url` text,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `npwp` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_vendors`
--

INSERT INTO `master_vendors` (`id`, `vendors_code`, `vendors_name`, `vendor_type`, `pic_name`, `pic_position`, `pic_phone`, `pic_email`, `bank_name`, `bank_account_name`, `bank_account_number`, `bank_swift_code`, `bank_iban`, `bank_currency`, `category`, `address`, `maps_url`, `city`, `phone`, `email`, `npwp`, `status`, `created_at`, `updated_at`) VALUES
(1, 'VRMI2601', 'Tokopedia', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, '', NULL, 'Jakarta', '', '', NULL, 'active', '2025-12-08 15:55:25', '2025-12-08 15:55:25');

-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_employee_settings`
--

CREATE TABLE `payroll_employee_settings` (
  `id` bigint NOT NULL,
  `employee_id` int NOT NULL,
  `login_user_id` int DEFAULT NULL,
  `pay_type` varchar(20) NOT NULL DEFAULT 'MONTHLY',
  `salary_basic` decimal(18,2) NOT NULL DEFAULT '0.00',
  `op_rate_day` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_position` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_child` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_transport` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_quota` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_fixed` decimal(18,2) NOT NULL DEFAULT '0.00',
  `deduction_fixed` decimal(18,2) NOT NULL DEFAULT '0.00',
  `overtime_rate_per_hour` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_loans`
--

CREATE TABLE `payroll_loans` (
  `id` bigint NOT NULL,
  `employee_id` int NOT NULL,
  `loan_type` varchar(20) NOT NULL DEFAULT 'LOAN',
  `principal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tenor_months` int NOT NULL DEFAULT '1',
  `installment_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `start_period_ym` varchar(7) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ACTIVE',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_runs`
--

CREATE TABLE `payroll_runs` (
  `id` bigint NOT NULL,
  `period_ym` varchar(7) NOT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `posted_by` int DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `paid_by` int DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_run_items`
--

CREATE TABLE `payroll_run_items` (
  `id` bigint NOT NULL,
  `run_id` bigint NOT NULL,
  `employee_id` int NOT NULL,
  `login_user_id` int DEFAULT NULL,
  `pay_type` varchar(20) NOT NULL DEFAULT 'MONTHLY',
  `matrix_year` int DEFAULT NULL,
  `matrix_status` varchar(20) DEFAULT NULL,
  `matrix_level` varchar(5) DEFAULT NULL,
  `matrix_take_home` decimal(18,2) NOT NULL DEFAULT '0.00',
  `work_days` int NOT NULL DEFAULT '0',
  `days_present` int NOT NULL DEFAULT '0',
  `leave_days` int NOT NULL DEFAULT '0',
  `absent_days` int NOT NULL DEFAULT '0',
  `salary_basic` decimal(18,2) NOT NULL DEFAULT '0.00',
  `base_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `op_rate_day` decimal(18,2) NOT NULL DEFAULT '0.00',
  `op_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_position` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_child` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_transport` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_quota` decimal(18,2) NOT NULL DEFAULT '0.00',
  `allowance_fixed` decimal(18,2) NOT NULL DEFAULT '0.00',
  `deduction_fixed` decimal(18,2) NOT NULL DEFAULT '0.00',
  `overtime_rate_per_hour` decimal(18,2) NOT NULL DEFAULT '0.00',
  `overtime_hours` decimal(18,2) NOT NULL DEFAULT '0.00',
  `overtime_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `other_allowance` decimal(18,2) NOT NULL DEFAULT '0.00',
  `other_deduction` decimal(18,2) NOT NULL DEFAULT '0.00',
  `absence_deduction` decimal(18,2) NOT NULL DEFAULT '0.00',
  `kasbon_deduction` decimal(18,2) NOT NULL DEFAULT '0.00',
  `loan_deduction` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_pph21` decimal(18,2) NOT NULL DEFAULT '0.00',
  `bpjs_tk` decimal(18,2) NOT NULL DEFAULT '0.00',
  `bpjs_kes` decimal(18,2) NOT NULL DEFAULT '0.00',
  `gross_pay` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_deduction` decimal(18,2) NOT NULL DEFAULT '0.00',
  `net_pay` decimal(18,2) NOT NULL DEFAULT '0.00',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_salary_matrix`
--

CREATE TABLE `payroll_salary_matrix` (
  `id` bigint NOT NULL,
  `matrix_year` int NOT NULL,
  `payroll_status` varchar(20) NOT NULL,
  `payroll_level` varchar(5) NOT NULL,
  `job_title` varchar(50) DEFAULT NULL,
  `take_home_pay` decimal(18,2) NOT NULL DEFAULT '0.00',
  `basic_salary` decimal(18,2) NOT NULL DEFAULT '0.00',
  `op_rate_day` decimal(18,2) NOT NULL DEFAULT '0.00',
  `work_days_default` int NOT NULL DEFAULT '21',
  `tunj_jabatan` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tunj_anak` decimal(18,2) NOT NULL DEFAULT '0.00',
  `transport` decimal(18,2) NOT NULL DEFAULT '0.00',
  `kuota` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `products_media`
--

CREATE TABLE `products_media` (
  `id` int NOT NULL,
  `product_id` int NOT NULL,
  `media_type` enum('image','video') NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

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

--
-- Struktur dari tabel `purchases_ceisa_payment`
--

CREATE TABLE `purchases_ceisa_payment` (
  `id` int NOT NULL,
  `pay_code` varchar(60) NOT NULL,
  `pib_id` int NOT NULL,
  `pay_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `method` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `note` text,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_ceisa_pib`
--

CREATE TABLE `purchases_ceisa_pib` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `ceisa_status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `submitted_date` date DEFAULT NULL,
  `reject_count` int NOT NULL DEFAULT '0',
  `reject_reason` text,
  `bc11_no` varchar(80) DEFAULT NULL,
  `bc11_date` date DEFAULT NULL,
  `noa_no` varchar(80) DEFAULT NULL,
  `noa_date` date DEFAULT NULL,
  `billing_aju_no` varchar(80) DEFAULT NULL,
  `billing_aju_date` date DEFAULT NULL,
  `billing_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `billing_currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `sppb_no` varchar(80) DEFAULT NULL,
  `sppb_date` date DEFAULT NULL,
  `final_pib_no` varchar(80) DEFAULT NULL,
  `final_pib_date` date DEFAULT NULL,
  `note` text,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_ceisa_pib`
--

INSERT INTO `purchases_ceisa_pib` (`id`, `po_id`, `ceisa_status`, `submitted_date`, `reject_count`, `reject_reason`, `bc11_no`, `bc11_date`, `noa_no`, `noa_date`, `billing_aju_no`, `billing_aju_date`, `billing_amount`, `billing_currency`, `sppb_no`, `sppb_date`, `final_pib_no`, `final_pib_date`, `note`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 'DRAFT', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 'IDR', NULL, NULL, NULL, NULL, NULL, 'admin', '2026-01-02 01:03:36', '2026-01-02 01:03:36');

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_forwarder_invoice`
--

CREATE TABLE `purchases_forwarder_invoice` (
  `id` int NOT NULL,
  `fap_code` varchar(60) NOT NULL,
  `invoice_type` varchar(30) NOT NULL DEFAULT 'FORWARDER',
  `invoice_number` varchar(80) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `vendor_id` int DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `po_id` int DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `subtotal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `status` varchar(30) NOT NULL DEFAULT 'UNPAID',
  `note` text,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_forwarder_payment`
--

CREATE TABLE `purchases_forwarder_payment` (
  `id` int NOT NULL,
  `pay_code` varchar(60) NOT NULL,
  `fap_id` int NOT NULL,
  `pay_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `method` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `note` text,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_forwarder_quotes`
--

CREATE TABLE `purchases_forwarder_quotes` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `vendor_id` int NOT NULL,
  `quote_date` date NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `total_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `leadtime_days` int NOT NULL DEFAULT '0',
  `note` text,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_forwarder_quotes`
--

INSERT INTO `purchases_forwarder_quotes` (`id`, `po_id`, `vendor_id`, `quote_date`, `currency`, `total_cost`, `leadtime_days`, `note`, `status`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 1, '2026-01-01', 'IDR', 100000000.00, 20, '', 'SELECTED', 'admin', '2026-01-02 01:53:51', '2026-01-02 01:53:56', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_forwarding_docs`
--

CREATE TABLE `purchases_forwarding_docs` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `doc_type` varchar(30) NOT NULL,
  `doc_number` varchar(80) DEFAULT NULL,
  `doc_date` date DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `note` text,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_import_control`
--

CREATE TABLE `purchases_import_control` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `production_start_date` date DEFAULT NULL,
  `production_done_date` date DEFAULT NULL,
  `pickup_date` date DEFAULT NULL,
  `etd` date DEFAULT NULL,
  `eta` date DEFAULT NULL,
  `arrived_id_date` date DEFAULT NULL,
  `arrived_warehouse_date` date DEFAULT NULL,
  `note` text,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_import_control`
--

INSERT INTO `purchases_import_control` (`id`, `po_id`, `production_start_date`, `production_done_date`, `pickup_date`, `etd`, `eta`, `arrived_id_date`, `arrived_warehouse_date`, `note`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, '2026-02-02', NULL, NULL, NULL, NULL, NULL, '', 'admin', '2026-01-02 01:03:30', '2026-01-02 01:06:26');

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_invoice_ap`
--

CREATE TABLE `purchases_invoice_ap` (
  `id` int NOT NULL,
  `ap_code` varchar(60) NOT NULL,
  `invoice_type` varchar(30) NOT NULL DEFAULT 'PROFORMA',
  `invoice_number` varchar(80) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `manufacture_id` int DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `po_id` int DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `subtotal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_percent` decimal(6,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `status` varchar(30) NOT NULL DEFAULT 'UNPAID',
  `note` text,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_invoice_ap`
--

INSERT INTO `purchases_invoice_ap` (`id`, `ap_code`, `invoice_type`, `invoice_number`, `invoice_date`, `due_date`, `manufacture_id`, `office_code`, `po_id`, `currency`, `subtotal`, `tax_percent`, `tax_amount`, `total_amount`, `status`, `note`, `doc_path`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'RMI-AP-BGR-260101-001', 'PROFORMA', 'Yaxin', '2026-01-01', NULL, 1, 'BGR', 1, 'CNY', 30300.00, 0.00, 0.00, 30300.00, 'UNPAID', '', NULL, 'admin', '2026-01-02 01:08:13', '2026-01-02 07:54:27', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_payment_ap`
--

CREATE TABLE `purchases_payment_ap` (
  `id` int NOT NULL,
  `pay_code` varchar(60) NOT NULL,
  `ap_id` int NOT NULL,
  `pay_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `method` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `note` text,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_po`
--

CREATE TABLE `purchases_po` (
  `id` int NOT NULL,
  `po_code` varchar(60) NOT NULL,
  `po_date` date NOT NULL,
  `pr_id` int DEFAULT NULL,
  `manufacture_id` int DEFAULT NULL,
  `factory_forwarding_info` text,
  `forwarder_vendor_id` int DEFAULT NULL,
  `forwarder_status` varchar(30) NOT NULL DEFAULT 'PENDING',
  `forwarder_note` text,
  `office_code` varchar(20) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `payment_term` varchar(30) DEFAULT NULL,
  `note` text,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_po`
--

INSERT INTO `purchases_po` (`id`, `po_code`, `po_date`, `pr_id`, `manufacture_id`, `factory_forwarding_info`, `forwarder_vendor_id`, `forwarder_status`, `forwarder_note`, `office_code`, `currency`, `payment_term`, `note`, `status`, `total_amount`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'RMI-PO-BGR-260101-001', '2026-01-01', 1, 1, NULL, 1, 'IN_PROGRESS', NULL, 'BGR', 'CNY', '', '', 'IN_PRODUCTION', 101000.00, 'admin', '2026-01-02 01:02:50', '2026-01-02 07:15:49', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_po_items`
--

CREATE TABLE `purchases_po_items` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `line_no` int NOT NULL DEFAULT '1',
  `product_id` int DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `products_name` varchar(255) DEFAULT NULL,
  `qty` decimal(18,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(30) DEFAULT NULL,
  `unit_price` decimal(18,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(18,2) NOT NULL DEFAULT '0.00',
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_po_items`
--

INSERT INTO `purchases_po_items` (`id`, `po_id`, `line_no`, `product_id`, `sku`, `products_name`, `qty`, `unit`, `unit_price`, `subtotal`, `deleted_at`, `created_at`) VALUES
(1, 1, 1, 3, 'ALK-001', 'Alkes A', 10.00, 'unit', 10000.00, 100000.00, NULL, '2026-01-02 01:02:50'),
(2, 1, 2, 1, 'OBT-001', 'Obat A', 10.00, 'unit', 100.00, 1000.00, NULL, '2026-01-02 01:02:50');

-- --------------------------------------------------------

--
-- Struktur dari tabel `rbac_dept_role_permissions`
--

CREATE TABLE `rbac_dept_role_permissions` (
  `dept_code` varchar(40) NOT NULL,
  `role_code` varchar(40) NOT NULL,
  `perm_code` varchar(80) NOT NULL,
  `allow_flag` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `rbac_dept_role_permissions`
--

INSERT INTO `rbac_dept_role_permissions` (`dept_code`, `role_code`, `perm_code`, `allow_flag`, `created_at`) VALUES
('SYS', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-01 14:59:54'),
('SYS', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-01 14:59:54'),
('SYS', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-01 14:59:54'),
('SYS', 'STAFF', 'ABSENSI.APPROVE', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'ABSENSI.OFFICE_SETTINGS', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'ABSENSI.RECAP', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'MASTER.COMPANY_BANK_CRUD', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'MASTER.DEPARTMENT_CRUD', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'MASTER.EMPLOYEE_CRUD', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.AUDIT', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.EXPORT_BANK', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.LOANS', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.MATRIX_MANAGE', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.RUN_CREATE', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.RUN_EDIT', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.RUN_PAID', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.RUN_POST', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.SETTINGS', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PAYROLL.VIEW', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PURCHASES.APPROVE', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PURCHASES.CREATE', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PURCHASES.DELETE', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PURCHASES.EDIT', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PURCHASES.EXPORT', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'PURCHASES.VIEW', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'SALES.CREATE', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'SALES.DELETE', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'SALES.EDIT', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'SALES.EXPORT', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'SALES.VIEW', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'SYSTEM.RBAC_MANAGE', 1, '2026-01-01 16:36:03'),
('SYS', 'STAFF', 'SYSTEM.USER_MANAGE', 1, '2026-01-01 16:36:03');

-- --------------------------------------------------------

--
-- Struktur dari tabel `rbac_permissions`
--

CREATE TABLE `rbac_permissions` (
  `perm_code` varchar(80) NOT NULL,
  `perm_name` varchar(120) NOT NULL,
  `module` varchar(40) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `rbac_permissions`
--

INSERT INTO `rbac_permissions` (`perm_code`, `perm_name`, `module`, `description`, `is_active`) VALUES
('ABSENSI.APPROVE', 'Absensi - Approval', 'ABSENSI', 'Approve request absensi.', 1),
('ABSENSI.CHECKIN', 'Absensi - Check-in/Check-out', 'ABSENSI', 'Check-in/out by photo.', 1),
('ABSENSI.OFFICE_SETTINGS', 'Absensi - Office Settings', 'ABSENSI', 'GeoFence/Office settings.', 1),
('ABSENSI.RECAP', 'Absensi - Rekap HR', 'ABSENSI', 'Rekap & laporan absensi.', 1),
('ABSENSI.REQUEST', 'Absensi - Request Izin/Sakit/Dinas', 'ABSENSI', 'Pengajuan izin/sakit/dinas.', 1),
('ABSENSI.VIEW', 'Absensi - View Dashboard', 'ABSENSI', 'Melihat dashboard absensi.', 1),
('MASTER.COMPANY_BANK_CRUD', 'Rekening Perusahaan CRUD', 'MASTER', 'Kelola rekening perusahaan (FIN).', 1),
('MASTER.DEPARTMENT_CRUD', 'Master Departments CRUD', 'MASTER', 'Kelola master departemen.', 1),
('MASTER.EMPLOYEE_CRUD', 'Master Employees CRUD', 'MASTER', 'Create/Read/Update/Delete karyawan.', 1),
('PAYROLL.AUDIT', 'Payroll - Audit Log', 'PAYROLL', 'Lihat audit payroll.', 1),
('PAYROLL.EXPORT_BANK', 'Payroll - Export Bank', 'PAYROLL', 'Export pembayaran bank.', 1),
('PAYROLL.LOANS', 'Payroll - Pinjaman/Kasbon', 'PAYROLL', 'Pengajuan pinjaman/kasbon payroll.', 1),
('PAYROLL.MATRIX_MANAGE', 'Payroll - Master Golongan Gaji', 'PAYROLL', 'Import/edit matrix golongan gaji.', 1),
('PAYROLL.RUN_CREATE', 'Payroll - Generate Run', 'PAYROLL', 'Generate payroll run.', 1),
('PAYROLL.RUN_EDIT', 'Payroll - Edit Run Items', 'PAYROLL', 'Edit item run (tunj/potongan/lembur).', 1),
('PAYROLL.RUN_PAID', 'Payroll - Mark Paid', 'PAYROLL', 'Set run paid / final.', 1),
('PAYROLL.RUN_POST', 'Payroll - Post Run', 'PAYROLL', 'Lock/post payroll run.', 1),
('PAYROLL.SETTINGS', 'Payroll - Settings', 'PAYROLL', 'Mapping & payroll settings.', 1),
('PAYROLL.VIEW', 'Payroll - View', 'PAYROLL', 'Melihat dashboard payroll.', 1),
('PURCHASES.APPROVE', 'Purchases - Approve', 'PURCHASES', 'Approve purchases/PO.', 1),
('PURCHASES.CREATE', 'Purchases - Create', 'PURCHASES', 'Membuat purchases/PO.', 1),
('PURCHASES.DELETE', 'Purchases - Delete', 'PURCHASES', 'Menghapus purchases/PO (manager only).', 1),
('PURCHASES.EDIT', 'Purchases - Edit', 'PURCHASES', 'Mengubah purchases/PO.', 1),
('PURCHASES.EXPORT', 'Purchases - Export', 'PURCHASES', 'Export data purchases.', 1),
('PURCHASES.VIEW', 'Purchases - View', 'PURCHASES', 'Melihat data purchases.', 1),
('SALES.CREATE', 'Sales - Create', 'SALES', 'Membuat transaksi/data sales.', 1),
('SALES.DELETE', 'Sales - Delete', 'SALES', 'Menghapus data sales (sebaiknya manager only).', 1),
('SALES.EDIT', 'Sales - Edit', 'SALES', 'Mengubah transaksi/data sales.', 1),
('SALES.EXPORT', 'Sales - Export', 'SALES', 'Export data sales.', 1),
('SALES.VIEW', 'Sales - View', 'SALES', 'Melihat data sales.', 1),
('SYSTEM.RBAC_MANAGE', 'Manage Role & Permission', 'SYSTEM', 'Hanya SUPERADMIN/ADMIN.', 1),
('SYSTEM.USER_MANAGE', 'Manage Users (Master System Login)', 'SYSTEM', 'Hanya SUPERADMIN/ADMIN.', 1);

-- --------------------------------------------------------

--
-- Struktur dari tabel `rbac_user_permissions`
--

CREATE TABLE `rbac_user_permissions` (
  `user_id` bigint NOT NULL,
  `perm_code` varchar(80) NOT NULL,
  `allow_flag` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `sales_do`
--

CREATE TABLE `sales_do` (
  `id` int NOT NULL,
  `do_code` varchar(50) NOT NULL,
  `tracking_code` varchar(30) DEFAULT NULL,
  `do_date` date NOT NULL,
  `customer_id` int DEFAULT NULL,
  `customers_code` varchar(50) NOT NULL,
  `office_code` varchar(20) NOT NULL,
  `sales_emp_code` varchar(50) DEFAULT NULL,
  `shipping_address` text,
  `customer_pic` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `crm_start_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `crm_end_time` datetime DEFAULT NULL,
  `status_wqs` varchar(20) NOT NULL DEFAULT 'pending',
  `wqs_note` text,
  `wqs_updated_at` datetime DEFAULT NULL,
  `status_scm` varchar(20) NOT NULL DEFAULT 'pending',
  `scm_note` text,
  `scm_updated_at` datetime DEFAULT NULL,
  `status_act` varchar(20) NOT NULL DEFAULT 'pending',
  `act_note` text,
  `act_updated_at` datetime DEFAULT NULL,
  `status_fin` varchar(20) NOT NULL DEFAULT 'pending',
  `fin_due_date` date DEFAULT NULL,
  `fin_paid_date` date DEFAULT NULL,
  `fin_paid_amount` decimal(18,2) DEFAULT '0.00',
  `fin_note` text,
  `fin_updated_at` datetime DEFAULT NULL,
  `wqs_status` varchar(20) NOT NULL DEFAULT 'pending',
  `scm_status` varchar(20) NOT NULL DEFAULT 'pending',
  `act_status` varchar(20) NOT NULL DEFAULT 'pending',
  `fin_status` varchar(20) NOT NULL DEFAULT 'pending',
  `note` text,
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_code` varchar(20) DEFAULT NULL,
  `tax_included` tinyint(1) DEFAULT '0',
  `tax_rate_percent` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(18,2) DEFAULT '0.00',
  `grand_total` decimal(18,2) DEFAULT '0.00',
  `price_include_tax` tinyint(1) DEFAULT '0',
  `crm_status` varchar(20) NOT NULL DEFAULT 'crm_to_wqs',
  `flow_status` varchar(20) NOT NULL DEFAULT 'CRM',
  `crm_duration_seconds` int DEFAULT NULL,
  `crm_started_at` datetime DEFAULT NULL,
  `crm_finished_at` datetime DEFAULT NULL,
  `is_price_include_tax` tinyint(1) DEFAULT '0',
  `crm_created_at` datetime DEFAULT NULL,
  `crm_start_at` datetime DEFAULT NULL,
  `crm_finish_at` datetime DEFAULT NULL,
  `crm_duration_sec` int DEFAULT NULL,
  `wqs_picked_at` datetime DEFAULT NULL,
  `scm_delivered_at` datetime DEFAULT NULL,
  `act_invoiced_at` datetime DEFAULT NULL,
  `fin_paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `wqs_stock_before` varchar(255) DEFAULT NULL,
  `wqs_stock_after` varchar(255) DEFAULT NULL,
  `wqs_started_at` datetime DEFAULT NULL,
  `wqs_ready_at` datetime DEFAULT NULL,
  `scm_on_delivery_at` datetime DEFAULT NULL,
  `delivery_mode` varchar(20) DEFAULT NULL,
  `delivery_vendor_id` int DEFAULT NULL,
  `act_due_date` date DEFAULT NULL,
  `act_amount` decimal(15,2) DEFAULT NULL,
  `act_ready_fin_at` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `last_updated_at` datetime DEFAULT NULL,
  `fin_payment_file` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `sales_do`
--

INSERT INTO `sales_do` (`id`, `do_code`, `tracking_code`, `do_date`, `customer_id`, `customers_code`, `office_code`, `sales_emp_code`, `shipping_address`, `customer_pic`, `customer_phone`, `status`, `crm_start_time`, `crm_end_time`, `status_wqs`, `wqs_note`, `wqs_updated_at`, `status_scm`, `scm_note`, `scm_updated_at`, `status_act`, `act_note`, `act_updated_at`, `status_fin`, `fin_due_date`, `fin_paid_date`, `fin_paid_amount`, `fin_note`, `fin_updated_at`, `wqs_status`, `scm_status`, `act_status`, `fin_status`, `note`, `total_amount`, `tax_code`, `tax_included`, `tax_rate_percent`, `tax_amount`, `grand_total`, `price_include_tax`, `crm_status`, `flow_status`, `crm_duration_seconds`, `crm_started_at`, `crm_finished_at`, `is_price_include_tax`, `crm_created_at`, `crm_start_at`, `crm_finish_at`, `crm_duration_sec`, `wqs_picked_at`, `scm_delivered_at`, `act_invoiced_at`, `fin_paid_at`, `created_at`, `updated_at`, `wqs_stock_before`, `wqs_stock_after`, `wqs_started_at`, `wqs_ready_at`, `scm_on_delivery_at`, `delivery_mode`, `delivery_vendor_id`, `act_due_date`, `act_amount`, `act_ready_fin_at`, `last_updated_by`, `last_updated_at`, `fin_payment_file`) VALUES
(15, 'RMI-BGR-20251211-007', 'RMI-BGR-20251211-007', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'fin_done', '2025-12-11 20:36:46', NULL, 'done', NULL, '2025-12-11 15:32:12', 'done', NULL, '2025-12-11 15:49:52', 'done', NULL, '2025-12-11 16:09:56', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 16:18:46', 'pending', 'pending', 'pending', 'pending', '', 75000.00, 'PPN11', 0, 11.00, 8250.00, 83250.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 13:43:15', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11 13:43:15', '2025-12-11 16:18:46', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 'RMI-BGR-20251211-008', 'RMI-BGR-20251211-008', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'fin_done', '2025-12-11 20:36:46', NULL, 'open', NULL, '2025-12-11 15:32:06', 'open', NULL, '2025-12-11 15:49:46', 'done', NULL, '2025-12-11 16:19:21', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 16:19:32', 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 13:44:54', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11 13:44:54', '2025-12-11 16:19:32', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'RMI-BGR-251211-001', 'RMI-BGR-251211-001', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'wqs_done', '2025-12-11 20:36:46', NULL, 'done', NULL, '2025-12-11 19:48:56', 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', 'Cito', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 19:47:56', '2025-12-11 19:45:56', '2025-12-11 19:47:56', 120, NULL, NULL, NULL, NULL, '2025-12-11 19:47:56', '2025-12-11 19:48:56', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'RMI-BGR-251211-002', 'RMI-BGR-251211-002', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'crm_to_wqs', '2025-12-11 20:36:46', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 20:20:29', '2025-12-11 20:19:59', '2025-12-11 20:20:29', 30, NULL, NULL, NULL, NULL, '2025-12-11 20:20:29', '2025-12-11 20:20:29', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 'RMI-BGR-251211-003', 'RMI-BGR-251211-003', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'crm_to_wqs', '2025-12-11 20:39:43', '2025-12-11 20:39:43', 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 20:39:43', NULL, NULL, 0, NULL, NULL, NULL, NULL, '2025-12-11 20:39:43', '2025-12-11 20:39:43', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(20, 'RMI-TGR-251211-001', 'RMI-TGR-251211-001', '2025-12-11', NULL, 'H042;RS HERMINA SERPONG;RS Swasta;Hermina;Kota Tan', 'TGR', NULL, '', '', '', 'fin_done', '2025-12-11 23:51:36', NULL, 'done', NULL, '2025-12-11 23:52:06', 'done', NULL, '2025-12-11 23:52:19', 'done', NULL, '2025-12-11 23:52:40', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 23:52:53', 'pending', 'pending', 'pending', 'pending', '', 0.00, 'PPN11', 0, 11.00, 0.00, 0.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 23:51:36', '2025-12-11 23:51:05', '2025-12-11 23:51:36', 31, NULL, NULL, NULL, NULL, '2025-12-11 23:51:36', '2025-12-11 23:52:53', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

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

--
-- Struktur dari tabel `sales_do_items`
--

CREATE TABLE `sales_do_items` (
  `id` int NOT NULL,
  `do_id` int NOT NULL,
  `line_no` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `is_package` tinyint(1) DEFAULT '0',
  `show_package` tinyint(1) DEFAULT '0',
  `stock_at_crm` int DEFAULT NULL,
  `show_package_items` tinyint(1) DEFAULT '0',
  `products_name` varchar(255) DEFAULT NULL,
  `qty` int DEFAULT '0',
  `unit` varchar(20) DEFAULT NULL,
  `unit_price` decimal(18,2) DEFAULT '0.00',
  `disc_percent` decimal(5,2) DEFAULT '0.00',
  `exp_date` date DEFAULT NULL,
  `serial_lot` varchar(100) DEFAULT NULL,
  `subtotal` decimal(18,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `sales_do_items`
--

INSERT INTO `sales_do_items` (`id`, `do_id`, `line_no`, `product_id`, `sku`, `barcode`, `is_package`, `show_package`, `stock_at_crm`, `show_package_items`, `products_name`, `qty`, `unit`, `unit_price`, `disc_percent`, `exp_date`, `serial_lot`, `subtotal`) VALUES
(5, 15, 1, 2, 'OBT-002', NULL, 0, 0, NULL, 0, 'Obat B', 1, 'unit', 75000.00, 0.00, NULL, NULL, 75000.00),
(6, 16, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, NULL, NULL, 50000.00),
(7, 17, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, NULL, NULL, 50000.00),
(8, 18, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, NULL, NULL, 50000.00),
(9, 19, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, '2025-12-14', '66666', 50000.00),
(10, 20, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 0.00, 0.00, NULL, NULL, 0.00);

-- --------------------------------------------------------

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

--
-- Struktur dari tabel `system_config`
--

CREATE TABLE `system_config` (
  `id` int NOT NULL,
  `config_group` varchar(50) DEFAULT 'general',
  `config_key` varchar(100) NOT NULL,
  `config_value` text,
  `office_code` varchar(10) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `system_config`
--

INSERT INTO `system_config` (`id`, `config_group`, `config_key`, `config_value`, `office_code`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'numbering', 'numbering_do', 'DO-[OFFICE]-[YYYY][MM][####]', NULL, 'Polanya nomor DO', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(2, 'numbering', 'numbering_invoice', 'INV-[OFFICE]-[YYYY][MM][####]', NULL, 'Polanya nomor Invoice', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(3, 'numbering', 'numbering_quotation', 'QUO-[OFFICE]-[YYYY][MM][####]', NULL, 'Polanya nomor Quotation', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(4, 'numbering', 'numbering_employee', '[DEPT][YY][MM][###]', NULL, 'Polanya kode employee', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(5, 'default', 'default_tax_all', 'PPN-GEN', NULL, 'Default profil pajak untuk transaksi umum', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(6, 'default', 'default_tax_BGR', 'PPN-GEN', 'BGR', 'Override tax untuk kantor Bogor (kalau perlu)', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(7, 'default', 'default_payment_all', 'TOP30', NULL, 'Default payment terms seluruh kantor', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(8, 'default', 'default_payment_RS_HERMINA', 'TOP30', NULL, 'Contoh default payment khusus RS Hermina', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(9, 'general', 'company_name', 'Rizqullah Mediska Indonesia', NULL, 'Nama perusahaan', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(10, 'general', 'company_domain', 'rizqullahmediska.com', NULL, 'Domain resmi perusahaan', 1, '2025-12-10 21:50:05', '2025-12-10 21:50:05'),
(11, 'MASTER_DATA', 'app_title', 'ERP RMI SOFULL', NULL, 'Judul aplikasi di header Master Data.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(12, 'MASTER_DATA', 'app_tagline', 'ERP Fullstack Rizqullah Mediska Indonesia', NULL, 'Tagline singkat di Master Data.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(13, 'THEME', 'mode', 'dark', NULL, 'Tema tampilan (dark / light). Sekarang: dark sebagai standar RMI.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(14, 'CUSTOMERS', 'code_prefix_hermina', 'H', NULL, 'Prefix kode untuk customer segment Hermina (contoh: H001).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(15, 'CUSTOMERS', 'code_prefix_nonhermina', 'NH', NULL, 'Prefix kode untuk customer segment Non Hermina (contoh: NH001).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(16, 'CUSTOMERS', 'code_prefix_rsud', 'RSUD', NULL, 'Prefix kode untuk customer segment RSUD (contoh: RSUD001).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(17, 'CUSTOMERS', 'segment_keyword_hermina', 'HERMINA', NULL, 'Jika Nama Customer mengandung kata ini → segment = Hermina.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(18, 'CUSTOMERS', 'segment_keyword_rsud', 'RSUD', NULL, 'Jika Nama Customer mengandung kata ini → segment = RSUD.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(19, 'CUSTOMERS', 'default_category_swasta', 'RS Swasta', NULL, 'Default kategori untuk RS Swasta.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(20, 'CUSTOMERS', 'default_category_pemerintah', 'RS Pemerintah', NULL, 'Default kategori untuk RS Pemerintah.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(21, 'IMPORT_CUSTOMERS', 'csv_has_header', '1', NULL, '1 = baris pertama adalah header, 0 = tidak (dipakai saat import di master_customers.php).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(22, 'IMPORT_CUSTOMERS', 'csv_max_rows', '500', NULL, 'Batas maksimum baris dalam 1 file import customers.', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(23, 'IMPORT_CUSTOMERS', 'csv_columns_example', 'customers_name,city,address,phone,email,segment,category,office_code,google_maps_url', NULL, 'Contoh urutan kolom CSV untuk import customers (master_customers.php).', 1, '2026-01-01 22:00:59', '2026-01-01 22:00:59'),
(24, 'KPI_OPERATIONAL', 'WORK_START', '08:00', NULL, 'Jam mulai operasional (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(25, 'KPI_OPERATIONAL', 'WORK_END', '17:00', NULL, 'Jam selesai operasional (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(26, 'KPI_OPERATIONAL', 'WORK_DAYS', 'MON,TUE,WED,THU,FRI,SAT', NULL, 'Hari kerja (GLOBAL). Format: MON,TUE,...', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(27, 'KPI_OPERATIONAL', 'CUTOFF_DO_INPUT', '16:00', NULL, 'Cutoff input DO (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(28, 'KPI_SLA', 'SLA_WQS_HOURS', '24', NULL, 'SLA WQS (jam) - GLOBAL.', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(29, 'KPI_SLA', 'SLA_SCM_HOURS', '24', NULL, 'SLA SCM (jam) - GLOBAL.', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(30, 'KPI_SLA', 'SLA_ACT_HOURS', '24', NULL, 'SLA ACT (jam) - GLOBAL.', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(31, 'KPI_SLA', 'SLA_FIN_HOURS', '24', NULL, 'SLA FIN (jam) - GLOBAL.', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(32, 'KPI_FA_POLICY', 'DEPR_METHOD', 'STRAIGHT_LINE', NULL, 'Metode depresiasi fixed asset (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(33, 'KPI_FA_POLICY', 'SALVAGE_DEFAULT_PERCENT', '0', NULL, 'Default salvage value percent (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14');

-- --------------------------------------------------------

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

--
-- Struktur dari tabel `wqs_pr_items`
--

CREATE TABLE `wqs_pr_items` (
  `id` int NOT NULL,
  `pr_id` int NOT NULL,
  `line_no` int NOT NULL DEFAULT '1',
  `product_id` int DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `products_name` varchar(255) DEFAULT NULL,
  `qty` decimal(18,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(30) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `wqs_pr_items`
--

INSERT INTO `wqs_pr_items` (`id`, `pr_id`, `line_no`, `product_id`, `sku`, `products_name`, `qty`, `unit`, `deleted_at`, `created_at`) VALUES
(1, 1, 1, 3, 'ALK-001', 'Alkes A', 10.00, 'unit', NULL, '2026-01-02 00:35:42'),
(2, 1, 2, 1, 'OBT-001', 'Obat A', 10.00, 'unit', NULL, '2026-01-02 00:35:42');

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock`
--

CREATE TABLE `wqs_stock` (
  `id` int NOT NULL,
  `product_id` int NOT NULL,
  `stock_qty` int NOT NULL DEFAULT '0',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_adjustments`
--

CREATE TABLE `wqs_stock_adjustments` (
  `id` int NOT NULL,
  `adj_code` varchar(40) NOT NULL,
  `product_id` int NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `delta_qty` decimal(18,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_baseline_lock`
--

CREATE TABLE `wqs_stock_baseline_lock` (
  `id` tinyint NOT NULL,
  `locked_at` datetime NOT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_snapshot`
--

CREATE TABLE `wqs_stock_snapshot` (
  `id` int NOT NULL,
  `locked_at` datetime NOT NULL,
  `product_id` int NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `stock_qty` decimal(18,2) NOT NULL DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indeks untuk tabel yang dibuang
--

--
-- Indeks untuk tabel `absensi_audit`
--
ALTER TABLE `absensi_audit`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `absensi_logs`
--
ALTER TABLE `absensi_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `absensi_offices`
--
ALTER TABLE `absensi_offices`
  ADD PRIMARY KEY (`office_code`);

--
-- Indeks untuk tabel `absensi_requests`
--
ALTER TABLE `absensi_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `absensi_settings`
--
ALTER TABLE `absensi_settings`
  ADD PRIMARY KEY (`k`);

--
-- Indeks untuk tabel `absensi_user_profile`
--
ALTER TABLE `absensi_user_profile`
  ADD PRIMARY KEY (`user_id`);

--
-- Indeks untuk tabel `erp_audit_log`
--
ALTER TABLE `erp_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_entity` (`entity_key`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indeks untuk tabel `fa_assets`
--
ALTER TABLE `fa_assets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `asset_code` (`asset_code`);

--
-- Indeks untuk tabel `fa_audits`
--
ALTER TABLE `fa_audits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `audit_code` (`audit_code`);

--
-- Indeks untuk tabel `fa_audit_lines`
--
ALTER TABLE `fa_audit_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_asset` (`audit_id`,`asset_id`),
  ADD KEY `fk_al_asset` (`asset_id`);

--
-- Indeks untuk tabel `fa_audit_log`
--
ALTER TABLE `fa_audit_log`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `fa_dep_lines`
--
ALTER TABLE `fa_dep_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dep_asset_period` (`asset_id`,`period_ym`),
  ADD KEY `fk_dep_run` (`run_id`);

--
-- Indeks untuk tabel `fa_dep_runs`
--
ALTER TABLE `fa_dep_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dep_period` (`period_ym`);

--
-- Indeks untuk tabel `fa_disposals`
--
ALTER TABLE `fa_disposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ds_asset` (`asset_id`);

--
-- Indeks untuk tabel `fa_maintenance`
--
ALTER TABLE `fa_maintenance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_mt_asset` (`asset_id`);

--
-- Indeks untuk tabel `fa_transfers`
--
ALTER TABLE `fa_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_tr_asset` (`asset_id`);

--
-- Indeks untuk tabel `hrl_docs`
--
ALTER TABLE `hrl_docs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_doc_code` (`doc_code`),
  ADD KEY `ix_unit` (`unit`),
  ADD KEY `ix_category` (`category`),
  ADD KEY `ix_status` (`status`),
  ADD KEY `ix_deleted_at` (`deleted_at`);

--
-- Indeks untuk tabel `hrl_doc_acks`
--
ALTER TABLE `hrl_doc_acks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_ack` (`doc_id`,`version_no`,`username`),
  ADD KEY `ix_docver` (`doc_id`,`version_no`),
  ADD KEY `ix_user` (`username`);

--
-- Indeks untuk tabel `hrl_doc_versions`
--
ALTER TABLE `hrl_doc_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_doc_version` (`doc_id`,`version_no`),
  ADD KEY `ix_doc` (`doc_id`),
  ADD KEY `ix_status` (`status`),
  ADD KEY `ix_deleted_at` (`deleted_at`);

--
-- Indeks untuk tabel `master_company_bank_accounts`
--
ALTER TABLE `master_company_bank_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_purpose` (`purpose`),
  ADD KEY `idx_active` (`is_active`),
  ADD KEY `idx_office` (`office_code`),
  ADD KEY `idx_bank` (`bank_name`),
  ADD KEY `idx_acc` (`account_number`);

--
-- Indeks untuk tabel `master_customers`
--
ALTER TABLE `master_customers`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_departements`
--
ALTER TABLE `master_departements`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_discount_policy`
--
ALTER TABLE `master_discount_policy`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dept_level_seg` (`department_code`,`level_name`,`segment`);

--
-- Indeks untuk tabel `master_emailcompany`
--
ALTER TABLE `master_emailcompany`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email_full` (`email_full`);

--
-- Indeks untuk tabel `master_employees`
--
ALTER TABLE `master_employees`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_manufactures`
--
ALTER TABLE `master_manufactures`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_mpr`
--
ALTER TABLE `master_mpr`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_mpr_customer` (`customer_id`);

--
-- Indeks untuk tabel `master_office`
--
ALTER TABLE `master_office`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `office_code` (`office_code`),
  ADD UNIQUE KEY `uq_office_code` (`office_code`);

--
-- Indeks untuk tabel `master_payment_terms`
--
ALTER TABLE `master_payment_terms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payment_terms_code` (`payment_terms_code`);

--
-- Indeks untuk tabel `master_pricelist`
--
ALTER TABLE `master_pricelist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sku` (`sku`),
  ADD KEY `idx_office` (`office_code`),
  ADD KEY `idx_customer` (`customers_code`),
  ADD KEY `idx_active` (`status`,`deleted_at`);

--
-- Indeks untuk tabel `master_products`
--
ALTER TABLE `master_products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manufacture_id` (`manufacture_id`),
  ADD KEY `idx_vendor_id` (`vendor_id`);

--
-- Indeks untuk tabel `master_system_login`
--
ALTER TABLE `master_system_login`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_msl_status` (`status`),
  ADD KEY `idx_msl_role` (`role`),
  ADD KEY `idx_msl_office` (`office_code`);

--
-- Indeks untuk tabel `master_system_login_handover`
--
ALTER TABLE `master_system_login_handover`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_handover_username` (`username`),
  ADD KEY `idx_handover_changed_at` (`changed_at`);

--
-- Indeks untuk tabel `master_tax`
--
ALTER TABLE `master_tax`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tax_code` (`tax_code`);

--
-- Indeks untuk tabel `master_user`
--
ALTER TABLE `master_user`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_user_customer` (`customer_id`) USING BTREE;

--
-- Indeks untuk tabel `master_vendors`
--
ALTER TABLE `master_vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vendors_code` (`vendors_code`);

--
-- Indeks untuk tabel `payroll_employee_settings`
--
ALTER TABLE `payroll_employee_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_employee` (`employee_id`),
  ADD KEY `idx_login_user_id` (`login_user_id`);

--
-- Indeks untuk tabel `payroll_loans`
--
ALTER TABLE `payroll_loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_employee` (`employee_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_start` (`start_period_ym`),
  ADD KEY `idx_type` (`loan_type`);

--
-- Indeks untuk tabel `payroll_runs`
--
ALTER TABLE `payroll_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_period` (`period_ym`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `payroll_run_items`
--
ALTER TABLE `payroll_run_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_run_employee` (`run_id`,`employee_id`),
  ADD KEY `idx_run` (`run_id`),
  ADD KEY `idx_employee` (`employee_id`);

--
-- Indeks untuk tabel `payroll_salary_matrix`
--
ALTER TABLE `payroll_salary_matrix`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_matrix` (`matrix_year`,`payroll_status`,`payroll_level`),
  ADD KEY `idx_year` (`matrix_year`),
  ADD KEY `idx_status` (`payroll_status`);

--
-- Indeks untuk tabel `products_media`
--
ALTER TABLE `products_media`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `purchases_audit_log`
--
ALTER TABLE `purchases_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_ref` (`ref_code`);

--
-- Indeks untuk tabel `purchases_ceisa_payment`
--
ALTER TABLE `purchases_ceisa_payment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pib_pay_code` (`pay_code`),
  ADD KEY `idx_pib` (`pib_id`);

--
-- Indeks untuk tabel `purchases_ceisa_pib`
--
ALTER TABLE `purchases_ceisa_pib`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_po` (`po_id`),
  ADD KEY `idx_status` (`ceisa_status`);

--
-- Indeks untuk tabel `purchases_forwarder_invoice`
--
ALTER TABLE `purchases_forwarder_invoice`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fap_code` (`fap_code`),
  ADD KEY `idx_vendor` (`vendor_id`),
  ADD KEY `idx_po` (`po_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `purchases_forwarder_payment`
--
ALTER TABLE `purchases_forwarder_payment`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fpay_code` (`pay_code`),
  ADD KEY `idx_fap` (`fap_id`);

--
-- Indeks untuk tabel `purchases_forwarder_quotes`
--
ALTER TABLE `purchases_forwarder_quotes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_po` (`po_id`),
  ADD KEY `idx_vendor` (`vendor_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `purchases_forwarding_docs`
--
ALTER TABLE `purchases_forwarding_docs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_po` (`po_id`),
  ADD KEY `idx_type` (`doc_type`);

--
-- Indeks untuk tabel `purchases_import_control`
--
ALTER TABLE `purchases_import_control`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_po` (`po_id`),
  ADD KEY `idx_prod_done` (`production_done_date`),
  ADD KEY `idx_eta` (`eta`);

--
-- Indeks untuk tabel `purchases_invoice_ap`
--
ALTER TABLE `purchases_invoice_ap`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ap_code` (`ap_code`);

--
-- Indeks untuk tabel `purchases_payment_ap`
--
ALTER TABLE `purchases_payment_ap`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pay_code` (`pay_code`),
  ADD KEY `idx_ap` (`ap_id`);

--
-- Indeks untuk tabel `purchases_po`
--
ALTER TABLE `purchases_po`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_po_code` (`po_code`);

--
-- Indeks untuk tabel `purchases_po_items`
--
ALTER TABLE `purchases_po_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_po` (`po_id`),
  ADD KEY `idx_product` (`product_id`);

--
-- Indeks untuk tabel `rbac_dept_role_permissions`
--
ALTER TABLE `rbac_dept_role_permissions`
  ADD PRIMARY KEY (`dept_code`,`role_code`,`perm_code`),
  ADD KEY `fk_rbac_perm` (`perm_code`);

--
-- Indeks untuk tabel `rbac_permissions`
--
ALTER TABLE `rbac_permissions`
  ADD PRIMARY KEY (`perm_code`);

--
-- Indeks untuk tabel `rbac_user_permissions`
--
ALTER TABLE `rbac_user_permissions`
  ADD PRIMARY KEY (`user_id`,`perm_code`),
  ADD KEY `fk_rbac_perm2` (`perm_code`);

--
-- Indeks untuk tabel `sales_do`
--
ALTER TABLE `sales_do`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_do_code` (`do_code`);

--
-- Indeks untuk tabel `sales_do_audit`
--
ALTER TABLE `sales_do_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_do_id` (`do_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indeks untuk tabel `sales_do_items`
--
ALTER TABLE `sales_do_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_do` (`do_id`);

--
-- Indeks untuk tabel `system_audit_logs`
--
ALTER TABLE `system_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_module_action` (`module`,`action`),
  ADD KEY `idx_record` (`record_table`,`record_id`),
  ADD KEY `idx_username` (`username`);

--
-- Indeks untuk tabel `system_config`
--
ALTER TABLE `system_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_key_office` (`config_key`,`office_code`);

--
-- Indeks untuk tabel `wqs_pr`
--
ALTER TABLE `wqs_pr`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pr_code` (`pr_code`);

--
-- Indeks untuk tabel `wqs_pr_items`
--
ALTER TABLE `wqs_pr_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pr` (`pr_id`),
  ADD KEY `idx_product` (`product_id`);

--
-- Indeks untuk tabel `wqs_stock`
--
ALTER TABLE `wqs_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product` (`product_id`);

--
-- Indeks untuk tabel `wqs_stock_adjustments`
--
ALTER TABLE `wqs_stock_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_code` (`adj_code`),
  ADD KEY `idx_pid` (`product_id`);

--
-- Indeks untuk tabel `wqs_stock_baseline_lock`
--
ALTER TABLE `wqs_stock_baseline_lock`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `wqs_stock_snapshot`
--
ALTER TABLE `wqs_stock_snapshot`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pid` (`product_id`),
  ADD KEY `idx_sku` (`sku`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `absensi_audit`
--
ALTER TABLE `absensi_audit`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `absensi_logs`
--
ALTER TABLE `absensi_logs`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `absensi_requests`
--
ALTER TABLE `absensi_requests`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `erp_audit_log`
--
ALTER TABLE `erp_audit_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_assets`
--
ALTER TABLE `fa_assets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_audits`
--
ALTER TABLE `fa_audits`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_audit_lines`
--
ALTER TABLE `fa_audit_lines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_audit_log`
--
ALTER TABLE `fa_audit_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_dep_lines`
--
ALTER TABLE `fa_dep_lines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_dep_runs`
--
ALTER TABLE `fa_dep_runs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_disposals`
--
ALTER TABLE `fa_disposals`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_maintenance`
--
ALTER TABLE `fa_maintenance`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_transfers`
--
ALTER TABLE `fa_transfers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_docs`
--
ALTER TABLE `hrl_docs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `hrl_doc_acks`
--
ALTER TABLE `hrl_doc_acks`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_doc_versions`
--
ALTER TABLE `hrl_doc_versions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `master_company_bank_accounts`
--
ALTER TABLE `master_company_bank_accounts`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_customers`
--
ALTER TABLE `master_customers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT untuk tabel `master_departements`
--
ALTER TABLE `master_departements`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1153;

--
-- AUTO_INCREMENT untuk tabel `master_discount_policy`
--
ALTER TABLE `master_discount_policy`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_emailcompany`
--
ALTER TABLE `master_emailcompany`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `master_employees`
--
ALTER TABLE `master_employees`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `master_manufactures`
--
ALTER TABLE `master_manufactures`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `master_mpr`
--
ALTER TABLE `master_mpr`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_office`
--
ALTER TABLE `master_office`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `master_payment_terms`
--
ALTER TABLE `master_payment_terms`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `master_pricelist`
--
ALTER TABLE `master_pricelist`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_products`
--
ALTER TABLE `master_products`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `master_system_login`
--
ALTER TABLE `master_system_login`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `master_system_login_handover`
--
ALTER TABLE `master_system_login_handover`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_tax`
--
ALTER TABLE `master_tax`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `master_user`
--
ALTER TABLE `master_user`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_vendors`
--
ALTER TABLE `master_vendors`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `payroll_employee_settings`
--
ALTER TABLE `payroll_employee_settings`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_loans`
--
ALTER TABLE `payroll_loans`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_runs`
--
ALTER TABLE `payroll_runs`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_run_items`
--
ALTER TABLE `payroll_run_items`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_salary_matrix`
--
ALTER TABLE `payroll_salary_matrix`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `products_media`
--
ALTER TABLE `products_media`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_audit_log`
--
ALTER TABLE `purchases_audit_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT untuk tabel `purchases_ceisa_payment`
--
ALTER TABLE `purchases_ceisa_payment`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_ceisa_pib`
--
ALTER TABLE `purchases_ceisa_pib`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarder_invoice`
--
ALTER TABLE `purchases_forwarder_invoice`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarder_payment`
--
ALTER TABLE `purchases_forwarder_payment`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarder_quotes`
--
ALTER TABLE `purchases_forwarder_quotes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarding_docs`
--
ALTER TABLE `purchases_forwarding_docs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_import_control`
--
ALTER TABLE `purchases_import_control`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `purchases_invoice_ap`
--
ALTER TABLE `purchases_invoice_ap`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_payment_ap`
--
ALTER TABLE `purchases_payment_ap`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_po`
--
ALTER TABLE `purchases_po`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_po_items`
--
ALTER TABLE `purchases_po_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `sales_do`
--
ALTER TABLE `sales_do`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT untuk tabel `sales_do_audit`
--
ALTER TABLE `sales_do_audit`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `sales_do_items`
--
ALTER TABLE `sales_do_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `system_audit_logs`
--
ALTER TABLE `system_audit_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `system_config`
--
ALTER TABLE `system_config`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT untuk tabel `wqs_pr`
--
ALTER TABLE `wqs_pr`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `wqs_pr_items`
--
ALTER TABLE `wqs_pr_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock`
--
ALTER TABLE `wqs_stock`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_adjustments`
--
ALTER TABLE `wqs_stock_adjustments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_snapshot`
--
ALTER TABLE `wqs_stock_snapshot`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `fa_audit_lines`
--
ALTER TABLE `fa_audit_lines`
  ADD CONSTRAINT `fk_al_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_al_audit` FOREIGN KEY (`audit_id`) REFERENCES `fa_audits` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `fa_dep_lines`
--
ALTER TABLE `fa_dep_lines`
  ADD CONSTRAINT `fk_dep_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dep_run` FOREIGN KEY (`run_id`) REFERENCES `fa_dep_runs` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `fa_disposals`
--
ALTER TABLE `fa_disposals`
  ADD CONSTRAINT `fk_ds_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `fa_maintenance`
--
ALTER TABLE `fa_maintenance`
  ADD CONSTRAINT `fk_mt_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `fa_transfers`
--
ALTER TABLE `fa_transfers`
  ADD CONSTRAINT `fk_tr_asset` FOREIGN KEY (`asset_id`) REFERENCES `fa_assets` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `master_user`
--
ALTER TABLE `master_user`
  ADD CONSTRAINT `fk_mpr_customer` FOREIGN KEY (`customer_id`) REFERENCES `master_customers` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `rbac_dept_role_permissions`
--
ALTER TABLE `rbac_dept_role_permissions`
  ADD CONSTRAINT `fk_rbac_perm` FOREIGN KEY (`perm_code`) REFERENCES `rbac_permissions` (`perm_code`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `rbac_user_permissions`
--
ALTER TABLE `rbac_user_permissions`
  ADD CONSTRAINT `fk_rbac_perm2` FOREIGN KEY (`perm_code`) REFERENCES `rbac_permissions` (`perm_code`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Fix: pastikan admin & superadmin punya role ADMIN/SUPERADMIN
--
UPDATE master_system_login SET role='admin', level='ADMIN', department='SYS', updated_at=NOW() WHERE username IN ('admin','administrator');
UPDATE master_system_login SET role='owner', level='SUPERADMIN', department='SYS', updated_at=NOW() WHERE username='superadmin';

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
