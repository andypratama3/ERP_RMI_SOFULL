-- phpMyAdmin SQL Dump
-- version 6.0.0-dev+20251118.dfcf3dd949
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Waktu pembuatan: 27 Jan 2026 pada 16.22
-- Versi server: 8.0.44
-- Versi PHP: 8.4.15

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
  `created_at` datetime NOT NULL,
  `office_id` bigint DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
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
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-01 21:32:41'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-22 17:43:57'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-22 20:05:52'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-22 20:06:15'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-22 21:06:19'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-22 21:48:35'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-23 13:29:52'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-23 15:23:12'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-23 15:24:34'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-23 16:09:31'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-23 17:31:42'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-24 11:15:05'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-24 11:25:28'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-24 17:05:43'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-24 17:05:45'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-24 18:20:38'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-25 16:17:52'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 05:11:32'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 05:11:36'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 05:11:40'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 05:12:26'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 05:15:22'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 05:15:49'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 05:15:51'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 05:15:56'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 06:06:47'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 06:06:52'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 06:06:54'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 06:06:56'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 06:07:01'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 06:07:03'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 06:07:42'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 07:17:10'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 07:18:37'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 07:18:41'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 07:18:43'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 07:21:32'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 07:21:38'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 07:35:45'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 16:43:18'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-26 22:22:27'),
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-27 18:01:40');

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
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
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
('geofence_enforce', '1', '2026-01-01 21:32:41'),
('geofence_enforce', '1', '2026-01-22 17:43:57'),
('geofence_enforce', '1', '2026-01-22 20:05:52'),
('geofence_enforce', '1', '2026-01-22 20:06:15'),
('geofence_enforce', '1', '2026-01-22 21:06:19'),
('geofence_enforce', '1', '2026-01-22 21:48:35'),
('geofence_enforce', '1', '2026-01-23 13:29:52'),
('geofence_enforce', '1', '2026-01-23 15:23:12'),
('geofence_enforce', '1', '2026-01-23 15:24:34'),
('geofence_enforce', '1', '2026-01-23 16:09:31'),
('geofence_enforce', '1', '2026-01-23 17:31:42'),
('geofence_enforce', '1', '2026-01-24 11:15:05'),
('geofence_enforce', '1', '2026-01-24 11:25:28'),
('geofence_enforce', '1', '2026-01-24 17:05:43'),
('geofence_enforce', '1', '2026-01-24 17:05:45'),
('geofence_enforce', '1', '2026-01-24 18:20:38'),
('geofence_enforce', '1', '2026-01-25 16:17:52'),
('geofence_enforce', '1', '2026-01-26 05:11:32'),
('geofence_enforce', '1', '2026-01-26 05:11:36'),
('geofence_enforce', '1', '2026-01-26 05:11:40'),
('geofence_enforce', '1', '2026-01-26 05:12:26'),
('geofence_enforce', '1', '2026-01-26 05:15:22'),
('geofence_enforce', '1', '2026-01-26 05:15:49'),
('geofence_enforce', '1', '2026-01-26 05:15:51'),
('geofence_enforce', '1', '2026-01-26 05:15:56'),
('geofence_enforce', '1', '2026-01-26 06:06:47'),
('geofence_enforce', '1', '2026-01-26 06:06:52'),
('geofence_enforce', '1', '2026-01-26 06:06:54'),
('geofence_enforce', '1', '2026-01-26 06:06:56'),
('geofence_enforce', '1', '2026-01-26 06:07:01'),
('geofence_enforce', '1', '2026-01-26 06:07:03'),
('geofence_enforce', '1', '2026-01-26 06:07:42'),
('geofence_enforce', '1', '2026-01-26 07:17:10'),
('geofence_enforce', '1', '2026-01-26 07:18:37'),
('geofence_enforce', '1', '2026-01-26 07:18:41'),
('geofence_enforce', '1', '2026-01-26 07:18:43'),
('geofence_enforce', '1', '2026-01-26 07:21:32'),
('geofence_enforce', '1', '2026-01-26 07:21:38'),
('geofence_enforce', '1', '2026-01-26 07:35:45'),
('geofence_enforce', '1', '2026-01-26 16:43:18'),
('geofence_enforce', '1', '2026-01-26 22:22:27'),
('geofence_enforce', '1', '2026-01-27 18:01:40');

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
-- Struktur dari tabel `hrl_reg_alkes_cases`
--

CREATE TABLE `hrl_reg_alkes_cases` (
  `id` int NOT NULL,
  `case_code` varchar(40) NOT NULL,
  `manufacture_id` int DEFAULT NULL,
  `manufacture_code` varchar(60) NOT NULL,
  `manufacture_name` varchar(200) DEFAULT NULL,
  `product_name` varchar(200) NOT NULL,
  `is_oem` tinyint(1) NOT NULL DEFAULT '0',
  `stage_no` int NOT NULL DEFAULT '1',
  `stage_code` varchar(40) DEFAULT NULL,
  `next_pic_dept` varchar(10) DEFAULT NULL,
  `revision_count` tinyint NOT NULL DEFAULT '0',
  `revision_deadline` date DEFAULT NULL,
  `oss_pb_umku` varchar(120) DEFAULT NULL,
  `regalkes_ref` varchar(120) DEFAULT NULL,
  `nie_type` varchar(10) DEFAULT NULL,
  `nie_no` varchar(150) DEFAULT NULL,
  `nie_issue_date` date DEFAULT NULL,
  `nie_file_rel` varchar(255) DEFAULT NULL,
  `sku_imported_count` int NOT NULL DEFAULT '0',
  `sku_last_import_at` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'OPEN',
  `note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` varchar(64) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` varchar(64) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_reg_alkes_case_docs`
--

CREATE TABLE `hrl_reg_alkes_case_docs` (
  `id` int NOT NULL,
  `case_id` int NOT NULL,
  `doc_type` varchar(40) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_rel` varchar(255) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `uploaded_by` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_requests`
--

CREATE TABLE `hrl_requests` (
  `id` bigint NOT NULL,
  `req_code` varchar(64) DEFAULT NULL,
  `req_type` varchar(32) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `dept_code` varchar(32) DEFAULT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `gps_accuracy_m` int DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'DRAFT',
  `created_by` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `submitted_by` varchar(64) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `manager_approved_by` varchar(64) DEFAULT NULL,
  `manager_approved_at` datetime DEFAULT NULL,
  `manager_sign_method` varchar(16) DEFAULT NULL,
  `hrl_approved_by` varchar(64) DEFAULT NULL,
  `hrl_approved_at` datetime DEFAULT NULL,
  `hrl_sign_method` varchar(16) DEFAULT NULL,
  `fin_approved_by` varchar(64) DEFAULT NULL,
  `fin_approved_at` datetime DEFAULT NULL,
  `fin_sign_method` varchar(16) DEFAULT NULL,
  `paid_by` varchar(64) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `rejected_by` varchar(64) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `reject_note` text,
  `deleted_by` varchar(64) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_request_files`
--

CREATE TABLE `hrl_request_files` (
  `id` bigint NOT NULL,
  `request_id` bigint NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'ATTACH',
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime` varchar(128) DEFAULT NULL,
  `size_bytes` int DEFAULT NULL,
  `uploaded_by` varchar(64) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_user_pins`
--

CREATE TABLE `hrl_user_pins` (
  `username` varchar(64) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_audit_log`
--

CREATE TABLE `kpi_audit_log` (
  `id` bigint UNSIGNED NOT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  `ref_code` varchar(120) DEFAULT NULL,
  `detail` longtext,
  `actor` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_employee`
--

CREATE TABLE `kpi_employee` (
  `id` bigint UNSIGNED NOT NULL,
  `month_ym` varchar(7) NOT NULL,
  `dept_code` varchar(50) NOT NULL,
  `employee_code` varchar(100) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `metrics_json` longtext,
  `note` text,
  `deleted_at` datetime DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_office`
--

CREATE TABLE `kpi_office` (
  `id` bigint UNSIGNED NOT NULL,
  `month_ym` varchar(7) NOT NULL,
  `office_code` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `metrics_json` longtext,
  `note` text,
  `deleted_at` datetime DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_snapshot`
--

CREATE TABLE `kpi_snapshot` (
  `id` bigint UNSIGNED NOT NULL,
  `snapshot_month` varchar(7) NOT NULL,
  `scope` varchar(20) NOT NULL,
  `actor` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_snapshot_items`
--

CREATE TABLE `kpi_snapshot_items` (
  `id` bigint UNSIGNED NOT NULL,
  `snapshot_id` bigint UNSIGNED NOT NULL,
  `payload_json` longtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
  `id` bigint UNSIGNED NOT NULL,
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
(3, 'SYS150901', 'Admin', 'SYS', 'SYS', '', '', '', NULL, '', '', '', '', '', 'sys', '2015', '09', '', '', NULL, NULL, NULL, NULL, 'active', '', '2026-01-01 22:57:16', '2026-01-25 10:15:28'),
(4, 'SYS150902', 'SUPERADMIN', 'SYS', 'SYS', '', '', '', NULL, '', '', '', '', '', 'sys', '2015', '09', '', '', NULL, NULL, NULL, NULL, 'active', '', '2026-01-01 22:57:57', '2026-01-02 05:57:57'),
(5, 'FIN250101', 'Test_Employee', 'FIN', 'Staff', 'A', 'PROBATION', '2A', NULL, '', '', '', '', '', 'bgr', '2025', '01', '', '', NULL, NULL, NULL, NULL, 'active', '', '2026-01-25 03:45:44', '2026-01-25 10:45:44'),
(6, 'FIN250102', 'Test_Employee_Mgr', 'FIN', 'Manager', 'C', 'PROBATION', '4A', NULL, '', '', '', '', '', 'bgr', '2025', '01', '', '', NULL, NULL, NULL, NULL, 'active', '', '2026-01-25 03:46:34', '2026-01-25 10:46:34');

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
  `office_lat` decimal(15,12) DEFAULT NULL,
  `office_lng` decimal(15,12) DEFAULT NULL,
  `office_radius_m` int DEFAULT '120'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_office`
--

INSERT INTO `master_office` (`id`, `office_code`, `office_name`, `city`, `address`, `phone`, `is_active`, `created_by`, `created_at`, `updated_at`, `office_lat`, `office_lng`, `office_radius_m`) VALUES
(4, 'bdg', 'Rizqullah Mediska Indonesia Bandung', 'Kota Bandung', 'Ruko Puri Dago Mas Unit 428, Jalan Terusan Jakarta, Kota Bandung, Provinsi Jawa Barat, 40293', '0812-1067-5863', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.917464000000, 107.619123000000, 150),
(1, 'bgr', 'Rizqullah Mediska Indonesia', 'Kab. Bogor', 'Jalan Pondok Rajeg, Ruko Sentra Pondok Rajeg No. 7 & 8, Kel. Pondok Rajeg, Kec. Cibinong, Kabupaten Bogor, Provinsi Jawa Barat, 16914', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.597147000000, 106.806039000000, 150),
(2, 'bks', 'Rizqullah Mediska Indonesia Bekasi', 'Kota Bekasi', 'Perumahan The East View Residence Blok F 17, Jl. Raya Mustika Sari, Kel. Mustikasari, Kec. Mustikajaya, Kota Bekasi, Provinsi Jawa Barat, 17157', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.238270000000, 106.975570000000, 150),
(8, 'jgy', 'Depo Yogyakarta', 'Yogyakarta', '', NULL, 1, 'SYSTEM', '2025-12-08 09:22:59', '2025-12-08 09:22:59', NULL, NULL, 120),
(7, 'kal', 'Depo Kalimantan', 'Kalimantan', '', NULL, 1, 'SYSTEM', '2025-12-08 09:22:59', '2025-12-08 09:22:59', NULL, NULL, 120),
(5, 'slo', 'Rizqullah Mediska Indonesia Jawa Tengah (Solo)', 'Kota Surakarta', 'Jalan Pakel No. 06, Kel. Banyuanyar, Kec. Banjarsari, Kota Surakarta, Provinsi Jawa Tengah, 57137', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -7.566667000000, 110.816667000000, 150),
(6, 'smg', 'Rizqullah Mediska Indonesia Semarang', 'Kota Semarang', 'Ruko Tlogo Timun Mas No. 1A Kav. C, Kel. Tlogosari Kulon, Kec. Pedurungan, Kota Semarang, Provinsi Jawa Tengah, 50196', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.966667000000, 110.416667000000, 150),
(9, 'sys', 'SYS', 'SYS', '', '', 0, 'SYSTEM', '2026-01-02 05:55:45', '2026-01-02 07:22:59', NULL, NULL, 120),
(3, 'tgr', 'Rizqullah Mediska Indonesia Tangerang', 'Kota Tangerang', 'Jalan Muhamad Toha No. B26 Km. 0,6, Kel. Periuk, Kec. Periuk, Kota Tangerang, Provinsi Banten, 15131', '0813-8425-1574', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.178306000000, 106.631889000000, 150);

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
(1, 'OBT-001', 'Obat A', 'BMHP', NULL, NULL, NULL, NULL, 100, 50000.00, 'unit', 'active', '2025-12-08 17:33:28', '2026-01-20 22:25:06', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, '8991234567890', 'AKL.12345678901', NULL, 120, 'SINGLE', NULL),
(2, 'OBT-002', 'Obat B', 'BMHP', NULL, NULL, NULL, NULL, 150, 75000.00, 'unit', 'active', '2025-12-08 17:33:28', '2026-01-20 22:25:06', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, 'SINGLE', NULL),
(3, 'ALK-001', 'Alkes A', 'BMHP', NULL, NULL, NULL, NULL, 50, 150000.00, 'unit', 'active', '2025-12-08 17:33:28', '2026-01-20 22:25:06', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, 'SINGLE', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_products_doc`
--

CREATE TABLE `master_products_doc` (
  `id` bigint UNSIGNED NOT NULL,
  `product_id` bigint UNSIGNED NOT NULL,
  `doc_type` varchar(50) NOT NULL DEFAULT 'GENERAL',
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `uploaded_by` bigint UNSIGNED NOT NULL DEFAULT '0',
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_by` bigint UNSIGNED NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_products_print`
--

CREATE TABLE `master_products_print` (
  `id` bigint UNSIGNED NOT NULL,
  `product_id` bigint UNSIGNED DEFAULT NULL,
  `print_type` varchar(50) NOT NULL DEFAULT 'LIST',
  `note` varchar(255) DEFAULT NULL,
  `printed_by` bigint UNSIGNED NOT NULL DEFAULT '0',
  `printed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
(1, 'superadmin', 'Super Admin', '$2b$10$pfgOq0FsaHzBSbdSnVh11eTYhueWwXNZzmMaO7W3dXtlHu2/jFjAy', 'owner', 'SUPERADMIN', 'SYS', NULL, NULL, NULL, NULL, 'active', '2026-01-01 21:17:38', '2026-01-22 18:45:47', '2026-01-22 18:45:47'),
(2, 'admin', 'Admin', '$2b$10$pfgOq0FsaHzBSbdSnVh11eTYhueWwXNZzmMaO7W3dXtlHu2/jFjAy', 'admin', 'ADMIN', 'SYS', NULL, NULL, NULL, NULL, 'active', '2026-01-01 21:17:38', '2026-01-27 23:17:32', '2026-01-27 23:17:32'),
(3, 'StaffACT_BDG', 'Staff ACT (BDG)', '$2y$12$x.tiNrhGXhyeLPv4H2p.OedCYi/OZEAYgVTbW1j2z5NrsGKv.iWLu', 'STAFF', 'STAFF', 'ACT', 'BDG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:08', '2026-01-26 05:15:39', NULL),
(4, 'MgrACT_BGR', 'Manager ACT (BGR)', '$2y$12$uYZNeMRwfAUQM/cUmMHiU.hcGwA5VLfh1Kis5d0V59X5xbmf.jJgu', 'MANAGER', 'MANAGER', 'ACT', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:09', '2026-01-26 05:15:39', NULL),
(5, 'StaffACT_BGR', 'Staff ACT (BGR)', '$2y$12$o3OnUnWKt0W.SC/HjscHEOMeNdILLmsW2masDyS7NCfaQ2mlu8EZa', 'STAFF', 'STAFF', 'ACT', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:10', '2026-01-26 05:15:39', NULL),
(6, 'StaffACT_BKS', 'Staff ACT (BKS)', '$2y$12$Z98svCiK98P7kBq7JxZMhOAicP1KCsNeh0KhgVa/5ZzeFI4I1oniq', 'STAFF', 'STAFF', 'ACT', 'BKS', NULL, NULL, NULL, 'active', '2026-01-25 13:59:11', '2026-01-26 05:15:39', NULL),
(7, 'StaffACT_JGY', 'Staff ACT (JGY)', '$2y$12$KwsV3RAgz7Ri7VhxEm2bLOMBok.7N2sFEDJ80Fi956fyrNiV4QCpi', 'STAFF', 'STAFF', 'ACT', 'JGY', NULL, NULL, NULL, 'active', '2026-01-25 13:59:12', '2026-01-26 05:15:39', NULL),
(8, 'StaffACT_KAL', 'Staff ACT (KAL)', '$2y$12$82wTa5oHwkvvbcd1Cjo63O7I6WN/avDrvmyp2hFeRbDXQjZmrto9W', 'STAFF', 'STAFF', 'ACT', 'KAL', NULL, NULL, NULL, 'active', '2026-01-25 13:59:13', '2026-01-26 05:15:39', NULL),
(9, 'StaffACT_SLO', 'Staff ACT (SLO)', '$2y$12$5vKsNb9.N1zhGjU151PUx.rbm.vfKsjgdON4chp2SK9Al4V6HByr2', 'STAFF', 'STAFF', 'ACT', 'SLO', NULL, NULL, NULL, 'active', '2026-01-25 13:59:14', '2026-01-26 05:15:39', NULL),
(10, 'StaffACT_SMG', 'Staff ACT (SMG)', '$2y$12$5YvhMmQimxaMr4N9ECjU6OrcnoqE6Q3a.4KupcMI6s7r3Ws.dYNxW', 'STAFF', 'STAFF', 'ACT', 'SMG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:15', '2026-01-26 05:15:39', NULL),
(11, 'StaffACT_TGR', 'Staff ACT (TGR)', '$2y$12$D9jwZowuQjNZYQFHWyfkY.KSbinSeieTpAN7ztF9XcCxi1OsHwl2S', 'STAFF', 'STAFF', 'ACT', 'TGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:16', '2026-01-26 05:15:39', NULL),
(12, 'StaffCRM_BDG', 'Staff CRM (BDG)', '$2y$12$X0gRHXVcU5nk2pG4PkEiqOsfDUjDzy8YjrF3H1MWYkvht9psnlsXC', 'STAFF', 'STAFF', 'CRM', 'BDG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:17', '2026-01-26 05:15:39', NULL),
(13, 'MgrCRM_BGR', 'Manager CRM (BGR)', '$2y$12$HnMb.qAaNJCxlHb4dmMQB.IsUYCeOeYAL5DzBWE7hNxIegIVWpALm', 'MANAGER', 'MANAGER', 'CRM', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:18', '2026-01-26 05:15:39', NULL),
(14, 'StaffCRM_BGR', 'Staff CRM (BGR)', '$2y$12$XhDViu90BcOz24HxFWfDB.dMmcZVSoYT.l0T4lCYQNEFUkdeF0Y9a', 'STAFF', 'STAFF', 'CRM', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:19', '2026-01-26 05:15:39', NULL),
(15, 'StaffCRM_BKS', 'Staff CRM (BKS)', '$2y$12$D8PgnuzMHqIsH0cgPoyV6Od/.4C6cTGOu1HL3I2izV/EU4apwtaOi', 'STAFF', 'STAFF', 'CRM', 'BKS', NULL, NULL, NULL, 'active', '2026-01-25 13:59:20', '2026-01-26 05:15:39', NULL),
(16, 'StaffCRM_JGY', 'Staff CRM (JGY)', '$2y$12$gG7O3EOcdxOgJtZIVsatruMNa2OnAWIDpO9PI.2jjIHf8SBJKswfS', 'STAFF', 'STAFF', 'CRM', 'JGY', NULL, NULL, NULL, 'active', '2026-01-25 13:59:21', '2026-01-26 05:15:39', NULL),
(17, 'StaffCRM_KAL', 'Staff CRM (KAL)', '$2y$12$MVgrgXVG9xa8JUa5v39zZOFvEqYBy/Jwini.gYEg7vz6Jb19orrxW', 'STAFF', 'STAFF', 'CRM', 'KAL', NULL, NULL, NULL, 'active', '2026-01-25 13:59:22', '2026-01-26 05:15:39', NULL),
(18, 'StaffCRM_SLO', 'Staff CRM (SLO)', '$2y$12$s96BZ9BUGnCyXI4IgreYFugMIzArrS4XHNQf1NI23385vXXCspG8u', 'STAFF', 'STAFF', 'CRM', 'SLO', NULL, NULL, NULL, 'active', '2026-01-25 13:59:23', '2026-01-26 05:15:39', NULL),
(19, 'StaffCRM_SMG', 'Staff CRM (SMG)', '$2y$12$Yz0zjjjUr/8s4Rp3cBDCse0aRrN5ocl9bkmvKoYiuV62Um9L7Va3W', 'STAFF', 'STAFF', 'CRM', 'SMG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:24', '2026-01-26 05:15:39', NULL),
(20, 'StaffCRM_TGR', 'Staff CRM (TGR)', '$2y$12$bZe29yFQA.WtpwdK7hVNk.cmODrd.SNA/PjricaBaFKf6XkJvB1uy', 'STAFF', 'STAFF', 'CRM', 'TGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:25', '2026-01-26 05:15:39', NULL),
(21, 'StaffFIN_BDG', 'Staff FIN (BDG)', '$2y$12$iIDhnPFe91MauTKxw.nYF.dIhDvv4mAkRkwZ2VWGNmDA7ckHzeh9G', 'STAFF', 'STAFF', 'FIN', 'BDG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:26', '2026-01-26 05:15:39', NULL),
(22, 'MgrFIN_BGR', 'Manager FIN (BGR)', '$2y$12$kC4j4v0iNNx5menHnljcOePKHNAYEeQXKruMA7iaULhra3jQ4GAUS', 'MANAGER', 'MANAGER', 'FIN', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:27', '2026-01-26 05:15:39', NULL),
(23, 'StaffFIN_BGR', 'Staff FIN (BGR)', '$2y$12$RxehmWHrEfJK4Siu7g3CEOMrjrCstZvi4LjSgbAlOzQuC9j1bZ/Sa', 'STAFF', 'STAFF', 'FIN', 'BGR', 'FIN250101', '2026-01-26 05:14:28', 'admin', 'active', '2026-01-25 13:59:28', '2026-01-26 06:06:41', '2026-01-26 06:06:41'),
(24, 'StaffFIN_BKS', 'Staff FIN (BKS)', '$2y$12$OJ5ii5Iy9GpWGuk8l9zo1OAl6Y.soLQYyHz2JFUjePcD/.XBwBsJS', 'STAFF', 'STAFF', 'FIN', 'BKS', NULL, NULL, NULL, 'active', '2026-01-25 13:59:29', '2026-01-26 05:15:39', NULL),
(25, 'StaffFIN_JGY', 'Staff FIN (JGY)', '$2y$12$h5tmYqgWqVQslpV2M74OxOZB1wbOJ9P/HRkqe7vICUxE.yv.OaOKm', 'STAFF', 'STAFF', 'FIN', 'JGY', NULL, NULL, NULL, 'active', '2026-01-25 13:59:30', '2026-01-26 05:15:39', NULL),
(26, 'StaffFIN_KAL', 'Staff FIN (KAL)', '$2y$12$8shub35VOu6vEkj8H9LCu.n6xTNxxOEkCob4/x8VjvaMBUpm3Ba2O', 'STAFF', 'STAFF', 'FIN', 'KAL', NULL, NULL, NULL, 'active', '2026-01-25 13:59:31', '2026-01-26 05:15:39', NULL),
(27, 'StaffFIN_SLO', 'Staff FIN (SLO)', '$2y$12$T18tbd8OcVem3FWuID.Wt.4JjYRpuZZ/zExFpEs/2WArLDu8LLZ5W', 'STAFF', 'STAFF', 'FIN', 'SLO', NULL, NULL, NULL, 'active', '2026-01-25 13:59:32', '2026-01-26 05:15:39', NULL),
(28, 'StaffFIN_SMG', 'Staff FIN (SMG)', '$2y$12$wxVdGIK/4h4iOZac.wsxLeRmW7oCEDlG7NqRCrKISVJPJHlkwUO3O', 'STAFF', 'STAFF', 'FIN', 'SMG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:33', '2026-01-26 05:15:39', NULL),
(29, 'StaffFIN_TGR', 'Staff FIN (TGR)', '$2y$12$/5px3yWjcbqQIF/BKgs3tueOMmyhQvgGfggXVMEctza2Ej821GvjS', 'STAFF', 'STAFF', 'FIN', 'TGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:34', '2026-01-26 05:15:39', NULL),
(30, 'StaffHRL_BDG', 'Staff HRL (BDG)', '$2y$12$diRNIWX.Xl08wVerZMGXOOGspFOCrhBMlB1IVz5uiA9GiRfTCoDlW', 'STAFF', 'STAFF', 'HRL', 'BDG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:35', '2026-01-26 05:15:39', NULL),
(31, 'MgrHRL_BGR', 'Manager HRL (BGR)', '$2y$12$JA/1qvRpPxBmFkccJ1aYwueZCACY9K50GEIM7yN1p/K0ISEOiQF8O', 'MANAGER', 'MANAGER', 'HRL', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:36', '2026-01-26 05:15:39', NULL),
(32, 'StaffHRL_BGR', 'Staff HRL (BGR)', '$2y$12$zDme74l0de2hfKio/BNzH.HrkVQKQggsFJTX2bhdx5ZtVA0LBy7A6', 'STAFF', 'STAFF', 'HRL', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:43', '2026-01-26 05:15:39', NULL),
(33, 'StaffHRL_BKS', 'Staff HRL (BKS)', '$2y$12$XAmUQc9Q9l1pyKecklOi2uyku.AxaPU9rHr6XBBbVTWAqNfKpJUpq', 'STAFF', 'STAFF', 'HRL', 'BKS', NULL, NULL, NULL, 'active', '2026-01-25 13:59:44', '2026-01-26 05:15:39', NULL),
(34, 'StaffHRL_JGY', 'Staff HRL (JGY)', '$2y$12$LtGfjAdgl6Pxs2WzZGUaxu6S829.OqtzIPkvXF.DhLijhxkdXFyBu', 'STAFF', 'STAFF', 'HRL', 'JGY', NULL, NULL, NULL, 'active', '2026-01-25 13:59:45', '2026-01-26 05:15:39', NULL),
(35, 'StaffHRL_KAL', 'Staff HRL (KAL)', '$2y$12$qMPoC9sdZqNXTFgXwup3neEAVQla9FoKi4Y8GJnehvXW/3xJEvudW', 'STAFF', 'STAFF', 'HRL', 'KAL', NULL, NULL, NULL, 'active', '2026-01-25 13:59:46', '2026-01-26 05:15:39', NULL),
(36, 'StaffHRL_SLO', 'Staff HRL (SLO)', '$2y$12$IXImf/ZnuYwL7/nqeIZaDecApx0HeeNzOmCrzZhIPXffhRl4R8kky', 'STAFF', 'STAFF', 'HRL', 'SLO', NULL, NULL, NULL, 'active', '2026-01-25 13:59:47', '2026-01-26 05:15:39', NULL),
(37, 'StaffHRL_SMG', 'Staff HRL (SMG)', '$2y$12$LcpYFryF.rMP0mcLEbSoQ.Q1WzJ2l6hCvsdo2D8TatWucfyMKQj2K', 'STAFF', 'STAFF', 'HRL', 'SMG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:49', '2026-01-26 05:15:39', NULL),
(38, 'StaffHRL_TGR', 'Staff HRL (TGR)', '$2y$12$yfsd9y8WjU3UcllHBehdrO76kVd8siVL3pzG9PD70jBfCNvKoU2wW', 'STAFF', 'STAFF', 'HRL', 'TGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:50', '2026-01-26 05:15:39', NULL),
(39, 'StaffITC_BDG', 'Staff ITC (BDG)', '$2y$12$G01mD9CDBB8zE8fwyPburup6AkX0VMTxOspSH1MEZwZ6/8j1liTEO', 'STAFF', 'STAFF', 'ITC', 'BDG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:51', '2026-01-26 05:15:39', NULL),
(40, 'MgrITC_BGR', 'Manager ITC (BGR)', '$2y$12$Z3WksSTVjgT6PzXiUEQqvObaWP75YggalxPJUO0IT8e//ZOuP.I2y', 'MANAGER', 'MANAGER', 'ITC', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:52', '2026-01-26 05:15:39', NULL),
(41, 'StaffITC_BGR', 'Staff ITC (BGR)', '$2y$12$xzs09oqxqeiS6XjoM7uGsu3RR.adyOsoBivcR82MXpgn1pkegS6pK', 'STAFF', 'STAFF', 'ITC', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:53', '2026-01-26 05:15:39', NULL),
(42, 'StaffITC_BKS', 'Staff ITC (BKS)', '$2y$12$nl89tBqSJA.D/FtiBPBQnODHA71/ueZvDt/47D8AGvV5hSmCcDgRC', 'STAFF', 'STAFF', 'ITC', 'BKS', NULL, NULL, NULL, 'active', '2026-01-25 13:59:54', '2026-01-26 05:15:39', NULL),
(43, 'StaffITC_JGY', 'Staff ITC (JGY)', '$2y$12$N//nkpu12wNXqD49vWw3Te/fpyj5ha8ThTnf4otGAkHij5TJsVa72', 'STAFF', 'STAFF', 'ITC', 'JGY', NULL, NULL, NULL, 'active', '2026-01-25 13:59:55', '2026-01-26 05:15:39', NULL),
(44, 'StaffITC_KAL', 'Staff ITC (KAL)', '$2y$12$zDIRxLOzXeJKkwVKHdxOL.XQPDFjxBK34WHD4rcptP3ogYFpj7lGO', 'STAFF', 'STAFF', 'ITC', 'KAL', NULL, NULL, NULL, 'active', '2026-01-25 13:59:56', '2026-01-26 05:15:39', NULL),
(45, 'StaffITC_SLO', 'Staff ITC (SLO)', '$2y$12$oCgg10DU9oripphMqVDHCuw64L10femmCyVHgKTxHZDIeAVkNDGVS', 'STAFF', 'STAFF', 'ITC', 'SLO', NULL, NULL, NULL, 'active', '2026-01-25 13:59:57', '2026-01-26 05:15:39', NULL),
(46, 'StaffITC_SMG', 'Staff ITC (SMG)', '$2y$12$uQdhSTstf7jUu0gkZzDh9.DQYj/A50.m6nRBooqzL21z6gFJFr.YW', 'STAFF', 'STAFF', 'ITC', 'SMG', NULL, NULL, NULL, 'active', '2026-01-25 13:59:58', '2026-01-26 05:15:39', NULL),
(47, 'StaffITC_TGR', 'Staff ITC (TGR)', '$2y$12$mvEaQsyI3Ae2Qn5lShf0quA4i85Bcom3yp8Ny4AKylOvsebpIadDa', 'STAFF', 'STAFF', 'ITC', 'TGR', NULL, NULL, NULL, 'active', '2026-01-25 13:59:59', '2026-01-26 05:15:39', NULL),
(48, 'StaffMPR_BDG', 'Staff MPR (BDG)', '$2y$12$umPA58wemqMsWUeLDOKzg.Auear942gtGRFjOwd5amEiKWMSBFgY.', 'STAFF', 'STAFF', 'MPR', 'BDG', NULL, NULL, NULL, 'active', '2026-01-25 14:00:00', '2026-01-26 05:15:39', NULL),
(49, 'MgrMPR_BGR', 'Manager MPR (BGR)', '$2y$12$qmPoj2aOpWR.OqKTJ8tJUuMJz7pxDCLEakQ1bJTclKUDCoXQjyKBG', 'MANAGER', 'MANAGER', 'MPR', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 14:00:01', '2026-01-26 05:15:39', NULL),
(50, 'StaffMPR_BGR', 'Staff MPR (BGR)', '$2y$12$DwBuSXyRuAlfDx/JPtUjReKiOCvuTRMV1EAQcLnz27J2MIGo/mFFm', 'STAFF', 'STAFF', 'MPR', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 14:00:02', '2026-01-26 05:15:39', NULL),
(51, 'StaffMPR_BKS', 'Staff MPR (BKS)', '$2y$12$Ha74DQLYYqdnVFES8f.j3OC15UwWOCQC6L1O1eftg6WZhQRSeDvRe', 'STAFF', 'STAFF', 'MPR', 'BKS', NULL, NULL, NULL, 'active', '2026-01-25 14:00:03', '2026-01-26 05:15:39', NULL),
(52, 'StaffMPR_JGY', 'Staff MPR (JGY)', '$2y$12$cPemzLKRgaVmzOzPIJ5xO.Iz.vYgNAgWpUXEVIC5rjv0I0TFTrZ2m', 'STAFF', 'STAFF', 'MPR', 'JGY', NULL, NULL, NULL, 'active', '2026-01-25 14:00:04', '2026-01-26 05:15:39', NULL),
(53, 'StaffMPR_KAL', 'Staff MPR (KAL)', '$2y$12$6xxmA0IPQplE9Cgn9p0BWOEQ8NKKqEFWttreI1pxstKesRXhy8LN6', 'STAFF', 'STAFF', 'MPR', 'KAL', NULL, NULL, NULL, 'active', '2026-01-25 14:00:05', '2026-01-26 05:15:39', NULL),
(54, 'StaffMPR_SLO', 'Staff MPR (SLO)', '$2y$12$s/Iq01XWHdPEG0Jjr/qhnODj/LUdqryrWNShKbqOLworBufDGDKWC', 'STAFF', 'STAFF', 'MPR', 'SLO', NULL, NULL, NULL, 'active', '2026-01-25 14:00:06', '2026-01-26 05:15:39', NULL),
(55, 'StaffMPR_SMG', 'Staff MPR (SMG)', '$2y$12$79eZ8WWJoC9xLLJa50pgRuxeXkEEmT/TIyIaMG80XzEZcVgitjCby', 'STAFF', 'STAFF', 'MPR', 'SMG', NULL, NULL, NULL, 'active', '2026-01-25 14:00:07', '2026-01-26 05:15:39', NULL),
(56, 'StaffMPR_TGR', 'Staff MPR (TGR)', '$2y$12$N/F0uKO4J3dd5D8hBQRIhus7qRpkxP34/t0eWGG/fy8u40gzH6lM2', 'STAFF', 'STAFF', 'MPR', 'TGR', NULL, NULL, NULL, 'active', '2026-01-25 14:00:08', '2026-01-26 05:15:39', NULL),
(57, 'StaffPQP_BDG', 'Staff PQP (BDG)', '$2y$12$3tk7Fr11s64ZNgE4Zx862.4nlA1gKQ4qWUeoo0P4oxPVX2vGD29zi', 'STAFF', 'STAFF', 'PQP', 'BDG', NULL, NULL, NULL, 'active', '2026-01-25 14:00:09', '2026-01-26 05:15:39', NULL),
(58, 'MgrPQP_BGR', 'Manager PQP (BGR)', '$2y$12$7m6tWxwFcNSTNA6Z4hNTOeilcs.AeHaqv.6ApW7/fVip6wm82mgV6', 'MANAGER', 'MANAGER', 'PQP', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 14:00:10', '2026-01-26 05:15:39', NULL),
(59, 'StaffPQP_BGR', 'Staff PQP (BGR)', '$2y$12$m1WQDr0CU.qRn2YfvqBDs.KP9C6mpW.gJqSrMEj2vwvZe.Ir4xRdm', 'STAFF', 'STAFF', 'PQP', 'BGR', NULL, NULL, NULL, 'active', '2026-01-25 14:00:11', '2026-01-26 05:15:39', NULL),
(60, 'StaffPQP_BKS', 'Staff PQP (BKS)', '$2y$12$op64DZUgzL01tVTvq0Ivvumh0.UMbfHNjAZwPocXJn36lRROJ4B8a', 'STAFF', 'STAFF', 'PQP', 'BKS', NULL, NULL, NULL, 'active', '2026-01-25 14:00:12', '2026-01-26 05:15:39', NULL),
(61, 'StaffPQP_JGY', 'Staff PQP (JGY)', '$2y$12$OQJ29J9ov305ijxT13fsvO033WaUidROk9SkcqXsTeYcMrH2J.K.W', 'STAFF', 'STAFF', 'PQP', 'JGY', NULL, NULL, NULL, 'active', '2026-01-26 05:14:50', '2026-01-26 05:15:39', NULL),
(62, 'StaffPQP_KAL', 'Staff PQP (KAL)', '$2y$12$cqN651f.DMUYF8HBR1ls6u2RMe7CwgnrpVAvn479GKlZxBVXQ2eaS', 'STAFF', 'STAFF', 'PQP', 'KAL', NULL, NULL, NULL, 'active', '2026-01-26 05:14:51', '2026-01-26 05:15:39', NULL),
(63, 'StaffPQP_SLO', 'Staff PQP (SLO)', '$2y$12$VPo5oe2.xG76e25Dz3cGCeso2e.lulFZppewRg70327nVaQshJlgO', 'STAFF', 'STAFF', 'PQP', 'SLO', NULL, NULL, NULL, 'active', '2026-01-26 05:14:52', '2026-01-26 05:15:39', NULL),
(64, 'StaffPQP_SMG', 'Staff PQP (SMG)', '$2y$12$BG0jMz0KQImS6DEbJMsCo.R2Sr/MYVTFWYPgtqo12XcMqVy663gCG', 'STAFF', 'STAFF', 'PQP', 'SMG', NULL, NULL, NULL, 'active', '2026-01-26 05:14:53', '2026-01-26 05:15:39', NULL),
(65, 'StaffPQP_TGR', 'Staff PQP (TGR)', '$2y$12$juqiMcH2BEpyFjZDlu8gNOUayrvsAkYD7kyAbz6UxYAeN1k5EvkG2', 'STAFF', 'STAFF', 'PQP', 'TGR', NULL, NULL, NULL, 'active', '2026-01-26 05:14:54', '2026-01-26 05:15:39', NULL),
(66, 'StaffSCM_BDG', 'Staff SCM (BDG)', '$2y$12$g6dIXWv.A6xYKKwnwPBuxetL2oMActmS73nojv5jMsslwhp9LpySy', 'STAFF', 'STAFF', 'SCM', 'BDG', NULL, NULL, NULL, 'active', '2026-01-26 05:14:55', '2026-01-26 05:15:39', NULL),
(67, 'MgrSCM_BGR', 'Manager SCM (BGR)', '$2y$12$j55cgH1L..ZFeMhj4ZzzZuC6YobrjxawgPMNpL06QCS/TBD3yCQjS', 'MANAGER', 'MANAGER', 'SCM', 'BGR', NULL, NULL, NULL, 'active', '2026-01-26 05:14:56', '2026-01-26 05:15:39', NULL),
(68, 'StaffSCM_BGR', 'Staff SCM (BGR)', '$2y$12$SRTrvBqGlG2I2JsDvFjMrevangh5J7Gu7Y/bJeAQ2I4yTZYV.5knO', 'STAFF', 'STAFF', 'SCM', 'BGR', NULL, NULL, NULL, 'active', '2026-01-26 05:14:57', '2026-01-26 05:15:39', NULL),
(69, 'StaffSCM_BKS', 'Staff SCM (BKS)', '$2y$12$IvHH/ft8SXAXbjoFYbOtVu5bMe805QEnY99oHwmdAOiumXFk3uN3.', 'STAFF', 'STAFF', 'SCM', 'BKS', NULL, NULL, NULL, 'active', '2026-01-26 05:14:58', '2026-01-26 05:15:39', NULL),
(70, 'StaffSCM_JGY', 'Staff SCM (JGY)', '$2y$12$jXdwqo0vVAihAgB1ZqFCjOA/JYnFjFC21r6ZHCYmP60qIUXgKLYtW', 'STAFF', 'STAFF', 'SCM', 'JGY', NULL, NULL, NULL, 'active', '2026-01-26 05:14:59', '2026-01-26 05:15:39', NULL),
(71, 'StaffSCM_KAL', 'Staff SCM (KAL)', '$2y$12$.nWfnUgE9GbwnK362GLtQ.or.hnCo4Vd3srLD.X8A30m8DEsbbhHu', 'STAFF', 'STAFF', 'SCM', 'KAL', NULL, NULL, NULL, 'active', '2026-01-26 05:15:00', '2026-01-26 05:15:39', NULL),
(72, 'StaffSCM_SLO', 'Staff SCM (SLO)', '$2y$12$MEwSHU8jXPYfu9ZsCpiQWe7QfufxQ9FclY60oI9dshYIcQTnK1otu', 'STAFF', 'STAFF', 'SCM', 'SLO', NULL, NULL, NULL, 'active', '2026-01-26 05:15:01', '2026-01-26 05:15:39', NULL),
(73, 'StaffSCM_SMG', 'Staff SCM (SMG)', '$2y$12$C1J2zZnvbsH4X03dm3zzcu8nhInUAKoS4UNEeJY8xhYl3x4jRsXYm', 'STAFF', 'STAFF', 'SCM', 'SMG', NULL, NULL, NULL, 'active', '2026-01-26 05:15:02', '2026-01-26 05:15:39', NULL),
(74, 'StaffSCM_TGR', 'Staff SCM (TGR)', '$2y$12$HkJbHGWgjpr.lB1ntsAHa.Wk8oDrDdVpdJuOfkNZMgKF1unkxEVR6', 'STAFF', 'STAFF', 'SCM', 'TGR', NULL, NULL, NULL, 'active', '2026-01-26 05:15:03', '2026-01-26 05:15:39', NULL),
(75, 'StaffSYS_HQ', 'Staff SYS (HQ)', '$2y$12$2lVPAjtcGc1hGOS/IlLFKOy3chw9HjbyPNf2IOye1k2CPLNsALwfm', 'SYS', 'SYS', 'SYS', 'HQ', NULL, NULL, NULL, 'active', '2026-01-26 05:15:04', '2026-01-26 05:15:39', NULL),
(76, 'StaffSYS_BDG', 'Staff SYS (BDG)', '$2y$12$s95DIEP35hf62Ri.9z1Eau8EZuUwasGJ3mexksrRsYOTG8SjPzY.y', 'STAFF', 'STAFF', 'SYS', 'BDG', NULL, NULL, NULL, 'active', '2026-01-26 05:15:05', '2026-01-26 05:15:39', NULL),
(77, 'MgrSYS_BGR', 'Manager SYS (BGR)', '$2y$12$sFlUpheWAyeVVsQ0Hg7l0Ow32ct8kL/CuQDR/l9MZQqooWkXEfOfi', 'MANAGER', 'MANAGER', 'SYS', 'BGR', NULL, NULL, NULL, 'active', '2026-01-26 05:15:06', '2026-01-26 05:15:39', NULL),
(78, 'StaffSYS_BGR', 'Staff SYS (BGR)', '$2y$12$HpdxfUkjqtHxYrhzWJoUCuoMCe1nLfpAJdu.8CSK1XgSuhFLn/FI6', 'STAFF', 'STAFF', 'SYS', 'BGR', NULL, NULL, NULL, 'active', '2026-01-26 05:15:07', '2026-01-26 05:15:39', NULL),
(79, 'StaffSYS_BKS', 'Staff SYS (BKS)', '$2y$12$BJp/8EYfg7/SEqd9aKhFUOke5vG8pWdFWJedpzny5hFRnAoz.YKiS', 'STAFF', 'STAFF', 'SYS', 'BKS', NULL, NULL, NULL, 'active', '2026-01-26 05:15:08', '2026-01-26 05:15:39', NULL),
(80, 'StaffSYS_JGY', 'Staff SYS (JGY)', '$2y$12$Qob4nSbZ.rdJqpiRNBhWieHohrfranvhhmGcmoecd9.tYVFWV/5Sq', 'STAFF', 'STAFF', 'SYS', 'JGY', NULL, NULL, NULL, 'active', '2026-01-26 05:15:09', '2026-01-26 05:15:39', NULL),
(81, 'StaffSYS_KAL', 'Staff SYS (KAL)', '$2y$12$gOEZApmvczS5nCKpMsILhOokpiU9uA8dt6Ef4uW7cfxT.zDiURetO', 'STAFF', 'STAFF', 'SYS', 'KAL', NULL, NULL, NULL, 'active', '2026-01-26 05:15:10', '2026-01-26 05:15:39', NULL),
(82, 'StaffWQS_BDG', 'Staff WQS (BDG)', '$2y$12$dcatEK.SwhhJaU7w78hR6.DWDZ/pbtoKBHeuaMAGSIC5tjAB.9xRS', 'STAFF', 'STAFF', 'WQS', 'BDG', NULL, NULL, NULL, 'active', '2026-01-26 05:15:11', '2026-01-26 05:15:39', NULL),
(83, 'MgrWQS_BGR', 'Manager WQS (BGR)', '$2y$12$/Agt1JuGJxku1Y1LwSXCUuTNRxdZwrR5wgnEBeeUWAtHTq6UfUVdy', 'MANAGER', 'MANAGER', 'WQS', 'BGR', NULL, NULL, NULL, 'active', '2026-01-26 05:15:12', '2026-01-26 05:15:39', NULL),
(84, 'StaffWQS_BGR', 'Staff WQS (BGR)', '$2y$12$Nr1ZLsDKfZ/QEN3..uRYLOLWRgy4GfJF9M9fIqH1ALM9qMsdpxXr2', 'STAFF', 'STAFF', 'WQS', 'BGR', NULL, NULL, NULL, 'active', '2026-01-26 05:15:13', '2026-01-26 05:15:39', NULL),
(85, 'StaffWQS_BKS', 'Staff WQS (BKS)', '$2y$12$PwJsRBjjlBf5YYSmD2FxKOxMpAIjNiCcPH8NYJVKHgcMpR1Idxzii', 'STAFF', 'STAFF', 'WQS', 'BKS', NULL, NULL, NULL, 'active', '2026-01-26 05:15:14', '2026-01-26 05:15:39', NULL),
(86, 'StaffWQS_JGY', 'Staff WQS (JGY)', '$2y$12$Nc.Lein55rJ1FkmFh4/eIeIg7k6JEPkHYVIKsica.AerR3ptgUjdq', 'STAFF', 'STAFF', 'WQS', 'JGY', NULL, NULL, NULL, 'active', '2026-01-26 05:15:15', '2026-01-26 05:15:39', NULL),
(87, 'StaffWQS_KAL', 'Staff WQS (KAL)', '$2y$12$3ikcMxxeWVKYzeq7/vdLPOTSCf91U6Fx1l.X8UfFSbunGiRUFUfxW', 'STAFF', 'STAFF', 'WQS', 'KAL', NULL, NULL, NULL, 'active', '2026-01-26 05:15:16', '2026-01-26 05:15:39', NULL),
(88, 'StaffWQS_SLO', 'Staff WQS (SLO)', '$2y$12$R.TXlrhKSJjR.VcKA4Rv2.MjgE96D8bauDTduVSkm9My/sRT2oPce', 'STAFF', 'STAFF', 'WQS', 'SLO', NULL, NULL, NULL, 'active', '2026-01-26 05:15:17', '2026-01-26 05:15:39', NULL),
(89, 'StaffWQS_SMG', 'Staff WQS (SMG)', '$2y$12$//p0DTABoO4k75mG3BWB8eEQ6/neQesN10HFpbHvVMyMTFFVtDp9u', 'STAFF', 'STAFF', 'WQS', 'SMG', NULL, NULL, NULL, 'active', '2026-01-26 05:15:18', '2026-01-26 05:15:39', NULL),
(90, 'StaffWQS_TGR', 'Staff WQS (TGR)', '$2y$12$ggCjYPC/4hkLIpQcvgzZWO.YSRPkj1NL.FszSlPWQUz6krH9gCyR2', 'STAFF', 'STAFF', 'WQS', 'TGR', NULL, NULL, NULL, 'active', '2026-01-26 05:15:40', '2026-01-26 05:15:40', NULL);

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
-- Struktur dari tabel `mpr_budget_requests`
--

CREATE TABLE `mpr_budget_requests` (
  `id` int NOT NULL,
  `request_code` varchar(40) NOT NULL,
  `plan_id` int NOT NULL,
  `request_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `purpose` text,
  `vendor_name` varchar(255) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `dept_code` varchar(20) NOT NULL DEFAULT 'MPR',
  `office_code` varchar(20) NOT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `approval_note` text,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `mpr_ops_payments`
--

CREATE TABLE `mpr_ops_payments` (
  `id` int NOT NULL,
  `work_date` date NOT NULL,
  `employee_code` varchar(50) NOT NULL,
  `office_code` varchar(20) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'PAID',
  `paid_amount` decimal(18,2) DEFAULT NULL,
  `paid_ref` varchar(100) DEFAULT NULL,
  `note` text,
  `paid_at` datetime DEFAULT NULL,
  `paid_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `mpr_plans`
--

CREATE TABLE `mpr_plans` (
  `id` int NOT NULL,
  `plan_code` varchar(40) NOT NULL,
  `title` varchar(255) NOT NULL,
  `objective` text,
  `target` text,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `budget` decimal(18,2) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `dept_code` varchar(20) NOT NULL DEFAULT 'MPR',
  `office_code` varchar(20) NOT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `approval_note` text,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `mpr_progress`
--

CREATE TABLE `mpr_progress` (
  `id` int NOT NULL,
  `plan_id` int NOT NULL,
  `progress_date` date NOT NULL,
  `progress_pct` int DEFAULT NULL,
  `milestone` varchar(255) DEFAULT NULL,
  `issues` text,
  `next_step` text,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `mpr_visits`
--

CREATE TABLE `mpr_visits` (
  `id` int NOT NULL,
  `plan_id` int NOT NULL,
  `visit_date` date NOT NULL,
  `partner_name` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `result` text,
  `notes` text,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `gps_accuracy_m` int DEFAULT NULL,
  `gps_captured_at` datetime DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `customers_code` varchar(50) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `contact_id` int DEFAULT NULL,
  `contact_name` varchar(150) DEFAULT NULL,
  `contact_role_title` varchar(100) DEFAULT NULL,
  `contact_department` varchar(100) DEFAULT NULL,
  `employee_code` varchar(50) DEFAULT NULL,
  `employee_name` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
('ACT', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('ACT', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('ACT', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('ACT', 'MANAGER', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('ACT', 'MANAGER', 'SALES.AUDIT_VIEW', 1, '2026-01-24 04:12:23'),
('ACT', 'MANAGER', 'SALES.EXPORT', 1, '2026-01-24 04:12:23'),
('ACT', 'MANAGER', 'SALES.TASK_ACT', 1, '2026-01-24 04:12:23'),
('ACT', 'MANAGER', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('ACT', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('ACT', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('ACT', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('ACT', 'STAFF', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('ACT', 'STAFF', 'SALES.TASK_ACT', 1, '2026-01-24 04:12:23'),
('ACT', 'STAFF', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'MASTER.CUSTOMER_CRUD', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'MASTER.CUSTOMER_EXPORT', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'MASTER.PIC_CUSTOMER_CRUD', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'MASTER.PRICELIST_SELL_CRUD', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'SALES.AUDIT_VIEW', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'SALES.CREATE', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'SALES.DELETE', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'SALES.EDIT', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'SALES.EXPORT', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'SALES.PRINT', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('CRM', 'MANAGER', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'MASTER.CUSTOMER_CRUD', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'MASTER.CUSTOMER_EXPORT', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'MASTER.PIC_CUSTOMER_CRUD', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'MASTER.PRICELIST_SELL_CRUD', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'SALES.CREATE', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'SALES.EDIT', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'SALES.PRINT', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('CRM', 'STAFF', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'FIXED_ASSET.ASSET_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'FIXED_ASSET.AUDIT_VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'FIXED_ASSET.DEPRECIATION_RUN', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'FIXED_ASSET.OPERATIONS', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'FIXED_ASSET.TAX_ANNUAL', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'FIXED_ASSET.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'MASTER.COMPANY_BANK_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'MASTER.PAYMENT_TERMS_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'MASTER.TAX_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'PAYROLL.AUDIT', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'PAYROLL.EXPORT_BANK', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'PAYROLL.RUN_PAID', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'PAYROLL.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'PURCHASES.ADMIN_GL_AUTO', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'PURCHASES.AP_INVOICE_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'PURCHASES.AP_PAYMENT_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'PURCHASES.EXPORT', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'PURCHASES.REPORTS_VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'SALES.TASK_FIN', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'MANAGER', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'FIXED_ASSET.ASSET_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'FIXED_ASSET.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'PURCHASES.AP_INVOICE_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'PURCHASES.AP_PAYMENT_CRUD', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'PURCHASES.REPORTS_VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'SALES.TASK_FIN', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('FIN', 'STAFF', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'ABSENSI.ADMIN_PINS', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'ABSENSI.ADMIN_USERS', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'ABSENSI.APPROVE', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'ABSENSI.RECAP', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'MASTER.DEPARTMENT_CRUD', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'MASTER.EMPLOYEE_CRUD', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'PAYROLL.AUDIT', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'PAYROLL.LOANS', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'PAYROLL.MATRIX_MANAGE', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'PAYROLL.PAYSLIP_VIEW', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'PAYROLL.RUN_CREATE', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'PAYROLL.RUN_EDIT', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'PAYROLL.RUN_POST', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'PAYROLL.SETTINGS', 1, '2026-01-24 04:12:23'),
('HRL', 'MANAGER', 'PAYROLL.VIEW', 1, '2026-01-24 04:12:23'),
('HRL', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('HRL', 'STAFF', 'ABSENSI.RECAP', 1, '2026-01-24 04:12:23'),
('HRL', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('HRL', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('HRL', 'STAFF', 'MASTER.EMPLOYEE_CRUD', 1, '2026-01-24 04:12:23'),
('HRL', 'STAFF', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('HRL', 'STAFF', 'PAYROLL.LOANS', 1, '2026-01-24 04:12:23'),
('HRL', 'STAFF', 'PAYROLL.PAYSLIP_VIEW', 1, '2026-01-24 04:12:23'),
('HRL', 'STAFF', 'PAYROLL.VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'ABSENSI.OFFICE_SETTINGS', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'MASTER.EMAIL_COMPANY_CRUD', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'MASTER.OFFICE_CRUD', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'SYSTEM.CONFIG_MANAGE', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'SYSTEM.SECURITY_VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'SYSTEM.USER_MANAGE', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'TOOLS.ENTERPRISE_AUDIT_EXPORT', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'TOOLS.ITC_RESET_PASSWORD', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'TOOLS.PURCHASES_M2_APPLY', 1, '2026-01-24 04:12:23'),
('ITC', 'MANAGER', 'TOOLS.VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'ABSENSI.OFFICE_SETTINGS', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'MASTER.EMAIL_COMPANY_CRUD', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'MASTER.OFFICE_CRUD', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'SYSTEM.SECURITY_VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'TOOLS.ITC_RESET_PASSWORD', 1, '2026-01-24 04:12:23'),
('ITC', 'STAFF', 'TOOLS.VIEW', 1, '2026-01-24 04:12:23'),
('MPR', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('MPR', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('MPR', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('MPR', 'MANAGER', 'MASTER.CUSTOMER_EXPORT', 1, '2026-01-24 04:12:23'),
('MPR', 'MANAGER', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('MPR', 'MANAGER', 'SALES.AUDIT_VIEW', 1, '2026-01-24 04:12:23'),
('MPR', 'MANAGER', 'SALES.EXPORT', 1, '2026-01-24 04:12:23'),
('MPR', 'MANAGER', 'SALES.PRINT', 1, '2026-01-24 04:12:23'),
('MPR', 'MANAGER', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('MPR', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('MPR', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('MPR', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('MPR', 'STAFF', 'MASTER.CUSTOMER_EXPORT', 1, '2026-01-24 04:12:23'),
('MPR', 'STAFF', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('MPR', 'STAFF', 'SALES.PRINT', 1, '2026-01-24 04:12:23'),
('MPR', 'STAFF', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'MASTER.MANUFACTURE_CRUD', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'MASTER.PRODUCT_CRUD', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'MASTER.PRODUCT_MEDIA_UPLOAD', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'MASTER.PRODUCT_PACKAGE_CRUD', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('PQP', 'MANAGER', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('PQP', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('PQP', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('PQP', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('PQP', 'STAFF', 'MASTER.MANUFACTURE_CRUD', 1, '2026-01-24 04:12:23'),
('PQP', 'STAFF', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-01-24 04:12:23'),
('PQP', 'STAFF', 'MASTER.PRODUCT_CRUD', 1, '2026-01-24 04:12:23'),
('PQP', 'STAFF', 'MASTER.PRODUCT_MEDIA_UPLOAD', 1, '2026-01-24 04:12:23'),
('PQP', 'STAFF', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('PQP', 'STAFF', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'MASTER.VENDOR_CRUD', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.CEISA_PIB', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.EXPORT', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.FORWARDING_CRUD', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.GR_PROCESS', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.IMPORT_CONTROL', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.PO_APPROVE', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.PO_CRUD', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.PO_PRINT', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.REPORTS_VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'PURCHASES.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'SALES.TASK_SCM', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'MANAGER', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'MASTER.VENDOR_CRUD', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'PURCHASES.CEISA_PIB', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'PURCHASES.FORWARDING_CRUD', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'PURCHASES.GR_PROCESS', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'PURCHASES.IMPORT_CONTROL', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'PURCHASES.PO_CRUD', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'PURCHASES.PO_PRINT', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'PURCHASES.REPORTS_VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'PURCHASES.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'SALES.TASK_SCM', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('SCM', 'STAFF', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('SYS', 'ADMIN', 'ABSENSI.ADMIN_PINS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'ABSENSI.ADMIN_USERS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'ABSENSI.APPROVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'ABSENSI.CHECKIN', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'ABSENSI.OFFICE_SETTINGS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'ABSENSI.RECAP', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'ABSENSI.REQUEST', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'ABSENSI.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'DOC.DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'DOC.DOWNLOAD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'DOC.META.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'DOC.UPLOAD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FA.ASSET_BULK', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FA.ASSET_DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FA.ASSET_IMPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FA.ASSET_SAVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FA.ASSET_SEED', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FA.ASSET_TEMPLATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FA.AUDIT_CLOSE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FA.AUDIT_CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FA.AUDIT_SAVE_LINES', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.ASSET_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.AUDIT_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.DASHBOARD_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.DEP_RUN', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.DEPRECIATION_RUN', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.OPERATIONS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.OPS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.REPORT_TAX_ANNUAL', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.TAX_ANNUAL', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'FIXED_ASSET.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'HRL_PROCESS.FILE.DOWNLOAD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'HRL_PROCESS.PIN.SET', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'HRL_PROCESS.REQUEST.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.AUDIT_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.AUDIT.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.EMPLOYEE_EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.EMPLOYEE_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.EMPLOYEE.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.LOCK', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.OFFICE_EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.OFFICE_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.OFFICE.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.PURCH.DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.PURCH.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.PURCHASES_EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.PURCHASES_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.SNAPSHOT.CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.SNAPSHOT.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.STOCK_EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.STOCK_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.STOCK.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'KPI.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MANUFACTURES_DOCS.ACCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_CUSTOMERS.ACCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_CUSTOMERS.EXPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_CUSTOMERS.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_DATA.ACCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_DEPARTEMENTS.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_EMAILCOMPANY.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_EMPLOYEES.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_PRODUCTS.MEDIA_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_PRODUCTS.PACKAGE_EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_PRODUCTS.PACKAGE_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_PRODUCTS.PRINT_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_SYSTEM.CONFIG_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_SYSTEM.LOGIN_MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_TAX.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_USER.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER_VENDORS.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.COMPANY_BANK_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.CUSTOMER_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.CUSTOMER_EXPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.DEPARTMENT_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.EMAIL_COMPANY_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.EMPLOYEE_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.MANUFACTURE_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.MANUFACTURES.BULK', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.MANUFACTURES.DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.MANUFACTURES.IMPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.MANUFACTURES.MUTATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.MANUFACTURES.RESTORE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.MANUFACTURES.SEED', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.MANUFACTURES.TOGGLE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.MANUFACTURES.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.OFFICE_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.OFFICE.AUTOFILL', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.OFFICE.BULK', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.OFFICE.DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.OFFICE.IMPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.OFFICE.SAVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.OFFICE.SYNC', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.OFFICE.TOGGLE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.OFFICE.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PAYMENT_TERMS_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PAYMENT_TERMS.DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PAYMENT_TERMS.SAVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PAYMENT_TERMS.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PIC_CUSTOMER_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRICELIST_SELL_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRICELIST.BULK', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRICELIST.IMPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRICELIST.SAVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRICELIST.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRICELIST.VIEW_SELL', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRODUCT_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRODUCT_MEDIA_UPLOAD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRODUCT_PACKAGE_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRODUCTS.BULK', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRODUCTS.IMPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRODUCTS.SAVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.PRODUCTS.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.TAX_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.VENDOR_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MASTER.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MPR.ACCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MPR.FIN.DAILY.EXPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MPR.FIN.DAILY.PAY', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MPR.FIN.DAILY.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MPR.PLAN.EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'MPR.PLAN.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'ORG.BRANCH.MANAGER', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'ORG.DIRECTOR', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.AUDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.AUDIT.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.CREATE_RUN', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.EXPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.EXPORT_BANK', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.LOAN_DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.LOAN_EXPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.LOAN_SAVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.LOAN_STATUS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.LOANS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.MATRIX_DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.MATRIX_EXPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.MATRIX_IMPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.MATRIX_MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.MATRIX_SAVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.MATRIX_TEMPLATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.MATRIX_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.PAYSLIP_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.RUN_CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.RUN_EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.RUN_PAID', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.RUN_POST', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.RUN_RECALC_ABSENSI', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.RUN_SYNC_LOANS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.RUN_SYNC_MATRIX', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.RUN_UPDATE_ITEM', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.RUN_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.SETTINGS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.SETTINGS_SAVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.SETTINGS_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PAYROLL.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.ADMIN_GL_AUTO', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.ADMIN_STOCK_UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.AP_INVOICE_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.AP_PAYMENT_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.AP.ACCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.AP.CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.AP.PAYMENT.CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.AP.UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.AP.VOID', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.API_PR', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.APPROVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.CEISA_PIB', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.EXPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.FORWARDING_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.GR_PROCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.IMPORT_CONTROL', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.PO_APPROVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.PO_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.PO_PRINT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.PO.ACCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.PO.CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.PO.DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.PO.RESTORE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.PO.STATUS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.PO.UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.REPORTS_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.REPORTS.ACCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'PURCHASES.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'REG_ALKES.CASE.DOC.DOWNLOAD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'REG_ALKES.CASE.DOC.UPLOAD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.ACT.ACCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.ACT.UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.AUDIT_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.BACKFILL.ACCESS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.CONTROL_TOWER_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.CRM_DO_EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.DASHBOARD_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.DELETE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.DO_PRINT_CF', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.DO_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.EDIT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.EXPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.FIN_TASKS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.KPI_AUDIT_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.KPI_SLA_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.PRINT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.SCM_TASKS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.TASK_ACT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.TASK_FIN', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.TASK_SCM', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.TASK_WQS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SALES.WQS.UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.ADJUST', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.ADJUSTMENT.CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.ADJUSTMENT.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.AUDIT_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.WQS.ALLOCATION.UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.WQS.INCOMING.READ', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.WQS.INCOMING.UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.WQS.PICKING.UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.WQS.PR.CREATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.WQS.PR.READ', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.WQS.PR.UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'STOCK.WQS.STOCK.UPDATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYS.AUDIT.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYS.CONFIG.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYS.LOGIN', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYS.RBAC.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYS.SUPERADMIN', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYS.USER.MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYSTEM.CONFIG_MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYSTEM.RBAC_MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYSTEM.SECURITY_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'SYSTEM.USER_MANAGE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'TOOLS.ENTERPRISE_AUDIT_EXPORT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'TOOLS.ITC_RESET_PASSWORD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'TOOLS.PURCHASES_M2_APPLY', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'TOOLS.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WF.OUTBOX.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WF.TASK.APPROVE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WF.TASK.DELEGATE', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WF.TASK.REJECT', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WF.TASK.VIEW', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WQS.ALLOCATION', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WQS.API_INCOMING_PO', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WQS.DO_TASKS', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WQS.INCOMING_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WQS.PICKING_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WQS.PR_CRUD', 1, '2026-01-25 16:38:06'),
('SYS', 'ADMIN', 'WQS.PR_PRINT', 1, '2026-01-25 16:38:06'),
('SYS', 'SUPERADMIN', 'ABSENSI.ADMIN_PINS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'ABSENSI.ADMIN_USERS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'ABSENSI.APPROVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'ABSENSI.CHECKIN', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'ABSENSI.OFFICE_SETTINGS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'ABSENSI.RECAP', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'ABSENSI.REQUEST', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'ABSENSI.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'DOC.DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'DOC.DOWNLOAD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'DOC.META.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'DOC.UPLOAD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FA.ASSET_BULK', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FA.ASSET_DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FA.ASSET_IMPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FA.ASSET_SAVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FA.ASSET_SEED', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FA.ASSET_TEMPLATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FA.AUDIT_CLOSE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FA.AUDIT_CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FA.AUDIT_SAVE_LINES', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.ASSET_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.AUDIT_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.DASHBOARD_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.DEP_RUN', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.DEPRECIATION_RUN', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.OPERATIONS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.OPS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.REPORT_TAX_ANNUAL', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.TAX_ANNUAL', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'FIXED_ASSET.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'HRL_PROCESS.FILE.DOWNLOAD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'HRL_PROCESS.PIN.SET', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'HRL_PROCESS.REQUEST.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.AUDIT_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.AUDIT.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.EMPLOYEE_EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.EMPLOYEE_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.EMPLOYEE.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.LOCK', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.OFFICE_EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.OFFICE_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.OFFICE.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.PURCH.DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.PURCH.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.PURCHASES_EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.PURCHASES_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.SNAPSHOT.CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.SNAPSHOT.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.STOCK_EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.STOCK_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.STOCK.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'KPI.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MANUFACTURES_DOCS.ACCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_CUSTOMERS.ACCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_CUSTOMERS.EXPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_CUSTOMERS.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_DATA.ACCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_DEPARTEMENTS.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_EMAILCOMPANY.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_EMPLOYEES.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_PRODUCTS.MEDIA_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_PRODUCTS.PACKAGE_EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_PRODUCTS.PACKAGE_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_PRODUCTS.PRINT_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_SYSTEM.CONFIG_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_SYSTEM.LOGIN_MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_TAX.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_USER.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER_VENDORS.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.COMPANY_BANK_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.CUSTOMER_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.CUSTOMER_EXPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.DEPARTMENT_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.EMAIL_COMPANY_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.EMPLOYEE_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.MANUFACTURE_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.MANUFACTURES.BULK', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.MANUFACTURES.DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.MANUFACTURES.IMPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.MANUFACTURES.MUTATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.MANUFACTURES.RESTORE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.MANUFACTURES.SEED', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.MANUFACTURES.TOGGLE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.MANUFACTURES.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.OFFICE_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.OFFICE.AUTOFILL', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.OFFICE.BULK', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.OFFICE.DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.OFFICE.IMPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.OFFICE.SAVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.OFFICE.SYNC', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.OFFICE.TOGGLE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.OFFICE.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PAYMENT_TERMS_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PAYMENT_TERMS.DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PAYMENT_TERMS.SAVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PAYMENT_TERMS.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PIC_CUSTOMER_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRICELIST_SELL_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRICELIST.BULK', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRICELIST.IMPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRICELIST.SAVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRICELIST.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRICELIST.VIEW_SELL', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRODUCT_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRODUCT_MEDIA_UPLOAD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRODUCT_PACKAGE_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRODUCTS.BULK', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRODUCTS.IMPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRODUCTS.SAVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.PRODUCTS.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.TAX_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.VENDOR_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MASTER.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MPR.ACCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MPR.FIN.DAILY.EXPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MPR.FIN.DAILY.PAY', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MPR.FIN.DAILY.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MPR.PLAN.EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'MPR.PLAN.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'ORG.BRANCH.MANAGER', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'ORG.DIRECTOR', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.AUDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.AUDIT.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.CREATE_RUN', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.EXPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.EXPORT_BANK', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.LOAN_DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.LOAN_EXPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.LOAN_SAVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.LOAN_STATUS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.LOANS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.MATRIX_DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.MATRIX_EXPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.MATRIX_IMPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.MATRIX_MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.MATRIX_SAVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.MATRIX_TEMPLATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.MATRIX_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.PAYSLIP_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.RUN_CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.RUN_EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.RUN_PAID', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.RUN_POST', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.RUN_RECALC_ABSENSI', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.RUN_SYNC_LOANS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.RUN_SYNC_MATRIX', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.RUN_UPDATE_ITEM', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.RUN_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.SETTINGS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.SETTINGS_SAVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.SETTINGS_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PAYROLL.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.ADMIN_GL_AUTO', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.ADMIN_STOCK_UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.AP_INVOICE_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.AP_PAYMENT_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.AP.ACCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.AP.CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.AP.PAYMENT.CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.AP.UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.AP.VOID', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.API_PR', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.APPROVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.CEISA_PIB', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.EXPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.FORWARDING_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.GR_PROCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.IMPORT_CONTROL', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.PO_APPROVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.PO_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.PO_PRINT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.PO.ACCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.PO.CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.PO.DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.PO.RESTORE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.PO.STATUS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.PO.UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.REPORTS_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.REPORTS.ACCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'PURCHASES.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'REG_ALKES.CASE.DOC.DOWNLOAD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'REG_ALKES.CASE.DOC.UPLOAD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.ACT.ACCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.ACT.UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.AUDIT_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.BACKFILL.ACCESS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.CONTROL_TOWER_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.CRM_DO_EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.DASHBOARD_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.DELETE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.DO_PRINT_CF', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.DO_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.EDIT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.EXPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.FIN_TASKS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.KPI_AUDIT_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.KPI_SLA_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.PRINT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.SCM_TASKS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.TASK_ACT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.TASK_FIN', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.TASK_SCM', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.TASK_WQS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SALES.WQS.UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.ADJUST', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.ADJUSTMENT.CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.ADJUSTMENT.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.AUDIT_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.WQS.ALLOCATION.UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.WQS.INCOMING.READ', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.WQS.INCOMING.UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.WQS.PICKING.UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.WQS.PR.CREATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.WQS.PR.READ', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.WQS.PR.UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'STOCK.WQS.STOCK.UPDATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYS.AUDIT.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYS.CONFIG.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYS.LOGIN', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYS.RBAC.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYS.SUPERADMIN', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYS.USER.MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYSTEM.CONFIG_MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYSTEM.RBAC_MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYSTEM.SECURITY_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'SYSTEM.USER_MANAGE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'TOOLS.ENTERPRISE_AUDIT_EXPORT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'TOOLS.ITC_RESET_PASSWORD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'TOOLS.PURCHASES_M2_APPLY', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'TOOLS.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WF.OUTBOX.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WF.TASK.APPROVE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WF.TASK.DELEGATE', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WF.TASK.REJECT', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WF.TASK.VIEW', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WQS.ALLOCATION', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WQS.API_INCOMING_PO', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WQS.DO_TASKS', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WQS.INCOMING_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WQS.PICKING_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WQS.PR_CRUD', 1, '2026-01-26 11:59:34'),
('SYS', 'SUPERADMIN', 'WQS.PR_PRINT', 1, '2026-01-26 11:59:34'),
('WQS', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'PURCHASES.GR_PROCESS', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'SALES.TASK_WQS', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'STOCK.ADJUST', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'STOCK.AUDIT_VIEW', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'WQS.ALLOCATION', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'WQS.DO_TASKS', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'WQS.INCOMING_CRUD', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'WQS.PICKING_CRUD', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'WQS.PR_CRUD', 1, '2026-01-24 04:12:23'),
('WQS', 'MANAGER', 'WQS.PR_PRINT', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'ABSENSI.VIEW', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'MASTER.VIEW', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'PURCHASES.GR_PROCESS', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'SALES.TASK_WQS', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'SALES.VIEW', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'STOCK.VIEW', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'WQS.DO_TASKS', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'WQS.INCOMING_CRUD', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'WQS.PICKING_CRUD', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'WQS.PR_CRUD', 1, '2026-01-24 04:12:23'),
('WQS', 'STAFF', 'WQS.PR_PRINT', 1, '2026-01-24 04:12:23');

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
('ABSENSI.ADMIN_PINS', 'Absensi - Admin Pins', 'ABSENSI', 'Kelola PIN absensi.', 1),
('ABSENSI.ADMIN_USERS', 'Absensi - Admin Users', 'ABSENSI', 'Kelola user absensi (mapping pin).', 1),
('ABSENSI.APPROVE', 'Absensi - Approval', 'ABSENSI', 'Approve request absensi.', 1),
('ABSENSI.CHECKIN', 'Absensi - Check-in/Check-out', 'ABSENSI', 'Check-in/out by photo.', 1),
('ABSENSI.OFFICE_SETTINGS', 'Absensi - Office Settings', 'ABSENSI', 'GeoFence/Office settings.', 1),
('ABSENSI.RECAP', 'Absensi - Rekap HR', 'ABSENSI', 'Rekap & laporan absensi.', 1),
('ABSENSI.REQUEST', 'Absensi - Request Izin/Sakit/Dinas', 'ABSENSI', 'Pengajuan izin/sakit/dinas.', 1),
('ABSENSI.VIEW', 'Absensi - View Dashboard', 'ABSENSI', 'Melihat dashboard absensi.', 1),
('DOC.DELETE', 'Doc Delete', 'DOC', NULL, 1),
('DOC.DOWNLOAD', 'Doc Download', 'DOC', NULL, 1),
('DOC.META.VIEW', 'Doc Meta View', 'DOC', NULL, 1),
('DOC.UPLOAD', 'Doc Upload', 'DOC', NULL, 1),
('FA.ASSET_BULK', 'Fa Asset Bulk', 'FA', NULL, 1),
('FA.ASSET_DELETE', 'Fa Asset Delete', 'FA', NULL, 1),
('FA.ASSET_IMPORT', 'Fa Asset Import', 'FA', NULL, 1),
('FA.ASSET_SAVE', 'Fa Asset Save', 'FA', NULL, 1),
('FA.ASSET_SEED', 'Fa Asset Seed', 'FA', NULL, 1),
('FA.ASSET_TEMPLATE', 'Fa Asset Template', 'FA', NULL, 1),
('FA.AUDIT_CLOSE', 'Fa Audit Close', 'FA', NULL, 1),
('FA.AUDIT_CREATE', 'Fa Audit Create', 'FA', NULL, 1),
('FA.AUDIT_SAVE_LINES', 'Fa Audit Save Lines', 'FA', NULL, 1),
('FIXED_ASSET.ASSET_CRUD', 'Fixed Asset - Assets CRUD', 'FIXED_ASSET', 'Kelola master aset (acquisition, data aset).', 1),
('FIXED_ASSET.AUDIT_VIEW', 'Fixed Asset - Audit View', 'FIXED_ASSET', 'Lihat audit log aset.', 1),
('FIXED_ASSET.DASHBOARD_VIEW', 'Fixed Asset Dashboard View', 'FIXED_ASSET', NULL, 1),
('FIXED_ASSET.DEP_RUN', 'Fixed Asset Dep Run', 'FIXED_ASSET', NULL, 1),
('FIXED_ASSET.DEPRECIATION_RUN', 'Fixed Asset - Depreciation Run', 'FIXED_ASSET', 'Hitung depresiasi periodik.', 1),
('FIXED_ASSET.OPERATIONS', 'Fixed Asset - Operations', 'FIXED_ASSET', 'Operasional aset (move, repair, dispose).', 1),
('FIXED_ASSET.OPS', 'Fixed Asset Ops', 'FIXED_ASSET', NULL, 1),
('FIXED_ASSET.REPORT_TAX_ANNUAL', 'Fixed Asset Report Tax Annual', 'FIXED_ASSET', NULL, 1),
('FIXED_ASSET.TAX_ANNUAL', 'Fixed Asset - Tax Annual', 'FIXED_ASSET', 'Perhitungan pajak tahunan aset.', 1),
('FIXED_ASSET.VIEW', 'Fixed Asset - View', 'FIXED_ASSET', 'Melihat dashboard fixed asset.', 1),
('HRL_PROCESS.FILE.DOWNLOAD', 'Hrl Process File Download', 'HRL_PROCESS', NULL, 1),
('HRL_PROCESS.PIN.SET', 'Hrl Process Pin Set', 'HRL_PROCESS', NULL, 1),
('HRL_PROCESS.REQUEST.VIEW', 'Hrl Process Request View', 'HRL_PROCESS', NULL, 1),
('KPI.AUDIT_VIEW', 'KPI - Audit View', 'KPI', 'Melihat audit log KPI.', 1),
('KPI.AUDIT.VIEW', 'Kpi Audit View', 'KPI', NULL, 1),
('KPI.DELETE', 'KPI - Delete', 'KPI', 'Menghapus entri KPI (Admin+).', 1),
('KPI.EDIT', 'KPI - Edit', 'KPI', 'Edit/Import/Sync data KPI.', 1),
('KPI.EMPLOYEE_EDIT', 'KPI Employee - Edit', 'KPI', 'Edit KPI Employee.', 1),
('KPI.EMPLOYEE_VIEW', 'KPI Employee - View', 'KPI', 'Melihat KPI Employee.', 1),
('KPI.EMPLOYEE.VIEW', 'Kpi Employee View', 'KPI', NULL, 1),
('KPI.LOCK', 'KPI - Lock Rows', 'KPI', 'Mengunci data KPI (Head Dept+).', 1),
('KPI.OFFICE_EDIT', 'KPI Office - Edit', 'KPI', 'Edit KPI Office.', 1),
('KPI.OFFICE_VIEW', 'KPI Office - View', 'KPI', 'Melihat KPI Office.', 1),
('KPI.OFFICE.VIEW', 'Kpi Office View', 'KPI', NULL, 1),
('KPI.PURCH.DELETE', 'Kpi Purch Delete', 'KPI', NULL, 1),
('KPI.PURCH.VIEW', 'Kpi Purch View', 'KPI', NULL, 1),
('KPI.PURCHASES_EDIT', 'KPI Purchases - Edit', 'KPI', 'Edit KPI Purchases.', 1),
('KPI.PURCHASES_VIEW', 'KPI Purchases - View', 'KPI', 'Melihat KPI Purchases.', 1),
('KPI.SNAPSHOT.CREATE', 'Kpi Snapshot Create', 'KPI', NULL, 1),
('KPI.SNAPSHOT.VIEW', 'Kpi Snapshot View', 'KPI', NULL, 1),
('KPI.STOCK_EDIT', 'KPI Stock - Edit', 'KPI', 'Edit KPI Stock.', 1),
('KPI.STOCK_VIEW', 'KPI Stock - View', 'KPI', 'Melihat KPI Stock.', 1),
('KPI.STOCK.VIEW', 'Kpi Stock View', 'KPI', NULL, 1),
('KPI.VIEW', 'KPI - View', 'KPI', 'Melihat modul KPI (Office/Employee/Stock/Purchases).', 1),
('MANUFACTURES_DOCS.ACCESS', 'Manufactures Docs Access', 'MANUFACTURES_DOCS', NULL, 1),
('MASTER_CUSTOMERS.ACCESS', 'Master Customers Access', 'MASTER_CUSTOMERS', NULL, 1),
('MASTER_CUSTOMERS.EXPORT', 'Master Customers Export', 'MASTER_CUSTOMERS', NULL, 1),
('MASTER_CUSTOMERS.MANAGE', 'Master Customers Manage', 'MASTER_CUSTOMERS', NULL, 1),
('MASTER_DATA.ACCESS', 'Master Data Access', 'MASTER_DATA', NULL, 1),
('MASTER_DEPARTEMENTS.MANAGE', 'Master Departements Manage', 'MASTER_DEPARTEMENTS', NULL, 1),
('MASTER_EMAILCOMPANY.MANAGE', 'Master Emailcompany Manage', 'MASTER_EMAILCOMPANY', NULL, 1),
('MASTER_EMPLOYEES.MANAGE', 'Master Employees Manage', 'MASTER_EMPLOYEES', NULL, 1),
('MASTER_PRODUCTS.MEDIA_VIEW', 'Master Products Media View', 'MASTER_PRODUCTS', NULL, 1),
('MASTER_PRODUCTS.PACKAGE_EDIT', 'Master Products Package Edit', 'MASTER_PRODUCTS', NULL, 1),
('MASTER_PRODUCTS.PACKAGE_VIEW', 'Master Products Package View', 'MASTER_PRODUCTS', NULL, 1),
('MASTER_PRODUCTS.PRINT_VIEW', 'Master Products Print View', 'MASTER_PRODUCTS', NULL, 1),
('MASTER_SYSTEM.CONFIG_VIEW', 'Master System Config View', 'MASTER_SYSTEM', NULL, 1),
('MASTER_SYSTEM.LOGIN_MANAGE', 'Master System Login Manage', 'MASTER_SYSTEM', NULL, 1),
('MASTER_TAX.MANAGE', 'Master Tax Manage', 'MASTER_TAX', NULL, 1),
('MASTER_USER.MANAGE', 'Master User Manage', 'MASTER_USER', NULL, 1),
('MASTER_VENDORS.MANAGE', 'Master Vendors Manage', 'MASTER_VENDORS', NULL, 1),
('MASTER.COMPANY_BANK_CRUD', 'Rekening Perusahaan CRUD', 'MASTER', 'Kelola rekening perusahaan (FIN).', 1),
('MASTER.CUSTOMER_CRUD', 'Master Customers CRUD', 'MASTER', 'Kelola pelanggan/RS/klinik (create/edit/delete).', 1),
('MASTER.CUSTOMER_EXPORT', 'Export Customers', 'MASTER', 'Export customer list (CSV/Excel).', 1),
('MASTER.DEPARTMENT_CRUD', 'Master Departments CRUD', 'MASTER', 'Kelola master departemen.', 1),
('MASTER.EMAIL_COMPANY_CRUD', 'Master Email Company CRUD', 'MASTER', 'Kelola email perusahaan (SMTP/from).', 1),
('MASTER.EMPLOYEE_CRUD', 'Master Employees CRUD', 'MASTER', 'Create/Read/Update/Delete karyawan.', 1),
('MASTER.MANUFACTURE_CRUD', 'Master Manufactures (Pabrik) CRUD', 'MASTER', 'Kelola data pabrik/manufacturer.', 1),
('MASTER.MANUFACTURES.BULK', 'Master Manufactures Bulk', 'MASTER', NULL, 1),
('MASTER.MANUFACTURES.DELETE', 'Master Manufactures Delete', 'MASTER', NULL, 1),
('MASTER.MANUFACTURES.IMPORT', 'Master Manufactures Import', 'MASTER', NULL, 1),
('MASTER.MANUFACTURES.MUTATE', 'Master Manufactures Mutate', 'MASTER', NULL, 1),
('MASTER.MANUFACTURES.RESTORE', 'Master Manufactures Restore', 'MASTER', NULL, 1),
('MASTER.MANUFACTURES.SEED', 'Master Manufactures Seed', 'MASTER', NULL, 1),
('MASTER.MANUFACTURES.TOGGLE', 'Master Manufactures Toggle', 'MASTER', NULL, 1),
('MASTER.MANUFACTURES.VIEW', 'Master Manufactures View', 'MASTER', NULL, 1),
('MASTER.OFFICE_CRUD', 'Master Office CRUD', 'MASTER', 'Kelola master kantor/office code.', 1),
('MASTER.OFFICE.AUTOFILL', 'Master Office Autofill', 'MASTER', NULL, 1),
('MASTER.OFFICE.BULK', 'Master Office Bulk', 'MASTER', NULL, 1),
('MASTER.OFFICE.DELETE', 'Master Office Delete', 'MASTER', NULL, 1),
('MASTER.OFFICE.IMPORT', 'Master Office Import', 'MASTER', NULL, 1),
('MASTER.OFFICE.SAVE', 'Master Office Save', 'MASTER', NULL, 1),
('MASTER.OFFICE.SYNC', 'Master Office Sync', 'MASTER', NULL, 1),
('MASTER.OFFICE.TOGGLE', 'Master Office Toggle', 'MASTER', NULL, 1),
('MASTER.OFFICE.VIEW', 'Master Office View', 'MASTER', NULL, 1),
('MASTER.PAYMENT_TERMS_CRUD', 'Master Payment Terms CRUD', 'MASTER', 'Kelola termin pembayaran.', 1),
('MASTER.PAYMENT_TERMS.DELETE', 'Master Payment Terms Delete', 'MASTER', NULL, 1),
('MASTER.PAYMENT_TERMS.SAVE', 'Master Payment Terms Save', 'MASTER', NULL, 1),
('MASTER.PAYMENT_TERMS.VIEW', 'Master Payment Terms View', 'MASTER', NULL, 1),
('MASTER.PIC_CUSTOMER_CRUD', 'Master User/PIC Customers CRUD', 'MASTER', 'Kelola PIC customer (mapping user ↔ customer).', 1),
('MASTER.PRICELIST_BUY_CRUD', 'Master Pricelist Buy CRUD', 'MASTER', 'Kelola harga beli (untuk PQP/FIN).', 1),
('MASTER.PRICELIST_SELL_CRUD', 'Master Pricelist Sell CRUD', 'MASTER', 'Kelola harga jual (untuk CRM/MPR).', 1),
('MASTER.PRICELIST.BULK', 'Master Pricelist Bulk', 'MASTER', NULL, 1),
('MASTER.PRICELIST.IMPORT', 'Master Pricelist Import', 'MASTER', NULL, 1),
('MASTER.PRICELIST.SAVE', 'Master Pricelist Save', 'MASTER', NULL, 1),
('MASTER.PRICELIST.VIEW', 'Master Pricelist View', 'MASTER', NULL, 1),
('MASTER.PRICELIST.VIEW_SELL', 'Master Pricelist View Sell', 'MASTER', NULL, 1),
('MASTER.PRODUCT_CRUD', 'Master Products CRUD', 'MASTER', 'Kelola master products single.', 1),
('MASTER.PRODUCT_MEDIA_UPLOAD', 'Products Media Upload', 'MASTER', 'Upload foto/video produk.', 1),
('MASTER.PRODUCT_PACKAGE_CRUD', 'Master Products Package CRUD', 'MASTER', 'Kelola paket produk (bundle).', 1),
('MASTER.PRODUCTS.BULK', 'Master Products Bulk', 'MASTER', NULL, 1),
('MASTER.PRODUCTS.IMPORT', 'Master Products Import', 'MASTER', NULL, 1),
('MASTER.PRODUCTS.SAVE', 'Master Products Save', 'MASTER', NULL, 1),
('MASTER.PRODUCTS.VIEW', 'Master Products View', 'MASTER', NULL, 1),
('MASTER.TAX_CRUD', 'Master Tax CRUD', 'MASTER', 'Kelola pajak/PPN/withholding.', 1),
('MASTER.VENDOR_CRUD', 'Master Vendors CRUD', 'MASTER', 'Kelola vendor jasa/logistik/forwarder.', 1),
('MASTER.VIEW', 'Master Data Center - View', 'MASTER', 'Akses dashboard Master Data Center.', 1),
('MPR.ACCESS', 'Mpr Access', 'MPR', NULL, 1),
('MPR.FIN.DAILY.EXPORT', 'Mpr Fin Daily Export', 'MPR', NULL, 1),
('MPR.FIN.DAILY.PAY', 'Mpr Fin Daily Pay', 'MPR', NULL, 1),
('MPR.FIN.DAILY.VIEW', 'Mpr Fin Daily View', 'MPR', NULL, 1),
('MPR.PLAN.EDIT', 'Mpr Plan Edit', 'MPR', NULL, 1),
('MPR.PLAN.VIEW', 'Mpr Plan View', 'MPR', NULL, 1),
('ORG.BRANCH.MANAGER', 'Org Branch Manager', 'ORG', NULL, 1),
('ORG.DIRECTOR', 'Org Director', 'ORG', NULL, 1),
('PAYROLL.AUDIT', 'Payroll - Audit Log', 'PAYROLL', 'Lihat audit payroll.', 1),
('PAYROLL.AUDIT.VIEW', 'Payroll Audit View', 'PAYROLL', NULL, 1),
('PAYROLL.CREATE_RUN', 'Payroll Create Run', 'PAYROLL', NULL, 1),
('PAYROLL.EXPORT', 'Payroll Export', 'PAYROLL', NULL, 1),
('PAYROLL.EXPORT_BANK', 'Payroll - Export Bank', 'PAYROLL', 'Export file pembayaran bank.', 1),
('PAYROLL.LOAN_DELETE', 'Payroll Loan Delete', 'PAYROLL', NULL, 1),
('PAYROLL.LOAN_EXPORT', 'Payroll Loan Export', 'PAYROLL', NULL, 1),
('PAYROLL.LOAN_SAVE', 'Payroll Loan Save', 'PAYROLL', NULL, 1),
('PAYROLL.LOAN_STATUS', 'Payroll Loan Status', 'PAYROLL', NULL, 1),
('PAYROLL.LOANS', 'Payroll - Pinjaman/Kasbon', 'PAYROLL', 'Kelola pinjaman/kasbon.', 1),
('PAYROLL.MATRIX_DELETE', 'Payroll Matrix Delete', 'PAYROLL', NULL, 1),
('PAYROLL.MATRIX_EXPORT', 'Payroll Matrix Export', 'PAYROLL', NULL, 1),
('PAYROLL.MATRIX_IMPORT', 'Payroll Matrix Import', 'PAYROLL', NULL, 1),
('PAYROLL.MATRIX_MANAGE', 'Payroll - Master Golongan Gaji', 'PAYROLL', 'Import/edit salary matrix.', 1),
('PAYROLL.MATRIX_SAVE', 'Payroll Matrix Save', 'PAYROLL', NULL, 1),
('PAYROLL.MATRIX_TEMPLATE', 'Payroll Matrix Template', 'PAYROLL', NULL, 1),
('PAYROLL.MATRIX_VIEW', 'Payroll Matrix View', 'PAYROLL', NULL, 1),
('PAYROLL.PAYSLIP_VIEW', 'Payroll - Payslip View', 'PAYROLL', 'Lihat/print payslip.', 1),
('PAYROLL.RUN_CREATE', 'Payroll - Generate Run', 'PAYROLL', 'Generate payroll run per periode.', 1),
('PAYROLL.RUN_EDIT', 'Payroll - Edit Run Items', 'PAYROLL', 'Edit item run (tunj/potongan/lembur).', 1),
('PAYROLL.RUN_PAID', 'Payroll - Mark Paid', 'PAYROLL', 'Set run paid / final.', 1),
('PAYROLL.RUN_POST', 'Payroll - Post/Lock Run', 'PAYROLL', 'Lock/post payroll run (finalisasi).', 1),
('PAYROLL.RUN_RECALC_ABSENSI', 'Payroll Run Recalc Absensi', 'PAYROLL', NULL, 1),
('PAYROLL.RUN_SYNC_LOANS', 'Payroll Run Sync Loans', 'PAYROLL', NULL, 1),
('PAYROLL.RUN_SYNC_MATRIX', 'Payroll Run Sync Matrix', 'PAYROLL', NULL, 1),
('PAYROLL.RUN_UPDATE_ITEM', 'Payroll Run Update Item', 'PAYROLL', NULL, 1),
('PAYROLL.RUN_VIEW', 'Payroll Run View', 'PAYROLL', NULL, 1),
('PAYROLL.SETTINGS', 'Payroll - Settings', 'PAYROLL', 'Konfigurasi payroll & mapping.', 1),
('PAYROLL.SETTINGS_SAVE', 'Payroll Settings Save', 'PAYROLL', NULL, 1),
('PAYROLL.SETTINGS_VIEW', 'Payroll Settings View', 'PAYROLL', NULL, 1),
('PAYROLL.VIEW', 'Payroll - View', 'PAYROLL', 'Melihat dashboard payroll & history runs.', 1),
('PURCHASES.ADMIN_GL_AUTO', 'GL Auto Posting', 'PURCHASES', 'Generate jurnal otomatis (high risk).', 1),
('PURCHASES.ADMIN_STOCK_UPDATE', 'Stock Update from GR', 'PURCHASES', 'Update stock dari GR (high risk).', 1),
('PURCHASES.AP_INVOICE_CRUD', 'Invoice AP CRUD', 'PURCHASES', 'Input/edit Invoice AP.', 1),
('PURCHASES.AP_PAYMENT_CRUD', 'Payment AP CRUD', 'PURCHASES', 'Input/edit pembayaran AP.', 1),
('PURCHASES.AP.ACCESS', 'Purchases Ap Access', 'PURCHASES', NULL, 1),
('PURCHASES.AP.CREATE', 'Purchases Ap Create', 'PURCHASES', NULL, 1),
('PURCHASES.AP.PAYMENT.CREATE', 'Purchases Ap Payment Create', 'PURCHASES', NULL, 1),
('PURCHASES.AP.UPDATE', 'Purchases Ap Update', 'PURCHASES', NULL, 1),
('PURCHASES.AP.VOID', 'Purchases Ap Void', 'PURCHASES', NULL, 1),
('PURCHASES.API_PR', 'Purchases PR API', 'PURCHASES', 'Endpoint API PR (internal).', 1),
('PURCHASES.APPROVE', 'Purchases - Approve', 'PURCHASES', 'Approve purchases/PO.', 1),
('PURCHASES.CEISA_PIB', 'CEISA PIB', 'PURCHASES', 'Entry/view dokumen PIB/CEISA.', 1),
('PURCHASES.CREATE', 'Purchases - Create', 'PURCHASES', 'Membuat purchases/PO.', 1),
('PURCHASES.DELETE', 'Purchases - Delete', 'PURCHASES', 'Menghapus purchases/PO (manager only).', 1),
('PURCHASES.EDIT', 'Purchases - Edit', 'PURCHASES', 'Mengubah purchases/PO.', 1),
('PURCHASES.EXPORT', 'Purchases - Export', 'PURCHASES', 'Export laporan/data purchases.', 1),
('PURCHASES.FORWARDING_CRUD', 'Forwarding/Logistik CRUD', 'PURCHASES', 'Quotes, invoice forwarder, payment forwarder, tasks forwarding.', 1),
('PURCHASES.GR_PROCESS', 'Goods Receipt (GR)', 'PURCHASES', 'Input/terima barang dari PO.', 1),
('PURCHASES.IMPORT_CONTROL', 'Import Control Tower', 'PURCHASES', 'Monitoring import & compliance (control tower).', 1),
('PURCHASES.PO_APPROVE', 'PO Approve', 'PURCHASES', 'Approve/lock PO sebelum proses lanjut.', 1),
('PURCHASES.PO_CRUD', 'PO CRUD', 'PURCHASES', 'Create/edit/delete PO & detail item.', 1),
('PURCHASES.PO_PRINT', 'PO Print', 'PURCHASES', 'Print PO.', 1),
('PURCHASES.PO.ACCESS', 'Purchases Po Access', 'PURCHASES', NULL, 1),
('PURCHASES.PO.CREATE', 'Purchases Po Create', 'PURCHASES', NULL, 1),
('PURCHASES.PO.DELETE', 'Purchases Po Delete', 'PURCHASES', NULL, 1),
('PURCHASES.PO.RESTORE', 'Purchases Po Restore', 'PURCHASES', NULL, 1),
('PURCHASES.PO.STATUS', 'Purchases Po Status', 'PURCHASES', NULL, 1),
('PURCHASES.PO.UPDATE', 'Purchases Po Update', 'PURCHASES', NULL, 1),
('PURCHASES.REPORTS_VIEW', 'Purchases Reports View', 'PURCHASES', 'Lihat laporan purchases.', 1),
('PURCHASES.REPORTS.ACCESS', 'Purchases Reports Access', 'PURCHASES', NULL, 1),
('PURCHASES.VIEW', 'Purchases - View', 'PURCHASES', 'Melihat dashboard & daftar transaksi purchases.', 1),
('REG_ALKES.CASE.DOC.DOWNLOAD', 'Reg Alkes Case Doc Download', 'REG_ALKES', NULL, 1),
('REG_ALKES.CASE.DOC.UPLOAD', 'Reg Alkes Case Doc Upload', 'REG_ALKES', NULL, 1),
('SALES.ACT.ACCESS', 'Sales Act Access', 'SALES', NULL, 1),
('SALES.ACT.UPDATE', 'Sales Act Update', 'SALES', NULL, 1),
('SALES.AUDIT_VIEW', 'Sales - Audit View', 'SALES', 'Lihat audit log DO & SLA KPI.', 1),
('SALES.BACKFILL.ACCESS', 'Sales Backfill Access', 'SALES', NULL, 1),
('SALES.CONTROL_TOWER_VIEW', 'Sales Control Tower View', 'SALES', NULL, 1),
('SALES.CREATE', 'Sales - Create DO', 'SALES', 'Membuat DO / transaksi sales.', 1),
('SALES.CRM_DO_EDIT', 'Sales Crm Do Edit', 'SALES', NULL, 1),
('SALES.DASHBOARD_VIEW', 'Sales Dashboard View', 'SALES', NULL, 1),
('SALES.DELETE', 'Sales - Delete/Cancel DO', 'SALES', 'Delete/cancel DO (high risk).', 1),
('SALES.DO_PRINT_CF', 'Sales Do Print Cf', 'SALES', NULL, 1),
('SALES.DO_VIEW', 'Sales Do View', 'SALES', NULL, 1),
('SALES.EDIT', 'Sales - Edit DO', 'SALES', 'Edit data DO sebelum lock/approve.', 1),
('SALES.EXPORT', 'Sales - Export', 'SALES', 'Export data sales/KPI CSV.', 1),
('SALES.FIN_TASKS', 'Sales Fin Tasks', 'SALES', NULL, 1),
('SALES.KPI_AUDIT_VIEW', 'Sales Kpi Audit View', 'SALES', NULL, 1),
('SALES.KPI_SLA_VIEW', 'Sales Kpi Sla View', 'SALES', NULL, 1),
('SALES.PRINT', 'Sales - Print DO', 'SALES', 'Print CF/DO.', 1),
('SALES.SCM_TASKS', 'Sales Scm Tasks', 'SALES', NULL, 1),
('SALES.TASK_ACT', 'Sales - ACT Tasks', 'SALES', 'Task DO tahap ACT (dokumen, invoice/tax file, amount).', 1),
('SALES.TASK_FIN', 'Sales - FIN Tasks', 'SALES', 'Task DO tahap Finance (payment, AR).', 1),
('SALES.TASK_SCM', 'Sales - SCM Tasks', 'SALES', 'Task DO tahap SCM (vendor/forwarding).', 1),
('SALES.TASK_WQS', 'Sales - WQS Tasks', 'SALES', 'Task DO tahap gudang (packing/picking/dispatch).', 1),
('SALES.VIEW', 'Sales - View', 'SALES', 'Melihat dashboard, control tower, daftar & detail DO.', 1),
('SALES.WQS.UPDATE', 'Sales Wqs Update', 'SALES', NULL, 1),
('STOCK.ADJUST', 'Stock - Adjustment', 'STOCK', 'Input penyesuaian stok (high impact).', 1),
('STOCK.ADJUSTMENT.CREATE', 'Stock Adjustment Create', 'STOCK', NULL, 1),
('STOCK.ADJUSTMENT.VIEW', 'Stock Adjustment View', 'STOCK', NULL, 1),
('STOCK.AUDIT_VIEW', 'Stock - Audit View', 'STOCK', 'Lihat audit stok (movement log).', 1),
('STOCK.VIEW', 'Stock - View', 'STOCK', 'Melihat stok (list/summary).', 1),
('STOCK.WQS.ALLOCATION.UPDATE', 'Stock Wqs Allocation Update', 'STOCK', NULL, 1),
('STOCK.WQS.INCOMING.READ', 'Stock Wqs Incoming Read', 'STOCK', NULL, 1),
('STOCK.WQS.INCOMING.UPDATE', 'Stock Wqs Incoming Update', 'STOCK', NULL, 1),
('STOCK.WQS.PICKING.UPDATE', 'Stock Wqs Picking Update', 'STOCK', NULL, 1),
('STOCK.WQS.PR.CREATE', 'Stock Wqs Pr Create', 'STOCK', NULL, 1),
('STOCK.WQS.PR.READ', 'Stock Wqs Pr Read', 'STOCK', NULL, 1),
('STOCK.WQS.PR.UPDATE', 'Stock Wqs Pr Update', 'STOCK', NULL, 1),
('STOCK.WQS.STOCK.UPDATE', 'Stock Wqs Stock Update', 'STOCK', NULL, 1),
('SYS.AUDIT.VIEW', 'Sys Audit View', 'SYS', NULL, 1),
('SYS.CONFIG.MANAGE', 'Sys Config Manage', 'SYS', NULL, 1),
('SYS.LOGIN', 'Sys Login', 'SYS', NULL, 1),
('SYS.RBAC.MANAGE', 'Sys Rbac Manage', 'SYS', NULL, 1),
('SYS.SUPERADMIN', 'Sys Superadmin', 'SYS', NULL, 1),
('SYS.USER.MANAGE', 'Sys User Manage', 'SYS', NULL, 1),
('SYSTEM.CONFIG_MANAGE', 'System Config', 'SYSTEM', 'Global configuration keys (seed KPI, office code, feature toggles).', 1),
('SYSTEM.RBAC_MANAGE', 'Manage Role & Permission', 'SYSTEM', 'Manage permission registry & Dept+Role matrix (RBAC Center).', 1),
('SYSTEM.SECURITY_VIEW', 'Security & Session Diagnostics', 'SYSTEM', 'View security/session info (read-only).', 1),
('SYSTEM.USER_MANAGE', 'Manage Users (Master System Login)', 'SYSTEM', 'Create/update user login, dept/role/office assignment; reset password; lock/unlock user.', 1),
('TOOLS.ENTERPRISE_AUDIT_EXPORT', 'Tools - Enterprise Audit Export', 'TOOLS', 'Export audit report (CSV/JSON).', 1),
('TOOLS.ENTERPRISE_AUDIT_VIEW', 'Tools - Enterprise Audit View', 'TOOLS', 'Lihat hasil static scan audit keamanan/konsistensi.', 1),
('TOOLS.ITC_RESET_PASSWORD', 'Tools - ITC Reset Password', 'TOOLS', 'Reset password user (ITC support).', 1),
('TOOLS.PURCHASES_M2_APPLY', 'Tools - Apply Purchases M2 Patch', 'TOOLS', 'Jalankan patch/repair purchases (high risk).', 1),
('TOOLS.VIEW', 'Tools - View', 'TOOLS', 'Akses menu Tools/Diagnostics.', 1),
('WF.OUTBOX.VIEW', 'Wf Outbox View', 'WF', NULL, 1),
('WF.TASK.APPROVE', 'Wf Task Approve', 'WF', NULL, 1),
('WF.TASK.DELEGATE', 'Wf Task Delegate', 'WF', NULL, 1),
('WF.TASK.REJECT', 'Wf Task Reject', 'WF', NULL, 1),
('WF.TASK.VIEW', 'Wf Task View', 'WF', NULL, 1),
('WQS.ALLOCATION', 'WQS Allocation', 'WQS', 'Alokasi stock untuk order/DO.', 1),
('WQS.API_INCOMING_PO', 'WQS Incoming PO API', 'WQS', 'API untuk load item PO ke incoming.', 1),
('WQS.DO_TASKS', 'WQS DO Tasks (Sales)', 'WQS', 'Task DO yang dilakukan gudang (dari modul sales).', 1),
('WQS.INCOMING_CRUD', 'WQS Incoming CRUD', 'WQS', 'Penerimaan barang (incoming) + upload dokumen.', 1),
('WQS.PICKING_CRUD', 'WQS Picking CRUD', 'WQS', 'Picking barang untuk DO/Order.', 1),
('WQS.PR_CRUD', 'WQS PR CRUD', 'WQS', 'Create & manage Purchase Request.', 1),
('WQS.PR_PRINT', 'WQS PR Print', 'WQS', 'Print PR.', 1);

-- --------------------------------------------------------

--
-- Struktur dari tabel `rbac_roles`
--

CREATE TABLE `rbac_roles` (
  `role_code` varchar(64) NOT NULL,
  `role_name` varchar(128) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `rbac_roles`
--

INSERT INTO `rbac_roles` (`role_code`, `role_name`, `description`, `is_active`, `created_at`) VALUES
('ACT.MANAGER', 'ACT Manager', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('ACT.STAFF', 'ACT Staff', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('ADMIN', 'Admin', 'Administrator', 1, '2026-01-22 19:32:49'),
('CRM.MANAGER', 'CRM Manager', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('CRM.STAFF', 'CRM Staff', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('FIN.MANAGER', 'FIN Manager', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('FIN.STAFF', 'FIN Staff', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('HRL.MANAGER', 'HRL Manager', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('HRL.STAFF', 'HRL Staff', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('ITC.MANAGER', 'ITC Manager', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('ITC.STAFF', 'ITC Staff', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('MANAGER', 'Manager', 'Department Manager', 1, '2026-01-22 19:32:49'),
('MPR.MANAGER', 'MPR Manager', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('MPR.STAFF', 'MPR Staff', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('ORG.DIRECTOR', 'Org Director', 'Boleh VIEW lintas scope; tindakan sensitif butuh permission eksplisit.', 1, '2026-01-22 19:47:44'),
('OWNER', 'Owner', 'Owner / Top-level', 1, '2026-01-22 19:32:49'),
('PQP.MANAGER', 'PQP Manager', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('PQP.STAFF', 'PQP Staff', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('SCM.MANAGER', 'SCM Manager', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('SCM.STAFF', 'SCM Staff', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('STAFF', 'Staff', 'Staff', 1, '2026-01-22 19:32:49'),
('SUPERADMIN', 'Super Admin', 'Super admin', 1, '2026-01-22 19:32:49'),
('SYS.ADMIN', 'System Admin', 'Admin operasional (tanpa bypass).', 1, '2026-01-22 19:47:44'),
('SYS.SUPERADMIN', 'System Superadmin', 'Bypass RBAC + scope (audit wajib).', 1, '2026-01-22 19:47:44'),
('WQS.MANAGER', 'WQS Manager', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44'),
('WQS.STAFF', 'WQS Staff', 'Baseline role otomatis dari dept+level.', 1, '2026-01-22 19:47:44');

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
  `fin_payment_file` varchar(255) DEFAULT NULL,
  `scm_receive_photo` varchar(255) DEFAULT NULL,
  `scm_receive_video` varchar(255) DEFAULT NULL,
  `scm_delivery_photo` varchar(255) DEFAULT NULL,
  `scm_delivery_video` varchar(255) DEFAULT NULL,
  `scm_signature_data` longtext,
  `act_tax_invoice_file` varchar(255) DEFAULT NULL,
  `act_exchange_doc_file` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `sales_do`
--

INSERT INTO `sales_do` (`id`, `do_code`, `tracking_code`, `do_date`, `customer_id`, `customers_code`, `office_code`, `sales_emp_code`, `shipping_address`, `customer_pic`, `customer_phone`, `status`, `crm_start_time`, `crm_end_time`, `status_wqs`, `wqs_note`, `wqs_updated_at`, `status_scm`, `scm_note`, `scm_updated_at`, `status_act`, `act_note`, `act_updated_at`, `status_fin`, `fin_due_date`, `fin_paid_date`, `fin_paid_amount`, `fin_note`, `fin_updated_at`, `wqs_status`, `scm_status`, `act_status`, `fin_status`, `note`, `total_amount`, `tax_code`, `tax_included`, `tax_rate_percent`, `tax_amount`, `grand_total`, `price_include_tax`, `crm_status`, `flow_status`, `crm_duration_seconds`, `crm_started_at`, `crm_finished_at`, `is_price_include_tax`, `crm_created_at`, `crm_start_at`, `crm_finish_at`, `crm_duration_sec`, `wqs_picked_at`, `scm_delivered_at`, `act_invoiced_at`, `fin_paid_at`, `created_at`, `updated_at`, `wqs_stock_before`, `wqs_stock_after`, `wqs_started_at`, `wqs_ready_at`, `scm_on_delivery_at`, `delivery_mode`, `delivery_vendor_id`, `act_due_date`, `act_amount`, `act_ready_fin_at`, `last_updated_by`, `last_updated_at`, `fin_payment_file`, `scm_receive_photo`, `scm_receive_video`, `scm_delivery_photo`, `scm_delivery_video`, `scm_signature_data`, `act_tax_invoice_file`, `act_exchange_doc_file`) VALUES
(15, 'RMI-BGR-20251211-007', 'RMI-BGR-20251211-007', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'fin_done', '2025-12-11 20:36:46', NULL, 'done', NULL, '2025-12-11 15:32:12', 'done', NULL, '2025-12-11 15:49:52', 'done', NULL, '2025-12-11 16:09:56', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 16:18:46', 'pending', 'pending', 'pending', 'pending', '', 75000.00, 'PPN11', 0, 11.00, 8250.00, 83250.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 13:43:15', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11 13:43:15', '2025-12-11 16:18:46', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 'RMI-BGR-20251211-008', 'RMI-BGR-20251211-008', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'fin_done', '2025-12-11 20:36:46', NULL, 'open', NULL, '2025-12-11 15:32:06', 'open', NULL, '2025-12-11 15:49:46', 'done', NULL, '2025-12-11 16:19:21', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 16:19:32', 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 13:44:54', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11 13:44:54', '2025-12-11 16:19:32', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'RMI-BGR-251211-001', 'RMI-BGR-251211-001', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'wqs_done', '2025-12-11 20:36:46', NULL, 'done', NULL, '2025-12-11 19:48:56', 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', 'Cito', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 19:47:56', '2025-12-11 19:45:56', '2025-12-11 19:47:56', 120, NULL, NULL, NULL, NULL, '2025-12-11 19:47:56', '2025-12-11 19:48:56', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'RMI-BGR-251211-002', 'RMI-BGR-251211-002', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'crm_to_wqs', '2025-12-11 20:36:46', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 20:20:29', '2025-12-11 20:19:59', '2025-12-11 20:20:29', 30, NULL, NULL, NULL, NULL, '2025-12-11 20:20:29', '2025-12-11 20:20:29', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 'RMI-BGR-251211-003', 'RMI-BGR-251211-003', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'crm_to_wqs', '2025-12-11 20:39:43', '2025-12-11 20:39:43', 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 20:39:43', NULL, NULL, 0, NULL, NULL, NULL, NULL, '2025-12-11 20:39:43', '2025-12-11 20:39:43', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(20, 'RMI-TGR-251211-001', 'RMI-TGR-251211-001', '2025-12-11', NULL, 'H042;RS HERMINA SERPONG;RS Swasta;Hermina;Kota Tan', 'TGR', NULL, '', '', '', 'fin_done', '2025-12-11 23:51:36', NULL, 'done', NULL, '2025-12-11 23:52:06', 'done', NULL, '2025-12-11 23:52:19', 'done', NULL, '2025-12-11 23:52:40', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 23:52:53', 'pending', 'pending', 'pending', 'pending', '', 0.00, 'PPN11', 0, 11.00, 0.00, 0.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 23:51:36', '2025-12-11 23:51:05', '2025-12-11 23:51:36', 31, NULL, NULL, NULL, NULL, '2025-12-11 23:51:36', '2025-12-11 23:52:53', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

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
  `products_name` varchar(255) DEFAULT NULL,
  `qty` int DEFAULT '0',
  `unit` varchar(20) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `serial_lot` varchar(120) DEFAULT NULL,
  `unit_price` decimal(18,2) DEFAULT '0.00',
  `disc_percent` decimal(5,2) DEFAULT '0.00',
  `subtotal` decimal(18,2) DEFAULT '0.00',
  `barcode` varchar(100) DEFAULT NULL,
  `stock_at_crm` int DEFAULT NULL,
  `show_package_items` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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

-- --------------------------------------------------------

--
-- Struktur dari tabel `system_config`
--

CREATE TABLE `system_config` (
  `id` int NOT NULL,
  `config_group` varchar(50) NOT NULL,
  `config_key` varchar(100) NOT NULL,
  `config_value` text NOT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `system_config`
--

INSERT INTO `system_config` (`id`, `config_group`, `config_key`, `config_value`, `office_code`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'MASTER_DATA', 'app_title', 'ERP RMI SOFULL', NULL, 'Judul aplikasi di header Master Data.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(3, 'THEME', 'mode', 'dark', NULL, 'Tema tampilan (dark / light). Sekarang: dark sebagai standar RMI.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(4, 'CUSTOMERS', 'code_prefix_hermina', 'H', NULL, 'Prefix kode untuk customer segment Hermina (contoh: H001).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(5, 'CUSTOMERS', 'code_prefix_nonhermina', 'NH', NULL, 'Prefix kode untuk customer segment Non Hermina (contoh: NH001).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(6, 'CUSTOMERS', 'code_prefix_rsud', 'RSUD', NULL, 'Prefix kode untuk customer segment RSUD (contoh: RSUD001).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(7, 'CUSTOMERS', 'segment_keyword_hermina', 'HERMINA', NULL, 'Jika Nama Customer mengandung kata ini → segment = Hermina.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(8, 'CUSTOMERS', 'segment_keyword_rsud', 'RSUD', NULL, 'Jika Nama Customer mengandung kata ini → segment = RSUD.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(9, 'CUSTOMERS', 'default_category_swasta', 'RS Swasta', NULL, 'Default kategori untuk RS Swasta.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(10, 'CUSTOMERS', 'default_category_pemerintah', 'RS Pemerintah', NULL, 'Default kategori untuk RS Pemerintah.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(11, 'IMPORT_CUSTOMERS', 'csv_has_header', '1', NULL, '1 = baris pertama adalah header, 0 = tidak (dipakai saat import di master_customers.php).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(12, 'IMPORT_CUSTOMERS', 'csv_max_rows', '500', NULL, 'Batas maksimum baris dalam 1 file import customers.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(13, 'IMPORT_CUSTOMERS', 'csv_columns_example', 'customers_name,city,address,phone,email,segment,category,office_code,google_maps_url', NULL, 'Contoh urutan kolom CSV untuk import customers (master_customers.php).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(14, 'KPI_OPERATIONAL', 'WORK_START', '08:00', NULL, 'Jam mulai operasional (GLOBAL).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(15, 'KPI_OPERATIONAL', 'WORK_END', '17:00', NULL, 'Jam selesai operasional (GLOBAL).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(16, 'KPI_OPERATIONAL', 'WORK_DAYS', 'MON,TUE,WED,THU,FRI,SAT', NULL, 'Hari kerja (GLOBAL). Format: MON,TUE,...', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(17, 'KPI_OPERATIONAL', 'CUTOFF_DO_INPUT', '16:00', NULL, 'Cutoff input DO (GLOBAL).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(18, 'KPI_SLA', 'SLA_WQS_HOURS', '24', NULL, 'SLA WQS (jam) - GLOBAL.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(19, 'KPI_SLA', 'SLA_SCM_HOURS', '24', NULL, 'SLA SCM (jam) - GLOBAL.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(20, 'KPI_SLA', 'SLA_ACT_HOURS', '24', NULL, 'SLA ACT (jam) - GLOBAL.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(21, 'KPI_SLA', 'SLA_FIN_HOURS', '24', NULL, 'SLA FIN (jam) - GLOBAL.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(22, 'KPI_FA_POLICY', 'DEPR_METHOD', 'STRAIGHT_LINE', NULL, 'Metode depresiasi fixed asset (GLOBAL).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(23, 'KPI_FA_POLICY', 'SALVAGE_DEFAULT_PERCENT', '0', NULL, 'Default salvage value percent (GLOBAL).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(24, 'PURCHASES_POLICY', 'DEFAULT_DP_PERCENT', '30', NULL, 'Default DP (%) untuk invoice PROFORMA (DP) saat link PO.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(25, 'PURCHASES_POLICY', 'DEFAULT_FINAL_PERCENT', '70', NULL, 'Default FINAL (%) untuk invoice FINAL saat link PO.', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(26, 'PURCHASES_POLICY', 'BLOCK_DUPLICATE_DP', '1', NULL, 'Blok pembuatan invoice DP dobel untuk PO yang sama (1=aktif).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(27, 'PURCHASES_POLICY', 'BLOCK_DUPLICATE_FINAL', '1', NULL, 'Blok pembuatan invoice FINAL dobel untuk PO yang sama (1=aktif).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(28, 'PURCHASES_POLICY', 'ALLOW_VOID_UNPAID_DUPLICATE', '1', NULL, 'Izinkan VOID invoice duplikat yang masih UNPAID dan belum ada payment (1=aktif).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(29, 'PURCHASES_POLICY', 'SHOW_SUPPLIER_BANK_ON_PAYMENT', '1', NULL, 'Tampilkan rekening tujuan (pabrikan) di halaman Payment AP (1=aktif).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(30, 'PO_POLICY', 'WARN_ZERO_UNIT_PRICE', '1', NULL, 'Tampilkan warning jika ada item PO yang unit price = 0 (1=aktif).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(31, 'PO_POLICY', 'BLOCK_STATUS_IF_TOTAL_ZERO', '1', NULL, 'Blok perubahan status (IN_PRODUCTION/READY/CLOSED) jika total PO = 0 (1=aktif).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(32, 'WQS_POLICY', 'INCOMING_HARD_VALIDATE_PO', '1', NULL, 'Validasi Incoming terhadap outstanding PO (hard) (1=aktif).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(33, 'WQS_POLICY', 'INCOMING_HIDE_OUTSTANDING_ZERO_SKU', '1', NULL, 'Sembunyikan SKU yang outstanding=0 dari dropdown Incoming (1=aktif).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(34, 'WQS_POLICY', 'INCOMING_DISABLE_WHEN_PO_FULLY_RECEIVED', '1', NULL, 'Disable simpan/tambah item jika PO fully received (1=aktif).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(35, 'WQS_POLICY', 'INCOMING_OVER_RECEIVE_MODE', 'BLOCK', NULL, 'Jika barang datang melebihi PO: BLOCK (default) atau ADJUSTMENT (buat penyesuaian stok terpisah).', 1, '2026-01-22 17:43:35', '2026-01-22 17:43:35'),
(36, 'MASTER_DATA', 'app_tagline', 'ERP Fullstack Rizqullah Mediska Indonesia', NULL, 'Tagline singkat di Master Data.', 1, '2026-01-25 06:00:47', '2026-01-25 06:00:47');

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_allocations`
--

CREATE TABLE `wqs_allocations` (
  `id` int NOT NULL,
  `incoming_id` int NOT NULL,
  `incoming_item_id` int NOT NULL,
  `product_id` int NOT NULL,
  `sku` varchar(50) NOT NULL,
  `lot_number` varchar(80) DEFAULT NULL,
  `serial_number` varchar(80) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `depo_name` varchar(60) DEFAULT NULL,
  `qty` int NOT NULL DEFAULT '0',
  `note` varchar(255) DEFAULT NULL,
  `allocated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_incoming`
--

CREATE TABLE `wqs_incoming` (
  `id` int NOT NULL,
  `incoming_code` varchar(50) NOT NULL,
  `received_date` date NOT NULL,
  `po_code` varchar(60) DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `depo_name` varchar(60) DEFAULT NULL,
  `ref_note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_incoming_items`
--

CREATE TABLE `wqs_incoming_items` (
  `id` int NOT NULL,
  `incoming_id` int NOT NULL,
  `product_id` int NOT NULL,
  `sku` varchar(50) NOT NULL,
  `lot_number` varchar(80) DEFAULT NULL,
  `serial_number` varchar(80) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `qty` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_picking`
--

CREATE TABLE `wqs_picking` (
  `id` int NOT NULL,
  `do_code` varchar(60) NOT NULL,
  `do_id` int DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `depo_name` varchar(60) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `picked_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_picking_items`
--

CREATE TABLE `wqs_picking_items` (
  `id` int NOT NULL,
  `picking_id` int NOT NULL,
  `allocation_id` int NOT NULL,
  `product_id` int NOT NULL,
  `sku` varchar(50) NOT NULL,
  `qty` int NOT NULL DEFAULT '0',
  `lot_number` varchar(80) DEFAULT NULL,
  `serial_number` varchar(80) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `depo_name` varchar(60) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock`
--

CREATE TABLE `wqs_stock` (
  `product_id` int NOT NULL,
  `stock_qty` decimal(18,2) NOT NULL DEFAULT '0.00',
  `updated_at` datetime DEFAULT NULL,
  `source` varchar(40) DEFAULT NULL
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
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_absensi_logs_user_id` (`user_id`),
  ADD KEY `idx_absensi_logs_deleted_at` (`deleted_at`),
  ADD KEY `idx_absensi_logs_created_at` (`created_at`),
  ADD KEY `idx_absensi_logs_user_created` (`user_id`,`created_at`);

--
-- Indeks untuk tabel `absensi_requests`
--
ALTER TABLE `absensi_requests`
  ADD KEY `idx_absensi_requests_user_id` (`user_id`),
  ADD KEY `idx_absensi_requests_deleted_at` (`deleted_at`),
  ADD KEY `idx_absensi_requests_status` (`status`),
  ADD KEY `idx_absensi_requests_start_end` (`start_date`,`end_date`),
  ADD KEY `idx_absensi_requests_user_status` (`user_id`,`status`);

--
-- Indeks untuk tabel `erp_audit_log`
--
ALTER TABLE `erp_audit_log`
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_entity` (`entity_key`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_module_created` (`module`,`created_at`),
  ADD KEY `idx_module_action_created` (`module`,`action`,`created_at`),
  ADD KEY `idx_entity_created` (`entity_key`,`created_at`);

--
-- Indeks untuk tabel `hrl_reg_alkes_cases`
--
ALTER TABLE `hrl_reg_alkes_cases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_case_code` (`case_code`);

--
-- Indeks untuk tabel `hrl_reg_alkes_case_docs`
--
ALTER TABLE `hrl_reg_alkes_case_docs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_case` (`case_id`);

--
-- Indeks untuk tabel `hrl_requests`
--
ALTER TABLE `hrl_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_req_code` (`req_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_dept_office` (`dept_code`,`office_code`),
  ADD KEY `idx_created_by` (`created_by`);

--
-- Indeks untuk tabel `hrl_request_files`
--
ALTER TABLE `hrl_request_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_request_id` (`request_id`),
  ADD KEY `idx_kind` (`kind`);

--
-- Indeks untuk tabel `hrl_user_pins`
--
ALTER TABLE `hrl_user_pins`
  ADD PRIMARY KEY (`username`);

--
-- Indeks untuk tabel `kpi_audit_log`
--
ALTER TABLE `kpi_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kpi_audit_module` (`module`),
  ADD KEY `idx_kpi_audit_action` (`action`),
  ADD KEY `idx_kpi_audit_created` (`created_at`);

--
-- Indeks untuk tabel `kpi_employee`
--
ALTER TABLE `kpi_employee`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kpi_emp_month_dept_emp` (`month_ym`,`dept_code`,`employee_code`),
  ADD KEY `idx_kpi_emp_month` (`month_ym`),
  ADD KEY `idx_kpi_emp_dept` (`dept_code`),
  ADD KEY `idx_kpi_emp_emp` (`employee_code`),
  ADD KEY `idx_kpi_emp_status` (`status`);

--
-- Indeks untuk tabel `kpi_office`
--
ALTER TABLE `kpi_office`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kpi_office_month_office` (`month_ym`,`office_code`),
  ADD KEY `idx_kpi_office_month` (`month_ym`),
  ADD KEY `idx_kpi_office_office` (`office_code`),
  ADD KEY `idx_kpi_office_status` (`status`);

--
-- Indeks untuk tabel `kpi_snapshot`
--
ALTER TABLE `kpi_snapshot`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kpi_snapshot_month` (`snapshot_month`),
  ADD KEY `idx_kpi_snapshot_created` (`created_at`);

--
-- Indeks untuk tabel `kpi_snapshot_items`
--
ALTER TABLE `kpi_snapshot_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kpi_snapshot_items_sid` (`snapshot_id`);

--
-- Indeks untuk tabel `master_employees`
--
ALTER TABLE `master_employees`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_office`
--
ALTER TABLE `master_office`
  ADD UNIQUE KEY `uq_office_code` (`office_code`);

--
-- Indeks untuk tabel `master_products_doc`
--
ALTER TABLE `master_products_doc`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_doc_type` (`doc_type`),
  ADD KEY `idx_uploaded_at` (`uploaded_at`),
  ADD KEY `idx_is_deleted` (`is_deleted`);

--
-- Indeks untuk tabel `master_products_print`
--
ALTER TABLE `master_products_print`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_printed_at` (`printed_at`);

--
-- Indeks untuk tabel `master_system_login`
--
ALTER TABLE `master_system_login`
  ADD UNIQUE KEY `uniq_msl_id` (`id`),
  ADD KEY `idx_msl_status` (`status`),
  ADD KEY `idx_msl_role` (`role`),
  ADD KEY `idx_msl_office` (`office_code`);

--
-- Indeks untuk tabel `mpr_budget_requests`
--
ALTER TABLE `mpr_budget_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mpr_budget_code` (`request_code`),
  ADD KEY `idx_mpr_budget_plan` (`plan_id`),
  ADD KEY `idx_mpr_budget_status` (`status`),
  ADD KEY `idx_mpr_budget_office` (`office_code`),
  ADD KEY `idx_mpr_budget_deleted` (`deleted_at`);

--
-- Indeks untuk tabel `mpr_ops_payments`
--
ALTER TABLE `mpr_ops_payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mpr_ops_pay` (`work_date`,`employee_code`,`office_code`),
  ADD KEY `idx_mpr_ops_date` (`work_date`),
  ADD KEY `idx_mpr_ops_emp` (`employee_code`);

--
-- Indeks untuk tabel `mpr_plans`
--
ALTER TABLE `mpr_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mpr_plans_code` (`plan_code`),
  ADD KEY `idx_mpr_plans_status` (`status`),
  ADD KEY `idx_mpr_plans_office` (`office_code`),
  ADD KEY `idx_mpr_plans_dept` (`dept_code`),
  ADD KEY `idx_mpr_plans_deleted` (`deleted_at`);

--
-- Indeks untuk tabel `mpr_progress`
--
ALTER TABLE `mpr_progress`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mpr_progress_plan` (`plan_id`),
  ADD KEY `idx_mpr_progress_date` (`progress_date`),
  ADD KEY `idx_mpr_progress_deleted` (`deleted_at`);

--
-- Indeks untuk tabel `mpr_visits`
--
ALTER TABLE `mpr_visits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mpr_visits_plan` (`plan_id`),
  ADD KEY `idx_mpr_visits_date` (`visit_date`),
  ADD KEY `idx_mpr_visits_deleted` (`deleted_at`);

--
-- Indeks untuk tabel `purchases_invoice_ap`
--
ALTER TABLE `purchases_invoice_ap`
  ADD UNIQUE KEY `uq_ap_code` (`ap_code`);

--
-- Indeks untuk tabel `purchases_payment_ap`
--
ALTER TABLE `purchases_payment_ap`
  ADD UNIQUE KEY `uq_pay_code` (`pay_code`);

--
-- Indeks untuk tabel `purchases_po`
--
ALTER TABLE `purchases_po`
  ADD UNIQUE KEY `uq_po_code` (`po_code`);

--
-- Indeks untuk tabel `rbac_dept_role_permissions`
--
ALTER TABLE `rbac_dept_role_permissions`
  ADD UNIQUE KEY `uniq_dept_role_perm` (`dept_code`,`role_code`,`perm_code`);

--
-- Indeks untuk tabel `rbac_permissions`
--
ALTER TABLE `rbac_permissions`
  ADD UNIQUE KEY `uniq_perm_code` (`perm_code`);

--
-- Indeks untuk tabel `rbac_roles`
--
ALTER TABLE `rbac_roles`
  ADD PRIMARY KEY (`role_code`);

--
-- Indeks untuk tabel `rbac_user_permissions`
--
ALTER TABLE `rbac_user_permissions`
  ADD UNIQUE KEY `uniq_user_perm` (`user_id`,`perm_code`);

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
  ADD KEY `idx_config_group` (`config_group`),
  ADD KEY `idx_config_key` (`config_key`);

--
-- Indeks untuk tabel `wqs_allocations`
--
ALTER TABLE `wqs_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_incoming` (`incoming_id`),
  ADD KEY `idx_item` (`incoming_item_id`),
  ADD KEY `idx_product` (`product_id`),
  ADD KEY `idx_sku` (`sku`),
  ADD KEY `idx_office` (`office_code`);

--
-- Indeks untuk tabel `wqs_incoming`
--
ALTER TABLE `wqs_incoming`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_incoming_code` (`incoming_code`),
  ADD KEY `idx_received_date` (`received_date`),
  ADD KEY `idx_po` (`po_code`),
  ADD KEY `idx_office` (`office_code`);

--
-- Indeks untuk tabel `wqs_incoming_items`
--
ALTER TABLE `wqs_incoming_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_incoming` (`incoming_id`),
  ADD KEY `idx_product` (`product_id`),
  ADD KEY `idx_sku` (`sku`);

--
-- Indeks untuk tabel `wqs_picking`
--
ALTER TABLE `wqs_picking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_do_code` (`do_code`),
  ADD KEY `idx_do_id` (`do_id`),
  ADD KEY `idx_picked_at` (`picked_at`);

--
-- Indeks untuk tabel `wqs_picking_items`
--
ALTER TABLE `wqs_picking_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_picking` (`picking_id`),
  ADD KEY `idx_alloc` (`allocation_id`),
  ADD KEY `idx_sku` (`sku`);

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
  ADD PRIMARY KEY (`product_id`);

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
-- AUTO_INCREMENT untuk tabel `hrl_reg_alkes_cases`
--
ALTER TABLE `hrl_reg_alkes_cases`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_reg_alkes_case_docs`
--
ALTER TABLE `hrl_reg_alkes_case_docs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_requests`
--
ALTER TABLE `hrl_requests`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_request_files`
--
ALTER TABLE `hrl_request_files`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_audit_log`
--
ALTER TABLE `kpi_audit_log`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_employee`
--
ALTER TABLE `kpi_employee`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_office`
--
ALTER TABLE `kpi_office`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_snapshot`
--
ALTER TABLE `kpi_snapshot`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_snapshot_items`
--
ALTER TABLE `kpi_snapshot_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_employees`
--
ALTER TABLE `master_employees`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT untuk tabel `master_products_doc`
--
ALTER TABLE `master_products_doc`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_products_print`
--
ALTER TABLE `master_products_print`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_system_login`
--
ALTER TABLE `master_system_login`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT untuk tabel `mpr_budget_requests`
--
ALTER TABLE `mpr_budget_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mpr_ops_payments`
--
ALTER TABLE `mpr_ops_payments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mpr_plans`
--
ALTER TABLE `mpr_plans`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mpr_progress`
--
ALTER TABLE `mpr_progress`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mpr_visits`
--
ALTER TABLE `mpr_visits`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `sales_do_audit`
--
ALTER TABLE `sales_do_audit`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `sales_do_items`
--
ALTER TABLE `sales_do_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `system_audit_logs`
--
ALTER TABLE `system_audit_logs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `system_config`
--
ALTER TABLE `system_config`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT untuk tabel `wqs_allocations`
--
ALTER TABLE `wqs_allocations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_incoming`
--
ALTER TABLE `wqs_incoming`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_incoming_items`
--
ALTER TABLE `wqs_incoming_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_picking`
--
ALTER TABLE `wqs_picking`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_picking_items`
--
ALTER TABLE `wqs_picking_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_pr`
--
ALTER TABLE `wqs_pr`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_pr_items`
--
ALTER TABLE `wqs_pr_items`
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
-- Ketidakleluasaan untuk tabel `kpi_snapshot_items`
--
ALTER TABLE `kpi_snapshot_items`
  ADD CONSTRAINT `fk_kpi_snapshot_items_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `kpi_snapshot` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
