-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Waktu pembuatan: 09 Mar 2026 pada 06.53
-- Versi server: 10.11.11-MariaDB
-- Versi PHP: 8.2.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `erp_rmi_sofull`
--
CREATE DATABASE IF NOT EXISTS `erp_rmi_sofull` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `erp_rmi_sofull`;

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_audit`
--

DROP TABLE IF EXISTS `absensi_audit`;
CREATE TABLE `absensi_audit` (
  `id` bigint(20) NOT NULL,
  `actor_user_id` bigint(20) DEFAULT NULL,
  `actor_username` varchar(80) DEFAULT NULL,
  `action` varchar(48) NOT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `absensi_audit`
--

TRUNCATE TABLE `absensi_audit`;
--
-- Dumping data untuk tabel `absensi_audit`
--

INSERT DELAYED IGNORE INTO `absensi_audit` (`id`, `actor_user_id`, `actor_username`, `action`, `payload_json`, `ip`, `user_agent`, `created_at`) VALUES
(1, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\": 1, \"is_hr_admin\": 0, \"office_code\": \"JGY\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:40:53'),
(2, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\": 2, \"is_hr_admin\": 0, \"office_code\": \"\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:40:54'),
(3, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\": 2, \"is_hr_admin\": 0, \"office_code\": \"JGY\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:41:00'),
(4, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\":3,\"office_code\":\"BGR\",\"is_hr_admin\":0}', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '2026-02-25 23:44:50'),
(5, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\":2,\"office_code\":\"\",\"is_hr_admin\":1}', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '2026-03-06 22:55:06'),
(6, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\":2,\"office_code\":\"\",\"is_hr_admin\":1}', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '2026-03-06 22:58:32'),
(7, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\":1,\"office_code\":\"\",\"is_hr_admin\":1}', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '2026-03-06 22:59:14'),
(8, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\":2,\"office_code\":\"\",\"is_hr_admin\":1}', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '2026-03-06 22:59:22'),
(9, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\":2,\"office_code\":\"\",\"is_hr_admin\":1}', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '2026-03-06 23:00:37'),
(10, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\":1,\"office_code\":\"\",\"is_hr_admin\":1}', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '2026-03-06 23:00:40'),
(11, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\":26,\"office_code\":\"BGR\",\"is_hr_admin\":0}', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '2026-03-06 23:04:22'),
(12, 2, 'admin', 'USER_PROFILE_SAVE', '{\"user_id\":27,\"office_code\":\"BGR\",\"is_hr_admin\":0}', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '2026-03-06 23:04:28');

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_logs`
--

DROP TABLE IF EXISTS `absensi_logs`;
CREATE TABLE `absensi_logs` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `username` varchar(80) DEFAULT NULL,
  `action_type` varchar(16) NOT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `distance_m` int(11) DEFAULT NULL,
  `geo_lat` decimal(10,7) DEFAULT NULL,
  `geo_lng` decimal(10,7) DEFAULT NULL,
  `geo_acc` int(11) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `office_id` bigint(20) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `absensi_logs`
--

TRUNCATE TABLE `absensi_logs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_offices`
--

DROP TABLE IF EXISTS `absensi_offices`;
CREATE TABLE `absensi_offices` (
  `office_code` varchar(32) NOT NULL,
  `office_name` varchar(120) NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `radius_m` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `absensi_offices`
--

TRUNCATE TABLE `absensi_offices`;
--
-- Dumping data untuk tabel `absensi_offices`
--

INSERT DELAYED IGNORE INTO `absensi_offices` (`office_code`, `office_name`, `lat`, `lng`, `radius_m`, `is_active`, `updated_at`) VALUES
('DEFAULT', 'Kantor Default', NULL, NULL, 120, 1, '2026-01-01 21:32:41');

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_requests`
--

DROP TABLE IF EXISTS `absensi_requests`;
CREATE TABLE `absensi_requests` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `username` varchar(80) NOT NULL,
  `req_type` varchar(16) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'PENDING',
  `approver_id` bigint(20) DEFAULT NULL,
  `approver_name` varchar(80) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `absensi_requests`
--

TRUNCATE TABLE `absensi_requests`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_settings`
--

DROP TABLE IF EXISTS `absensi_settings`;
CREATE TABLE `absensi_settings` (
  `k` varchar(64) NOT NULL,
  `v` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `absensi_settings`
--

TRUNCATE TABLE `absensi_settings`;
--
-- Dumping data untuk tabel `absensi_settings`
--

INSERT DELAYED IGNORE INTO `absensi_settings` (`k`, `v`, `updated_at`) VALUES
('geofence_enforce', '1', '2026-01-01 21:32:41');

-- --------------------------------------------------------

--
-- Struktur dari tabel `absensi_user_profile`
--

DROP TABLE IF EXISTS `absensi_user_profile`;
CREATE TABLE `absensi_user_profile` (
  `user_id` bigint(20) NOT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `is_hr_admin` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `absensi_user_profile`
--

TRUNCATE TABLE `absensi_user_profile`;
--
-- Dumping data untuk tabel `absensi_user_profile`
--

INSERT DELAYED IGNORE INTO `absensi_user_profile` (`user_id`, `office_code`, `is_hr_admin`, `updated_at`) VALUES
(1, NULL, 1, '2026-03-06 23:00:40'),
(2, NULL, 1, '2026-03-06 23:00:37'),
(3, 'BGR', 0, '2026-02-25 23:44:50'),
(26, 'BGR', 0, '2026-03-06 23:04:22'),
(27, 'BGR', 0, '2026-03-06 23:04:28');

-- --------------------------------------------------------

--
-- Struktur dari tabel `api_partner_keys`
--

DROP TABLE IF EXISTS `api_partner_keys`;
CREATE TABLE `api_partner_keys` (
  `id` int(10) UNSIGNED NOT NULL,
  `partner_name` varchar(120) NOT NULL,
  `environment` enum('production','development') NOT NULL DEFAULT 'production',
  `api_key_hash` varchar(255) NOT NULL,
  `scopes` text DEFAULT NULL COMMENT 'Comma-separated: sales.do.read,stock.items.read',
  `rate_limit_per_hour` int(10) UNSIGNED NOT NULL DEFAULT 1000,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `api_partner_keys`
--

TRUNCATE TABLE `api_partner_keys`;
--
-- Dumping data untuk tabel `api_partner_keys`
--

INSERT DELAYED IGNORE INTO `api_partner_keys` (`id`, `partner_name`, `environment`, `api_key_hash`, `scopes`, `rate_limit_per_hour`, `status`, `created_by`, `created_at`, `updated_at`, `last_used_at`, `note`) VALUES
(1, 'Hermina Group UAT', 'development', '$2y$12$8Vv.tFTUjYbjSYd6tGEGxufcHvwe5fjQyAk0n3EnTF2g0QCFtQqo.', 'order:create', 1000, 'active', 'admin', '2026-03-08 00:25:54', '2026-03-08 02:20:47', '2026-03-08 02:20:47', 'UAT H2H sebelum meeting');

-- --------------------------------------------------------

--
-- Struktur dari tabel `api_partner_order_idempotency`
--

DROP TABLE IF EXISTS `api_partner_order_idempotency`;
CREATE TABLE `api_partner_order_idempotency` (
  `id` int(11) NOT NULL,
  `partner_id` int(11) NOT NULL,
  `idempotency_key` varchar(120) NOT NULL,
  `do_id` int(11) NOT NULL,
  `do_code` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `api_partner_order_idempotency`
--

TRUNCATE TABLE `api_partner_order_idempotency`;
--
-- Dumping data untuk tabel `api_partner_order_idempotency`
--

INSERT DELAYED IGNORE INTO `api_partner_order_idempotency` (`id`, `partner_id`, `idempotency_key`, `do_id`, `do_code`, `created_at`) VALUES
(1, 1, 'UAT-20260308004508', 24, 'RMI-BGR-260308-001', '2026-03-08 00:45:09'),
(2, 1, 'UAT-IDEM-1772905509', 25, 'RMI-BGR-260308-002', '2026-03-08 00:45:09'),
(3, 1, 'UAT-20260308004554', 26, 'RMI-BGR-260308-003', '2026-03-08 00:45:55'),
(4, 1, 'UAT-IDEM-1772905555', 27, 'RMI-BGR-260308-004', '2026-03-08 00:45:55'),
(5, 1, 'UAT-20260308014827', 28, 'RMI-BGR-260308-005', '2026-03-08 01:48:29'),
(6, 1, 'UAT-IDEM-1772909309', 29, 'RMI-BGR-260308-006', '2026-03-08 01:48:30'),
(7, 1, 'LOG-TEST-001', 30, 'RMI-BGR-260308-007', '2026-03-08 02:20:47');

-- --------------------------------------------------------

--
-- Struktur dari tabel `api_rate_limits`
--

DROP TABLE IF EXISTS `api_rate_limits`;
CREATE TABLE `api_rate_limits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scope_key` varchar(80) NOT NULL,
  `actor_key` varchar(120) NOT NULL,
  `counter` int(11) NOT NULL DEFAULT 0,
  `window_started_at` datetime NOT NULL,
  `reset_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `api_rate_limits`
--

TRUNCATE TABLE `api_rate_limits`;
--
-- Dumping data untuk tabel `api_rate_limits`
--

INSERT DELAYED IGNORE INTO `api_rate_limits` (`id`, `scope_key`, `actor_key`, `counter`, `window_started_at`, `reset_at`, `created_at`, `updated_at`) VALUES
(1, 'MOBILE_AUTH_LOGIN_IP', 'ip:10.10.60.20', 3, '2026-03-08 23:24:49', '2026-03-08 23:34:49', '2026-03-02 01:16:53', '2026-03-08 23:34:34'),
(2, 'MOBILE_AUTH_LOGIN_USER', 'u:__smoke_invalid__', 3, '2026-03-08 23:24:49', '2026-03-08 23:34:49', '2026-03-02 01:16:53', '2026-03-08 23:34:34'),
(23, 'MOBILE_AUTH_REFRESH', 'ip:10.10.60.20', 2, '2026-03-04 06:19:50', '2026-03-04 06:29:50', '2026-03-02 04:17:34', '2026-03-04 06:19:53'),
(39, 'MOBILE_AUTH_REFRESH', 'ip:127.0.0.1', 3, '2026-03-03 01:14:27', '2026-03-03 01:24:27', '2026-03-03 01:14:27', '2026-03-03 01:17:05');

-- --------------------------------------------------------

--
-- Struktur dari tabel `api_rate_limit_policies`
--

DROP TABLE IF EXISTS `api_rate_limit_policies`;
CREATE TABLE `api_rate_limit_policies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `scope_key` varchar(80) NOT NULL,
  `window_seconds` int(11) NOT NULL DEFAULT 60,
  `max_hits` int(11) NOT NULL DEFAULT 20,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `api_rate_limit_policies`
--

TRUNCATE TABLE `api_rate_limit_policies`;
--
-- Dumping data untuk tabel `api_rate_limit_policies`
--

INSERT DELAYED IGNORE INTO `api_rate_limit_policies` (`id`, `scope_key`, `window_seconds`, `max_hits`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'GL_ENQUEUE_POSTING', 60, 20, 1, '2026-03-01 13:38:05', '2026-03-01 13:38:05'),
(2, 'SALES_TRACKING_SYNC', 60, 30, 1, '2026-03-01 13:38:28', '2026-03-01 13:38:28'),
(3, 'TRACKING_PUBLIC_VIEW', 60, 45, 1, '2026-03-01 13:38:28', '2026-03-01 13:38:28'),
(4, 'MOBILE_AUTH_LOGIN_IP', 600, 10, 1, '2026-03-01 13:38:30', '2026-03-01 13:38:30'),
(5, 'MOBILE_AUTH_LOGIN_USER', 600, 10, 1, '2026-03-01 13:38:30', '2026-03-01 13:38:30'),
(6, 'MOBILE_AUTH_REFRESH', 600, 30, 1, '2026-03-01 13:38:30', '2026-03-01 13:38:30'),
(7, 'MOBILE_CHAT_SEND', 60, 60, 1, '2026-03-01 13:38:31', '2026-03-01 13:38:31');

-- --------------------------------------------------------

--
-- Struktur dari tabel `auth_login_attempts`
--

DROP TABLE IF EXISTS `auth_login_attempts`;
CREATE TABLE `auth_login_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `username` varchar(120) NOT NULL,
  `failed_count` int(11) NOT NULL DEFAULT 0,
  `last_attempt_at` datetime DEFAULT NULL,
  `locked_until` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `auth_login_attempts`
--

TRUNCATE TABLE `auth_login_attempts`;
--
-- Dumping data untuk tabel `auth_login_attempts`
--

INSERT DELAYED IGNORE INTO `auth_login_attempts` (`id`, `ip_address`, `username`, `failed_count`, `last_attempt_at`, `locked_until`, `created_at`, `updated_at`) VALUES
(3, '10.10.60.21', 'staafitc_bgr', 1, '2026-03-04 05:31:57', NULL, '2026-03-04 05:31:57', '2026-03-04 05:31:57'),
(4, '10.10.60.21', 'mgr_scm_bgr', 1, '2026-03-04 16:14:11', NULL, '2026-03-04 16:14:11', '2026-03-04 16:14:11'),
(8, '10.10.60.21', 'hermina demo', 2, '2026-03-07 14:12:05', NULL, '2026-03-07 14:11:59', '2026-03-07 14:12:05'),
(10, '10.10.60.21', 'yaxin_demo', 5, '2026-03-07 14:43:44', '2026-03-07 14:53:44', '2026-03-07 14:38:58', '2026-03-07 14:43:44'),
(15, '114.10.78.142', 'manufacturer_demo', 1, '2026-03-07 20:57:45', NULL, '2026-03-07 20:57:45', '2026-03-07 20:57:45'),
(16, '114.10.77.142', 'manufacturer_demo', 1, '2026-03-07 21:01:02', NULL, '2026-03-07 21:01:02', '2026-03-07 21:01:02'),
(17, '36.93.107.114', 'staafbranch_kal', 1, '2026-03-08 00:33:16', NULL, '2026-03-08 00:33:16', '2026-03-08 00:33:16'),
(18, '2a09:bac3:39ac:232::38:cd', 'mgrbranch_bgr', 1, '2026-03-09 04:20:28', NULL, '2026-03-09 04:20:28', '2026-03-09 04:20:28'),
(19, '2a09:bac3:39ac:232::38:cd', 'staffbrach_bgr', 2, '2026-03-09 04:21:04', NULL, '2026-03-09 04:20:46', '2026-03-09 04:21:04'),
(21, '2a09:bac3:39ac:232::38:cd', 'staffbrach_bdg', 1, '2026-03-09 04:22:39', NULL, '2026-03-09 04:22:39', '2026-03-09 04:22:39');

-- --------------------------------------------------------

--
-- Struktur dari tabel `auth_mfa_bypass_tickets`
--

DROP TABLE IF EXISTS `auth_mfa_bypass_tickets`;
CREATE TABLE `auth_mfa_bypass_tickets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `reason` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `auth_mfa_bypass_tickets`
--

TRUNCATE TABLE `auth_mfa_bypass_tickets`;
--
-- Dumping data untuk tabel `auth_mfa_bypass_tickets`
--

INSERT DELAYED IGNORE INTO `auth_mfa_bypass_tickets` (`id`, `user_id`, `reason`, `expires_at`, `status`, `is_active`, `created_by`, `requested_by`, `approved_by`, `approved_at`, `created_at`, `updated_at`) VALUES
(1, 2, 'Test', '2026-03-01 14:48:22', 'PENDING', 0, 2, 2, NULL, NULL, '2026-03-01 13:48:32', '2026-03-01 13:48:32');

-- --------------------------------------------------------

--
-- Struktur dari tabel `auth_mfa_policies`
--

DROP TABLE IF EXISTS `auth_mfa_policies`;
CREATE TABLE `auth_mfa_policies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role_code` varchar(50) NOT NULL DEFAULT '*',
  `dept_code` varchar(50) NOT NULL DEFAULT '*',
  `require_mfa` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `auth_mfa_policies`
--

TRUNCATE TABLE `auth_mfa_policies`;
--
-- Dumping data untuk tabel `auth_mfa_policies`
--

INSERT DELAYED IGNORE INTO `auth_mfa_policies` (`id`, `role_code`, `dept_code`, `require_mfa`, `is_active`, `created_at`, `updated_at`) VALUES
(2, 'SUPERADMIN', 'SYS', 0, 1, '2026-02-25 20:09:04', '2026-03-03 21:29:33'),
(3, 'ADMIN', '*', 0, 0, '2026-02-25 20:09:04', '2026-03-01 16:49:58'),
(4, 'STAFF', 'BRANCH', 0, 1, '2026-02-28 05:48:21', '2026-03-01 16:50:15'),
(5, 'MANAGER', '*', 0, 0, '2026-03-01 13:38:07', '2026-03-01 16:49:41'),
(6, 'STAFF', 'FIN', 0, 0, '2026-03-01 13:38:08', '2026-03-01 16:50:21'),
(7, 'STAFF', 'ACT', 0, 0, '2026-03-01 13:38:08', '2026-03-01 16:50:09'),
(8, 'STAFF', 'SYS', 1, 1, '2026-03-01 13:38:08', '2026-03-01 13:49:56'),
(9, 'STAFF', '*', 0, 1, '2026-03-01 13:38:08', '2026-03-01 13:49:56');

-- --------------------------------------------------------

--
-- Struktur dari tabel `auth_webauthn_credentials`
--

DROP TABLE IF EXISTS `auth_webauthn_credentials`;
CREATE TABLE `auth_webauthn_credentials` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `credential_id` varchar(512) NOT NULL,
  `public_key` text NOT NULL,
  `sign_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `aaguid` varchar(64) DEFAULT NULL,
  `friendly_name` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `auth_webauthn_credentials`
--

TRUNCATE TABLE `auth_webauthn_credentials`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `bank_accounts`
--

DROP TABLE IF EXISTS `bank_accounts`;
CREATE TABLE `bank_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `account_code` varchar(50) NOT NULL,
  `account_name` varchar(150) NOT NULL,
  `account_number` varchar(60) DEFAULT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `branch` varchar(120) DEFAULT NULL,
  `purpose` varchar(20) NOT NULL DEFAULT 'RECEIVE',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `note` text DEFAULT NULL,
  `gl_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `bank_accounts`
--

TRUNCATE TABLE `bank_accounts`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `bank_reconciliations`
--

DROP TABLE IF EXISTS `bank_reconciliations`;
CREATE TABLE `bank_reconciliations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `bank_account_id` bigint(20) UNSIGNED NOT NULL,
  `period_key` varchar(7) NOT NULL,
  `status` enum('DRAFT','RECONCILED','LOCKED') NOT NULL DEFAULT 'DRAFT',
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `bank_reconciliations`
--

TRUNCATE TABLE `bank_reconciliations`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `bank_recon_matches`
--

DROP TABLE IF EXISTS `bank_recon_matches`;
CREATE TABLE `bank_recon_matches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `reconciliation_id` bigint(20) UNSIGNED NOT NULL,
  `statement_line_id` bigint(20) UNSIGNED NOT NULL,
  `erp_txn_type` varchar(50) NOT NULL,
  `erp_txn_id` bigint(20) NOT NULL,
  `matched_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `bank_recon_matches`
--

TRUNCATE TABLE `bank_recon_matches`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `bank_statements`
--

DROP TABLE IF EXISTS `bank_statements`;
CREATE TABLE `bank_statements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `bank_account_id` bigint(20) UNSIGNED NOT NULL,
  `period_key` varchar(7) NOT NULL,
  `opening_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `closing_balance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `uploaded_file` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `bank_statements`
--

TRUNCATE TABLE `bank_statements`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `bank_statement_lines`
--

DROP TABLE IF EXISTS `bank_statement_lines`;
CREATE TABLE `bank_statement_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `statement_id` bigint(20) UNSIGNED NOT NULL,
  `txn_date` date NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `txn_type` enum('DEBIT','CREDIT') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `bank_statement_lines`
--

TRUNCATE TABLE `bank_statement_lines`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `bank_statement_raw`
--

DROP TABLE IF EXISTS `bank_statement_raw`;
CREATE TABLE `bank_statement_raw` (
  `id` int(10) UNSIGNED NOT NULL,
  `batch_code` varchar(60) NOT NULL,
  `bank_account_id` bigint(20) UNSIGNED NOT NULL,
  `file_name` varchar(255) NOT NULL DEFAULT '',
  `row_count` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `uploaded_by` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `bank_statement_raw`
--

TRUNCATE TABLE `bank_statement_raw`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_acl`
--

DROP TABLE IF EXISTS `chat_acl`;
CREATE TABLE `chat_acl` (
  `channel_id` bigint(20) NOT NULL,
  `subject_type` enum('LEVEL','DEPT','OFFICE','USER') NOT NULL,
  `subject_key` varchar(80) NOT NULL,
  `perm_read` tinyint(1) NOT NULL DEFAULT 1,
  `perm_send` tinyint(1) NOT NULL DEFAULT 1,
  `perm_pin` tinyint(1) NOT NULL DEFAULT 0,
  `perm_manage` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_acl`
--

TRUNCATE TABLE `chat_acl`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_attachments`
--

DROP TABLE IF EXISTS `chat_attachments`;
CREATE TABLE `chat_attachments` (
  `id` bigint(20) NOT NULL,
  `message_id` bigint(20) NOT NULL,
  `channel_id` bigint(20) NOT NULL,
  `uploader_user_id` int(11) DEFAULT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `storage_path` varchar(255) NOT NULL,
  `mime` varchar(120) NOT NULL,
  `size_bytes` bigint(20) NOT NULL,
  `sha256` varchar(64) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_attachments`
--

TRUNCATE TABLE `chat_attachments`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_channels`
--

DROP TABLE IF EXISTS `chat_channels`;
CREATE TABLE `chat_channels` (
  `id` bigint(20) NOT NULL,
  `type` varchar(20) DEFAULT NULL,
  `channel_key` varchar(120) DEFAULT NULL,
  `channel_type` enum('PUBLIC','PRIVATE','DM','SYSTEM','DOC_CONTEXT') NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `dept_code` varchar(20) DEFAULT NULL,
  `context_entity_type` varchar(20) DEFAULT NULL,
  `context_entity_id` bigint(20) DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `retention_mode` enum('none','purge') DEFAULT 'none',
  `retention_days` int(11) DEFAULT NULL,
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `pin_policy` varchar(20) NOT NULL DEFAULT 'ADMIN_ONLY',
  `dm_user_low` bigint(20) DEFAULT NULL,
  `dm_user_high` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_channels`
--

TRUNCATE TABLE `chat_channels`;
--
-- Dumping data untuk tabel `chat_channels`
--

INSERT DELAYED IGNORE INTO `chat_channels` (`id`, `type`, `channel_key`, `channel_type`, `name`, `description`, `office_code`, `dept_code`, `context_entity_type`, `context_entity_id`, `created_by`, `created_at`, `retention_mode`, `retention_days`, `is_private`, `pin_policy`, `dm_user_low`, `dm_user_high`) VALUES
(1, 'CHANNEL', NULL, 'PUBLIC', 'general', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-26 00:01:52', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(2, 'CHANNEL', NULL, 'PUBLIC', 'office-sys', NULL, NULL, NULL, NULL, NULL, '2', '2026-02-26 00:02:07', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(3, 'CHANNEL', NULL, 'PUBLIC', 'dept-sys', NULL, NULL, NULL, NULL, NULL, '2', '2026-02-26 00:02:07', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(4, 'CHANNEL', NULL, 'PUBLIC', 'test', NULL, NULL, NULL, NULL, NULL, '2', '2026-02-26 00:03:40', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(6, 'CHANNEL', NULL, 'PUBLIC', 'testy', NULL, NULL, NULL, NULL, NULL, '2', '2026-02-26 00:06:13', 'purge', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(7, 'CHANNEL', NULL, 'PUBLIC', 'yyy', NULL, NULL, NULL, NULL, NULL, '2', '2026-02-26 00:14:00', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(8, 'CHANNEL', NULL, 'PUBLIC', 'tteeettt', NULL, NULL, NULL, NULL, NULL, '2', '2026-02-26 00:18:43', 'purge', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(9, 'DM', NULL, 'PUBLIC', 'dm_1_2', NULL, NULL, NULL, NULL, NULL, '2', '2026-02-26 00:22:21', 'none', NULL, 1, 'ADMIN_ONLY', 1, 2),
(10, 'CHANNEL', NULL, 'PUBLIC', 'sales-do', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-26 00:46:40', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(11, 'CHANNEL', NULL, 'PUBLIC', 'purchases-po', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-26 00:46:40', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(12, 'CHANNEL', NULL, 'PUBLIC', 'purchases-ap', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-26 00:46:40', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(13, 'CHANNEL', NULL, 'PUBLIC', 'stock', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-26 00:46:40', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(14, 'CHANNEL', NULL, 'PUBLIC', 'hrl', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-26 00:46:40', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(15, 'CHANNEL', NULL, 'PUBLIC', 'mpr', NULL, NULL, NULL, NULL, NULL, NULL, '2026-02-26 00:46:40', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(16, 'DM', NULL, 'PUBLIC', 'dm_2_30', NULL, NULL, NULL, NULL, NULL, '2', '2026-02-27 14:14:59', 'none', NULL, 1, 'ADMIN_ONLY', 2, 30),
(17, 'CHANNEL', NULL, 'PUBLIC', 'office-bgr', NULL, NULL, NULL, NULL, NULL, '30', '2026-02-27 14:15:41', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(18, 'CHANNEL', NULL, 'PUBLIC', 'dept-hrl', NULL, NULL, NULL, NULL, NULL, '30', '2026-02-27 14:15:41', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(19, 'CHANNEL', NULL, 'PUBLIC', 'dept-crm', NULL, NULL, NULL, NULL, NULL, '26', '2026-02-27 22:53:24', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(20, 'CHANNEL', NULL, 'PUBLIC', 'dept-fin', NULL, NULL, NULL, NULL, NULL, '28', '2026-03-01 21:44:32', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(21, 'CHANNEL', NULL, 'PUBLIC', 'dept-itc', NULL, NULL, NULL, NULL, NULL, '2', '2026-03-02 16:11:37', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(22, 'CHANNEL', NULL, 'PUBLIC', 'dept-scm', NULL, NULL, NULL, NULL, NULL, '37', '2026-03-02 22:07:08', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(23, 'CHANNEL', NULL, 'PUBLIC', 'dept-branch', NULL, NULL, NULL, NULL, NULL, '168', '2026-03-05 21:11:55', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(24, 'CHANNEL', NULL, 'PUBLIC', 'office-slo', NULL, NULL, NULL, NULL, NULL, '172', '2026-03-06 14:31:37', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(25, 'CHANNEL', NULL, 'PUBLIC', 'office-bdg', NULL, NULL, NULL, NULL, NULL, '167', '2026-03-06 15:19:36', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL),
(26, 'CHANNEL', NULL, 'PUBLIC', 'office-kal', NULL, NULL, NULL, NULL, NULL, '171', '2026-03-08 00:22:02', 'none', NULL, 0, 'ADMIN_ONLY', NULL, NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_channel_acl`
--

DROP TABLE IF EXISTS `chat_channel_acl`;
CREATE TABLE `chat_channel_acl` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel_id` bigint(20) UNSIGNED NOT NULL,
  `subject_type` varchar(20) NOT NULL DEFAULT 'ROLE',
  `subject_key` varchar(120) NOT NULL DEFAULT '',
  `role_code` varchar(40) NOT NULL,
  `can_read` tinyint(1) NOT NULL DEFAULT 1,
  `can_send` tinyint(1) NOT NULL DEFAULT 1,
  `can_pin` tinyint(1) NOT NULL DEFAULT 0,
  `can_manage` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` bigint(20) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_channel_acl`
--

TRUNCATE TABLE `chat_channel_acl`;
--
-- Dumping data untuk tabel `chat_channel_acl`
--

INSERT DELAYED IGNORE INTO `chat_channel_acl` (`id`, `channel_id`, `subject_type`, `subject_key`, `role_code`, `can_read`, `can_send`, `can_pin`, `can_manage`, `updated_by`, `updated_at`) VALUES
(1, 6, 'ROLE', '', 'USER', 1, 1, 0, 0, 2, '2026-02-26 00:22:00'),
(2, 6, 'ROLE', '', 'ADMIN', 1, 1, 1, 1, 2, '2026-02-26 00:22:00'),
(3, 6, 'ROLE', '', 'SUPERADMIN', 1, 1, 1, 1, 2, '2026-02-26 00:22:00'),
(13, 19, 'ROLE', '', 'ADMIN', 1, 1, 1, 1, 2, '2026-03-01 05:23:16'),
(14, 19, 'ROLE', '', 'SUPERADMIN', 1, 1, 1, 1, 2, '2026-03-01 05:23:16'),
(15, 19, 'ROLE', '', 'USER', 1, 1, 0, 0, 2, '2026-03-01 05:23:16');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_channel_members`
--

DROP TABLE IF EXISTS `chat_channel_members`;
CREATE TABLE `chat_channel_members` (
  `channel_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `joined_at` datetime DEFAULT current_timestamp(),
  `last_read_message_id` bigint(20) NOT NULL DEFAULT 0,
  `last_read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_channel_members`
--

TRUNCATE TABLE `chat_channel_members`;
--
-- Dumping data untuk tabel `chat_channel_members`
--

INSERT DELAYED IGNORE INTO `chat_channel_members` (`channel_id`, `user_id`, `username`, `joined_at`, `last_read_message_id`, `last_read_at`) VALUES
(1, 2, 'admin', '2026-02-26 00:03:44', 0, '2026-02-26 00:19:19'),
(2, 2, 'admin', '2026-02-26 00:02:07', 0, '2026-02-26 00:19:19'),
(3, 2, 'admin', '2026-02-26 00:02:07', 0, '2026-02-26 00:19:19'),
(4, 2, 'admin', '2026-02-26 00:03:41', 0, '2026-02-26 00:19:19'),
(6, 2, 'admin', '2026-02-26 00:06:13', 0, '2026-02-26 00:19:19'),
(7, 2, 'admin', '2026-02-26 00:14:00', 5, '2026-02-26 00:42:03'),
(7, 37, 'MgrSCM_BGR', '2026-03-02 22:09:54', 5, '2026-03-02 22:09:59'),
(8, 2, 'admin', '2026-02-26 00:18:43', 2, '2026-02-26 00:34:17'),
(8, 37, 'MgrSCM_BGR', '2026-03-02 22:09:48', 2, '2026-03-02 22:09:48'),
(9, 1, 'superadmin', '2026-02-26 00:22:21', 0, NULL),
(9, 2, 'admin', '2026-02-26 00:22:21', 0, NULL),
(16, 2, 'admin', '2026-02-27 14:14:59', 8, '2026-03-01 11:52:59'),
(16, 30, 'MgrHRL_BGR', '2026-02-27 14:14:59', 7, '2026-02-27 14:28:11'),
(17, 3, 'MgrITC_BGR', '2026-03-08 15:16:22', 0, NULL),
(17, 26, 'MgrCRM_BGR', '2026-02-27 22:53:24', 0, NULL),
(17, 28, 'MgrFIN_BGR', '2026-03-01 21:44:32', 0, NULL),
(17, 30, 'MgrHRL_BGR', '2026-02-27 14:15:41', 0, NULL),
(17, 37, 'MgrSCM_BGR', '2026-03-02 22:07:08', 0, NULL),
(17, 168, 'StaffBRANCH_BGR', '2026-03-05 21:11:55', 0, NULL),
(18, 30, 'MgrHRL_BGR', '2026-02-27 14:15:41', 0, NULL),
(19, 26, 'MgrCRM_BGR', '2026-02-27 22:53:24', 0, NULL),
(20, 28, 'MgrFIN_BGR', '2026-03-01 21:44:32', 0, NULL),
(21, 2, 'admin', '2026-03-02 16:11:37', 0, NULL),
(21, 3, 'MgrITC_BGR', '2026-03-08 15:16:22', 0, NULL),
(22, 2, 'admin', '2026-03-04 20:36:32', 9, '2026-03-04 20:36:32'),
(22, 26, 'MgrCRM_BGR', '2026-03-04 16:26:25', 9, '2026-03-04 16:27:15'),
(22, 37, 'MgrSCM_BGR', '2026-03-02 22:07:08', 0, NULL),
(22, 167, 'StaffBRANCH_BDG', '2026-03-06 15:19:45', 9, '2026-03-06 15:19:49'),
(23, 167, 'StaffBRANCH_BDG', '2026-03-06 15:19:36', 0, NULL),
(23, 168, 'StaffBRANCH_BGR', '2026-03-05 21:11:55', 0, NULL),
(23, 171, 'StaffBRANCH_KAL', '2026-03-08 00:22:02', 0, NULL),
(23, 172, 'StaffBRANCH_SLO', '2026-03-06 14:31:37', 0, NULL),
(24, 172, 'StaffBRANCH_SLO', '2026-03-06 14:31:37', 0, NULL),
(25, 167, 'StaffBRANCH_BDG', '2026-03-06 15:19:36', 0, NULL),
(26, 3, 'MgrITC_BGR', '2026-03-08 15:16:24', 10, '2026-03-08 15:16:24'),
(26, 168, 'StaffBRANCH_BGR', '2026-03-08 11:48:31', 10, '2026-03-08 11:48:34'),
(26, 171, 'StaffBRANCH_KAL', '2026-03-08 00:22:02', 10, '2026-03-08 00:22:53');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_config`
--

DROP TABLE IF EXISTS `chat_config`;
CREATE TABLE `chat_config` (
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_config`
--

TRUNCATE TABLE `chat_config`;
--
-- Dumping data untuk tabel `chat_config`
--

INSERT DELAYED IGNORE INTO `chat_config` (`config_key`, `config_value`, `updated_at`) VALUES
('allowed_attachment_mime', '[\"application/pdf\",\"image/png\",\"image/jpeg\",\"application/vnd.openxmlformats-officedocument.wordprocessingml.document\",\"application/vnd.openxmlformats-officedocument.spreadsheetml.sheet\"]', '2026-02-26 00:01:52'),
('allow_dm_read_receipt', '1', '2026-02-26 00:23:14'),
('allow_message_pin', '1', '2026-02-26 00:23:14'),
('allow_reactions', '1', '2026-02-26 00:23:14'),
('allow_typing_indicator', '1', '2026-02-26 00:23:14'),
('attachment_purge_after_days', '180', '2026-02-26 00:23:14'),
('default_pin_policy', 'ADMIN_ONLY', '2026-02-26 00:23:14'),
('enable_channel_acl', '1', '2026-02-26 00:23:14'),
('max_attachment_size_mb', '10', '2026-02-26 00:23:14'),
('mention_badge_enabled', '1', '2026-02-26 00:23:14'),
('purge_soft_deleted_after_days', '90', '2026-02-26 00:23:14'),
('retention_mode', 'archive', '2026-02-26 00:23:14');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_custom_emojis`
--

DROP TABLE IF EXISTS `chat_custom_emojis`;
CREATE TABLE `chat_custom_emojis` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `emoji_code` varchar(60) NOT NULL,
  `emoji_char` varchar(16) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_custom_emojis`
--

TRUNCATE TABLE `chat_custom_emojis`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_exports`
--

DROP TABLE IF EXISTS `chat_exports`;
CREATE TABLE `chat_exports` (
  `id` bigint(20) NOT NULL,
  `channel_id` bigint(20) NOT NULL,
  `requested_by` varchar(50) NOT NULL,
  `requested_at` datetime DEFAULT current_timestamp(),
  `range_start` datetime NOT NULL,
  `range_end` datetime NOT NULL,
  `format` enum('CSV','JSON') NOT NULL DEFAULT 'CSV',
  `status` enum('READY','FAILED') NOT NULL DEFAULT 'READY',
  `file_path` varchar(255) NOT NULL,
  `sha256` varchar(64) NOT NULL,
  `row_count` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_exports`
--

TRUNCATE TABLE `chat_exports`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_mentions`
--

DROP TABLE IF EXISTS `chat_mentions`;
CREATE TABLE `chat_mentions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `mentioned_user_id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_mentions`
--

TRUNCATE TABLE `chat_mentions`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_messages`
--

DROP TABLE IF EXISTS `chat_messages`;
CREATE TABLE `chat_messages` (
  `id` bigint(20) NOT NULL,
  `channel_id` bigint(20) NOT NULL,
  `sender_user_id` int(11) DEFAULT NULL,
  `sender_username` varchar(50) DEFAULT NULL,
  `reply_to_message_id` bigint(20) DEFAULT NULL,
  `thread_root_message_id` bigint(20) DEFAULT NULL,
  `message_text` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `has_attachments` tinyint(1) DEFAULT 0,
  `has_mentions` tinyint(1) DEFAULT 0,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(50) DEFAULT NULL,
  `deleted_reason` varchar(255) DEFAULT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_messages`
--

TRUNCATE TABLE `chat_messages`;
--
-- Dumping data untuk tabel `chat_messages`
--

INSERT DELAYED IGNORE INTO `chat_messages` (`id`, `channel_id`, `sender_user_id`, `sender_username`, `reply_to_message_id`, `thread_root_message_id`, `message_text`, `created_at`, `has_attachments`, `has_mentions`, `is_deleted`, `deleted_at`, `deleted_by`, `deleted_reason`, `idempotency_key`) VALUES
(1, 8, 2, 'admin', NULL, 1, 'test', '2026-02-26 00:28:00', 0, 0, 0, NULL, NULL, NULL, NULL),
(2, 8, 2, 'admin', NULL, 2, 'y', '2026-02-26 00:28:31', 0, 0, 0, NULL, NULL, NULL, NULL),
(3, 7, 2, 'admin', NULL, 3, 'test', '2026-02-26 00:29:49', 0, 0, 0, NULL, NULL, NULL, NULL),
(4, 7, 2, 'admin', 3, 3, 'ok', '2026-02-26 00:29:56', 0, 0, 0, NULL, NULL, NULL, NULL),
(5, 7, 2, 'admin', NULL, 5, 'test', '2026-02-26 00:33:52', 0, 0, 0, NULL, NULL, NULL, NULL),
(6, 16, 2, 'admin', NULL, 6, 'Test', '2026-02-27 14:15:08', 0, 0, 0, NULL, NULL, NULL, NULL),
(7, 16, 30, 'MgrHRL_BGR', NULL, 7, 'Siap Pak ... gak bisa klik hahahaha', '2026-02-27 14:22:30', 0, 0, 0, NULL, NULL, NULL, NULL),
(8, 16, 2, 'admin', NULL, 8, 'ook', '2026-03-01 05:23:39', 0, 0, 0, NULL, NULL, NULL, NULL),
(9, 22, 26, 'MgrCRM_BGR', NULL, 9, 'testes', '2026-03-04 16:26:25', 0, 0, 0, NULL, NULL, NULL, NULL),
(10, 26, 171, 'StaffBRANCH_KAL', NULL, 10, 'tes', '2026-03-08 00:22:07', 0, 0, 0, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_message_context`
--

DROP TABLE IF EXISTS `chat_message_context`;
CREATE TABLE `chat_message_context` (
  `id` bigint(20) NOT NULL,
  `message_id` bigint(20) NOT NULL,
  `entity_type` varchar(40) NOT NULL,
  `entity_id` varchar(80) DEFAULT NULL,
  `entity_code` varchar(120) DEFAULT NULL,
  `entity_url` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_message_context`
--

TRUNCATE TABLE `chat_message_context`;
--
-- Dumping data untuk tabel `chat_message_context`
--

INSERT DELAYED IGNORE INTO `chat_message_context` (`id`, `message_id`, `entity_type`, `entity_id`, `entity_code`, `entity_url`, `created_at`) VALUES
(1, 10, 'DO', 'LIST', 'DO:LIST', 'https://erp.rizqullahmediska.com/ERP_RMI_SOFULL/chat/index.php?context=DO:LIST', '2026-03-08 00:22:07');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_message_idempotency`
--

DROP TABLE IF EXISTS `chat_message_idempotency`;
CREATE TABLE `chat_message_idempotency` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `channel_id` bigint(20) NOT NULL,
  `idempotency_key` varchar(80) NOT NULL,
  `message_id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_message_idempotency`
--

TRUNCATE TABLE `chat_message_idempotency`;
--
-- Dumping data untuk tabel `chat_message_idempotency`
--

INSERT DELAYED IGNORE INTO `chat_message_idempotency` (`id`, `user_id`, `channel_id`, `idempotency_key`, `message_id`, `created_at`) VALUES
(1, 2, 8, 'e112d623-b95a-459a-b21d-b425ef5af9c2', 1, '2026-02-26 00:28:00'),
(2, 2, 8, '5c1e0e13-450c-4ad8-a343-f9b8cb10e267', 2, '2026-02-26 00:28:31'),
(3, 2, 7, '156f5a9f-e2e1-4043-a238-c979eec2f00c', 3, '2026-02-26 00:29:49'),
(4, 2, 7, '9e7afa75-4970-4026-af7a-09a996862a09', 4, '2026-02-26 00:29:56'),
(5, 2, 7, '88464d81-e726-461d-b7a5-04d0ca0a33c5', 5, '2026-02-26 00:33:52'),
(6, 2, 16, '1772176508286_b916794fbdade8', 6, '2026-02-27 14:15:08'),
(7, 30, 16, 'c6aed8d6-7dbc-4383-b3fe-94a21e339b5d', 7, '2026-02-27 14:22:30'),
(8, 2, 16, '1772317419022_716dc29cc38838', 8, '2026-03-01 05:23:39'),
(9, 26, 22, 'cd3970b5-9a85-4a2b-ad49-8c41aa805ff2', 9, '2026-03-04 16:26:25'),
(10, 171, 26, 'd38da5dc-880a-4d97-b3c7-dea6cf02319c', 10, '2026-03-08 00:22:07');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_message_mentions`
--

DROP TABLE IF EXISTS `chat_message_mentions`;
CREATE TABLE `chat_message_mentions` (
  `message_id` bigint(20) NOT NULL,
  `mentioned_user_id` int(11) NOT NULL,
  `mentioned_username` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_message_mentions`
--

TRUNCATE TABLE `chat_message_mentions`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_message_reactions`
--

DROP TABLE IF EXISTS `chat_message_reactions`;
CREATE TABLE `chat_message_reactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `emoji` varchar(16) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_message_reactions`
--

TRUNCATE TABLE `chat_message_reactions`;
--
-- Dumping data untuk tabel `chat_message_reactions`
--

INSERT DELAYED IGNORE INTO `chat_message_reactions` (`id`, `message_id`, `user_id`, `emoji`, `created_at`) VALUES
(1, 3, 2, '👍', '2026-02-26 00:30:44');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_pins`
--

DROP TABLE IF EXISTS `chat_pins`;
CREATE TABLE `chat_pins` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel_id` bigint(20) UNSIGNED NOT NULL,
  `message_id` bigint(20) UNSIGNED NOT NULL,
  `pinned_by` bigint(20) NOT NULL,
  `pinned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `unpin_by` bigint(20) DEFAULT NULL,
  `unpin_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_pins`
--

TRUNCATE TABLE `chat_pins`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_presence`
--

DROP TABLE IF EXISTS `chat_presence`;
CREATE TABLE `chat_presence` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ONLINE',
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_presence`
--

TRUNCATE TABLE `chat_presence`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_quota_config`
--

DROP TABLE IF EXISTS `chat_quota_config`;
CREATE TABLE `chat_quota_config` (
  `id` tinyint(4) NOT NULL DEFAULT 1,
  `max_attachment_mb_per_user_per_day` int(11) NOT NULL DEFAULT 100,
  `max_attachment_mb_per_channel_per_day` int(11) NOT NULL DEFAULT 500,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_quota_config`
--

TRUNCATE TABLE `chat_quota_config`;
--
-- Dumping data untuk tabel `chat_quota_config`
--

INSERT DELAYED IGNORE INTO `chat_quota_config` (`id`, `max_attachment_mb_per_user_per_day`, `max_attachment_mb_per_channel_per_day`, `updated_at`) VALUES
(1, 100, 500, '2026-02-25 23:20:44');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_quota_usage`
--

DROP TABLE IF EXISTS `chat_quota_usage`;
CREATE TABLE `chat_quota_usage` (
  `id` bigint(20) NOT NULL,
  `usage_date` date NOT NULL,
  `user_id` int(11) NOT NULL,
  `channel_id` bigint(20) NOT NULL,
  `used_bytes` bigint(20) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_quota_usage`
--

TRUNCATE TABLE `chat_quota_usage`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_rate_limits`
--

DROP TABLE IF EXISTS `chat_rate_limits`;
CREATE TABLE `chat_rate_limits` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `ip_address` varchar(64) NOT NULL,
  `window_minute` varchar(16) NOT NULL,
  `send_count` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_rate_limits`
--

TRUNCATE TABLE `chat_rate_limits`;
--
-- Dumping data untuk tabel `chat_rate_limits`
--

INSERT DELAYED IGNORE INTO `chat_rate_limits` (`id`, `user_id`, `ip_address`, `window_minute`, `send_count`, `created_at`, `updated_at`) VALUES
(1, 2, '10.10.60.21', '20260225172140', 1, '2026-02-26 00:21:47', '2026-02-26 00:21:47'),
(2, 2, '10.10.60.21', '20260225172150', 10, '2026-02-26 00:21:50', '2026-02-26 00:21:51'),
(12, 2, '10.10.60.21', '20260225172220', 1, '2026-02-26 00:22:29', '2026-02-26 00:22:29'),
(13, 2, '10.10.60.21', '20260225172420', 1, '2026-02-26 00:24:26', '2026-02-26 00:24:26'),
(14, 2, '10.10.60.21', '20260225172430', 1, '2026-02-26 00:24:36', '2026-02-26 00:24:36'),
(15, 2, '10.10.60.21', '20260225172700', 1, '2026-02-26 00:27:03', '2026-02-26 00:27:03'),
(16, 2, '10.10.60.21', '20260225172710', 5, '2026-02-26 00:27:10', '2026-02-26 00:27:15'),
(21, 2, '10.10.60.21', '20260225172800', 1, '2026-02-26 00:28:00', '2026-02-26 00:28:00'),
(22, 2, '10.10.60.21', '20260225172830', 1, '2026-02-26 00:28:31', '2026-02-26 00:28:31'),
(23, 2, '10.10.60.21', '20260225172940', 1, '2026-02-26 00:29:49', '2026-02-26 00:29:49'),
(24, 2, '10.10.60.21', '20260225172950', 1, '2026-02-26 00:29:56', '2026-02-26 00:29:56'),
(25, 2, '10.10.60.21', '20260225173350', 1, '2026-02-26 00:33:52', '2026-02-26 00:33:52'),
(26, 2, '10.10.60.21', '20260227071500', 1, '2026-02-27 14:15:08', '2026-02-27 14:15:08'),
(27, 30, '172.225.72.66', '20260227072230', 1, '2026-02-27 14:22:30', '2026-02-27 14:22:30'),
(28, 2, '10.10.60.21', '20260228222330', 1, '2026-03-01 05:23:39', '2026-03-01 05:23:39'),
(29, 26, '172.69.166.17', '20260304092620', 1, '2026-03-04 16:26:25', '2026-03-04 16:26:25'),
(30, 171, '172.68.164.147', '20260307172200', 1, '2026-03-08 00:22:07', '2026-03-08 00:22:07');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_typing_status`
--

DROP TABLE IF EXISTS `chat_typing_status`;
CREATE TABLE `chat_typing_status` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_typing_status`
--

TRUNCATE TABLE `chat_typing_status`;
--
-- Dumping data untuk tabel `chat_typing_status`
--

INSERT DELAYED IGNORE INTO `chat_typing_status` (`id`, `channel_id`, `user_id`, `updated_at`) VALUES
(1, 6, 2, '2026-02-26 00:21:46'),
(2, 9, 2, '2026-02-26 00:26:59'),
(5, 8, 2, '2026-02-26 00:28:29'),
(7, 7, 2, '2026-02-26 00:33:50'),
(10, 16, 2, '2026-03-01 05:23:36'),
(11, 16, 30, '2026-02-27 14:22:28'),
(24, 20, 37, '2026-03-02 22:09:08'),
(34, 22, 37, '2026-03-02 22:10:28'),
(38, 22, 26, '2026-03-04 16:26:23'),
(40, 26, 171, '2026-03-08 00:22:06');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_user_channel_prefs`
--

DROP TABLE IF EXISTS `chat_user_channel_prefs`;
CREATE TABLE `chat_user_channel_prefs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `channel_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `is_muted` tinyint(1) NOT NULL DEFAULT 0,
  `notify_level` varchar(20) NOT NULL DEFAULT 'ALL',
  `mute_until` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_user_channel_prefs`
--

TRUNCATE TABLE `chat_user_channel_prefs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_user_channel_settings`
--

DROP TABLE IF EXISTS `chat_user_channel_settings`;
CREATE TABLE `chat_user_channel_settings` (
  `channel_id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `muted_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_user_channel_settings`
--

TRUNCATE TABLE `chat_user_channel_settings`;
--
-- Dumping data untuk tabel `chat_user_channel_settings`
--

INSERT DELAYED IGNORE INTO `chat_user_channel_settings` (`channel_id`, `user_id`, `muted_until`) VALUES
(7, 2, NULL),
(22, 37, '2026-03-02 23:10:44');

-- --------------------------------------------------------

--
-- Struktur dari tabel `chat_user_presence`
--

DROP TABLE IF EXISTS `chat_user_presence`;
CREATE TABLE `chat_user_presence` (
  `user_id` int(11) NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `last_ip` varchar(64) DEFAULT NULL,
  `last_user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `chat_user_presence`
--

TRUNCATE TABLE `chat_user_presence`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `crm_leads`
--

DROP TABLE IF EXISTS `crm_leads`;
CREATE TABLE `crm_leads` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lead_no` varchar(40) NOT NULL,
  `lead_name` varchar(160) NOT NULL,
  `company_name` varchar(160) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `normalized_phone` varchar(40) NOT NULL DEFAULT '',
  `email` varchar(120) DEFAULT NULL,
  `normalized_email` varchar(190) NOT NULL DEFAULT '',
  `source_channel` varchar(40) NOT NULL DEFAULT 'OTHER',
  `city` varchar(80) DEFAULT NULL,
  `estimated_value` decimal(18,2) NOT NULL DEFAULT 0.00,
  `priority` varchar(10) NOT NULL DEFAULT 'MEDIUM',
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `next_followup_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `assigned_to_user_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `crm_leads`
--

TRUNCATE TABLE `crm_leads`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `crm_lead_dedupe_rules`
--

DROP TABLE IF EXISTS `crm_lead_dedupe_rules`;
CREATE TABLE `crm_lead_dedupe_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `rule_name` varchar(80) NOT NULL,
  `on_duplicate` enum('SKIP','MERGE') NOT NULL DEFAULT 'SKIP',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `crm_lead_dedupe_rules`
--

TRUNCATE TABLE `crm_lead_dedupe_rules`;
--
-- Dumping data untuk tabel `crm_lead_dedupe_rules`
--

INSERT DELAYED IGNORE INTO `crm_lead_dedupe_rules` (`id`, `rule_name`, `on_duplicate`, `is_active`, `created_at`) VALUES
(1, 'DEFAULT', 'SKIP', 1, '2026-03-01 13:38:24');

-- --------------------------------------------------------

--
-- Struktur dari tabel `crm_lead_logs`
--

DROP TABLE IF EXISTS `crm_lead_logs`;
CREATE TABLE `crm_lead_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lead_id` bigint(20) UNSIGNED NOT NULL,
  `action` varchar(40) NOT NULL,
  `from_status` varchar(20) DEFAULT NULL,
  `to_status` varchar(20) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_username` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `crm_lead_logs`
--

TRUNCATE TABLE `crm_lead_logs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `customer_portal_users`
--

DROP TABLE IF EXISTS `customer_portal_users`;
CREATE TABLE `customer_portal_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `customers_code` varchar(50) NOT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `master_mpr_id` int(11) DEFAULT NULL COMMENT 'FK master_mpr.id',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `customer_portal_users`
--

TRUNCATE TABLE `customer_portal_users`;
--
-- Dumping data untuk tabel `customer_portal_users`
--

INSERT DELAYED IGNORE INTO `customer_portal_users` (`id`, `username`, `password_hash`, `full_name`, `email`, `phone`, `customers_code`, `office_code`, `master_mpr_id`, `status`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 'hermina_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Hermina Demo', NULL, NULL, 'Hoo1', 'BGR', NULL, 'active', '2026-03-07 20:55:46', '2026-03-07 05:36:38', '2026-03-07 20:55:46');

-- --------------------------------------------------------

--
-- Struktur dari tabel `erp_audit_log`
--

DROP TABLE IF EXISTS `erp_audit_log`;
CREATE TABLE `erp_audit_log` (
  `id` bigint(20) NOT NULL,
  `module` varchar(50) NOT NULL,
  `entity_key` varchar(120) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(120) DEFAULT NULL,
  `role` varchar(60) DEFAULT NULL,
  `level` varchar(60) DEFAULT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `erp_audit_log`
--

TRUNCATE TABLE `erp_audit_log`;
--
-- Dumping data untuk tabel `erp_audit_log`
--

INSERT DELAYED IGNORE INTO `erp_audit_log` (`id`, `module`, `entity_key`, `action`, `user_id`, `username`, `role`, `level`, `ip_address`, `user_agent`, `payload_json`, `created_at`) VALUES
(1, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"test\",\"is_private\":false}', '2026-02-26 00:03:41'),
(2, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"test\",\"is_private\":false}', '2026-02-26 00:03:41'),
(3, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"test\",\"is_private\":false}', '2026-02-26 00:03:41'),
(4, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"test\",\"is_private\":false}', '2026-02-26 00:03:41'),
(5, 'CHAT', 'USER#2', 'CHAT_MARK_ALL_READ', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"actor_username\":\"admin\",\"meta\":{\"updated_channels\":4}}', '2026-02-26 00:03:44'),
(6, 'CHAT', 'CHANNEL#6', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"testy\",\"is_private\":false}', '2026-02-26 00:06:13'),
(7, 'CHAT', 'CHANNEL#6', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"testy\",\"is_private\":false}', '2026-02-26 00:06:13'),
(8, 'CHAT', 'CHANNEL#6', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"testy\",\"is_private\":false}', '2026-02-26 00:06:14'),
(9, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"Test\",\"is_private\":false}', '2026-02-26 00:07:30'),
(10, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"Test\",\"is_private\":false}', '2026-02-26 00:07:30'),
(11, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"Test\",\"is_private\":false}', '2026-02-26 00:07:30'),
(12, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"Test\",\"is_private\":false}', '2026-02-26 00:07:30'),
(13, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"Test\",\"is_private\":false}', '2026-02-26 00:07:31'),
(14, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"Test\",\"is_private\":false}', '2026-02-26 00:07:31'),
(15, 'CHAT', 'CHANNEL#6', 'CHAT_CHANNEL_ACL_UPDATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"actor_username\":\"admin\",\"retention_mode\":\"purge\",\"retention_days\":null}', '2026-02-26 00:08:07'),
(16, 'CHAT', 'CHANNEL#4', 'CHAT_CHANNEL_ACL_UPDATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"actor_username\":\"admin\",\"retention_mode\":\"none\",\"retention_days\":null}', '2026-02-26 00:08:36'),
(17, 'CHAT', 'CHANNEL#1', 'CHAT_CHANNEL_ACL_UPDATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"actor_username\":\"admin\",\"retention_mode\":\"none\",\"retention_days\":null}', '2026-02-26 00:09:34'),
(18, 'CHAT', 'CHANNEL#7', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"yyy\",\"is_private\":false}', '2026-02-26 00:14:00'),
(19, 'CHAT', 'CHANNEL#4', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"test\",\"is_private\":false}', '2026-02-26 00:18:12'),
(20, 'CHAT', 'CHANNEL#8', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"tteeettt\",\"is_private\":false}', '2026-02-26 00:18:43'),
(21, 'CHAT', 'CHANNEL#8', 'CHANNEL_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"name\":\"tteeettt\",\"is_private\":false}', '2026-02-26 00:18:44'),
(22, 'CHAT', 'CHANNEL#8', 'CHAT_CHANNEL_ACL_UPDATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"actor_username\":\"admin\",\"retention_mode\":\"purge\",\"retention_days\":null}', '2026-02-26 00:19:03'),
(23, 'CHAT', 'USER#2', 'CHAT_MARK_ALL_READ', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"actor_username\":\"admin\",\"meta\":{\"updated_channels\":7}}', '2026-02-26 00:19:19'),
(24, 'CHAT', 'CHANNEL#6', 'CHAT_PIN_POLICY_UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"policy\":\"ADMIN_ONLY\",\"user_id\":2}', '2026-02-26 00:21:55'),
(25, 'CHAT', 'CHANNEL#6', 'CHAT_ACL_UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"updated_by\":2,\"items\":3}', '2026-02-26 00:22:00'),
(26, 'CHAT', 'CHANNEL#9', 'DM_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"by\":2,\"target_user_id\":1}', '2026-02-26 00:22:22'),
(27, 'CHAT', 'CONFIG', 'CHAT_ADMIN_SETTINGS_UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"updated_by\":2,\"keys\":[\"allow_reactions\",\"allow_typing_indicator\",\"allow_message_pin\",\"allow_dm_read_receipt\",\"enable_channel_acl\",\"mention_badge_enabled\",\"default_pin_policy\",\"retention_mode\",\"purge_soft_deleted_after_days\",\"attachment_purge_after_days\",\"max_attachment_size_mb\"]}', '2026-02-26 00:23:14'),
(28, 'CHAT', 'CHANNEL#7', 'CHAT_PIN_POLICY_UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"policy\":\"ADMIN_ONLY\",\"user_id\":2}', '2026-02-26 00:23:43'),
(29, 'CHAT', 'MSG#1', 'MESSAGE_SENT', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"channel_id\":8,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"admin\"}', '2026-02-26 00:28:00'),
(30, 'CHAT', 'MSG#2', 'MESSAGE_SENT', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"channel_id\":8,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"admin\"}', '2026-02-26 00:28:31'),
(31, 'CHAT', 'MSG#3', 'MESSAGE_SENT', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"channel_id\":7,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"admin\"}', '2026-02-26 00:29:49'),
(32, 'CHAT', 'MSG#4', 'MESSAGE_SENT', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"channel_id\":7,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"admin\"}', '2026-02-26 00:29:56'),
(33, 'CHAT', 'MSG#3', 'CHAT_REACTION_TOGGLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"emoji\":\"👍\",\"on\":true,\"user_id\":2}', '2026-02-26 00:30:44'),
(34, 'CHAT', 'MSG#5', 'MESSAGE_SENT', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"channel_id\":7,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"admin\"}', '2026-02-26 00:33:52'),
(35, 'CHAT', 'CHANNEL#7', 'CHAT_MUTE_SET', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"duration\":3600,\"actor_username\":\"admin\"}', '2026-02-26 00:35:22'),
(36, 'CHAT', 'CHANNEL#7', 'CHAT_MUTE_CLEAR', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"actor_username\":\"admin\"}', '2026-02-26 00:35:25'),
(37, 'TOOLS_BACKUP', 'BACKUP#before_cutover_280226', 'BACKUP_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"571427c0cb66e1ee441b8056\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"exit_code\":99,\"label\":\"before_cutover_280226\",\"description\":\"Backup created from Backup Manager\"}', '2026-02-26 07:16:45'),
(38, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"ef93a41e964cda4eb72a7125\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 07:18:00'),
(39, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"c9e8c7965bd411bd0eae17b7\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":2,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-02-26 07:19:46'),
(40, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"bfaa25db953f34662280d97e\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"duration_ms\":null,\"output_lines\":3,\"description\":\"Run test now from scheduler UI\"}', '2026-02-26 07:19:48'),
(41, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_DISABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"dea53df66b1a65f0a468ed19\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Disable scheduler 23:00 WIB\"}', '2026-02-26 07:19:50'),
(42, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"3c71395290eec33dab394c30\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"duration_ms\":null,\"output_lines\":3,\"description\":\"Run test now from scheduler UI\"}', '2026-02-26 07:19:52'),
(43, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"8a249f33402695b2ec4674f9\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 19:17:35'),
(44, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"a0650548a4dccba77d6c4726\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-02-26 19:17:38'),
(45, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"f942c337ae6b55668eeeae2e\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"duration_ms\":null,\"output_lines\":4,\"description\":\"Run test now from scheduler UI\"}', '2026-02-26 19:17:50'),
(46, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"80f43efb2bc969e99f913d84\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 19:21:10'),
(47, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"113b1ba004b70081eb0d77ec\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-02-26 19:21:13'),
(48, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"b694ee56121d3de228c19e26\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 19:24:50'),
(49, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"b694ee56121d3de228c19e26\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-02-26 19:24:58'),
(50, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"bca404b46a991758fcba4603\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 19:25:07'),
(51, 'TOOLS_BACKUP', 'RUNTIME_CONFIG#backup_runtime.env', 'BACKUP_RUNTIME_CONFIG_SAVED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"ba28264c6fe573c637534838\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"has_mysqldump_bin\":false,\"has_mysql_bin\":false,\"db_host\":\"127.0.0.1\",\"db_port\":\"3306\",\"db_name\":\"erp_rmi_sofull\",\"db_user\":\"root\",\"description\":\"Save runtime binaries/db config\"}', '2026-02-26 19:26:06'),
(52, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"3c054dc68f711be31f385365\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 19:26:16'),
(53, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"c5f35a045a46f08495717595\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"duration_ms\":null,\"output_lines\":4,\"description\":\"Run test now from scheduler UI\"}', '2026-02-26 19:26:39'),
(54, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"5ddb071a77ae8254f583a661\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 19:31:32'),
(55, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"5ddb071a77ae8254f583a661\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-02-26 19:31:40'),
(56, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"abada8999fdb5b380ec44a4f\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 19:31:47'),
(57, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"fa250749fe0ab9d1f0061b00\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"duration_ms\":null,\"output_lines\":4,\"description\":\"Run test now from scheduler UI\"}', '2026-02-26 19:31:58'),
(58, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"73e0147378d826ce0e005e71\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 19:31:59'),
(59, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"73e0147378d826ce0e005e71\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-02-26 19:32:07'),
(60, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"76b8c59d8c22cc247159b6ad\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"duration_ms\":null,\"output_lines\":4,\"description\":\"Run test now from scheduler UI\"}', '2026-02-26 21:05:13'),
(61, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"d651f76b668ff00b74a0ed80\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 21:25:14'),
(62, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"d651f76b668ff00b74a0ed80\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-02-26 21:25:22'),
(63, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"47f878ad5f14c4b4404b0e89\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-26 21:27:48'),
(64, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"47f878ad5f14c4b4404b0e89\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":true,\"total_checks\":18,\"failed_checks\":0,\"description\":\"Run UAT smoke checklist\"}', '2026-02-26 21:27:57'),
(65, 'CHAT', 'CHANNEL#16', 'DM_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"by\":2,\"target_user_id\":30}', '2026-02-27 14:14:59'),
(66, 'CHAT', 'MSG#6', 'MESSAGE_SENT', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"channel_id\":16,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"admin\"}', '2026-02-27 14:15:08'),
(67, 'CHAT', 'MSG#7', 'MESSAGE_SENT', 30, 'MgrHRL_BGR', 'MANAGER', 'MANAGER', '172.225.72.66', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1', '{\"channel_id\":16,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"MgrHRL_BGR\"}', '2026-02-27 14:22:30'),
(68, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"fb498f2d04637e4a45044ad3\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-28 14:55:06'),
(69, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"9cdd2567d28ada61c5dad938\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-02-28 14:55:09'),
(70, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"b3c59495461ef37fdb1b678d\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-28 16:35:16'),
(71, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"b3c59495461ef37fdb1b678d\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-02-28 16:35:17'),
(72, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"fb5266daf29c1273b0c81db1\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-28 16:38:36'),
(73, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"fb5266daf29c1273b0c81db1\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":true,\"total_checks\":18,\"failed_checks\":0,\"description\":\"Run UAT smoke checklist\"}', '2026-02-28 16:38:47'),
(74, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"f41f4997513a458e94828bb7\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-28 16:48:12'),
(75, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"501e9a201571d93e02d88faa\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-28 16:48:16'),
(76, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"501e9a201571d93e02d88faa\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-02-28 16:48:16'),
(77, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"5ffa27a0babb64a20c9a3637\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":18,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-02-28 16:48:21'),
(78, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"e7abb12a6d34cc292d2ff3d4\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-28 16:48:45'),
(79, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"aed81decd88827a043c26e25\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-02-28 16:48:55'),
(80, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"5e8956bc546e70771b28ebb5\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-28 16:51:26'),
(81, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"5e8956bc546e70771b28ebb5\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":true,\"total_checks\":18,\"failed_checks\":0,\"description\":\"Run UAT smoke checklist\"}', '2026-02-28 16:51:34'),
(82, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"8bd6f20a568bae2ecc4c3d36\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-02-28 16:51:44'),
(83, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"8bd6f20a568bae2ecc4c3d36\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"all_pass\":true,\"total_checks\":18,\"failed_checks\":0,\"description\":\"Run UAT smoke checklist\"}', '2026-02-28 16:51:52'),
(84, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"c6ef84e4fa1499fe5f18e8a0\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-01 01:43:02'),
(85, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"c3c7b239e7ad81a3a94a10c9\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"duration_ms\":null,\"output_lines\":11,\"description\":\"Run test now from scheduler UI\"}', '2026-03-01 01:43:29'),
(86, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"345266252b2280557a8b0aca\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"duration_ms\":null,\"output_lines\":11,\"description\":\"Run test now from scheduler UI\"}', '2026-03-01 02:21:29'),
(87, 'HRL_COMPLIANCE', 'REG_ALKES#export', 'COMPLIANCE_EXPORT_REG_ALKES', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"979ef5c9025ff72fede7d73b\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"row_count\":0,\"format\":\"csv\",\"range_from\":null,\"range_to\":null,\"description\":\"Export compliance\"}', '2026-03-01 03:04:34'),
(88, 'HRL_COMPLIANCE', 'REG_ALKES#export', 'COMPLIANCE_EXPORT_REG_ALKES', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"f88507484c83f12c14dc969c\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"row_count\":0,\"format\":\"csv\",\"range_from\":null,\"range_to\":null,\"description\":\"Export compliance\"}', '2026-03-01 03:04:56'),
(89, 'CHAT', 'CHANNEL#19', 'CHAT_ACL_UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"updated_by\":2,\"items\":3}', '2026-03-01 05:20:27'),
(90, 'CHAT', 'CHANNEL#19', 'CHAT_ACL_UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"updated_by\":2,\"items\":3}', '2026-03-01 05:20:31'),
(91, 'CHAT', 'CHANNEL#19', 'CHAT_ACL_UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"updated_by\":2,\"items\":3}', '2026-03-01 05:21:32'),
(92, 'CHAT', 'CHANNEL#19', 'CHAT_ACL_UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"updated_by\":2,\"items\":3}', '2026-03-01 05:23:16'),
(93, 'CHAT', 'CHANNEL#19', 'CHAT_PIN_POLICY_UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"policy\":\"ADMIN_ONLY\",\"user_id\":2}', '2026-03-01 05:23:21'),
(94, 'CHAT', 'MSG#8', 'MESSAGE_SENT', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"channel_id\":16,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"admin\"}', '2026-03-01 05:23:39'),
(95, 'MFA_BYPASS', 'BYPASS#1', 'CREATE_REQUEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"user_id\":2,\"expires_at\":\"2026-03-01 14:48:22\",\"reason\":\"Test\",\"requested_by\":2}', '2026-03-01 13:48:32'),
(96, 'MFA_POLICY', 'POL#3', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"ADMIN\",\"dept\":\"*\",\"require\":0,\"active\":0}', '2026-03-01 13:49:19'),
(97, 'MFA_POLICY', 'POL#1', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"OWNER\",\"dept\":\"*\",\"require\":0,\"active\":0}', '2026-03-01 13:49:28'),
(98, 'MFA_POLICY', 'PHASE1', 'APPLY_PHASE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"phase\":\"phase1\",\"by\":2}', '2026-03-01 13:49:56'),
(99, 'MFA_POLICY', 'POL#5', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"MANAGER\",\"dept\":\"*\",\"require\":0,\"active\":0}', '2026-03-01 16:49:41'),
(100, 'MFA_POLICY', 'POL#1', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"OWNER\",\"dept\":\"*\",\"require\":0,\"active\":0}', '2026-03-01 16:49:51'),
(101, 'MFA_POLICY', 'POL#3', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"ADMIN\",\"dept\":\"*\",\"require\":0,\"active\":0}', '2026-03-01 16:49:58'),
(102, 'MFA_POLICY', 'POL#7', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"STAFF\",\"dept\":\"ACT\",\"require\":0,\"active\":0}', '2026-03-01 16:50:09'),
(103, 'MFA_POLICY', 'POL#4', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"STAFF\",\"dept\":\"BRANCH\",\"require\":0,\"active\":1}', '2026-03-01 16:50:15'),
(104, 'MFA_POLICY', 'POL#6', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"STAFF\",\"dept\":\"FIN\",\"require\":0,\"active\":0}', '2026-03-01 16:50:21'),
(105, 'MFA_POLICY', 'POL#2', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"SUPERADMIN\",\"dept\":\"*\",\"require\":0,\"active\":1}', '2026-03-01 16:50:29'),
(106, 'MFA_POLICY', 'POL#2', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"SUPERADMIN\",\"dept\":\"*\",\"require\":0,\"active\":1}', '2026-03-01 17:15:22'),
(107, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"dbf5379b9fa733c2ad20edc1\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 00:16:07'),
(108, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7448348a56f9b7e6a398275c\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 00:16:40'),
(109, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"e7b0bd0076c3dc8c6abbe79b\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 00:32:52'),
(110, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"d7dba28f66a64c20d8945423\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 00:33:25'),
(111, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"87dbebb9720f0290190f6d04\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 00:48:31'),
(112, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"367eb75a2b9bc82bea62dc04\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 00:49:03'),
(113, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"5871078b9657be4b9517110d\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 00:53:08'),
(114, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"12d8bbaa3727bcd23a3a3f5e\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 00:53:41'),
(115, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"0cc8b15263b89d47e480a2a8\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 00:56:07'),
(116, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"8cb2758eeb1cdab5d4632c29\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 00:56:39'),
(117, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"ab3b02a365a95bb44308dc06\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 01:16:19'),
(118, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"812294be1001bd3d35ae7c32\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 01:16:51'),
(119, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"6fea9cde4e5e509eadf6e89a\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 01:16:54'),
(120, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"b7484574e2d79318708b4027\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 01:17:27'),
(121, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"02147a6cbffde92d108e15a1\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 01:21:13');
INSERT DELAYED IGNORE INTO `erp_audit_log` (`id`, `module`, `entity_key`, `action`, `user_id`, `username`, `role`, `level`, `ip_address`, `user_agent`, `payload_json`, `created_at`) VALUES
(122, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"03acdd48a1a980246bbb4a00\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 01:21:45'),
(123, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"d1e3babacbaff1c7c8a6aa13\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 01:47:24'),
(124, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"dee8fee953f7eb7980f485b6\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 01:47:57'),
(125, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"153c1e8b48312e48666ff7ab\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 01:51:51'),
(126, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"65e95a6f84bf51390a7e281c\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 01:52:25'),
(127, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"267d7693a262e2b8ff00f3a3\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 01:54:22'),
(128, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"601d5d48569f69ca32517ac6\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 02:39:20'),
(129, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"fb89d64a8d0660d672b67ac8\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 02:39:53'),
(130, 'RELEASE', 'release_verify_all#release-verify-all-20260302024003-660b787b', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260302024003-660b787b\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":false,\"summary\":{\"bundle_total\":7,\"bundle_ok\":0,\"bundle_warn\":0,\"bundle_fail\":7,\"pack_total\":7,\"pack_ok\":0,\"pack_warn\":0,\"pack_fail\":7},\"description\":\"Release verify all executed\"}', '2026-03-02 02:40:03'),
(131, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"142eaa5b3e6881e5237b7fac\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 02:45:14'),
(132, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7d9fb779c748ce98c17b2a54\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 02:45:46'),
(133, 'RELEASE', 'release_verify_all#release-verify-all-20260302024557-d56d1230', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260302024557-d56d1230\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":0,\"bundle_warn\":7,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":0,\"pack_warn\":7,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-02 02:45:57'),
(134, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"b9d4bb79020510c1cc7ac1de\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 02:50:54'),
(135, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"636a19c503520edbe872e7cd\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 02:51:26'),
(136, 'RELEASE', 'release_verify_all#release-verify-all-20260302025137-fcf60903', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260302025137-fcf60903\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":0,\"bundle_warn\":7,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":0,\"pack_warn\":7,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-02 02:51:37'),
(137, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"9c3caaa84679dd92f59fc668\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 02:58:47'),
(138, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"2f82b18cba131c929580ba68\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 02:59:19'),
(139, 'RELEASE', 'release_verify_all#release-verify-all-20260302025929-5ed13f0b', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260302025929-5ed13f0b\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":0,\"bundle_warn\":7,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":0,\"pack_warn\":7,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-02 02:59:29'),
(140, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"c8ed73bb485808ed626743ee\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 03:08:33'),
(141, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"65cd2b6ab3d7a459c7684fa8\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 03:09:06'),
(142, 'RELEASE', 'release_verify_all#release-verify-all-20260302030917-6f8f2ce9', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260302030917-6f8f2ce9\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":0,\"bundle_warn\":7,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":0,\"pack_warn\":7,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-02 03:09:17'),
(143, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"d1423ba5c7ecb1c6ef80d842\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 03:18:16'),
(144, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"bc74cce3c3156dad8ed8ff41\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 03:18:48'),
(145, 'RELEASE', 'release_verify_all#release-verify-all-20260302031859-7b802113', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260302031859-7b802113\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":0,\"bundle_warn\":7,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":0,\"pack_warn\":7,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-02 03:18:59'),
(146, 'RELEASE', 'release_verify_all#release-verify-all-20260302035851-86bd8a95', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260302035851-86bd8a95\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-02 03:58:51'),
(147, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"d557a3593b523f0a83324de4\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 04:36:33'),
(148, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"09184f19a7250b8d4a150932\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 04:37:05'),
(149, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"e53fd4ec06708076ae8f19c0\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 04:39:51'),
(150, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"69b1be82ec9421a17a357a48\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 04:40:25'),
(151, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"e0e6aefc8d8e7fa6b487b186\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 04:41:06'),
(152, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"d0055aa4abeb3b820fc03ccc\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 04:41:39'),
(153, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"2f93fb158fd03200aeaf5ea6\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 04:42:28'),
(154, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"9175600bbd64e81c27560292\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 04:43:00'),
(155, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"525165bd283ea85d70e57f6e\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 04:43:43'),
(156, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"38daceb577b2d9716de20e10\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-02 04:44:16'),
(157, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"e89a4cbbcdb7e8b6750d58ac\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-02 05:09:44'),
(158, 'TOOLS_BACKUP', 'RUNTIME_CONFIG#backup_runtime.env', 'BACKUP_RUNTIME_CONFIG_SAVED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"bdf4bcde238772c898ce1863\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"has_mysqldump_bin\":false,\"has_mysql_bin\":false,\"db_host\":\"10.10.60.20\",\"db_port\":\"3306\",\"db_name\":\"erp_rmi_sofull\",\"db_user\":\"root\",\"description\":\"Save runtime binaries/db config\"}', '2026-03-02 05:10:16'),
(159, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"db3653e59569ad4732013fb1\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"duration_ms\":null,\"output_lines\":11,\"description\":\"Run test now from scheduler UI\"}', '2026-03-02 05:10:50'),
(160, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"de6304980d8dc9c904ded075\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-02 05:11:55'),
(161, 'CHAT', 'MSG#3', 'CHAT_REACTION_TOGGLE', 37, 'MgrSCM_BGR', 'MANAGER', 'MANAGER', '162.158.162.150', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '{\"emoji\":\"👍\",\"on\":true,\"user_id\":37}', '2026-03-02 22:09:57'),
(162, 'CHAT', 'MSG#3', 'CHAT_REACTION_TOGGLE', 37, 'MgrSCM_BGR', 'MANAGER', 'MANAGER', '162.158.162.151', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '{\"emoji\":\"👍\",\"on\":false,\"user_id\":37}', '2026-03-02 22:09:59'),
(163, 'CHAT', 'CHANNEL#22', 'CHAT_MUTE_SET', 37, 'MgrSCM_BGR', 'MANAGER', 'MANAGER', '162.158.162.151', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '{\"duration\":3600,\"actor_username\":\"MgrSCM_BGR\"}', '2026-03-02 22:10:44'),
(164, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '127.0.0.1', NULL, '{\"request_id\":\"3ccef3c614c057a508e1daa6\",\"actor_username\":\"smoke_admin\",\"ip\":\"127.0.0.1\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 01:10:52'),
(165, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '127.0.0.1', NULL, '{\"request_id\":\"12611746bc07db01534aa194\",\"actor_username\":\"smoke_admin\",\"ip\":\"127.0.0.1\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 01:11:26'),
(166, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '127.0.0.1', NULL, '{\"request_id\":\"5f8d7188dd22b6022b2838ed\",\"actor_username\":\"smoke_admin\",\"ip\":\"127.0.0.1\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 01:12:29'),
(167, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '127.0.0.1', NULL, '{\"request_id\":\"7d38ba6d2187b49575eda613\",\"actor_username\":\"smoke_admin\",\"ip\":\"127.0.0.1\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 01:13:01'),
(168, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '127.0.0.1', NULL, '{\"request_id\":\"5db2d9685a720597609e32fa\",\"actor_username\":\"smoke_admin\",\"ip\":\"127.0.0.1\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 01:15:46'),
(169, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '127.0.0.1', NULL, '{\"request_id\":\"f3d92aec865874d6220af430\",\"actor_username\":\"smoke_admin\",\"ip\":\"127.0.0.1\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 01:16:18'),
(170, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"89d6d2a5157d1924677d7ff6\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 01:28:01'),
(171, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"fe521734a8d7495263ca5317\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"duration_ms\":null,\"output_lines\":11,\"description\":\"Run test now from scheduler UI\"}', '2026-03-03 01:28:42'),
(172, 'RELEASE', 'release_verify_all#release-verify-all-20260303013007-417ea18b', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260303013007-417ea18b\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-03 01:30:07'),
(173, 'RELEASE', 'release_verify_all#release-verify-all-20260303013010-7ca46944', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260303013010-7ca46944\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"full\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-03 01:30:10'),
(174, 'RELEASE', 'release_verify_all#release-verify-all-20260303110651-a66374cb', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260303110651-a66374cb\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"full\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-03 11:06:51'),
(175, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"e7bc4fbaf105222f2a5bf50b\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 11:10:11'),
(176, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"a6c377a16845f58b10948105\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 11:10:49'),
(177, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7aef072897f190c0db573c64\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 11:22:57'),
(178, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"710360da337bde0662e260ed\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 11:23:29'),
(179, 'RELEASE', 'release_verify_all#release-verify-all-20260303112340-50d59f57', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260303112340-50d59f57\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-03 11:23:40'),
(180, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"78770b6251609acc125fc0f8\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 15:48:17'),
(181, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"dcf3dcdfc42e54431ad541ee\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 15:48:50'),
(182, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"0ea8a023cdf50d56672e421b\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 15:50:24'),
(183, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"5a5b7130b3b02639e74093bc\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 15:50:57'),
(184, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"3ad3771959e56a4d0933c075\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 15:51:00'),
(185, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"8cf236020e1400382e6c1ce7\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 15:51:32'),
(186, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"abc752966061214242f3da2b\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 18:51:11'),
(187, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"e5fe1e4df66132fea9d117e5\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 18:51:43'),
(188, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"eaee21787edffb1f08ccd87e\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 19:11:04'),
(189, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"a0e040f8e1acc6b704080132\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 19:11:36'),
(190, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"d283b21d786401c8fd8b1c2c\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 19:12:37'),
(191, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"530640a4aa28e17216ee889e\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 19:13:09'),
(192, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"bb401d66b5e707680730d0e8\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 19:13:12'),
(193, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"bcfa7c70af80c859ea6c6e81\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 19:13:44'),
(194, 'MFA_POLICY', 'POL#2', 'UPDATE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"role\":\"SUPERADMIN\",\"dept\":\"SYS\",\"require\":0,\"active\":1}', '2026-03-03 21:29:33'),
(195, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"77996c7442a44334c65322d0\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-03 21:50:53'),
(196, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"8a85ae23ce00a1931dd317da\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-03 21:51:26'),
(197, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"c39a6cf207a42d61d5dec370\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 05:18:38'),
(198, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"d55b58c69e7fd2394fd7f1cf\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 05:19:19'),
(199, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"6bbb1a59b411052bc6b223c8\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 05:20:05'),
(200, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"ce5593ba1860f5f1186c9c32\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 05:20:45'),
(201, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"033d1dc2f9039eb443e2a656\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 05:20:56'),
(202, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7f234995a6000c93a35fe02d\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 05:21:34'),
(203, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"6033275bfa52194900b913c4\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 05:25:00'),
(204, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 175, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"319f8afd5d3417dd69faf69b\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 05:25:36'),
(205, 'ACCOUNT_READINESS', 'USER#176', 'SET_INACTIVE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"by\":2}', '2026-03-04 05:36:21'),
(206, 'ACCOUNT_READINESS', 'USER#175', 'SET_INACTIVE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"by\":2}', '2026-03-04 05:36:23'),
(207, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"e0e333e990eaea15625a4393\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 05:56:28'),
(208, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7fa130f298a6a47a51ea286a\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 05:57:05'),
(209, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"aff53c93b5c0e40acd4932b9\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 06:05:57'),
(210, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"f23a4368d411e8cb9aaa3295\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 06:06:30'),
(211, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"72b3549dfe9305a254aad02e\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 14:20:24'),
(212, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"2e00c3353fa3ead7f1e5c217\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 14:21:01'),
(213, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"c2ec91eb3ef487b9d54537ae\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 14:37:57'),
(214, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"fe702f58d7e160d73d163f73\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 14:38:32'),
(215, 'WQS_STOCK', 'PRODUCT#3', 'SET_STOCK', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"qty\":10,\"source\":\"BASELINE_MANUAL\"}', '2026-03-04 14:49:16'),
(216, 'WQS_STOCK', 'PRODUCT#1', 'SET_STOCK', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"qty\":10,\"source\":\"BASELINE_MANUAL\"}', '2026-03-04 14:49:24'),
(217, 'WQS_STOCK', 'PRODUCT#2', 'SET_STOCK', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"qty\":10,\"source\":\"BASELINE_MANUAL\"}', '2026-03-04 14:49:30'),
(218, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"ca7fc973b7d5487b489497e8\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 16:11:27'),
(219, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"a5a4c9916b309be7fe3ae1b9\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 16:12:05'),
(220, 'CHAT', 'MSG#9', 'MESSAGE_SENT', 26, 'MgrCRM_BGR', 'MANAGER', 'MANAGER', '172.69.166.17', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"channel_id\":22,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"MgrCRM_BGR\"}', '2026-03-04 16:26:25'),
(221, 'WQS_STOCK', 'PRODUCT#3', 'SET_STOCK', 27, 'StaffCRM_BGR', 'STAFF', 'STAFF', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"qty\":0,\"source\":\"BASELINE_MANUAL\"}', '2026-03-04 17:14:39'),
(222, 'RELEASE', 'release_verify_all#release-verify-all-20260304174650-84d09674', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260304174650-84d09674\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-04 17:46:50'),
(223, 'RELEASE', 'release_verify_all#release-verify-all-20260304174652-f5973e19', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260304174652-f5973e19\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"full\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-04 17:46:52'),
(224, 'RELEASE', 'release_verify_all#release-verify-all-20260304174700-2e1a5a3c', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260304174700-2e1a5a3c\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"full\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-04 17:47:00'),
(225, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"9a89b43551b248cfbb1dae72\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 17:52:28'),
(226, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"d6c5ceb4980b21726bb976e6\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"duration_ms\":null,\"output_lines\":11,\"description\":\"Run test now from scheduler UI\"}', '2026-03-04 17:53:21'),
(227, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"308bf76cbb4dc7305df04526\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-04 17:53:28'),
(228, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"8984e02094c9ae8a483fe5ac\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-04 17:53:44'),
(229, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"f3261a6e12fcf7d92137db52\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 17:53:47'),
(230, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"e2ca219e5c107d4158aed3a7\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-04 17:54:53'),
(231, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"ca049d8e7b8e507f2cd13ea4\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-04 17:55:38'),
(232, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"6eb493c672298e3efc107014\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-04 17:57:04'),
(233, 'TOOLS_BACKUP', 'RUNTIME_CONFIG#backup_runtime.env', 'BACKUP_RUNTIME_CONFIG_SAVED', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"889370ac34e640819557c235\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"has_mysqldump_bin\":false,\"has_mysql_bin\":false,\"db_host\":\"10.10.60.20\",\"db_port\":\"3306\",\"db_name\":\"erp_rmi_sofull\",\"db_user\":\"root\",\"description\":\"Save runtime binaries/db config\"}', '2026-03-04 17:57:52'),
(234, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"0e5094906e38d699bd1f5653\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-04 17:58:07'),
(235, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"a2963413ef38f5ae1678b085\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-04 18:03:19'),
(236, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"c7e04a25987ce9cbae5778f4\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":1,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-04 18:03:21'),
(237, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"bd73d17c6bd1af608aae23ee\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"duration_ms\":null,\"output_lines\":11,\"description\":\"Run test now from scheduler UI\"}', '2026-03-04 18:05:18'),
(238, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"237b677e1c778dc271dfcc43\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"duration_ms\":null,\"output_lines\":11,\"description\":\"Run test now from scheduler UI\"}', '2026-03-04 18:05:54'),
(239, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"e5be694952656b728840371a\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 20:29:13');
INSERT DELAYED IGNORE INTO `erp_audit_log` (`id`, `module`, `entity_key`, `action`, `user_id`, `username`, `role`, `level`, `ip_address`, `user_agent`, `payload_json`, `created_at`) VALUES
(240, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"request_id\":\"5d8d442a3e00a0d6054ffecd\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 20:29:18'),
(241, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"aa63cb85e617410ed188b123\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-04 22:28:25'),
(242, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"697ba2fc2dfcbeba664c3429\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-04 22:29:02'),
(243, 'ACCOUNT_READINESS', 'USER#178', 'SET_INACTIVE', 2, 'admin', 'ADMIN', 'ADMIN', '104.23.175.179', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"by\":2}', '2026-03-04 22:49:22'),
(244, 'ACCOUNT_READINESS', 'USER#177', 'SET_INACTIVE', 2, 'admin', 'ADMIN', 'ADMIN', '104.23.175.179', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Safari/605.1.15', '{\"by\":2}', '2026-03-04 22:49:25'),
(245, 'TAX_INVOICE', 'TAX#1', 'CREATE_DRAFT', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"sales_ref\":\"RMI-BGR-260306-001\",\"tax_no\":\"TAX-202603-00001\"}', '2026-03-06 16:49:06'),
(246, 'TAX_INVOICE', 'TAX#1', 'UPLOAD_DOC', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.162.150', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"path\":\"/uploads/tax_invoices/tax_20260306_165438_1b3cb5.pdf\"}', '2026-03-06 16:54:38'),
(247, 'TAX_INVOICE', 'TAX#1', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.162.151', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"DRAFT\"}', '2026-03-06 16:56:11'),
(248, 'TAX_INVOICE', 'TAX#1', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.162.151', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 16:56:37'),
(249, 'TAX_INVOICE', 'TAX#1', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.162.151', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 16:57:05'),
(250, 'TAX_INVOICE', 'TAX#1', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.162.151', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"DRAFT\"}', '2026-03-06 16:57:53'),
(251, 'TAX_INVOICE', 'TAX#1', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.162.151', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 16:57:55'),
(252, 'TAX_INVOICE', 'TAX#1', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.162.151', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"REVISED\"}', '2026-03-06 16:57:58'),
(253, 'TAX_INVOICE', 'TAX#2', 'CREATE_DRAFT', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.88.132', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"sales_ref\":\"RMI-BGR-260306-001\",\"tax_no\":\"TAX-202603-00002\"}', '2026-03-06 17:00:27'),
(254, 'TAX_INVOICE', 'TAX#3', 'CREATE_DRAFT', 2, 'admin', 'ADMIN', 'ADMIN', '172.69.176.140', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"sales_ref\":\"RMI-BGR-260306-001\",\"tax_no\":\"TAX-202603-00003\"}', '2026-03-06 17:01:39'),
(255, 'TAX_INVOICE', 'TAX#4', 'CREATE_DRAFT', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"sales_ref\":\"RMI-BGR-260306-001\",\"tax_no\":\"TAX-202603-00004\"}', '2026-03-06 17:45:56'),
(256, 'TAX_INVOICE', 'TAX#1', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"status\":\"CANCELLED\"}', '2026-03-06 17:46:12'),
(257, 'TAX_INVOICE', 'TAX#2', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"status\":\"CANCELLED\"}', '2026-03-06 17:46:16'),
(258, 'TAX_INVOICE', 'TAX#3', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"status\":\"CANCELLED\"}', '2026-03-06 17:46:17'),
(259, 'TAX_INVOICE', 'TAX#4', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"status\":\"REVISED\"}', '2026-03-06 17:46:24'),
(260, 'TAX_INVOICE', 'TAX#4', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"status\":\"ISSUED\"}', '2026-03-06 17:46:27'),
(261, 'TAX_INVOICE', 'TAX#4', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"status\":\"REVISED\"}', '2026-03-06 17:46:29'),
(262, 'TAX_INVOICE', 'TAX#4', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"status\":\"REVISED\"}', '2026-03-06 17:54:02'),
(263, 'TAX_INVOICE', 'TAX#1', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"status\":\"ISSUED\"}', '2026-03-06 17:55:33'),
(264, 'TAX_INVOICE', 'TAX#5', 'CREATE_DRAFT', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.108.140', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"sales_ref\":\"RMI-BGR-260304-001\",\"tax_no\":\"TAX-202603-00005\"}', '2026-03-06 21:02:39'),
(265, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.108.140', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 21:03:45'),
(266, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.108.140', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 21:03:47'),
(267, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '162.158.108.140', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 21:03:49'),
(268, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '172.71.81.69', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 21:04:23'),
(269, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '172.71.124.225', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 21:05:39'),
(270, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '172.71.124.225', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 21:05:45'),
(271, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '172.71.124.225', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"REVISED\"}', '2026-03-06 21:05:55'),
(272, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '172.71.124.225', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"CANCELLED\"}', '2026-03-06 21:06:02'),
(273, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '172.71.124.225', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"CANCELLED\"}', '2026-03-06 21:06:04'),
(274, 'TAX_INVOICE', 'TAX#5', 'SET_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '172.71.124.225', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '{\"status\":\"ISSUED\"}', '2026-03-06 21:06:16'),
(275, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"a676586fa18eb16a1d302944\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-06 23:11:38'),
(276, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"9b9463423205f7b73d26ee42\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":0,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-06 23:12:29'),
(277, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"request_id\":\"58c4805f82a7ed8807ce8fd7\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-06 23:17:27'),
(278, 'ACCOUNT_READINESS', 'USER#178', 'SET_INACTIVE', 2, 'admin', 'ADMIN', 'ADMIN', '104.23.175.179', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"by\":2}', '2026-03-06 23:27:33'),
(279, 'ACCOUNT_READINESS', 'USER#177', 'SET_INACTIVE', 2, 'admin', 'ADMIN', 'ADMIN', '104.23.175.179', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"by\":2}', '2026-03-06 23:27:35'),
(280, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"c9643728a0a0fc36e2ddbab5\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-07 01:14:29'),
(281, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"4b8a3fd2312cc1ddb9026ca9\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":18,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-07 01:15:13'),
(282, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"90047acfe3f436562b666f86\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-07 14:20:14'),
(283, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"f012b90dce7d146bfe4d710c\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":18,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-07 14:21:14'),
(284, 'CHAT', 'MSG#10', 'MESSAGE_SENT', 171, 'StaffBRANCH_KAL', 'STAFF', 'STAFF', '172.68.164.147', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36', '{\"channel_id\":26,\"mentions\":0,\"idempotency_key\":\"[REDACTED]\",\"actor_username\":\"StaffBRANCH_KAL\"}', '2026-03-08 00:22:07'),
(285, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"a7c4b4345532d4cba17cba30\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 04:28:21'),
(286, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7b36a05ed7c12b859790b89b\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":18,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 04:29:07'),
(287, 'RELEASE', 'release_verify_all#release-verify-all-20260308042934-22ba8706', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260308042934-22ba8706\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"full\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-08 04:29:34'),
(288, 'RELEASE', 'release_verify_all#release-verify-all-20260308042936-758930ce', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260308042936-758930ce\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":true,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-08 04:29:36'),
(289, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"request_id\":\"5ff8bdb8342ae1f274f58b95\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 04:30:16'),
(290, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_ENABLE', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"request_id\":\"38596b3d65f48c0be02418a8\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15\",\"status\":\"failed\",\"output_lines\":3,\"description\":\"Enable scheduler 23:00 WIB\"}', '2026-03-08 04:30:19'),
(291, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"request_id\":\"b1ed3fb96016461520291d8a\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15\",\"status\":\"failed\",\"duration_ms\":null,\"output_lines\":4,\"description\":\"Run test now from scheduler UI\"}', '2026-03-08 04:31:36'),
(292, 'RELEASE', 'release_verify_all#release-verify-all-20260308100021-60dcef65', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260308100021-60dcef65\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"full\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":false,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-08 10:00:21'),
(293, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '104.23.175.179', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"request_id\":\"0fba71977140d101ca82a44e\",\"actor_username\":\"admin\",\"ip\":\"104.23.175.179\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:05:25'),
(294, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"78806bbd4dec2c188db4f3fc\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:11:56'),
(295, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"8c7b49f5153e9e6b0daf2303\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 10:11:59'),
(296, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"a878d5ab9725611524373feb\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:12:04'),
(297, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"3d765dd6d5302f4323c6d4bf\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 10:12:07'),
(298, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"081821d028a59a3840ba848c\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:28:02'),
(299, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"70a4f0d3ca5821b0270aa9bd\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 10:28:04'),
(300, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"a4de6c9bb79473341a247fb5\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:30:00'),
(301, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"9abcc7aecd5c6587da260845\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 10:30:03'),
(302, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"27f0f177f8a533410e1d6675\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:30:08'),
(303, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"6a12971571d847c90fb4f976\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 10:30:10'),
(304, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"b7316954ff5c5ee241f85f77\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:34:06'),
(305, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"bf91cc91f98d8a048b5116ae\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 10:34:09'),
(306, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"38b63f013d27b518adc0270f\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:34:14'),
(307, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"52686b1dc3d3768d20a64bd3\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 10:34:16'),
(308, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"88582fdc67abf742cbebd07a\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:44:24'),
(309, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"31d82528ab616bea8575bac2\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 10:44:27'),
(310, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"bea0ba787a4116733c280930\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 10:44:31'),
(311, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"eb848b18b5c81d4dd86aba99\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 10:44:33'),
(312, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"request_id\":\"177345f909fbdbec04b56903\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 17:23:25'),
(313, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_RUN_TEST', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"request_id\":\"5126742ff86f0a8e5c8acd32\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15\",\"status\":\"failed\",\"duration_ms\":null,\"output_lines\":3,\"description\":\"Run test now from scheduler UI\"}', '2026-03-08 17:23:29'),
(314, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"386517934e693377d215318c\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 22:39:09'),
(315, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"c53291df4f8aea647666e0ce\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 22:39:12'),
(316, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"d5c03faf0ee46ad0618092ae\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 22:39:26'),
(317, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"8768bbf731e61141bf2f97a1\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 22:39:28'),
(318, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"db5446337403cf3f513fac73\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 22:42:42'),
(319, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"db5446337403cf3f513fac73\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-03-08 22:42:42'),
(320, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"1705956078a00a2e6420ea9e\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 22:42:43'),
(321, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"5ab80de93900f624a7caa527\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 22:45:14'),
(322, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"5ab80de93900f624a7caa527\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-03-08 22:45:15'),
(323, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"f5312d168ba87647df2ed596\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 22:45:15'),
(324, 'RELEASE', 'release_verify_all#release-verify-all-20260308224817-13099504', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260308224817-13099504\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":false,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-08 22:48:17'),
(325, 'RELEASE', 'release_verify_all#release-verify-all-20260308224818-817fade8', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260308224818-817fade8\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"full\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":false,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-08 22:48:18'),
(326, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"e138049f78bd3377883bb8e4\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:02:56'),
(327, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"e138049f78bd3377883bb8e4\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-03-08 23:02:56'),
(328, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"9daa14e32f494445526d3948\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:02:56'),
(329, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7920bf0e6cf5fd67013821d7\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:04:03'),
(330, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7920bf0e6cf5fd67013821d7\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-03-08 23:04:03'),
(331, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"c90d4f9c4f35b70da421dc20\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:04:03'),
(332, 'RELEASE', 'release_verify_all#release-verify-all-20260308231954-e2f36f9f', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260308231954-e2f36f9f\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"quick\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":false,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-08 23:19:54'),
(333, 'RELEASE', 'release_verify_all#release-verify-all-20260308231955-d6df61d7', 'RELEASE_VERIFY_ALL_RUN', NULL, 'SYSTEM', NULL, NULL, NULL, NULL, '{\"request_id\":\"release-verify-all-20260308231955-d6df61d7\",\"actor_username\":\"SYSTEM\",\"ip\":\"\",\"user_agent\":\"\",\"mode\":\"full\",\"env\":\"staging\",\"strict\":false,\"overall_ok\":false,\"summary\":{\"bundle_total\":7,\"bundle_ok\":7,\"bundle_warn\":0,\"bundle_fail\":0,\"pack_total\":7,\"pack_ok\":7,\"pack_warn\":0,\"pack_fail\":0},\"description\":\"Release verify all executed\"}', '2026-03-08 23:19:55'),
(334, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"14957ec5f06d152f451949ea\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:24:52'),
(335, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"d5d62ddfa066d1196d01dad6\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 23:24:55'),
(336, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"f949cbbf09ad937ed9eac2df\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:29:23'),
(337, 'TOOLS_QA', 'UAT#SMOKE', 'UAT_SMOKE_RUN', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"f949cbbf09ad937ed9eac2df\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"all_pass\":false,\"total_checks\":18,\"failed_checks\":2,\"description\":\"Run UAT smoke checklist\"}', '2026-03-08 23:29:24'),
(338, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7534871e7407bc27e10e2a27\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:29:24'),
(339, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"f81f8bff56ba906ab6aaee50\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:34:31'),
(340, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"8868262e93eb05db6df52874\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 23:34:33'),
(341, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"241b211b4db39161cdbb5add\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:36:37'),
(342, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"ce5aed161cc3dfe37e412ea9\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 23:36:39'),
(343, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"18ef97ebc3c2e2796e95d0d3\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-08 23:38:11'),
(344, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"659e369cf8748fc3ffa6a550\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":99,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-08 23:38:13'),
(345, 'TOOLS_BACKUP', 'SCHEDULER#2300', 'BACKUP_SCHEDULER_CHECK_STATUS', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"c5d84b4e67ba80717af87404\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"status\":\"ok\",\"description\":\"Check scheduler status\"}', '2026-03-09 06:38:58'),
(346, 'TOOLS_BACKUP', 'BACKUP#smoke_http', 'BACKUP_CREATED', 177, 'smoke_admin', 'ADMIN', 'ADMIN', '10.10.60.20', NULL, '{\"request_id\":\"7bc404ce054444dcd0aca48c\",\"actor_username\":\"smoke_admin\",\"ip\":\"10.10.60.20\",\"user_agent\":\"\",\"exit_code\":18,\"label\":\"smoke_http\",\"description\":\"Backup created from Backup Manager\"}', '2026-03-09 06:40:04'),
(347, 'HRL_COMPLIANCE', 'REG_ALKES#export', 'COMPLIANCE_EXPORT_REG_ALKES', 2, 'admin', 'ADMIN', 'ADMIN', '10.10.60.21', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15', '{\"request_id\":\"7b7e0e8ecf3a2be545c67fd6\",\"actor_username\":\"admin\",\"ip\":\"10.10.60.21\",\"user_agent\":\"Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3.1 Safari/605.1.15\",\"row_count\":0,\"format\":\"csv\",\"range_from\":null,\"range_to\":null,\"description\":\"Export compliance\"}', '2026-03-09 06:43:06');

-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_assets`
--

DROP TABLE IF EXISTS `fa_assets`;
CREATE TABLE `fa_assets` (
  `id` int(11) NOT NULL,
  `asset_code` varchar(50) NOT NULL,
  `asset_name` varchar(200) NOT NULL,
  `category` varchar(100) DEFAULT '',
  `office_code` varchar(30) DEFAULT '',
  `dept_code` varchar(30) DEFAULT '',
  `custodian_emp_id` int(11) DEFAULT NULL,
  `vendor_name` varchar(200) DEFAULT '',
  `purchase_ref` varchar(100) DEFAULT '',
  `invoice_no` varchar(100) DEFAULT '',
  `acq_date` date NOT NULL,
  `acq_cost` decimal(18,2) NOT NULL DEFAULT 0.00,
  `salvage_value` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_group_code` varchar(20) NOT NULL,
  `dep_method` enum('SL','DDB') NOT NULL DEFAULT 'SL',
  `status` enum('ACTIVE','INACTIVE','DISPOSED') NOT NULL DEFAULT 'ACTIVE',
  `disposed_at` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `fa_assets`
--

TRUNCATE TABLE `fa_assets`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_audits`
--

DROP TABLE IF EXISTS `fa_audits`;
CREATE TABLE `fa_audits` (
  `id` int(11) NOT NULL,
  `audit_code` varchar(50) NOT NULL,
  `office_code` varchar(30) DEFAULT '',
  `audit_date` date NOT NULL,
  `status` enum('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  `created_by` int(11) DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `fa_audits`
--

TRUNCATE TABLE `fa_audits`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_audit_lines`
--

DROP TABLE IF EXISTS `fa_audit_lines`;
CREATE TABLE `fa_audit_lines` (
  `id` int(11) NOT NULL,
  `audit_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `physical_status` enum('OK','MISSING','DAMAGED','NOT_FOUND') NOT NULL DEFAULT 'NOT_FOUND',
  `note` varchar(255) DEFAULT '',
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `fa_audit_lines`
--

TRUNCATE TABLE `fa_audit_lines`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_audit_log`
--

DROP TABLE IF EXISTS `fa_audit_log`;
CREATE TABLE `fa_audit_log` (
  `id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity` varchar(50) NOT NULL DEFAULT 'SYSTEM',
  `entity_id` int(11) NOT NULL DEFAULT 0,
  `meta_json` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `fa_audit_log`
--

TRUNCATE TABLE `fa_audit_log`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_dep_lines`
--

DROP TABLE IF EXISTS `fa_dep_lines`;
CREATE TABLE `fa_dep_lines` (
  `id` int(11) NOT NULL,
  `run_id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `period_ym` varchar(7) NOT NULL,
  `opening_book` decimal(18,2) NOT NULL DEFAULT 0.00,
  `dep_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `closing_book` decimal(18,2) NOT NULL DEFAULT 0.00,
  `accum_after` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `fa_dep_lines`
--

TRUNCATE TABLE `fa_dep_lines`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_dep_runs`
--

DROP TABLE IF EXISTS `fa_dep_runs`;
CREATE TABLE `fa_dep_runs` (
  `id` int(11) NOT NULL,
  `period_ym` varchar(7) NOT NULL,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  `run_by` int(11) DEFAULT NULL,
  `total_assets` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(18,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `fa_dep_runs`
--

TRUNCATE TABLE `fa_dep_runs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_disposals`
--

DROP TABLE IF EXISTS `fa_disposals`;
CREATE TABLE `fa_disposals` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `disposal_date` date NOT NULL,
  `disposal_type` enum('SOLD','DAMAGED','LOST') NOT NULL,
  `proceeds` decimal(18,2) NOT NULL DEFAULT 0.00,
  `doc_ref` varchar(120) DEFAULT '',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `fa_disposals`
--

TRUNCATE TABLE `fa_disposals`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_maintenance`
--

DROP TABLE IF EXISTS `fa_maintenance`;
CREATE TABLE `fa_maintenance` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `maint_date` date NOT NULL,
  `vendor` varchar(200) DEFAULT '',
  `cost` decimal(18,2) NOT NULL DEFAULT 0.00,
  `downtime_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `fa_maintenance`
--

TRUNCATE TABLE `fa_maintenance`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `fa_transfers`
--

DROP TABLE IF EXISTS `fa_transfers`;
CREATE TABLE `fa_transfers` (
  `id` int(11) NOT NULL,
  `asset_id` int(11) NOT NULL,
  `transfer_date` date NOT NULL,
  `from_office` varchar(30) DEFAULT '',
  `to_office` varchar(30) DEFAULT '',
  `from_dept` varchar(30) DEFAULT '',
  `to_dept` varchar(30) DEFAULT '',
  `from_custodian` int(11) DEFAULT NULL,
  `to_custodian` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `fa_transfers`
--

TRUNCATE TABLE `fa_transfers`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `gl_accounts`
--

DROP TABLE IF EXISTS `gl_accounts`;
CREATE TABLE `gl_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(200) NOT NULL,
  `account_type` enum('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE') NOT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `is_postable` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `gl_accounts`
--

TRUNCATE TABLE `gl_accounts`;
--
-- Dumping data untuk tabel `gl_accounts`
--

INSERT DELAYED IGNORE INTO `gl_accounts` (`id`, `code`, `name`, `account_type`, `parent_id`, `is_postable`, `status`, `created_at`, `updated_at`) VALUES
(1, '111001', 'Cash/Bank', 'ASSET', NULL, 1, 'ACTIVE', '2026-03-01 13:38:04', '2026-03-01 13:38:04'),
(2, '211001', 'Account Payable', 'LIABILITY', NULL, 1, 'ACTIVE', '2026-03-01 13:38:04', '2026-03-01 13:38:04');

-- --------------------------------------------------------

--
-- Struktur dari tabel `gl_journal_headers`
--

DROP TABLE IF EXISTS `gl_journal_headers`;
CREATE TABLE `gl_journal_headers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `journal_no` varchar(60) NOT NULL,
  `journal_date` date NOT NULL,
  `source_module` varchar(60) NOT NULL,
  `source_event` varchar(80) NOT NULL,
  `source_ref` varchar(120) NOT NULL,
  `reversal_of_header_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reverse_reason` varchar(255) DEFAULT NULL,
  `reversed_at` datetime DEFAULT NULL,
  `reversed_by` bigint(20) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `status` enum('DRAFT','POSTED','VOIDED') NOT NULL DEFAULT 'DRAFT',
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `gl_journal_headers`
--

TRUNCATE TABLE `gl_journal_headers`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `gl_journal_lines`
--

DROP TABLE IF EXISTS `gl_journal_lines`;
CREATE TABLE `gl_journal_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `header_id` bigint(20) UNSIGNED NOT NULL,
  `line_no` int(11) NOT NULL,
  `account_id` bigint(20) UNSIGNED NOT NULL,
  `dr_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `cr_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `memo` varchar(255) DEFAULT NULL,
  `cost_center` varchar(100) DEFAULT NULL,
  `project_code` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `gl_journal_lines`
--

TRUNCATE TABLE `gl_journal_lines`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `gl_mappings`
--

DROP TABLE IF EXISTS `gl_mappings`;
CREATE TABLE `gl_mappings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `module_name` varchar(60) NOT NULL,
  `event_name` varchar(80) NOT NULL,
  `debit_account_id` bigint(20) UNSIGNED NOT NULL,
  `credit_account_id` bigint(20) UNSIGNED NOT NULL,
  `rule_json` longtext DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `gl_mappings`
--

TRUNCATE TABLE `gl_mappings`;
--
-- Dumping data untuk tabel `gl_mappings`
--

INSERT DELAYED IGNORE INTO `gl_mappings` (`id`, `module_name`, `event_name`, `debit_account_id`, `credit_account_id`, `rule_json`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'PURCHASES', 'AP_INVOICE_CREATED', 1, 2, NULL, 1, '2026-03-01 13:38:04', '2026-03-01 13:38:04');

-- --------------------------------------------------------

--
-- Struktur dari tabel `gl_periods`
--

DROP TABLE IF EXISTS `gl_periods`;
CREATE TABLE `gl_periods` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `year_no` int(11) NOT NULL,
  `month_no` int(11) NOT NULL,
  `status` enum('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  `closed_at` datetime DEFAULT NULL,
  `closed_by` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `gl_periods`
--

TRUNCATE TABLE `gl_periods`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `gl_posting_batches`
--

DROP TABLE IF EXISTS `gl_posting_batches`;
CREATE TABLE `gl_posting_batches` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch_code` varchar(80) NOT NULL,
  `posted_at` datetime NOT NULL,
  `posted_by` bigint(20) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `gl_posting_batches`
--

TRUNCATE TABLE `gl_posting_batches`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `gl_reversal_requests`
--

DROP TABLE IF EXISTS `gl_reversal_requests`;
CREATE TABLE `gl_reversal_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `header_id` bigint(20) UNSIGNED NOT NULL,
  `reason` varchar(255) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `requested_by` int(11) DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `decision_note` varchar(255) DEFAULT NULL,
  `reversal_header_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `gl_reversal_requests`
--

TRUNCATE TABLE `gl_reversal_requests`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_docs`
--

DROP TABLE IF EXISTS `hrl_docs`;
CREATE TABLE `hrl_docs` (
  `id` int(11) NOT NULL,
  `doc_code` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'HR',
  `category` varchar(30) NOT NULL DEFAULT 'SOP',
  `scope` varchar(20) NOT NULL DEFAULT 'INTERNAL',
  `owner_dept` varchar(10) NOT NULL DEFAULT 'HRL',
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `current_version` int(11) NOT NULL DEFAULT 0,
  `effective_date` date DEFAULT NULL,
  `tags` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` varchar(50) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `hrl_docs`
--

TRUNCATE TABLE `hrl_docs`;
--
-- Dumping data untuk tabel `hrl_docs`
--

INSERT DELAYED IGNORE INTO `hrl_docs` (`id`, `doc_code`, `title`, `unit`, `category`, `scope`, `owner_dept`, `status`, `current_version`, `effective_date`, `tags`, `description`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
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
(11, 'HRL-HR-FORM-20260101-9A5E2A', 'Form permintaan karyawan', 'HR', 'FORM', 'INTERNAL', 'HRL', 'ACTIVE', 1, NULL, NULL, NULL, 'admin', '2026-01-02 02:01:37', '2026-01-02 02:02:56', NULL),
(12, 'HRL-LEGAL-SOP-REG-ALKES-004', 'SOP Recall / Penarikan Produk', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'regulator recall', 'Wajib untuk distributor Alkes. Prosedur penarikan produk dari pasar.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(13, 'HRL-LEGAL-SOP-REG-ALKES-005', 'SOP Keluhan Pelanggan & Adverse Event', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'regulator keluhan', 'Penanganan keluhan pelanggan dan adverse event. Pelaporan ke BPOM jika wajib.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(14, 'HRL-LEGAL-SOP-REG-ALKES-006', 'SOP Pengelolaan Cold Chain / Produk Sensitif Suhu', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'cold chain', 'Produk suhu terkontrol. Penerimaan penyimpanan pengiriman monitoring.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(15, 'HRL-LEGAL-SOP-REG-ALKES-007', 'SOP Pengelolaan Produk Expired / Near Expiry', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'expiry', 'FEFO penanganan expired pemusnahan.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(16, 'HRL-LEGAL-SOP-REG-ALKES-008', 'SOP Pengelolaan Labeling & Kemasan', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'labeling', 'Regulasi Alkes. Label bahasa Indonesia BPOM batch expiry.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(17, 'HRL-LEGAL-SOP-REG-ALKES-009', 'SOP Pengendalian Data SKU & Batch', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'traceability', 'Traceability batch di ERP. Pencatatan batch serial.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(18, 'HRL-LEGAL-SOP-IMPORT-001', 'SOP Import Alkes (Customs BPOM)', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'import', 'Proses impor produk Alkes: customs clearance BPOM.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(19, 'HRL-LEGAL-SOP-DELIVERY-001', 'SOP Distribusi ke Rumah Sakit (Delivery & Handover)', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'delivery', 'Prosedur pengiriman ke RS: packing DO serah terima.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(20, 'HRL-LEGAL-SOP-IT-002', 'SOP Validasi Sistem ERP (IQ/OQ/PQ)', 'IT', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'validasi', 'Installation Operational Performance Qualification untuk ERP.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(21, 'HRL-LEGAL-SOP-REG-ALKES-010', 'SOP Vendor Qualification (Alkes)', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'vendor', 'Kualifikasi vendor produk medis.', 'admin', '2026-02-26 15:39:30', '2026-02-26 15:39:30', NULL),
(22, 'HRL-LEGAL-SOP-HR-002', 'SOP Pengelolaan Dokumen Terkontrol', 'HR', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'document control', 'Document control numbering versi distribusi retention.', 'admin', '2026-02-26 15:39:30', '2026-02-26 17:30:21', NULL),
(23, 'HRL-LEGAL-FORM-005', 'Form Keluhan Pelanggan (Customer Complaint)', 'LEGAL', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'keluhan', 'Intake keluhan dari RS. Produk batch tanggal tindakan.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(24, 'HRL-LEGAL-FORM-006', 'Form Recall / Penarikan Produk', 'LEGAL', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'recall', 'Checklist recall: produk batch alasan jumlah lokasi status.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(25, 'HRL-LEGAL-FORM-007', 'Form Penerimaan Barang di Gudang (GRN Alkes)', 'LEGAL', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'grn', 'Pengecekan penerimaan: suhu kemasan label batch expiry.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(26, 'HRL-LEGAL-FORM-008', 'Form Serah Terima ke Rumah Sakit', 'LEGAL', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'delivery', 'Bukti delivery handover ke RS: DO tanda terima.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(27, 'HRL-LEGAL-FORM-009', 'Form Near Expiry / Expired Product Report', 'LEGAL', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'expiry', 'Laporan produk mendekati atau kadaluarsa.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(28, 'HRL-HR-FORM-024', 'Form Permintaan Akses ERP (Role-based)', 'HR', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'akses', 'Request akses sistem ERP: role modul alasan approval.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(29, 'HRL-HR-FORM-025', 'Form Perubahan Harga Jual (Pricelist)', 'HR', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'harga', 'Request perubahan harga ke customer.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(30, 'HRL-HR-FORM-026', 'Form Izin Cuti / Lembur', 'HR', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'cuti', 'Request cuti atau lembur: tipe tanggal alasan approval.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(31, 'HRL-HR-FORM-027', 'Form Training & Kompetensi Karyawan', 'HR', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'training', 'Evidence training: topik tanggal peserta nilai sign-off.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(32, 'HRL-LEGAL-FORM-010', 'Form Vendor Qualification (Logistik/Jasa)', 'LEGAL', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'vendor logistik', 'Kualifikasi vendor logistik. PIC SCM.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(33, 'HRL-HR-PP-001', 'PP Pengelolaan Dokumen Terkontrol', 'HR', 'PP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'document control', 'Aturan document control numbering versi distribusi retention.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(34, 'HRL-HR-PP-002', 'PP Kode Etik & Anti Suap', 'HR', 'PP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'compliance', 'Kode etik karyawan anti suap gratifikasi.', 'admin', '2026-02-26 15:39:30', '2026-02-26 17:30:21', NULL),
(35, 'HRL-HR-PP-003', 'PP Pengelolaan Informasi Rahasia', 'HR', 'PP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'rahasia', 'Kerahasiaan data NDA internal.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(36, 'HRL-HR-PP-004', 'PP Penggunaan Aset Perusahaan', 'HR', 'PP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'aset', 'Penggunaan laptop kendaraan peralatan.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(37, 'HRL-HR-CHK-001', 'Checklist Pre-Delivery Alkes', 'HR', 'CHECKLIST', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'delivery', 'Sebelum kirim ke RS: cek produk batch expiry kemasan suhu.', 'admin', '2026-02-26 15:39:30', '2026-02-26 17:30:21', NULL),
(38, 'HRL-HR-CHK-002', 'Checklist Pre-Registration (Reg Alkes)', 'HR', 'CHECKLIST', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'regulator', 'Sebelum submit ke BPOM: kelengkapan dokumen validasi.', 'admin', '2026-02-26 15:39:30', '2026-02-26 17:30:21', NULL),
(39, 'HRL-HR-CHK-003', 'Checklist Audit Internal Tahunan', 'HR', 'CHECKLIST', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'audit', 'Persiapan audit internal: dokumen evidence timeline.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(40, 'HRL-HR-CHK-004', 'Checklist Penerimaan Barang Import', 'HR', 'CHECKLIST', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'import', 'Verifikasi dokumen customs BPOM fisik barang.', 'admin', '2026-02-26 15:39:30', '2026-02-26 17:30:21', NULL),
(41, 'HRL-HR-CHK-005', 'Checklist Onboarding Vendor Baru', 'HR', 'CHECKLIST', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'vendor logistik', 'Kelengkapan dokumen vendor logistik. PIC SCM.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(42, 'HRL-HR-POL-003', 'Policy Retention & Arsip Dokumen', 'HR', 'DOC', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'retention', 'Lama penyimpanan dokumen per jenis. Sesuai regulasi.', 'admin', '2026-02-26 15:39:30', '2026-02-27 11:58:05', NULL),
(43, 'HRL-LEGAL-SOP-SCM-001', 'SOP Vendor Qualification (Logistik/Jasa)', 'LEGAL', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'vendor logistik', 'Kualifikasi vendor logistik forwarding ekspedisi. PIC SCM.', 'admin', '2026-02-26 17:30:21', '2026-02-27 11:58:05', NULL),
(44, 'HRL-HR-SOP-002', 'SOP Pengelolaan Dokumen Terkontrol', 'HR', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'document control', 'Document control numbering versi distribusi retention.', 'admin', '2026-02-27 11:58:05', '2026-02-27 11:58:05', NULL),
(45, 'HRL-LEGAL-PP-002', 'PP Kode Etik & Anti Suap', 'LEGAL', 'PP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'compliance', 'Kode etik karyawan anti suap gratifikasi.', 'admin', '2026-02-27 11:58:05', '2026-02-27 11:58:05', NULL),
(46, 'HRL-LEGAL-CHK-001', 'Checklist Pre-Delivery Alkes', 'LEGAL', 'CHECKLIST', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'delivery', 'Sebelum kirim ke RS: cek produk batch expiry kemasan suhu.', 'admin', '2026-02-27 11:58:05', '2026-02-27 11:58:05', NULL),
(47, 'HRL-LEGAL-CHK-002', 'Checklist Pre-Registration (Reg Alkes)', 'LEGAL', 'CHECKLIST', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'regulator', 'Sebelum submit ke BPOM: kelengkapan dokumen validasi.', 'admin', '2026-02-27 11:58:05', '2026-02-27 11:58:05', NULL),
(48, 'HRL-LEGAL-CHK-004', 'Checklist Penerimaan Barang Import', 'LEGAL', 'CHECKLIST', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'import', 'Verifikasi dokumen customs BPOM fisik barang.', 'admin', '2026-02-27 11:58:05', '2026-02-27 11:58:05', NULL),
(49, 'HRL-HR-PP-005', 'PP Disiplin Karyawan (SP1/SP2/SP3)', 'HR', 'PP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'disiplin', 'Aturan SP1 SP2 SP3 penganggaran sanksi progresif.', 'admin', '2026-02-27 11:58:05', '2026-02-27 11:58:05', NULL),
(50, 'HRL-HR-SOP-003', 'SOP Tindakan Disipliner', 'HR', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'disiplin', 'Prosedur penanganan pelanggaran penerbitan SP.', 'admin', '2026-02-27 11:58:05', '2026-02-27 11:58:05', NULL),
(51, 'HRL-HR-FORM-003', 'Form Disciplinary Action (SP1/SP2/SP3)', 'HR', 'FORM', 'INTERNAL', 'HRL', 'DRAFT', 0, NULL, 'disiplin', 'Form Surat Peringatan 1 2 3.', 'admin', '2026-02-27 11:58:05', '2026-02-27 11:58:05', NULL),
(52, 'HRL-HR-SOP-MPR-001', 'SOP MPR (Marketing & Project) - Kunjungan Budget Ops Daily', 'HR', 'SOP', 'INTERNAL', 'HRL', 'DRAFT', 0, '2026-03-04', 'MPR,kunjungan,budget,ops daily', 'Prosedur kunjungan ke User/PIC Customers, budget approval FIN, ops daily untuk buat Sales. MPR fokus berkunjung ke customer untuk generate DO.', 'admin', '2026-03-06 20:52:56', '2026-03-06 20:52:56', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_doc_acks`
--

DROP TABLE IF EXISTS `hrl_doc_acks`;
CREATE TABLE `hrl_doc_acks` (
  `id` int(11) NOT NULL,
  `doc_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `employee_code` varchar(50) DEFAULT NULL,
  `department` varchar(10) DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `ack_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ack_ip` varchar(45) DEFAULT NULL,
  `ack_user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `hrl_doc_acks`
--

TRUNCATE TABLE `hrl_doc_acks`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_doc_versions`
--

DROP TABLE IF EXISTS `hrl_doc_versions`;
CREATE TABLE `hrl_doc_versions` (
  `id` int(11) NOT NULL,
  `doc_id` int(11) NOT NULL,
  `version_no` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `checksum` varchar(64) DEFAULT NULL,
  `change_log` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `submitted_at` datetime DEFAULT NULL,
  `submitted_by` varchar(50) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(50) DEFAULT NULL,
  `rejected_note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(50) NOT NULL DEFAULT '',
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `hrl_doc_versions`
--

TRUNCATE TABLE `hrl_doc_versions`;
--
-- Dumping data untuk tabel `hrl_doc_versions`
--

INSERT DELAYED IGNORE INTO `hrl_doc_versions` (`id`, `doc_id`, `version_no`, `file_path`, `file_name`, `mime`, `file_size`, `checksum`, `change_log`, `status`, `submitted_at`, `submitted_by`, `approved_at`, `approved_by`, `rejected_note`, `created_at`, `created_by`, `deleted_at`) VALUES
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

DROP TABLE IF EXISTS `hrl_reg_alkes_cases`;
CREATE TABLE `hrl_reg_alkes_cases` (
  `id` int(11) NOT NULL,
  `case_code` varchar(40) NOT NULL,
  `manufacture_id` int(11) DEFAULT NULL,
  `manufacture_code` varchar(60) NOT NULL,
  `manufacture_name` varchar(200) DEFAULT NULL,
  `product_name` varchar(200) NOT NULL,
  `is_oem` tinyint(1) NOT NULL DEFAULT 0,
  `stage_no` int(11) NOT NULL DEFAULT 1,
  `stage_code` varchar(40) DEFAULT NULL,
  `next_pic_dept` varchar(10) DEFAULT NULL,
  `revision_count` tinyint(4) NOT NULL DEFAULT 0,
  `revision_deadline` date DEFAULT NULL,
  `oss_pb_umku` varchar(120) DEFAULT NULL,
  `regalkes_ref` varchar(120) DEFAULT NULL,
  `nie_type` varchar(10) DEFAULT NULL,
  `nie_no` varchar(150) DEFAULT NULL,
  `nie_issue_date` date DEFAULT NULL,
  `nie_file_rel` varchar(255) DEFAULT NULL,
  `sku_imported_count` int(11) NOT NULL DEFAULT 0,
  `sku_last_import_at` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'OPEN',
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(64) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` varchar(64) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `closed_by` varchar(64) DEFAULT NULL,
  `source` varchar(30) DEFAULT 'pqp' COMMENT 'pqp|portal',
  `portal_user_id` int(11) DEFAULT NULL COMMENT 'FK manufacturer_portal_users.id'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `hrl_reg_alkes_cases`
--

TRUNCATE TABLE `hrl_reg_alkes_cases`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_reg_alkes_case_docs`
--

DROP TABLE IF EXISTS `hrl_reg_alkes_case_docs`;
CREATE TABLE `hrl_reg_alkes_case_docs` (
  `id` int(11) NOT NULL,
  `case_id` int(11) NOT NULL,
  `doc_type` varchar(40) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_rel` varchar(255) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` varchar(64) DEFAULT NULL,
  `uploaded_via` varchar(20) DEFAULT 'erp' COMMENT 'erp|portal',
  `portal_user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `hrl_reg_alkes_case_docs`
--

TRUNCATE TABLE `hrl_reg_alkes_case_docs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_requests`
--

DROP TABLE IF EXISTS `hrl_requests`;
CREATE TABLE `hrl_requests` (
  `id` bigint(20) NOT NULL,
  `req_code` varchar(64) DEFAULT NULL,
  `req_type` varchar(32) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `dept_code` varchar(32) DEFAULT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `gps_accuracy_m` int(11) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'DRAFT',
  `created_by` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
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
  `reject_note` text DEFAULT NULL,
  `deleted_by` varchar(64) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `hrl_requests`
--

TRUNCATE TABLE `hrl_requests`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_request_files`
--

DROP TABLE IF EXISTS `hrl_request_files`;
CREATE TABLE `hrl_request_files` (
  `id` bigint(20) NOT NULL,
  `request_id` bigint(20) NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'ATTACH',
  `file_path` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime` varchar(128) DEFAULT NULL,
  `size_bytes` int(11) DEFAULT NULL,
  `uploaded_by` varchar(64) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `hrl_request_files`
--

TRUNCATE TABLE `hrl_request_files`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `hrl_user_pins`
--

DROP TABLE IF EXISTS `hrl_user_pins`;
CREATE TABLE `hrl_user_pins` (
  `username` varchar(64) NOT NULL,
  `pin_hash` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `hrl_user_pins`
--

TRUNCATE TABLE `hrl_user_pins`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `jobs`
--

DROP TABLE IF EXISTS `jobs`;
CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `job_type` varchar(100) NOT NULL,
  `payload_json` longtext DEFAULT NULL,
  `status` enum('PENDING','RUNNING','DONE','FAILED') NOT NULL DEFAULT 'PENDING',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `run_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `jobs`
--

TRUNCATE TABLE `jobs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_adjustments`
--

DROP TABLE IF EXISTS `kpi_adjustments`;
CREATE TABLE `kpi_adjustments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `month_no` tinyint(4) NOT NULL,
  `year_no` smallint(6) NOT NULL,
  `office_id` int(11) DEFAULT NULL,
  `field` varchar(40) NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_adjustments`
--

TRUNCATE TABLE `kpi_adjustments`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_audit_log`
--

DROP TABLE IF EXISTS `kpi_audit_log`;
CREATE TABLE `kpi_audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `module` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  `ref_code` varchar(120) DEFAULT NULL,
  `detail` longtext DEFAULT NULL,
  `actor` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_audit_log`
--

TRUNCATE TABLE `kpi_audit_log`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_corporate_rates`
--

DROP TABLE IF EXISTS `kpi_corporate_rates`;
CREATE TABLE `kpi_corporate_rates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `segment` varchar(40) NOT NULL,
  `rate_percent` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_corporate_rates`
--

TRUNCATE TABLE `kpi_corporate_rates`;
--
-- Dumping data untuk tabel `kpi_corporate_rates`
--

INSERT DELAYED IGNORE INTO `kpi_corporate_rates` (`id`, `segment`, `rate_percent`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'MAIN', 10.0000, 1, '2026-03-01 13:38:16', '2026-03-01 13:38:16'),
(2, 'ACCUNIT', 5.0000, 1, '2026-03-01 13:38:16', '2026-03-01 13:38:16'),
(3, 'TANGERANG', 10.0000, 1, '2026-03-01 13:38:16', '2026-03-01 13:38:16');

-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_customer_segment_map`
--

DROP TABLE IF EXISTS `kpi_customer_segment_map`;
CREATE TABLE `kpi_customer_segment_map` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `customer_code` varchar(80) NOT NULL,
  `segment` varchar(40) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_customer_segment_map`
--

TRUNCATE TABLE `kpi_customer_segment_map`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_daily_snapshots`
--

DROP TABLE IF EXISTS `kpi_daily_snapshots`;
CREATE TABLE `kpi_daily_snapshots` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `snapshot_date` date NOT NULL,
  `office_id` int(11) DEFAULT NULL,
  `segment` varchar(40) NOT NULL,
  `metrics_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_daily_snapshots`
--

TRUNCATE TABLE `kpi_daily_snapshots`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_employee`
--

DROP TABLE IF EXISTS `kpi_employee`;
CREATE TABLE `kpi_employee` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `month_ym` varchar(7) NOT NULL,
  `dept_code` varchar(50) NOT NULL,
  `employee_code` varchar(100) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `metrics_json` longtext DEFAULT NULL,
  `note` text DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_employee`
--

TRUNCATE TABLE `kpi_employee`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_gl_category_map`
--

DROP TABLE IF EXISTS `kpi_gl_category_map`;
CREATE TABLE `kpi_gl_category_map` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `gl_account_id` bigint(20) UNSIGNED NOT NULL,
  `category` varchar(30) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_gl_category_map`
--

TRUNCATE TABLE `kpi_gl_category_map`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_office`
--

DROP TABLE IF EXISTS `kpi_office`;
CREATE TABLE `kpi_office` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `month_ym` varchar(7) NOT NULL,
  `office_code` varchar(50) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `metrics_json` longtext DEFAULT NULL,
  `note` text DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` varchar(100) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_office`
--

TRUNCATE TABLE `kpi_office`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_snapshot`
--

DROP TABLE IF EXISTS `kpi_snapshot`;
CREATE TABLE `kpi_snapshot` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `snapshot_month` varchar(7) NOT NULL,
  `scope` varchar(20) NOT NULL,
  `actor` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_snapshot`
--

TRUNCATE TABLE `kpi_snapshot`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_snapshot_approvals`
--

DROP TABLE IF EXISTS `kpi_snapshot_approvals`;
CREATE TABLE `kpi_snapshot_approvals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `snapshot_id` bigint(20) UNSIGNED DEFAULT NULL,
  `snapshot_month` varchar(7) NOT NULL,
  `scope` varchar(20) NOT NULL,
  `maker_username` varchar(100) NOT NULL,
  `checker_username` varchar(100) NOT NULL,
  `approval_reason` text NOT NULL,
  `approval_status` varchar(20) NOT NULL DEFAULT 'APPROVED',
  `approved_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_snapshot_approvals`
--

TRUNCATE TABLE `kpi_snapshot_approvals`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_snapshot_items`
--

DROP TABLE IF EXISTS `kpi_snapshot_items`;
CREATE TABLE `kpi_snapshot_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `snapshot_id` bigint(20) UNSIGNED NOT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_snapshot_items`
--

TRUNCATE TABLE `kpi_snapshot_items`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `kpi_targets`
--

DROP TABLE IF EXISTS `kpi_targets`;
CREATE TABLE `kpi_targets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `month_no` tinyint(4) NOT NULL,
  `year_no` smallint(6) NOT NULL,
  `segment` varchar(40) NOT NULL,
  `office_id` int(11) DEFAULT NULL,
  `target_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source_key` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `kpi_targets`
--

TRUNCATE TABLE `kpi_targets`;
--
-- Dumping data untuk tabel `kpi_targets`
--

INSERT DELAYED IGNORE INTO `kpi_targets` (`id`, `month_no`, `year_no`, `segment`, `office_id`, `target_amount`, `created_at`, `updated_at`, `source_key`) VALUES
(1, 3, 2026, 'NON_HERMINA', 4, 100000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(2, 3, 2026, 'NON_HERMINA', 1, 100000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(3, 3, 2026, 'NON_HERMINA', 2, 100000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(4, 3, 2026, 'NON_HERMINA', 5, 100000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(5, 3, 2026, 'NON_HERMINA', 6, 100000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(8, 3, 2026, 'HERMINA', 4, 150000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(9, 3, 2026, 'HERMINA', 1, 150000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(10, 3, 2026, 'HERMINA', 2, 150000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(11, 3, 2026, 'HERMINA', 8, 150000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(12, 3, 2026, 'HERMINA', 7, 150000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(13, 3, 2026, 'HERMINA', 5, 150000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(14, 3, 2026, 'HERMINA', 6, 150000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(15, 3, 2026, 'ACCUNIT', 4, 25000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(16, 3, 2026, 'ACCUNIT', 1, 25000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(17, 3, 2026, 'ACCUNIT', 2, 25000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(18, 3, 2026, 'ACCUNIT', 5, 25000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(19, 3, 2026, 'ACCUNIT', 6, 25000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(22, 3, 2026, 'NON_HERMINA', 3, 50000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL),
(23, 3, 2026, 'HERMINA', 3, 60000000.00, '2026-03-01 13:38:22', '2026-03-01 13:38:22', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `manufacturer_portal_users`
--

DROP TABLE IF EXISTS `manufacturer_portal_users`;
CREATE TABLE `manufacturer_portal_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `manufacture_code` varchar(50) NOT NULL,
  `manufacture_id` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `manufacturer_portal_users`
--

TRUNCATE TABLE `manufacturer_portal_users`;
--
-- Dumping data untuk tabel `manufacturer_portal_users`
--

INSERT DELAYED IGNORE INTO `manufacturer_portal_users` (`id`, `username`, `password_hash`, `full_name`, `email`, `phone`, `manufacture_code`, `manufacture_id`, `status`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 'yaxin_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'YAXIN Demo User', NULL, NULL, 'YAXIN', 1, 'active', '2026-03-07 21:01:36', '2026-03-07 14:46:58', '2026-03-07 21:01:36');

-- --------------------------------------------------------

--
-- Struktur dari tabel `map_accounts`
--

DROP TABLE IF EXISTS `map_accounts`;
CREATE TABLE `map_accounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_code` varchar(40) DEFAULT NULL,
  `target_account_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `map_accounts`
--

TRUNCATE TABLE `map_accounts`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `map_customers`
--

DROP TABLE IF EXISTS `map_customers`;
CREATE TABLE `map_customers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_code` varchar(80) DEFAULT NULL,
  `target_customer_id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `map_customers`
--

TRUNCATE TABLE `map_customers`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `map_items`
--

DROP TABLE IF EXISTS `map_items`;
CREATE TABLE `map_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_code` varchar(80) DEFAULT NULL,
  `target_item_id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `map_items`
--

TRUNCATE TABLE `map_items`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `map_vendors`
--

DROP TABLE IF EXISTS `map_vendors`;
CREATE TABLE `map_vendors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_code` varchar(80) DEFAULT NULL,
  `target_vendor_id` bigint(20) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `map_vendors`
--

TRUNCATE TABLE `map_vendors`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `map_warehouses`
--

DROP TABLE IF EXISTS `map_warehouses`;
CREATE TABLE `map_warehouses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_code` varchar(50) DEFAULT NULL,
  `target_warehouse_id` bigint(20) NOT NULL,
  `target_office_code` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `map_warehouses`
--

TRUNCATE TABLE `map_warehouses`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `marketplace_orders_inbox`
--

DROP TABLE IF EXISTS `marketplace_orders_inbox`;
CREATE TABLE `marketplace_orders_inbox` (
  `id` int(11) NOT NULL,
  `source` varchar(60) NOT NULL DEFAULT 'unknown',
  `external_order_id` varchar(120) DEFAULT NULL,
  `payload_json` longtext NOT NULL,
  `processed_at` datetime DEFAULT NULL,
  `processed_by` varchar(80) DEFAULT NULL,
  `error_msg` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `marketplace_orders_inbox`
--

TRUNCATE TABLE `marketplace_orders_inbox`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_company_bank_accounts`
--

DROP TABLE IF EXISTS `master_company_bank_accounts`;
CREATE TABLE `master_company_bank_accounts` (
  `id` bigint(20) NOT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `bank_name` varchar(120) NOT NULL,
  `account_number` varchar(60) NOT NULL,
  `account_name` varchar(160) NOT NULL,
  `branch` varchar(120) DEFAULT NULL,
  `purpose` varchar(20) NOT NULL DEFAULT 'RECEIVE',
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_company_bank_accounts`
--

TRUNCATE TABLE `master_company_bank_accounts`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_customers`
--

DROP TABLE IF EXISTS `master_customers`;
CREATE TABLE `master_customers` (
  `id` int(11) NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_customers`
--

TRUNCATE TABLE `master_customers`;
--
-- Dumping data untuk tabel `master_customers`
--

INSERT DELAYED IGNORE INTO `master_customers` (`id`, `customers_code`, `customers_name`, `category`, `name`, `phone`, `email`, `npwp`, `segment`, `customer_type`, `staff_mpr_code`, `staff_crm_code`, `staff_scm_code`, `staff_act_code`, `staff_fin_code`, `customer_group`, `status`, `created_at`, `updated_at`, `address`, `city`, `office_code`, `cover_area`, `maps_url`) VALUES
(54, 'BDG-INT', 'Kantor Rizqullah Mediska Indonesia Bandung (Internal)', 'Internal', 'Kantor Rizqullah Mediska Indonesia Bandung (Internal)', '', '', '', 'Kantor', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:21:19', '', '', 'BDG', '', 'https://www.google.com/maps/search/?api=1&query=Kantor+Rizqullah+Mediska+Indonesia+Bandung+%28Internal%29'),
(55, 'BGR-INT', 'Kantor Rizqullah Mediska Indonesia (Internal)', 'Internal', 'Kantor Rizqullah Mediska Indonesia (Internal)', '', '', '', 'Kantor', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:21:10', '', '', 'BGR', '', 'https://www.google.com/maps/search/?api=1&query=Kantor+Rizqullah+Mediska+Indonesia+%28Internal%29'),
(56, 'BKS-INT', 'Kantor Rizqullah Mediska Indonesia Bekasi (Internal)', 'Internal', 'Kantor Rizqullah Mediska Indonesia Bekasi (Internal)', '', '', '', 'Kantor', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:20:56', '', '', 'BKS', '', 'https://www.google.com/maps/search/?api=1&query=Kantor+Rizqullah+Mediska+Indonesia+Bekasi+%28Internal%29'),
(57, 'JGY-INT', 'Kantor Depo Yogyakarta (Internal)', 'Internal', 'Kantor Depo Yogyakarta (Internal)', '', '', '', 'Kantor', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:20:40', '', '', 'JGY', '', 'https://www.google.com/maps/search/?api=1&query=Kantor+Depo+Yogyakarta+%28Internal%29'),
(58, 'KAL-INT', 'Kantor Depo Kalimantan (Internal)', 'Internal', 'Kantor Depo Kalimantan (Internal)', '', '', '', 'Kantor', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:20:30', '', '', 'KAL', '', 'https://www.google.com/maps/search/?api=1&query=Kantor+Depo+Kalimantan+%28Internal%29'),
(59, 'SLO-INT', 'Kantor Rizqullah Mediska Indonesia Jawa Tengah (Solo) (Internal)', 'Internal', 'Kantor Rizqullah Mediska Indonesia Jawa Tengah (Solo) (Internal)', NULL, NULL, NULL, 'Kantor', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, NULL, '', 'SLO', NULL, NULL),
(60, 'SMG-INT', 'Kantor Rizqullah Mediska Indonesia Semarang (Internal)', 'Internal', 'Kantor Rizqullah Mediska Indonesia Semarang (Internal)', NULL, NULL, NULL, 'Kantor', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, NULL, '', 'SMG', NULL, NULL),
(61, 'SYS-INT', 'Kantor SYS (Internal)', 'Internal', 'Kantor SYS (Internal)', NULL, NULL, NULL, 'Kantor', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, NULL, '', 'SYS', NULL, NULL),
(62, 'TGR-INT', 'Kantor Rizqullah Mediska Indonesia Tangerang (Internal)', 'Internal', 'Kantor Rizqullah Mediska Indonesia Tangerang (Internal)', NULL, NULL, NULL, 'Kantor', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, NULL, '', 'TGR', NULL, NULL),
(114, 'H001', 'RS HERMINA ACEH', 'RS Swasta', 'RS HERMINA ACEH', '06518072525', '', '42.455.114.1.108.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl.Soekarno Hatta RT 000 RW 000,Aje Cut Ingin Jaya Kab Aceh Besar Aceh', 'Kab Aceh Besar', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/km1ixb5i9omdrRhs5'),
(115, 'H002', 'RS HERMINA ARCAMANIK', 'RS Swasta', 'RS HERMINA ARCAMANIK', '02287242525', 'farmasi.herminaarca@gmail.com', '02.789.562.2.429-000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. A.H. Nasution KM7 No. 50, Kel. Antapani Wetan, Kec. Antapani, kota Bandung', 'Kota Bandung', 'BGR', 'Bandung & sekitarnya', 'https://maps.app.goo.gl/AEb2eoqkQY7fVgVq7'),
(116, 'H003', 'RS HERMINA BALIKPAPAN', 'RS Swasta', 'RS HERMINA BALIKPAPAN', '05428515230', 'farmasi.balikpapan@herminahospitals.com', '74.937.119.1.721.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. MT Haryono RT 45, Sepingan Baru Balikpapan, Kalimantan Timur, Indonesia', 'Kota Balikpapan', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/8NRU37LAZGZKtn7X6'),
(117, 'H004', 'RS HERMINA BANYUMANIK', 'RS Swasta', 'RS HERMINA BANYUMANIK', '02476488989', 'farmasi.banyumanik@gmail.com', '31.720.421.2-517.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Jenderal Pol Anton Sujarwo No.195A, Srondol Wetan, Kec. Banyumanik, Kota Semarang, Jawa Tengah 50263', 'Kota Semarang', 'SMR', 'Semarang & sekitarnya', 'https://maps.app.goo.gl/5eRQ6r8LN2jT7qS38'),
(118, 'H005', 'RS HERMINA BEKASI', 'RS Swasta', 'RS HERMINA BEKASI', '0218842121', 'pengadaanfarmasi.rshbks@gmail.com', '01.783.421.9-007.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Kemakmuran No.39, RT.004/RW.003, Marga Jaya, Kec. Bekasi Sel., Kota Bks, Jawa Barat 17141', 'Kota Bekasi', 'BKS', 'Bekasi & sekitarnya', 'https://maps.app.goo.gl/QDNmGJ5srtDkhBrU8'),
(119, 'H006', 'RS HERMINA BITUNG', 'RS Swasta', 'RS HERMINA BITUNG', '02159497525', '', '73.379.780.7-451.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'QHH6+FW4, Jl. Raya Serang No.10, Kadu, Kec. Curug, Kabupaten Tangerang, Banten 15810', 'Kabupaten Tangerang', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/4BhpzLZQ1c1B334C7'),
(120, 'H007', 'RS HERMINA BOGOR', 'RS Swasta', 'RS HERMINA BOGOR', '02518382525', '', '02.073.142.8.404.001', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jalan Ring Road I Kav. 23, 25, 27, RT.08/RW.08, Curugmekar, Kec. Bogor Bar., Kota Bogor, Jawa Barat 16113', 'Kota Bogor', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/Rmiz4aLbpWnK8kx17'),
(121, 'H008', 'RS HERMINA CIAWI', 'RS Swasta', 'RS HERMINA CIAWI', '02518407575', 'ifrsciawi@gmail.com', '08.614.217.1-743.4.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Raya Puncak - Gadog No.23, Pandansari, Kec. Ciawi, Kabupaten Bogor, Jawa Barat 16720', 'Kabupaten Bogor', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/BSPkTMnLCLVK7AHE8'),
(122, 'H009', 'RS HERMINA CILEDUG', 'RS Swasta', 'RS HERMINA CILEDUG', '02173454951', 'farmasiciledug@gmail.com', '04.124.281.0.441.6000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Cipto Mangunkusumo Gg. H. Mencong No. 3, Ciledug, RT.003/RW.004, East Sudimara, Tangerang, Tangerang City, Banten 15151', 'Kota Tangerang', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/FV1pWnuzmmhkRSwW6'),
(123, 'H010', 'RS HERMINA CILEGON', 'RS Swasta', 'RS HERMINA CILEGON', '02547812525', 'farmasi.herminacilegon@gmail.com', '86.126.303.6-417.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Kawasan PT Bonauli Real Estate, Jl. Terusan Jl. Bonakarta, RT.01/RW.01, Masigit, Kec. Jombang, Kota Cilegon, Banten 42414', 'Kota Cilegon', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/eykeAimUcenjdjLt8'),
(124, 'H011', 'RS HERMINA CIPUTAT', 'RS Swasta', 'RS HERMINA CIPUTAT', '02174702525', 'farmasi.ciputat@herminahospitals.com', '31.190.968.3-411.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jalan Ciputat Raya Jl. Kertamukti No.2, Ciputat, Ciputat Timur, South Tangerang City, Banten 15419', 'Kota Tangerang Selatan', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/15rDuKr746oU2MeYA'),
(125, 'H012', 'RS HERMINA CIRUAS', 'RS Swasta', 'RS HERMINA CIRUAS', '0254281829', 'farmasi.ciruas@herminahospitals.com', '70.970.383.9-401.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Raya Serang - Jkt No.Km.9, Ranjeng, Kec. Ciruas, Kabupaten Serang, Banten 42182', 'Kabupaten Serang', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/B1b8xskRYAnUCQ2r5'),
(126, 'H013', 'RS HERMINA DAAN MOGOT', 'RS Swasta', 'RS HERMINA DAAN MOGOT', '0215408989', 'farmasi.daanmogot@gmail.com', '02.073.114.7-038.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Kintamani Raya No.2, RT.1/RW.12, Kalideres, Kec. Kalideres, Kota Jakarta Barat, Daerah Khusus Ibukota Jakarta 11840', 'Jakarta Barat', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/PywBthw61yjr5mki9'),
(127, 'H014', 'RS HERMINA DEPOK', 'RS Swasta', 'RS HERMINA DEPOK', '0211500488', '', '01.973.467.2.007.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Siliwangi No.50, Depok, Kec. Pancoran Mas, Kota Depok, Jawa Barat 16431', 'Kota Depok', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/9qYcBXEXUEmoYxj86'),
(128, 'H015', 'RS HERMINA GALAXY', 'RS Swasta', 'RS HERMINA GALAXY', '0218222525', 'farmasi.galaxy@herminahospitals.com', '31.173.812.4-432.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Ruko Grand Galaxy City, Jl. Boulevar Raya Bar., RT.003/RW.017, Jaka Setia, Kec. Bekasi Sel., Kabupaten Bekasi, Jawa Barat 17147', 'Kabupaten Bekasi', 'BKS', 'Bekasi & sekitarnya', 'https://maps.app.goo.gl/Y3c2kmuCC2KaMJy56'),
(129, 'H016', 'RS HERMINA GRAND WISATA', 'RS Swasta', 'RS HERMINA GRAND WISATA', '02182651212', '', '21.026.393.5-431.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. West Gateway Blvd No.1 Blok JA 1, Lambangsari, Kec. Tambun Sel., Kabupaten Bekasi, Jawa Barat 17510', 'Kabupaten Bekasi', 'BKS', 'Bekasi & sekitarnya', 'https://maps.app.goo.gl/goSrGuHJtZXTQXMU8'),
(130, 'H017', 'RS HERMINA NUSANTARA', 'RS Swasta', 'RS HERMINA NUSANTARA', '05428252520', 'farmasi.rsuherminanusantara@gmail.com', '00.192.011.5.100.7.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Kawasan Inti Pusat Pemerintahan, Bumi Harapan, Kec. Sepaku, Kabupaten Penajam Paser Utara, Kalimantan Timur', 'Kabupaten Penajam Paser Utara', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/JffieQntbUBqCujB6'),
(131, 'H018', 'RS HERMINA JATINEGARA', 'RS Swasta', 'RS HERMINA JATINEGARA', '0218513838', 'farmasi.jatinegara@herminahospitals.com', '01.920.115.1.007.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Jatinegara Barat 126 Jakarta Timur, DKI Jakarta, Indonesia 13320', 'Jakarta Timur', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/8beVnXdhbdiP6a2U7'),
(132, 'H019', 'RS HERMINA KARAWANG', 'RS Swasta', 'RS HERMINA KARAWANG', '02678412525', 'farmasi.karawang@herminahospitals.com', '83.675.630.4-408.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jalan Tuparev, Blok Gg. Sukasari No.386A, RT.2/RW.4, Karawang Wetan, Kec. Karawang Tim., Karawang, Jawa Barat 41314', 'Kabupaten Karawang', 'BKS', 'Bekasi & sekitarnya', 'https://maps.app.goo.gl/NcKSs2VfCvgc8kVY9'),
(133, 'H020', 'RS HERMINA KEMAYORAN', 'RS Swasta', 'RS HERMINA KEMAYORAN', '02122602525', 'farmasikemayoran.hermina@gmail.com', '01.364.468.7-046.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Selangit Kav. 4, RW.10, Gn. Sahari Sel., Kec. Kemayoran, Kota Jakarta Pusat, Daerah Khusus Ibukota Jakarta 10620', 'Kota Jakarta Pusat', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/Q8gpMvP4vnZpe4Th8'),
(134, 'H021', 'RS HERMINA KENDARI', 'RS Swasta', 'RS HERMINA KENDARI', '04013192525', 'keuangan.kendari@herminahospitals.com', '84.671.659.5.811.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. DI Panjaitan, RT.06/RW.03, Wundudopi, Kec. Baruga, Kota Kendari, Sulawesi Tenggara 93117', 'Kota Kendari', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/cSKseyJtifP3LZYX7'),
(135, 'H022', 'RS HERMINA KUTABUMI / PERIUK TANGERANG', 'RS Swasta', 'RS HERMINA KUTABUMI / PERIUK TANGERANG', '02129432525', '', '85.048.181.3-402.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Moh. Toha, RT.001/RW.007, Nagrak, Kec. Periuk, Kota Tangerang, Banten 15131', 'Kota Tangerang', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/smaYDyeZFLGApxGc7'),
(136, 'H023', 'RS HERMINA LAMPUNG', 'RS Swasta', 'RS HERMINA LAMPUNG', '0721242525', 'jangmed.lampung@herminahospitals.com\nhutang.lampung@herminahospitals.com\nkeuangan.lampung@herminahospitals.com', '08.648.072.7.632.2.00', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Tulang Bawang No.21-23, Enggal, Engal, Kota Bandar Lampung, Lampung 35213', 'Kota Bandar Lampung', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/Zqf1Bbuoo452K3y76'),
(137, 'H024', 'RS HERMINA MADIUN', 'RS Swasta', 'RS HERMINA MADIUN', '03514108585', 'farmasi.hermina49@gmail.com', '94.070.566.8-532.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:59:54', 'Jl. Sido Makmur Jl. Ring Road Barat, Manguharjo, Kec. Manguharjo, Kota Madiun, Jawa Timur 63127', 'Kota Madiun', 'SLO', '', 'https://maps.app.goo.gl/1fnMAQiinAPWZqy79'),
(138, 'H025', 'RS HERMINA MAKASSAR', 'RS Swasta', 'RS HERMINA MAKASSAR', '04114091817', '', '73.472.002.2.805.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Toddopuli Raya Timur No.7, Borong, Kec. Manggala, Kota Makassar, Sulawesi Selatan 90231', 'Kota Makassar', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/CHE7SzT9gr2K4AEP9'),
(139, 'H026', 'RS HERMINA MANADO', 'RS Swasta', 'RS HERMINA MANADO', '04317242525', 'farmasi.manado@herminahospitals.com', '82.392.404.882.1.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Ring Road Manado II, Lingkungan I, Paniki Bawah, Kec. Mapanget, Kota Manado, Sulawesi Utara', 'Kota Manado', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/iZ1G4huqJitAjabV6'),
(140, 'H027', 'RS HERMINA MEDAN', 'RS Swasta', 'RS HERMINA MEDAN', '06180862525', 'farmasi.medan@herminahospitals.com', '75.709.650.8.124.00', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Asrama, Sei Sikambing C. II, Kec. Medan Helvetia, Kota Medan, Sumatera Utara 20123', 'Kota Medan', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/Q9WMYfEzyJQ3uHmp6'),
(141, 'H028', 'RS HERMINA MEKARSARI', 'RS Swasta', 'RS HERMINA MEKARSARI', '02129232525', 'farmasi.mekarsari@herminahospitals.com', '31.417.132.3.436.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Raya Cileungsi - Jonggol No.Km. 1, Cileungsi Kidul, Kec. Cileungsi, Kabupaten Bogor, Jawa Barat 16820', 'Kabupaten Bogor', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/mWGaL4Y8WATCkwAh7'),
(142, 'H029', 'RS HERMINA METLAND CIBITUNG', 'RS Swasta', 'RS HERMINA METLAND CIBITUNG', '02188362626', '', '91.400.950.1-413.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Perumahan Metland Cibitung, Jl. Metland Cibitung, Telagamurni, Kec. Cikarang Bar., Kabupaten Bekasi, Jawa Barat 17530', 'Kabupaten Bekasi', 'BKS', 'Bekasi & sekitarnya', 'https://maps.app.goo.gl/38J7BDz6wTHXcVXC7'),
(143, 'H030', 'RS HERMINA MUTIARA BUNDA SALATIGA', 'RS Swasta', 'RS HERMINA MUTIARA BUNDA SALATIGA', '0298329500', '', '96.677.893.8-505.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Merak No.8, Mangunsari, Kec. Sidomukti, Kota Salatiga, Jawa Tengah 50721', 'Kota Salatiga', 'SMR', 'Semarang & sekitarnya', 'https://maps.app.goo.gl/jBwfGveybso8FBi59'),
(144, 'H031', 'RS HERMINA OPI JAKABARING', 'RS Swasta', 'RS HERMINA OPI JAKABARING', '07113031520', 'farmasi.opijakabaring@herminahospitals.com', '08.209.521.1.7314.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Gubernur H. A Bastari, RT.010/RW.005, Kelurahan Jakabaring Selatan, Kec. Rambutan, Kab. Banyuasin, Sumatera Selatan 30257', 'Kabupaten Banyuasin', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/SsjawVhQE34Jhgrj6'),
(145, 'H032', 'RS HERMINA PALEMBANG', 'RS Swasta', 'RS HERMINA PALEMBANG', '1500488', 'keuangan.palembang@herminahospitals.com', '', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Jend. Basuki Rachmat No.897, Pahlawan, Kec. Kemuning, Kota Palembang, Sumatera Selatan 30127', 'Kota Palembang', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/26BXznJVLmCucJXE7'),
(146, 'H033', 'RS HERMINA PANDANARAN', 'RS Swasta', 'RS HERMINA PANDANARAN', '0248442525', 'tukarfakturfarmasiherpanda@gmail.com CC inkaso.rshpandanaran@gmail.com', '02.204.515.7-511.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Pandanaran No.24, Pekunden, Kec. Semarang Tengah, Kota Semarang, Jawa Tengah 50134', 'Kota Semarang', 'SMR', 'Semarang & sekitarnya', 'https://maps.app.goo.gl/9K3nhUKmKjCSysaJ8'),
(147, 'H034', 'RS HERMINA PASTEUR', 'RS Swasta', 'RS HERMINA PASTEUR', '0226072525', 'pengadaanherminapasteur17@gmail.com', '02.244.242.2-441.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:59:40', 'Jl. Dr. Djunjunan No.107, Pasteur, Kec. Cicendo, Kota Bandung, Jawa Barat 40173', 'Kota Bandung', 'BDG', '', 'https://maps.app.goo.gl/WtWVYRAAhBM8HtWT9'),
(148, 'H035', 'RS HERMINA PASURUAN', 'RS Swasta', 'RS HERMINA PASURUAN', '03434742523', 'farmasi.pasuruan@hermina.com', '53.126.379.6-446.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 04:00:41', 'Jl Raya Pasuruan,Probolinggo Km 5 RT 01 RW 01,Desa Sambirejo Kec Rejoso Kab Pasuruan-Jawa timur 67181', 'Kabupaten Pasuruan', 'SLO', '', 'https://maps.app.goo.gl/NkmLcamFfbEHR47C8'),
(149, 'H036', 'RS HERMINA PEKALONGAN', 'RS Swasta', 'RS HERMINA PEKALONGAN', '0285432525', 'farmasi.pekalongan@herminahospitals.com', '916448483502000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Jenderal Sudirman No.16a, RW.6, Podosugih, Kec. Pekalongan Bar., Kota Pekalongan, Jawa Tengah 51112', 'Kota Pekalongan', 'SMR', 'Semarang & sekitarnya', 'https://maps.app.goo.gl/RcCu6e9Bq8ZmgUEcA'),
(150, 'H037', 'RS HERMINA PEKANBARU', 'RS Swasta', 'RS HERMINA PEKANBARU', '07618411919', 'keuangan.pekanbaru@herminahospitals.com keuangan.pekanbaru@herminahospitals.com', '0836260851216000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Tuanku Tambusai, Delima, Kec. Tampan, Kota Pekanbaru, Riau 28292', 'Kota Pekanbaru', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/or8TkYahUEySWBKRA'),
(151, 'H038', 'RS HERMINA PIK DUA', 'RS Swasta', 'RS HERMINA PIK DUA', '1500488', 'jangmed.pekanbaru@herminahospitals.com', '04.218.818.2.2.418.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Raya Boulevard Osaka, Salembaran, Kosambi, Tangerang Regency, Banten 15214', 'Kabupaten Tangerang', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/Mvfo8tWBnux4VSMq7'),
(152, 'H039', 'RS HERMINA PODOMORO', 'RS Swasta', 'RS HERMINA PODOMORO', '0216404910', '', '81.666.093.0.048.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Blok E 3, Jl. Danau Agung 2 No.28 - 30, RT.3/RW.16, Sunter Agung, Kec. Tj. Priok, Jkt Utara, Daerah Khusus Ibukota Jakarta 14350', 'Kota Jakarta Utara', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/xcmxZtx2XgCRfW3u7'),
(153, 'H040', 'RS HERMINA PURWOKERTO', 'RS Swasta', 'RS HERMINA PURWOKERTO', '02817772525', 'farmasi.purwokerto@herminahospitals.com', '75.830.148.5-521.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Yos Sudarso, RT.03/RW.01, Karanglewas Lor, Kec. Purwokerto Bar., Kabupaten Banyumas, Jawa Tengah 53136', 'Kabupaten Banyumas', 'SMR', 'Semarang & sekitarnya', 'https://maps.app.goo.gl/qsGKpW5FBHhZ7kEr7'),
(154, 'H041', 'RS HERMINA SAMARINDA', 'RS Swasta', 'RS HERMINA SAMARINDA', '05412090707', 'farmasi.samarinda@herminahospitals.com', '80.876.505.1.722.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Teuku Umar No.RT. 34, Karang Asam Ilir, Kec. Sungai Kunjang, Kota Samarinda, Kalimantan Timur 75126', 'Kota Samarinda', 'BGR', 'Samarinda', 'https://maps.app.goo.gl/cygF642mjnaGrF1AA'),
(155, 'H042', 'RS HERMINA SERPONG', 'RS Swasta', 'RS HERMINA SERPONG', '02175884999', 'farmasi.serpong@herminahospitals.com', '31.823.871.4-411.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Raya Puspitek No.km 1 No 99, Buaran, Kec. Serpong, Kota Tangerang Selatan, Banten 15310', 'Kota Tangerang Selatan', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/76Xq7r96PPb2xEtB7'),
(156, 'H043', 'RS HERMINA SOLO', 'RS Swasta', 'RS HERMINA SOLO', '0271638989', 'gudangfarmasi.solo@gmail.com', '31.772.971.3-526.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:58:08', 'Jl. Kolonel Sutarto No.16, Jebres, Kec. Jebres, Kota Surakarta, Jawa Tengah 57126', 'Kota Surakarta', 'SLO', '', 'https://maps.app.goo.gl/Eukb6pGMGQyMcxqA7'),
(157, 'H044', 'RS HERMINA SOREANG', 'RS Swasta', 'RS HERMINA SOREANG', '0225892525', 'farmasisoreang@gmail.com', '41.244.472.1-445.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Terusan Al Fathu No.9A, Soreang, Kec. Soreang, Kabupaten Bandung, Jawa Barat 40911', 'Kabupaten Bandung', 'TGR', 'Bandung & sekitarnya', 'https://maps.app.goo.gl/w1A5138QiuBspSPY7'),
(158, 'H045', 'RS HERMINA SUKABUMI', 'RS Swasta', 'RS HERMINA SUKABUMI', '02666252525', 'kontrabonskb@gmail.com', '02.522.444.5.405.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Raya Sukaraja, RT.003/RW.003, Sukaraja, Kec. Sukaraja, Kabupaten Sukabumi, Jawa Barat 43192', 'Kabupaten Sukabumi', 'BGR', 'Bogor & sekitarnya', 'https://maps.app.goo.gl/PnoqtX86ZuVRKVtT6'),
(159, 'H046', 'RS HERMINA TANGERANG', 'RS Swasta', 'RS HERMINA TANGERANG', '02155772525', '', '02.673.095.2-415.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Ks. Tubun No.10, Ps. Baru, Kec. Karawaci, Kota Tangerang, Banten 15112', 'Kota Tangerang', 'TGR', 'Tangerang & sekitarnya', 'https://maps.app.goo.gl/QnfY14xrsNRe1RR96'),
(160, 'H047', 'RS HERMINA TANGKUBANPRAHU', 'RS Swasta', 'RS HERMINA TANGKUBANPRAHU', '0341322525', 'hutang.tangkubanprahu@herminahospitals.com', '0023481393651000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:59:20', 'Jl. Tangkuban Perahu No.29-33, Kauman, Kec. Klojen, Kota Malang, Jawa Timur 65119', 'Kota Malang', 'SLO', '', 'https://maps.app.goo.gl/JzHuyq7aeDs8AuwAA'),
(161, 'H048', 'RS HERMINA TASIKMALAYA', 'RS Swasta', 'RS HERMINA TASIKMALAYA', '02653172525', 'farmasi.tasikmalaya@herminahospitals.com', '43.317.218.6-425.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:58:25', 'Jl. Ir. H. Juanda No.7A, RT.03/RW.14, Cipedes, Kec. Cipedes, Kab. Tasikmalaya, Jawa Barat 46133', 'Kabupaten Tasikmalaya', 'BDG', '', 'https://maps.app.goo.gl/vE6NzkxYWxR6LQYf7'),
(162, 'H049', 'RS  UBAYA', 'RS Swasta', 'RS  UBAYA', '03199211515', 'gu.farmasi@rs.ubaya.ac.id', '91.076.254.1.606.000', 'Non Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:58:41', 'Jl. Raya Panjang Jiwo Permai No.87, Panjang Jiwo, Kec. Tenggilis Mejoyo, Surabaya, Jawa Timur 60299', 'Kota Surabaya', 'SLO', '', 'https://maps.app.goo.gl/AoAfkbj3Q2mtaCCZ9'),
(163, 'H050', 'RS HERMINA WONOGIRI', 'RS Swasta', 'RS HERMINA WONOGIRI', '02735327365', 'farmasi.wonogiri@herminahospitals.com', '94.070.566.8-532.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-03-08 03:58:58', 'Jl. Raya Wonogiri-Ponorogo No.KM. 5, Jatibedug, Purworejo, Kec. Wonogiri, Purworejo, Jawa Tengah 57612', 'Kabupaten Wonogiri', 'SLO', '', 'https://maps.app.goo.gl/yUfALJACAyjHSriUA'),
(164, 'H051', 'RS HERMINA YOGYA', 'RS Swasta', 'RS HERMINA YOGYA', '02742800808', 'farmasi.yogya@herminahospitals.com', '04.218.818.2.2.418.000', 'Hermina', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 'Jl. Selokan Mataram, RT.06/RW.50, Meguwo, Maguwoharjo, Kec. Depok, Kabupaten Sleman, Daerah Istimewa Yogyakarta 55282', 'Kabupaten Sleman', 'BGR', 'Yogyakarta', 'https://maps.app.goo.gl/b6pigraRxg9ngB2NA');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_departements`
--

DROP TABLE IF EXISTS `master_departements`;
CREATE TABLE `master_departements` (
  `id` int(11) NOT NULL,
  `dept_code` varchar(20) NOT NULL,
  `dept_name` varchar(150) NOT NULL,
  `level_type` varchar(20) NOT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_departements`
--

TRUNCATE TABLE `master_departements`;
--
-- Dumping data untuk tabel `master_departements`
--

INSERT DELAYED IGNORE INTO `master_departements` (`id`, `dept_code`, `dept_name`, `level_type`, `office_code`, `status`, `created_at`, `updated_at`) VALUES
(1, 'MPR', 'Marketing & Project', 'Manager', 'BGR', 'active', '2025-12-10 05:21:08', '2026-03-04 05:39:25'),
(2, 'CRM', 'Customer Relationship Management', 'Manager', 'BGR', 'active', '2025-12-10 05:21:08', '2026-03-04 05:39:25'),
(3, 'WQS', 'Warehouse & Quality Stock', 'Manager', 'BGR', 'active', '2025-12-10 05:21:08', '2026-03-04 05:39:25'),
(4, 'SCM', 'Supply Chain Management', 'Manager', 'BGR', 'active', '2025-12-10 05:21:08', '2026-03-04 05:39:25'),
(5, 'ACT', 'Accounting & Tax', 'Manager', 'BGR', 'active', '2025-12-10 05:21:08', '2026-03-04 05:39:25'),
(6, 'FIN', 'Finance', 'Manager', 'BGR', 'active', '2025-12-10 05:21:08', '2026-03-04 05:39:25'),
(7, 'HRL', 'Human Resource & Legal', 'Manager', 'BGR', 'active', '2025-12-10 05:21:08', '2026-03-04 05:39:25'),
(8, 'PQP', 'Product Quality & Purchasing', 'Manager', 'BGR', 'active', '2025-12-10 05:21:08', '2026-03-04 05:39:25'),
(9, 'ITC', 'IT & Cloud', 'Manager', 'BGR', 'active', '2025-12-10 05:21:08', '2026-03-04 05:39:25'),
(1054, 'MPR', 'Marketing & Project', 'Staff', 'KAL', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1055, 'MPR', 'Marketing & Project', 'Staff', 'JGY', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1056, 'MPR', 'Marketing & Project', 'Staff', 'BGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1057, 'MPR', 'Marketing & Project', 'Staff', 'BDG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1058, 'MPR', 'Marketing & Project', 'Staff', 'BKS', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1059, 'MPR', 'Marketing & Project', 'Staff', 'SLO', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1060, 'MPR', 'Marketing & Project', 'Staff', 'SMG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1061, 'MPR', 'Marketing & Project', 'Staff', 'TGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1062, 'CRM', 'Customer Relationship Management', 'Staff', 'KAL', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1063, 'CRM', 'Customer Relationship Management', 'Staff', 'JGY', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1064, 'CRM', 'Customer Relationship Management', 'Staff', 'BGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1065, 'CRM', 'Customer Relationship Management', 'Staff', 'BDG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1066, 'CRM', 'Customer Relationship Management', 'Staff', 'BKS', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1067, 'CRM', 'Customer Relationship Management', 'Staff', 'SLO', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1068, 'CRM', 'Customer Relationship Management', 'Staff', 'SMG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1069, 'CRM', 'Customer Relationship Management', 'Staff', 'TGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1070, 'WQS', 'Warehouse & Quality Stock', 'Staff', 'KAL', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1071, 'WQS', 'Warehouse & Quality Stock', 'Staff', 'JGY', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1072, 'WQS', 'Warehouse & Quality Stock', 'Staff', 'BGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1073, 'WQS', 'Warehouse & Quality Stock', 'Staff', 'BDG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1074, 'WQS', 'Warehouse & Quality Stock', 'Staff', 'BKS', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1075, 'WQS', 'Warehouse & Quality Stock', 'Staff', 'SLO', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1076, 'WQS', 'Warehouse & Quality Stock', 'Staff', 'SMG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1077, 'WQS', 'Warehouse & Quality Stock', 'Staff', 'TGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1078, 'SCM', 'Supply Chain Management', 'Staff', 'KAL', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1079, 'SCM', 'Supply Chain Management', 'Staff', 'JGY', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1080, 'SCM', 'Supply Chain Management', 'Staff', 'BGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1081, 'SCM', 'Supply Chain Management', 'Staff', 'BDG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1082, 'SCM', 'Supply Chain Management', 'Staff', 'BKS', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1083, 'SCM', 'Supply Chain Management', 'Staff', 'SLO', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1084, 'SCM', 'Supply Chain Management', 'Staff', 'SMG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1085, 'SCM', 'Supply Chain Management', 'Staff', 'TGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1086, 'ACT', 'Accounting & Tax', 'Staff', 'KAL', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1087, 'ACT', 'Accounting & Tax', 'Staff', 'JGY', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1088, 'ACT', 'Accounting & Tax', 'Staff', 'BGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1089, 'ACT', 'Accounting & Tax', 'Staff', 'BDG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1090, 'ACT', 'Accounting & Tax', 'Staff', 'BKS', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1091, 'ACT', 'Accounting & Tax', 'Staff', 'SLO', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1092, 'ACT', 'Accounting & Tax', 'Staff', 'SMG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1093, 'ACT', 'Accounting & Tax', 'Staff', 'TGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1094, 'FIN', 'Finance', 'Staff', 'KAL', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1095, 'FIN', 'Finance', 'Staff', 'JGY', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1096, 'FIN', 'Finance', 'Staff', 'BGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1097, 'FIN', 'Finance', 'Staff', 'BDG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1098, 'FIN', 'Finance', 'Staff', 'BKS', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1099, 'FIN', 'Finance', 'Staff', 'SLO', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1100, 'FIN', 'Finance', 'Staff', 'SMG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1101, 'FIN', 'Finance', 'Staff', 'TGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1102, 'HRL', 'Human Resource & Legal', 'Staff', 'KAL', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1103, 'HRL', 'Human Resource & Legal', 'Staff', 'JGY', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1104, 'HRL', 'Human Resource & Legal', 'Staff', 'BGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1105, 'HRL', 'Human Resource & Legal', 'Staff', 'BDG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1106, 'HRL', 'Human Resource & Legal', 'Staff', 'BKS', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1107, 'HRL', 'Human Resource & Legal', 'Staff', 'SLO', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1108, 'HRL', 'Human Resource & Legal', 'Staff', 'SMG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1109, 'HRL', 'Human Resource & Legal', 'Staff', 'TGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1110, 'PQP', 'Product Quality & Purchasing', 'Staff', 'KAL', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1111, 'PQP', 'Product Quality & Purchasing', 'Staff', 'JGY', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1112, 'PQP', 'Product Quality & Purchasing', 'Staff', 'BGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1113, 'PQP', 'Product Quality & Purchasing', 'Staff', 'BDG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1114, 'PQP', 'Product Quality & Purchasing', 'Staff', 'BKS', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1115, 'PQP', 'Product Quality & Purchasing', 'Staff', 'SLO', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1116, 'PQP', 'Product Quality & Purchasing', 'Staff', 'SMG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1117, 'PQP', 'Product Quality & Purchasing', 'Staff', 'TGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1118, 'ITC', 'IT & Cloud', 'Staff', 'KAL', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1119, 'ITC', 'IT & Cloud', 'Staff', 'JGY', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1120, 'ITC', 'IT & Cloud', 'Staff', 'BGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1121, 'ITC', 'IT & Cloud', 'Staff', 'BDG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1122, 'ITC', 'IT & Cloud', 'Staff', 'BKS', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1123, 'ITC', 'IT & Cloud', 'Staff', 'SLO', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1124, 'ITC', 'IT & Cloud', 'Staff', 'SMG', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1125, 'ITC', 'IT & Cloud', 'Staff', 'TGR', 'active', '2025-12-10 14:31:56', '2026-03-04 05:39:25'),
(1126, 'SYS', 'System', 'Manager', 'BGR', 'active', '2026-01-01 23:55:47', '2026-03-04 05:39:25'),
(1127, 'SYS', 'System', 'Staff', 'KAL', 'active', '2026-01-01 23:55:47', '2026-03-04 05:39:25'),
(1128, 'SYS', 'System', 'Staff', 'JGY', 'active', '2026-01-01 23:55:47', '2026-03-04 05:39:25'),
(1129, 'SYS', 'System', 'Staff', 'BGR', 'active', '2026-01-01 23:55:47', '2026-03-04 05:39:25'),
(1130, 'SYS', 'System', 'Staff', 'BDG', 'active', '2026-01-01 23:55:47', '2026-03-04 05:39:25'),
(1131, 'SYS', 'System', 'Staff', 'BKS', 'active', '2026-01-01 23:55:47', '2026-03-04 05:39:25'),
(1135, 'SYS', 'System', 'SYS', NULL, 'active', '2026-01-02 01:39:42', '2026-03-04 05:39:25'),
(1136, 'MPR', 'Marketing & Project', 'Staff', 'SYS', 'active', '2026-01-02 05:58:35', '2026-03-04 05:39:25'),
(1139, 'SCM', 'Supply Chain Management', 'Staff', 'SYS', 'active', '2026-01-02 05:58:35', '2026-03-04 05:39:25'),
(1141, 'FIN', 'Finance', 'Staff', 'SYS', 'active', '2026-01-02 05:58:35', '2026-03-04 05:39:25'),
(1142, 'HRL', 'Human Resource & Legal', 'Staff', 'SYS', 'active', '2026-01-02 05:58:35', '2026-03-04 05:39:25'),
(1143, 'PQP', 'Product Quality & Purchasing', 'Staff', 'SYS', 'active', '2026-01-02 05:58:35', '2026-03-04 05:39:25'),
(1144, 'ITC', 'IT & Cloud', 'Staff', 'SYS', 'active', '2026-01-02 05:58:35', '2026-03-04 05:39:25'),
(1149, 'CRM', 'Customer Relationship Management', 'Staff', 'SYS', 'active', '2026-01-02 06:42:22', '2026-03-04 05:39:25'),
(1151, 'ACT', 'Accounting & Tax', 'Staff', 'SYS', 'active', '2026-01-02 06:43:28', '2026-03-04 05:39:25'),
(1152, 'WQS', 'Warehouse & Quality Stock', 'Staff', 'SYS', 'active', '2026-01-02 06:46:03', '2026-03-04 05:39:25'),
(1153, 'CRM', 'Customer Relationship Management', 'Manager', 'BDG', 'active', '2026-02-26 07:57:21', '2026-03-04 05:39:25'),
(1154, 'MPR', 'Marketing & Project', 'Manager', 'BDG', 'active', '2026-02-26 07:57:21', '2026-03-04 05:39:25'),
(1155, 'WQS', 'Warehouse & Quality Stock', 'Manager', 'BDG', 'active', '2026-02-26 07:57:21', '2026-03-04 05:39:25'),
(1156, 'SCM', 'Supply Chain Management', 'Manager', 'BDG', 'active', '2026-02-26 07:57:21', '2026-03-04 05:39:25'),
(1157, 'HRL', 'Human Resource & Legal', 'Manager', 'BDG', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1158, 'PQP', 'Product Quality & Purchasing', 'Manager', 'BDG', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1159, 'ITC', 'IT & Cloud', 'Manager', 'BDG', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1160, 'ACT', 'Accounting & Tax', 'Manager', 'BDG', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1161, 'FIN', 'Finance', 'Manager', 'BDG', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1162, 'SYS', 'System', 'SYS', 'BDG', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1163, 'SYS', 'System', 'SYS', 'BGR', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1164, 'CRM', 'Customer Relationship Management', 'Manager', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1165, 'MPR', 'Marketing & Project', 'Manager', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1166, 'WQS', 'Warehouse & Quality Stock', 'Manager', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1167, 'SCM', 'Supply Chain Management', 'Manager', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1168, 'HRL', 'Human Resource & Legal', 'Manager', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1169, 'PQP', 'Product Quality & Purchasing', 'Manager', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1170, 'ITC', 'IT & Cloud', 'Manager', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1171, 'ACT', 'Accounting & Tax', 'Manager', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1172, 'FIN', 'Finance', 'Manager', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1173, 'SYS', 'System', 'SYS', 'BKS', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1174, 'CRM', 'Customer Relationship Management', 'Manager', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1175, 'MPR', 'Marketing & Project', 'Manager', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1176, 'WQS', 'Warehouse & Quality Stock', 'Manager', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1177, 'SCM', 'Supply Chain Management', 'Manager', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1178, 'HRL', 'Human Resource & Legal', 'Manager', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1179, 'PQP', 'Product Quality & Purchasing', 'Manager', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1180, 'ITC', 'IT & Cloud', 'Manager', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1181, 'ACT', 'Accounting & Tax', 'Manager', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1182, 'FIN', 'Finance', 'Manager', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1183, 'SYS', 'System', 'SYS', 'JGY', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1184, 'CRM', 'Customer Relationship Management', 'Manager', 'KAL', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1185, 'MPR', 'Marketing & Project', 'Manager', 'KAL', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1186, 'WQS', 'Warehouse & Quality Stock', 'Manager', 'KAL', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1187, 'SCM', 'Supply Chain Management', 'Manager', 'KAL', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1188, 'HRL', 'Human Resource & Legal', 'Manager', 'KAL', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1189, 'PQP', 'Product Quality & Purchasing', 'Manager', 'KAL', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1190, 'ITC', 'IT & Cloud', 'Manager', 'KAL', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1192, 'FIN', 'Finance', 'Manager', 'KAL', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1193, 'SYS', 'System', 'SYS', 'KAL', 'active', '2026-02-26 07:57:22', '2026-03-04 05:39:25'),
(1194, 'CRM', 'Customer Relationship Management', 'Manager', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1195, 'MPR', 'Marketing & Project', 'Manager', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1196, 'WQS', 'Warehouse & Quality Stock', 'Manager', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1197, 'SCM', 'Supply Chain Management', 'Manager', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1198, 'HRL', 'Human Resource & Legal', 'Manager', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1199, 'PQP', 'Product Quality & Purchasing', 'Manager', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1200, 'ITC', 'IT & Cloud', 'Manager', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1201, 'ACT', 'Accounting & Tax', 'Manager', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1202, 'FIN', 'Finance', 'Manager', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1203, 'SYS', 'System', 'SYS', 'SLO', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1204, 'CRM', 'Customer Relationship Management', 'Manager', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1205, 'MPR', 'Marketing & Project', 'Manager', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1206, 'WQS', 'Warehouse & Quality Stock', 'Manager', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1207, 'SCM', 'Supply Chain Management', 'Manager', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1208, 'HRL', 'Human Resource & Legal', 'Manager', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1209, 'PQP', 'Product Quality & Purchasing', 'Manager', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1210, 'ITC', 'IT & Cloud', 'Manager', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1211, 'ACT', 'Accounting & Tax', 'Manager', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1212, 'FIN', 'Finance', 'Manager', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1213, 'SYS', 'System', 'SYS', 'SMG', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1214, 'CRM', 'Customer Relationship Management', 'Manager', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1215, 'MPR', 'Marketing & Project', 'Manager', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1216, 'WQS', 'Warehouse & Quality Stock', 'Manager', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1217, 'SCM', 'Supply Chain Management', 'Manager', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1218, 'HRL', 'Human Resource & Legal', 'Manager', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1219, 'PQP', 'Product Quality & Purchasing', 'Manager', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1220, 'ITC', 'IT & Cloud', 'Manager', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1221, 'ACT', 'Accounting & Tax', 'Manager', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1222, 'FIN', 'Finance', 'Manager', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(1223, 'SYS', 'System', 'SYS', 'TGR', 'active', '2026-02-26 07:57:23', '2026-03-04 05:39:25'),
(2000, 'BRANCH', 'Branch / Depo Agent', 'Staff', NULL, 'active', '2026-02-28 05:48:08', '2026-03-04 05:39:21'),
(2100, 'BRANCH', 'Branch / Depo Agent', 'Staff', 'KAL', 'active', '2026-02-28 05:48:08', '2026-03-04 05:39:21'),
(2101, 'BRANCH', 'Branch / Depo Agent', 'Staff', 'JGY', 'active', '2026-02-28 05:48:08', '2026-03-04 05:39:21'),
(2102, 'BRANCH', 'Branch / Depo Agent', 'Staff', 'BGR', 'active', '2026-02-28 05:48:08', '2026-03-04 05:39:21'),
(2103, 'BRANCH', 'Branch / Depo Agent', 'Staff', 'BDG', 'active', '2026-02-28 05:48:08', '2026-03-04 05:39:21'),
(2104, 'BRANCH', 'Branch / Depo Agent', 'Staff', 'BKS', 'active', '2026-02-28 05:48:08', '2026-03-04 05:39:21'),
(2105, 'BRANCH', 'Branch / Depo Agent', 'Staff', 'SLO', 'active', '2026-02-28 05:48:08', '2026-03-04 05:39:21'),
(2106, 'BRANCH', 'Branch / Depo Agent', 'Staff', 'SMG', 'active', '2026-02-28 05:48:08', '2026-03-04 05:39:21'),
(2107, 'BRANCH', 'Branch / Depo Agent', 'Staff', 'TGR', 'active', '2026-02-28 05:48:08', '2026-03-04 05:39:21'),
(2108, 'ACT', 'Accounting & Tax', 'Manager', 'KAL', 'active', '2026-02-28 16:28:54', '2026-03-04 05:39:25');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_discount_policy`
--

DROP TABLE IF EXISTS `master_discount_policy`;
CREATE TABLE `master_discount_policy` (
  `id` int(11) NOT NULL,
  `department_code` varchar(20) NOT NULL,
  `level_name` varchar(50) NOT NULL,
  `segment` varchar(50) DEFAULT NULL,
  `max_discount_percent` decimal(5,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_discount_policy`
--

TRUNCATE TABLE `master_discount_policy`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_emailcompany`
--

DROP TABLE IF EXISTS `master_emailcompany`;
CREATE TABLE `master_emailcompany` (
  `id` int(11) NOT NULL,
  `scope_type` varchar(20) NOT NULL DEFAULT 'department',
  `dept_code` varchar(20) DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `email_local` varchar(100) NOT NULL,
  `email_domain` varchar(100) NOT NULL DEFAULT 'rizqullahmediska.com',
  `email_full` varchar(200) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_emailcompany`
--

TRUNCATE TABLE `master_emailcompany`;
--
-- Dumping data untuk tabel `master_emailcompany`
--

INSERT DELAYED IGNORE INTO `master_emailcompany` (`id`, `scope_type`, `dept_code`, `office_code`, `email_local`, `email_domain`, `email_full`, `note`, `is_primary`, `status`, `created_at`, `updated_at`) VALUES
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

DROP TABLE IF EXISTS `master_employees`;
CREATE TABLE `master_employees` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_employees`
--

TRUNCATE TABLE `master_employees`;
--
-- Dumping data untuk tabel `master_employees`
--

INSERT DELAYED IGNORE INTO `master_employees` (`id`, `employee_code`, `employee_name`, `dept_code`, `level_type`, `grade`, `payroll_status`, `payroll_level`, `job_title`, `nik`, `npwp`, `bpjs_tk_no`, `bpjs_kes_no`, `education`, `office_code`, `join_year`, `join_month`, `phone`, `email`, `bank_name`, `bank_branch`, `bank_account_name`, `bank_account_number`, `status`, `note`, `created_at`, `updated_at`) VALUES
(260, 'SYS150901', 'Admin', 'SYS', 'Staff', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'SYS', '2015', '09', NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-02-28 14:35:36', '2026-02-28 21:35:36'),
(261, 'SYS150902', 'SUPERADMIN', 'SYS', 'SYS', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'SYS', '2015', '09', NULL, NULL, NULL, NULL, NULL, NULL, 'active', NULL, '2026-02-28 14:35:36', '2026-02-28 21:35:36'),
(262, 'HRL241101', 'Ai Fatimah', 'HRL', 'Manager', '', NULL, NULL, NULL, '3203117009000003', '3203117009000003', '25022399445', '3645796702', 'S1 KESEHATAN MASYARAKAT', 'BGR', '2024', '11', '85814845928', 'humanresourceanndlegal@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', 'Import awal karyawan', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(263, 'WQS231201', 'Abdul Muhyi', 'WQS', 'Staff', '', NULL, NULL, NULL, '3201162311030002', '3201162311030002', '24054723333', '384857234', 'SMK', 'BGR', '2023', '12', '88213048431', 'warehouseandquantity@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', 'Supervisor gudang', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(264, 'WQS220501', 'Ahmad Syahrul Ahdzar', 'WQS', 'Staff', '', NULL, NULL, NULL, '3312080606000003', '3312080606000003', '24013038872', '91529201', 'SMK', 'BDG', '2022', '05', '82136735511', 'warehouseandquantity@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', 'Kode kosong akan auto-generate', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(265, 'WQS241101', 'Muhammad Farizal', 'WQS', 'Staff', '', NULL, NULL, NULL, '3171060102010001', '3171060102010001', '25043268587', '372770188', 'SMK', 'SMG', '2024', '11', '889991628', 'warehouseandquantity@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(266, 'CRM241101', 'Desdita Laila BR Purba', 'CRM', 'Staff', '', NULL, NULL, NULL, '1275026012970003', '1275026012970003', '24054723317', '2234349156', 'S1 AGROTEKNOLOGI PERTANIAN', 'BGR', '2024', '11', '81265057772', 'customerrelationship@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(267, 'WQS230901', 'Didi Ferriansyah Maulana', 'WQS', 'Staff', '', NULL, NULL, NULL, '3671031508020002', '3671031508020002', '24013038880', '2470905167', 'SMK', 'BGR', '2023', '09', '895360399150', 'warehouseandquantity@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(268, 'SCM240801', 'A Dhimas Setya Pambudi', 'SCM', 'Manager', '', NULL, NULL, NULL, '3329061901970002', '3329061901970002', '24196345391', '2273874647', 'D3 MANAJEMEN INFORMATIKA', 'BGR', '2024', '08', '8998482651', 'supplyandchain@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(269, 'SCM221101', 'Kukuh Aji Prastio', 'SCM', 'Staff', '', NULL, NULL, NULL, '3327092508970009', '3327092508970009', '23066538820', '2355632548', 'SMK', 'BGR', '2022', '11', '85719470945', 'supplyandchain@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(270, 'CRM230801', 'Lilis Wulandari', 'CRM', 'Manager', '', NULL, NULL, NULL, '3324054308000001', '3324054308000001', '23129011500', '608516864', 'S1 MANAJEMEN', 'BGR', '2023', '08', '81359217960', 'customerrelationship@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(271, 'WQS230101', 'M. Adhaz Janurul Hidayat', 'WQS', 'Staff', '', NULL, NULL, NULL, '3201013001040004', '3201013001040004', '23161732880', '1157481819', 'SMK', 'BGR', '2023', '01', '813988263886', 'warehouseandquantity@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(272, 'HRL231201', 'Maizura Hafidza', 'HRL', 'Manager', '', NULL, NULL, NULL, '3271046401010015', '3271046401010015', '23086104777', '1305132309', 'S1 HUKUM', 'BGR', '2023', '12', '81318934876', 'humanresourceanndlegal@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(273, 'WQS200501', 'Mia Astia', 'WQS', 'Manager', '', NULL, NULL, NULL, '3276104303970000', '3276104303970000', '20039456601', '2135337748', 'SMA', 'BGR', '2020', '05', '81959070852', 'warehouseandquantity@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(274, 'SCM230901', 'Moch Iksan Ardiansyah', 'SCM', 'Staff', '', NULL, NULL, NULL, '3202042605040002', '3202042605040002', '24013227137', '2400273685', 'SMK', 'BGR', '2023', '09', '85797085468', 'supplyandchain@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(275, 'WQS240701', 'Mualim', 'WQS', 'Staff', '', NULL, NULL, NULL, '3276021901810001', '3276021901810001', '24156122400', '511234288', 'SMA', 'SLO', '2024', '07', '82110345461', 'warehouseandquantity@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(276, 'MPR230801', 'Muhammad Adam Hidayat', 'MPR', 'Manager', '', NULL, NULL, NULL, '3201131010990007', '3201131010990007', '24013227129', '2306895862', 'SMK', 'BGR', '2023', '08', '87866132689', '-', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(277, 'WQS231001', 'Muhammad Iqbal Perdana', 'WQS', 'Staff', '', NULL, NULL, NULL, '3271061806970009', '3271061806970009', '24040028839', '3647955363', 'SMK', 'BKS', '2023', '10', '81212543152', 'warehouseandquantity@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(278, 'FIN211101', 'Romlah', 'FIN', 'Manager', '', NULL, NULL, NULL, '3201057112970002', '3201057112970002', '23182429797', '1172093163', 'SMK', 'BGR', '2021', '11', '8889336', 'accountingandfinance@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(279, 'CRM240401', 'Tasa Cahyaning Fitri', 'CRM', 'Staff', '', NULL, NULL, NULL, '3603314801010001', '3603314801010001', '25000386141', '2510021215', 'S1 MANAJEMEN', 'BGR', '2024', '04', '85717397443', 'tasa@rizqullahmediska.co.id', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(280, 'ITC240601', 'Vian Setiawan', 'ITC', 'Manager', '', NULL, NULL, NULL, '3201011603850002', '3201011603850002', '24156122442', '1627740088', 'D3 TEKNIK INFORMATIKA', 'BGR', '2024', '06', '87889330985', 'itsupport@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(281, 'ACT210701', 'Wulandari', 'ACT', 'Staff', '', NULL, NULL, NULL, '3276056101000002', '3276056101000002', '21078146293', '1279747642', 'D3 AKUNTANSI', 'BGR', '2021', '07', '89662446059', 'accountingandfinance@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(282, 'MPR231001', 'Yohanes Ratu Pito', 'MPR', 'Manager', '', NULL, NULL, NULL, '3175102406941001', '3175102406941001', '25011606313', '1624456034', 'SMK', 'BGR', '2023', '10', '85281694704', 'unitaccesores@gmail.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(283, 'ACT230801', 'Zuliana', 'ACT', 'Manager', '', NULL, NULL, NULL, '6403034604000001', '6403034604000001', '24013227160', '3224569061', 'S1 AKUNTANSI', 'BGR', '2023', '08', '85245800616', 'accountingandfinance@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(284, 'FIN240901', 'Elvia Zon Fitri', 'FIN', 'Staff', '', NULL, NULL, NULL, '1306166407980001', '1306166407980001', '25011606321', '1245382233', 'SMK', 'BGR', '2024', '09', '83163696113', 'accountingandfinance@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(285, 'ACT250201', 'Tyta Sukmawardani', 'ACT', 'Staff', '', NULL, NULL, NULL, '3174065008010002', '3174065008010002', '25057003946', '1210472537', 'S1', 'BGR', '2025', '02', '85780261368', 'unitaccesores@gmail.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(286, 'PQP250501', 'Widia Aulia Rahmah', 'PQP', 'Manager', '', NULL, NULL, NULL, '3201326107030002', '3201326107030002', '25109505591', '1724019939', 'S1 AKUNTANSI', 'BGR', '2025', '05', '83840148586', 'rizqullahmediskapqp@rizqullahmediskaindonesia.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(287, 'SCM260101', 'Pelipus Etding', 'SCM', 'Staff', '', NULL, NULL, NULL, '3175100510941002', '3175100510941002', '-', '1245876941', 'SMK', 'BGR', '2026', '01', '81317198630', '-', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(288, 'HRL150601', 'Risnawati', 'HRL', 'Staff', '', NULL, NULL, NULL, '3201015708870009', '3201015708870009', '-', '2104830551', '-', 'BGR', '2015', '06', '85956364536', '-', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(289, 'HRL230801', 'Imam Setiyadi', 'HRL', 'Staff', '', NULL, NULL, NULL, '3201010305960010', '3201010305960010', '-', '3943928878', '-', 'BGR', '2023', '08', '85210474615', '-', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(290, 'HRL230601', 'Sahroni', 'HRL', 'Staff', '', NULL, NULL, NULL, '3174091108650001', '3174091108650001', '-', '2094242286', '-', 'BGR', '2023', '06', '81511979067', '-', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(291, 'HRL230101', 'Harun', 'HRL', 'Staff', '', NULL, NULL, NULL, '3201010812600012', '3201010812600012', '-', '1964508726', '-', 'BGR', '2023', '01', '81295176133', '-', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(292, 'BRANCH231101', 'Nurochman', 'BRANCH', 'Manager', '', NULL, NULL, NULL, '3201131111790009', '3201131111790009', '23004220721', '1878202686', 'S2 MANAJEMEN', 'TGR', '2023', '11', '87784404600', 'rizqullah.tangerang99@gmail.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(293, 'CRM230802', 'Cita Nurhanipah', 'CRM', 'Staff', '', NULL, NULL, NULL, '3329014904020001', '3329014904020001', '24013038906', '2459469273', 'D3 ADMINISTRASI BISNIS', 'TGR', '2023', '08', '83898399634', 'accountingandfinance@rizqullahcorp.com', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(294, 'SCM241001', 'Triyantoro', 'SCM', 'Staff', '', NULL, NULL, NULL, '3173082704850008', '3173082704850008', '20014248775', '1966482189', 'SMK', 'TGR', '2024', '10', '85180637155', '-', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(295, 'MPR241001', 'Muhammad Imam Maulana', 'MPR', 'Staff', '', NULL, NULL, NULL, '3603080210010003', '3603080210010003', '24156122418', '1052695574', 'SMA', 'TGR', '2024', '10', '85885586372', '-', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24'),
(296, 'WQS260201', 'Pandu Prasetyo', 'WQS', 'Staff', '', NULL, NULL, NULL, '3276021005020015', '3276021005020015', '-', '-', 'SMK', 'BDG', '2026', '02', '82188790691', '-', NULL, NULL, NULL, NULL, 'active', '', '2026-02-28 14:48:43', '2026-02-28 22:04:24');

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_employees_template_upload_ready`
--

DROP TABLE IF EXISTS `master_employees_template_upload_ready`;
CREATE TABLE `master_employees_template_upload_ready` (
  `COL 1` varchar(1274) DEFAULT NULL,
  `COL 2` varchar(175) DEFAULT NULL,
  `COL 3` varchar(46) DEFAULT NULL,
  `COL 4` varchar(13) DEFAULT NULL,
  `COL 5` varchar(17) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_employees_template_upload_ready`
--

TRUNCATE TABLE `master_employees_template_upload_ready`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_import_runs`
--

DROP TABLE IF EXISTS `master_import_runs`;
CREATE TABLE `master_import_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `module` varchar(40) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `status` enum('PREVIEW','DONE','FAILED') NOT NULL DEFAULT 'PREVIEW',
  `rows_total` int(11) NOT NULL DEFAULT 0,
  `rows_success` int(11) NOT NULL DEFAULT 0,
  `rows_failed` int(11) NOT NULL DEFAULT 0,
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `master_import_runs`
--

TRUNCATE TABLE `master_import_runs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_import_run_errors`
--

DROP TABLE IF EXISTS `master_import_run_errors`;
CREATE TABLE `master_import_run_errors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `row_no` int(11) NOT NULL,
  `external_key` varchar(120) DEFAULT NULL,
  `error_message` varchar(255) NOT NULL,
  `row_payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `master_import_run_errors`
--

TRUNCATE TABLE `master_import_run_errors`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_manufactures`
--

DROP TABLE IF EXISTS `master_manufactures`;
CREATE TABLE `master_manufactures` (
  `id` int(11) NOT NULL,
  `manufactures_code` varchar(50) NOT NULL,
  `manufactures_name` varchar(150) NOT NULL,
  `manufacture_code` varchar(50) NOT NULL,
  `manufacture_name` varchar(150) NOT NULL,
  `brand_name` varchar(255) DEFAULT NULL,
  `origin_type` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
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
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `internal_office_code` varchar(30) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_manufactures`
--

TRUNCATE TABLE `master_manufactures`;
--
-- Dumping data untuk tabel `master_manufactures`
--

INSERT DELAYED IGNORE INTO `master_manufactures` (`id`, `manufactures_code`, `manufactures_name`, `manufacture_code`, `manufacture_name`, `brand_name`, `origin_type`, `country`, `city`, `address`, `phone`, `email`, `pic_name`, `pic_position`, `pic_phone`, `pic_email`, `bank_name`, `bank_account_name`, `bank_account_number`, `bank_swift_code`, `bank_iban`, `bank_currency`, `website`, `status`, `internal_office_code`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'YAXIN', 'Suzhou Yaxin Medical Products Co., Ltd', 'YAXIN', 'Suzhou Yaxin Medical Products Co., Ltd', 'RIZKIMED', 'Import', 'China', 'Suzhou', 'No.12, Zhongta Road, Mudu Town, Suzhou 215101, Jiangsu province, China', '+86 152 5017 8777', 'yaxin@yx-yiliao.com', 'Jim Wu', 'Sales', NULL, 'yaxin@yx-yiliao.com', 'JPMorgan Chase Bank N.A., Singapore Branch', 'Suzhou Yaxin Medical Products Co., Ltd', '1014 1740 2041 66', 'CHASSGSGXXX  or CHASSGSG', NULL, 'IDR', NULL, 1, NULL, '2026-01-01 18:00:42', '2026-01-02 01:00:42', NULL),
(2, 'KANTOR-BDG', 'Kantor Rizqullah Mediska Indonesia Bandung', 'KANTOR-BDG', 'Kantor Rizqullah Mediska Indonesia Bandung', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, 1, 'BDG', '2026-03-05 20:58:52', '2026-03-06 03:58:52', NULL),
(3, 'KANTOR-BGR', 'Kantor Rizqullah Mediska Indonesia', 'KANTOR-BGR', 'Kantor Rizqullah Mediska Indonesia', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, 1, 'BGR', '2026-03-05 20:58:52', '2026-03-06 03:58:52', NULL),
(4, 'KANTOR-BKS', 'Kantor Rizqullah Mediska Indonesia Bekasi', 'KANTOR-BKS', 'Kantor Rizqullah Mediska Indonesia Bekasi', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, 1, 'BKS', '2026-03-05 20:58:53', '2026-03-06 03:58:53', NULL),
(5, 'KANTOR-JGY', 'Kantor Depo Yogyakarta', 'KANTOR-JGY', 'Kantor Depo Yogyakarta', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, 1, 'JGY', '2026-03-05 20:58:53', '2026-03-06 03:58:53', NULL),
(6, 'KANTOR-KAL', 'Kantor Depo Kalimantan', 'KANTOR-KAL', 'Kantor Depo Kalimantan', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, 1, 'KAL', '2026-03-05 20:58:53', '2026-03-06 03:58:53', NULL),
(7, 'KANTOR-SLO', 'Kantor Rizqullah Mediska Indonesia Jawa Tengah (Solo)', 'KANTOR-SLO', 'Kantor Rizqullah Mediska Indonesia Jawa Tengah (Solo)', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, 1, 'SLO', '2026-03-05 20:58:53', '2026-03-06 03:58:53', NULL),
(8, 'KANTOR-SMG', 'Kantor Rizqullah Mediska Indonesia Semarang', 'KANTOR-SMG', 'Kantor Rizqullah Mediska Indonesia Semarang', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, 1, 'SMG', '2026-03-05 20:58:53', '2026-03-06 03:58:53', NULL),
(9, 'KANTOR-SYS', 'Kantor SYS', 'KANTOR-SYS', 'Kantor SYS', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, 1, 'SYS', '2026-03-05 20:58:53', '2026-03-06 03:58:53', NULL),
(10, 'KANTOR-TGR', 'Kantor Rizqullah Mediska Indonesia Tangerang', 'KANTOR-TGR', 'Kantor Rizqullah Mediska Indonesia Tangerang', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', NULL, 1, 'TGR', '2026-03-05 20:58:53', '2026-03-06 03:58:53', NULL),
(11, 'NANCHANG', 'Nanchang Kanghua Health Materials Co., Ltd', 'NANCHANG', 'Nanchang Kanghua Health Materials Co., Ltd', 'RIZKIMED', 'Import', 'China', 'Jianxian Nanchang', 'Moonllight Road Medical Device Technology Park, Jinxian Nanchang 331700 Jiangxi China', '+86 153 9791 1825', 'sales6@jxkh.cn', 'Zed Lee', 'Sales', '+86 153 9791 1825', 'sales6@jxkh.cn', 'Bank of China Jiangxi Branch Nanchang Kanghua City Jinxian Sub-Branch', 'Jiangxi Yichen Medical Instrument co., Ltd', '194742853322', 'BKCHCNBJ550', 'Jinxian Country Jiangxi Province, China', 'CNY', 'https://www.kanghuamed.com/contact-us/', 1, NULL, '2026-03-06 00:37:01', '2026-03-06 07:37:01', NULL),
(12, 'CATHAY', 'CATHAY MANUFACTURING CORP', 'CATHAY', 'CATHAY MANUFACTURING CORP', 'CATHAY', 'Import', 'China', 'Shanghai', 'Room 1510, No. 20, Lane 699 Guang Fu Lin Road, Songjiang District, Shanghai, China', '+86 138 1688 9961', 'jenny.feng@cathaymanufacturing.com', 'Jenny Feng', 'Sales', '+86 138 1688 9961', 'jenny.feng@cathaymanufacturing.com', 'China Construction Bank Corporation Shanghai Branch Songjiang Sub-Branch', 'Papps International Co.,Limited', '31050180363700000746', 'PCBCCNBJSHX', NULL, 'CNY', 'https://www.cathaymanufacturing.com/', 1, NULL, '2026-03-06 00:58:44', '2026-03-06 09:52:54', NULL),
(13, 'EXCELLENTCARE', 'EXCELLENTCARE MEDICAL (HUIZHOU) LTD', 'EXCELLENTCARE', 'EXCELLENTCARE MEDICAL (HUIZHOU) LTD', 'RIZKIMED', 'Import', 'China', 'Huizhou', 'Shatou Industrial Zone, Yuanzhou Town, Boluo Country, Huizhou, 516123, Guangdong, China', '0752-6358333-302', 'info@excellentcare.com.cn', 'Vicass', 'Sales', '+86 158 1717 7843', 'info-3@excellentcare.com.cn', 'THE AGRICULTURAL BANK OF CHINA, GUANGDONG, BOLUO, ZHOU SUB BRACH', 'EXCELLENTCARE MEDICAL (HUIZHOU) LTD', '44244001040009593', 'ABOCCNBJ190', NULL, 'CNY', 'https://emedical.net.cn/about/company', 1, NULL, '2026-03-06 02:08:02', '2026-03-06 09:08:02', NULL),
(14, 'CELECARE', 'WENZHOU CELECARE MEDICAL INSTRUMENTS CO., LTD', 'CELECARE', 'WENZHOU CELECARE MEDICAL INSTRUMENTS CO., LTD', 'RIZKIMED', 'Import', 'China', 'Wenzhou', 'NO.407 XIAJIN RD(1-4th Floor),JINZHU INDUSTRIAL ZONE, NANBAIXIANG STREET, OUHAI DISTRICT，WENZHOU CITY, ZHEJIANG PROVINCE, CHINA', '+86-577-56708225', 'info@celecare.com', 'Neil', 'Sales', '+86 135 6622 6722', 'neil@celecare.com', 'CITIBANK N.A HONG KONG BRANCH', 'WENZHOU CELECARE MEDICAL INSTRUMENTS CO., LTD', '3974000035858', 'CITIHKHXXX', NULL, 'CNY', 'https://www.celecaremedical.com/', 1, NULL, '2026-03-06 02:57:56', '2026-03-06 09:57:56', NULL),
(15, 'MEDPLUS', 'MEDPLUS INC', 'MEDPLUS', 'MEDPLUS INC', 'MEDPLUS', 'Import', 'China', 'GUANGZHOU', '4TH FLOOR, BUILDING6, NO. 586 ZHONGSHUN ROAD, ZHONGCUN, PANYU DISTRICT, GUANGZHOU, 511495, CHINA', '+86 20 3477 3301', 'info@gzmedplus.com', 'Sue', 'Sales', '+86 159 1445 7703', 'sales@gzmedplus.com', 'China Construction Bank Guangdong Branch', 'Medplus Inc', '44014130100229390515', 'PCBCCNBJGDX', NULL, 'CNY', NULL, 1, NULL, '2026-03-06 03:22:24', '2026-03-06 10:22:24', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_manufactures_docs`
--

DROP TABLE IF EXISTS `master_manufactures_docs`;
CREATE TABLE `master_manufactures_docs` (
  `id` int(11) NOT NULL,
  `manufacture_id` int(11) NOT NULL,
  `doc_type` varchar(40) NOT NULL,
  `doc_status` varchar(20) DEFAULT NULL,
  `doc_no` varchar(80) DEFAULT NULL,
  `doc_date` date DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_rel` varchar(255) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `master_manufactures_docs`
--

TRUNCATE TABLE `master_manufactures_docs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_mpr`
--

DROP TABLE IF EXISTS `master_mpr`;
CREATE TABLE `master_mpr` (
  `id` int(11) NOT NULL,
  `customers_code` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `contact_name` varchar(150) NOT NULL,
  `role_title` varchar(100) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_mpr`
--

TRUNCATE TABLE `master_mpr`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_office`
--

DROP TABLE IF EXISTS `master_office`;
CREATE TABLE `master_office` (
  `id` int(11) NOT NULL,
  `office_code` varchar(10) NOT NULL,
  `office_name` varchar(100) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` varchar(100) DEFAULT 'SYSTEM',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `office_lat` decimal(10,7) DEFAULT NULL,
  `office_lng` decimal(10,7) DEFAULT NULL,
  `office_radius_m` int(11) DEFAULT 120
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_office`
--

TRUNCATE TABLE `master_office`;
--
-- Dumping data untuk tabel `master_office`
--

INSERT DELAYED IGNORE INTO `master_office` (`id`, `office_code`, `office_name`, `city`, `address`, `phone`, `is_active`, `created_by`, `created_at`, `updated_at`, `office_lat`, `office_lng`, `office_radius_m`) VALUES
(1, 'BGR', 'Rizqullah Mediska Indonesia', 'Kab. Bogor', 'Jalan Pondok Rajeg, Ruko Sentra Pondok Rajeg No. 7 & 8, Kel. Pondok Rajeg, Kec. Cibinong, Kabupaten Bogor, Provinsi Jawa Barat, 16914', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-03-05 22:23:53', -6.5971470, 106.8060390, 150),
(2, 'BKS', 'Rizqullah Mediska Indonesia Bekasi', 'Kota Bekasi', 'Perumahan The East View Residence Blok F 17, Jl. Raya Mustika Sari, Kel. Mustikasari, Kec. Mustikajaya, Kota Bekasi, Provinsi Jawa Barat, 17157', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-03-05 22:23:53', -6.2382700, 106.9755700, 150),
(3, 'TGR', 'Rizqullah Mediska Indonesia Tangerang', 'Kota Tangerang', 'Jalan Muhamad Toha No. B26 Km. 0,6, Kel. Periuk, Kec. Periuk, Kota Tangerang, Provinsi Banten, 15131', '0813-8425-1574', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-03-05 22:23:53', -6.1783060, 106.6318890, 150),
(4, 'BDG', 'Rizqullah Mediska Indonesia Bandung', 'Kota Bandung', 'Ruko Puri Dago Mas Unit 428, Jalan Terusan Jakarta, Kota Bandung, Provinsi Jawa Barat, 40293', '0812-1067-5863', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-03-05 22:23:53', -6.9174640, 107.6191230, 150),
(5, 'SLO', 'Rizqullah Mediska Indonesia Jawa Tengah (Solo)', 'Kota Surakarta', 'Jalan Pakel No. 06, Kel. Banyuanyar, Kec. Banjarsari, Kota Surakarta, Provinsi Jawa Tengah, 57137', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-03-05 22:23:53', -7.5666670, 110.8166670, 150),
(6, 'SMG', 'Rizqullah Mediska Indonesia Semarang', 'Kota Semarang', 'Ruko Tlogo Timun Mas No. 1A Kav. C, Kel. Tlogosari Kulon, Kec. Pedurungan, Kota Semarang, Provinsi Jawa Tengah, 50196', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-03-05 22:23:53', -6.9666670, 110.4166670, 150),
(7, 'KAL', 'Depo Kalimantan', 'Kalimantan', '', NULL, 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-03-05 22:23:53', NULL, NULL, 120),
(8, 'JGY', 'Depo Yogyakarta', 'Yogyakarta', '', NULL, 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-03-05 22:23:53', NULL, NULL, 120),
(9, 'SYS', 'SYS', 'SYS', '', '', 0, 'SYSTEM', '2026-01-02 05:55:45', '2026-03-05 22:23:53', NULL, NULL, 120);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_payment_terms`
--

DROP TABLE IF EXISTS `master_payment_terms`;
CREATE TABLE `master_payment_terms` (
  `id` int(11) NOT NULL,
  `payment_terms_code` varchar(30) NOT NULL,
  `payment_terms_name` varchar(150) NOT NULL,
  `days_due` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_payment_terms`
--

TRUNCATE TABLE `master_payment_terms`;
--
-- Dumping data untuk tabel `master_payment_terms`
--

INSERT DELAYED IGNORE INTO `master_payment_terms` (`id`, `payment_terms_code`, `payment_terms_name`, `days_due`, `description`, `status`, `created_at`, `updated_at`) VALUES
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

DROP TABLE IF EXISTS `master_pricelist`;
CREATE TABLE `master_pricelist` (
  `id` int(11) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `customers_code` varchar(30) DEFAULT NULL,
  `buy_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `markup_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `sell_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `valid_from` date DEFAULT NULL,
  `valid_to` date DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_pricelist`
--

TRUNCATE TABLE `master_pricelist`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_products`
--

DROP TABLE IF EXISTS `master_products`;
CREATE TABLE `master_products` (
  `id` int(11) NOT NULL,
  `products_code` varchar(50) DEFAULT NULL,
  `sku` varchar(50) NOT NULL,
  `products_name` varchar(200) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'ALKES',
  `category` varchar(100) DEFAULT NULL,
  `product_group` varchar(20) DEFAULT NULL,
  `no_akl` varchar(100) DEFAULT NULL,
  `manufacture_id` int(11) DEFAULT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `stock_qty` int(11) NOT NULL DEFAULT 0,
  `price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(50) NOT NULL DEFAULT 'unit',
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
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
  `min_price` int(11) NOT NULL DEFAULT 0,
  `max_price` int(11) NOT NULL DEFAULT 0,
  `listing_level` int(11) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `akl_reg_no` varchar(150) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `product_type` enum('SINGLE','PAKET') NOT NULL DEFAULT 'SINGLE',
  `package_items` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_products`
--

TRUNCATE TABLE `master_products`;
--
-- Dumping data untuk tabel `master_products`
--

INSERT DELAYED IGNORE INTO `master_products` (`id`, `products_code`, `sku`, `products_name`, `type`, `category`, `product_group`, `no_akl`, `manufacture_id`, `vendor_id`, `category_id`, `stock_qty`, `price`, `unit`, `status`, `created_at`, `updated_at`, `photo_front`, `photo_back`, `photo_box`, `photo_unpacked`, `video_front`, `video_back`, `video_box`, `video_unpacked`, `general_name`, `licence_number`, `min_price`, `max_price`, `listing_level`, `barcode`, `akl_reg_no`, `exp_date`, `current_stock`, `product_type`, `package_items`) VALUES
(1, 'OBT-001', 'OBT-001', 'Obat A', 'ALKES', 'BMHP', NULL, NULL, NULL, NULL, NULL, 100, 50000.00, 'unit', 'active', '2025-12-08 17:33:28', '2026-03-01 13:37:59', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, '8991234567890', 'AKL.12345678901', NULL, 120, 'SINGLE', NULL),
(2, 'OBT-002', 'OBT-002', 'Obat B', 'ALKES', 'BMHP', NULL, NULL, NULL, NULL, NULL, 150, 75000.00, 'unit', 'active', '2025-12-08 17:33:28', '2026-03-01 13:37:59', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, 'SINGLE', NULL),
(3, 'ALK-001', 'ALK-001', 'Alkes A', 'ALKES', 'BMHP', NULL, NULL, NULL, NULL, NULL, 50, 150000.00, 'unit', 'active', '2025-12-08 17:33:28', '2026-03-01 13:37:59', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, 0, 'SINGLE', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_products_doc`
--

DROP TABLE IF EXISTS `master_products_doc`;
CREATE TABLE `master_products_doc` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `doc_type` varchar(50) NOT NULL DEFAULT 'GENERAL',
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `uploaded_by` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_by` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `master_products_doc`
--

TRUNCATE TABLE `master_products_doc`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_products_print`
--

DROP TABLE IF EXISTS `master_products_print`;
CREATE TABLE `master_products_print` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `print_type` varchar(50) NOT NULL DEFAULT 'LIST',
  `note` varchar(255) DEFAULT NULL,
  `printed_by` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `printed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `master_products_print`
--

TRUNCATE TABLE `master_products_print`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_system_login`
--

DROP TABLE IF EXISTS `master_system_login`;
CREATE TABLE `master_system_login` (
  `id` int(11) NOT NULL,
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
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `mfa_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `mfa_secret` varchar(128) DEFAULT NULL,
  `mfa_confirmed_at` datetime DEFAULT NULL,
  `mfa_backup_codes_hash` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `deactivated_at` datetime DEFAULT NULL,
  `deactivated_by` varchar(120) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `deleted_by` varchar(120) DEFAULT NULL,
  `delete_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_system_login`
--

TRUNCATE TABLE `master_system_login`;
--
-- Dumping data untuk tabel `master_system_login`
--

INSERT DELAYED IGNORE INTO `master_system_login` (`id`, `username`, `full_name`, `password_hash`, `role`, `level`, `department`, `office_code`, `holder_employee_code`, `holder_assigned_at`, `holder_assigned_by`, `status`, `mfa_enabled`, `mfa_secret`, `mfa_confirmed_at`, `mfa_backup_codes_hash`, `created_at`, `updated_at`, `last_login_at`, `deactivated_at`, `deactivated_by`, `deleted_at`, `deleted_by`, `delete_reason`) VALUES
(1, 'superadmin', 'Super Admin', '$2y$12$9BOmZd.NswVkDyRiOjo18eNzXGlZXG82Vp.SIjQrlS0BWyAL1nPAa', 'sys', 'SUPERADMIN', 'SYS', 'SYS', 'SYS150902', '2026-03-06 22:57:36', 'admin', 'ACTIVE', 1, '3O5JF74DBRMJBUUAJRSDH2I66TFMXRFR', '2026-03-05 00:56:17', '[\"$2y$12$bMG6pvYVLIHrTeniA02D\\/OFFdE6wwJserBHGuEebGMaKCwA\\/knm7S\",\"$2y$12$R7u7CHzPoSx0nTk7Tck3BeXk6rCvQREO.hm8I5wS2s6Z23rMU81BW\",\"$2y$12$pKsbt81gzLiVFdvzX7QVW.mgBkVnSRZkp1VWENkE9vBqptYOQuSWW\",\"$2y$12$sVlSwNOMlA9wV5.oLuEvUOq2.a68GDCAl4tGcQHXsl1E0md\\/NpOuG\",\"$2y$12$fytnvZLhkSjHpvUbw8wfvepYczpfaG4ROlgHeATRkGBJql7Fyj.cq\",\"$2y$12$HniFqJZym2oFlTITPvYpQOty99Wgt5j03.5LDJyJ6Z5Q\\/K6Rpqx9O\",\"$2y$12$3Rw.EwcyGykiOFPCCimihOra4kR2KyEKghaN249oirpfAq3Ns2MSC\",\"$2y$12$.25BptxwrCopRvmrEqNGReCCSBM0G1YZy3p0DjFM5CTY9c6VgSIxC\"]', '2026-01-01 21:17:38', '2026-03-05 00:56:17', '2026-03-05 00:51:51', NULL, NULL, NULL, NULL, NULL),
(2, 'admin', 'Admin', '$2y$12$ECsyBeBoxFUtyNXOylgjfu6fU0GCdkGy1/aNpWGlhJWfc9rIp2zBi', 'admin', 'ADMIN', 'SYS', 'SYS', 'SYS150901', '2026-03-06 22:58:09', 'admin', 'ACTIVE', 1, 'UHHE5ZDX6CZUGZF7OX5LO7ELYIBPQEDH', '2026-03-06 23:26:18', '[\"$2y$12$SGI0bQRbGimW\\/hIPv7C7qe9TFvfyBq6UyTDZo0ObisuzLuF5R5NWO\",\"$2y$12$1Tdk9ZIARrX0OVf\\/3FIbHOx\\/0LUxb71rfnzTXq1d7HuX0s\\/6E6U0q\",\"$2y$12$3oZlXqtj5CUVF6tdyFdEX.VPFR.Mm154zMkkTKiSujxKxMYAqTBJK\",\"$2y$12$6JSWo0tyuuFCJFTSUuhOleMuBRpa\\/sPZwCCeMS5cNK8dfMk06oV12\",\"$2y$12$F7NHgMhljO7zyPM\\/O\\/iJ2uAU4QjQ3ZiL32eGRWt62f2Z6SQp9ag6u\",\"$2y$12$IutS.WdDP2oVjKhxIiqn2evSaOISxgZp3eeMPJZzb7LY3Cpu7I5qe\",\"$2y$12$WTfbj6Juf.w2eMKeZN128.wT2CEUZywiLpaXYWvp\\/CtdQYkJL1J2a\",\"$2y$12$ancmJoAetrihxcdvu3vAYeYZJRDBCXEC2TND06yco0LiNkumHsPIK\"]', '2026-01-01 21:17:38', '2026-03-08 23:35:28', '2026-03-08 23:35:28', NULL, NULL, NULL, NULL, NULL),
(3, 'MgrITC_BGR', 'MgrITC_BGR', '$2y$12$LyuBQrFCe3cvlq5PIDmK/OhTzKWYCC3iwEJxSsYNg1MxusFQsFOpW', 'manager', NULL, 'ITC', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-25 17:17:33', '2026-03-09 04:19:46', '2026-03-09 04:19:46', NULL, NULL, NULL, NULL, NULL),
(4, 'StaffSYS_DEFAULT', 'Staff System (DEFAULT)', '$2y$12$2IBCAz.oohzvSfeb8vS/kezoeQjX/O3AVvwfwMwbvJhXT6mPe/Xwm', 'staff', 'STAFF', 'SYS', 'DEFAULT', NULL, NULL, NULL, 'INACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:01:57', '2026-02-26 08:57:34', NULL, NULL, NULL, '2026-02-26 08:57:34', 'admin', NULL),
(5, 'MgrACT_BDG', 'Manager Accounting/Customs (ACT) (BDG)', '$2y$12$nxSevSmVhWSlUpgdwybIl.gilkp16qd9EGS6IQ1O18i7n9WUdYjei', 'manager', 'MANAGER', 'ACT', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:01:58', '2026-02-26 08:01:58', NULL, NULL, NULL, NULL, NULL, NULL),
(6, 'StaffACT_BDG', 'Staff Accounting & Tax (BDG)', '$2y$12$QSZwEet8xXzZFlwBO/r.Cuji6IIOiKDv6Zp8dzmhyX3IJQeXR3i0e', 'staff', 'STAFF', 'ACT', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:01:58', '2026-02-26 08:01:58', NULL, NULL, NULL, NULL, NULL, NULL),
(7, 'MgrCRM_BDG', 'Manager Sales/CRM (CRM) (BDG)', '$2y$12$VVh2eYDYjcLKyCzYHkCTD.HsFzFMiT3WENJ4FBgbt9Z1Bs7hhN3G6', 'manager', 'MANAGER', 'CRM', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:01:58', '2026-02-26 08:01:58', NULL, NULL, NULL, NULL, NULL, NULL),
(8, 'StaffCRM_BDG', 'Staff Customer Relationship Management (BDG)', '$2y$12$EVTlyMWJWx47CfETNb96kO.8wGjHitvYLnHCSAroBwF9qXeBOkFZO', 'staff', 'STAFF', 'CRM', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:01:59', '2026-02-26 08:01:59', NULL, NULL, NULL, NULL, NULL, NULL),
(9, 'MgrFIN_BDG', 'Manager Finance (FIN) (BDG)', '$2y$12$DsBWuHoGVFOV3v5J5i8lJuwEIWRG5OBu/G1HuNkYMiJ1j98/AAJxa', 'manager', 'MANAGER', 'FIN', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:01:59', '2026-02-26 08:01:59', NULL, NULL, NULL, NULL, NULL, NULL),
(10, 'StaffFIN_BDG', 'Staff Finance (BDG)', '$2y$12$VRh0YZAEedNnvRcRliH8qOhTQEi2vnTEhRKT6iJrbEL1/dMW751p.', 'staff', 'STAFF', 'FIN', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:01:59', '2026-02-26 08:01:59', NULL, NULL, NULL, NULL, NULL, NULL),
(11, 'MgrHRL_BDG', 'Manager HR/Legal (HRL) (BDG)', '$2y$12$muXJw1InSpVH7JLDlKFl9ufGvjMt74YP0RzeVdJAflIqthcKCgq8S', 'manager', 'MANAGER', 'HRL', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:00', '2026-02-26 08:02:00', NULL, NULL, NULL, NULL, NULL, NULL),
(12, 'StaffHRL_BDG', 'Staff Human Resource & Legal (BDG)', '$2y$12$nMOeftzTlpFh2lJMfyWlFOMLVfvMV0KTRrRNVv4M.ezJh/0BG/Ate', 'staff', 'STAFF', 'HRL', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:00', '2026-02-26 08:02:00', NULL, NULL, NULL, NULL, NULL, NULL),
(13, 'MgrITC_BDG', 'Manager IT/Communication (ITC) (BDG)', '$2y$12$2sa3PyT3MR2Q2OQgkrBjcuG1PWn.mSP1WRFimvVCPYQkk3esCoX3m', 'manager', 'MANAGER', 'ITC', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:01', '2026-02-26 08:02:01', NULL, NULL, NULL, NULL, NULL, NULL),
(14, 'StaffITC_BDG', 'Staff IT & Cloud (BDG)', '$2y$12$pHJkZf9oS86eTpJUY44cXuIuXusUXu1Ooj2c3AXsUbtpkpgAZ6oGW', 'staff', 'STAFF', 'ITC', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:01', '2026-02-26 08:02:01', NULL, NULL, NULL, NULL, NULL, NULL),
(15, 'MgrMPR_BDG', 'Manager Marketing/PR (MPR) (BDG)', '$2y$12$l25JXyXCvEtU9u6lBClBqOC6FPWUNr0M1MMKiLn8vfT0Ac0fCRjQu', 'manager', 'MANAGER', 'MPR', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:01', '2026-03-06 07:22:25', '2026-03-06 07:22:25', NULL, NULL, NULL, NULL, NULL),
(16, 'StaffMPR_BDG', 'Staff Marketing & Project (BDG)', '$2y$12$T2L4gspfn15fo0aSubaoLOSO4da3twFQf1.3fk3UPTFuWoIBnF6eS', 'staff', 'STAFF', 'MPR', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:02', '2026-02-26 08:02:02', NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'MgrPQP_BDG', 'Manager Purchasing (PQP) (BDG)', '$2y$12$mZX7nzdNeFhi9dtS2iPRGOFdsCS4Jj6hA8kA.Hre9.xsKPEHW5Rq6', 'manager', 'MANAGER', 'PQP', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:02', '2026-02-26 08:02:02', NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'StaffPQP_BDG', 'Staff Product Quality & Purchasing (BDG)', '$2y$12$C2TvFyqagkhAmsQenyZu4.5ud0KdPXujU1VVucJT7Ob/zZ/Mgys8K', 'staff', 'STAFF', 'PQP', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:02', '2026-02-26 08:02:02', NULL, NULL, NULL, NULL, NULL, NULL),
(19, 'MgrSCM_BDG', 'Manager Supply Chain (SCM) (BDG)', '$2y$12$FL3cieaRhTJtkQFzUpOjKezwjXuN6ptzkyuLB4BQEzcQcI0c5TUOG', 'manager', 'MANAGER', 'SCM', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:03', '2026-02-26 08:02:03', NULL, NULL, NULL, NULL, NULL, NULL),
(20, 'StaffSCM_BDG', 'Staff Supply Chain Management (BDG)', '$2y$12$Rne7QU80cghBEkHulZ/tHuoFFR8JtDzOqSDRtrXT3tL4K4/r.4soW', 'staff', 'STAFF', 'SCM', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:03', '2026-02-26 08:02:03', NULL, NULL, NULL, NULL, NULL, NULL),
(21, 'StaffSYS_BDG', 'Staff System (BDG)', '$2y$12$zTQLOPJCkWmJhx9oLs1Luuk.9urLrUOG4m5lvoAGczN2NMdA4IBhG', 'staff', 'STAFF', 'SYS', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:03', '2026-02-26 08:02:03', NULL, NULL, NULL, NULL, NULL, NULL),
(22, 'MgrWQS_BDG', 'Manager Warehouse (WQS) (BDG)', '$2y$12$5dGxT/JDtOGgF58AWKGhrukMSLV8nZAvARlQLn5nsVHoFf8rOV0L2', 'manager', 'MANAGER', 'WQS', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:04', '2026-02-26 08:02:04', NULL, NULL, NULL, NULL, NULL, NULL),
(23, 'StaffWQS_BDG', 'Staff Warehouse & Quality Stock (BDG)', '$2y$12$LrGZrS8/HnD2wNeZWjGqI.NmoJTR/1TsV3PgCC6W.2TiGliYLbitG', 'staff', 'STAFF', 'WQS', 'BDG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:04', '2026-02-26 08:02:04', NULL, NULL, NULL, NULL, NULL, NULL),
(24, 'MgrACT_BGR', 'Manager Accounting & Tax (BGR)', '$2y$12$ZoZe3goKThwbeMAK6a9fp.dcKlquHlw5mkDnII51RL.ZWWGtY7x4O', 'manager', 'MANAGER', 'ACT', 'BGR', 'ACT230801', '2026-03-04 23:28:00', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:05', '2026-03-09 04:17:11', '2026-03-09 04:17:11', NULL, NULL, NULL, NULL, NULL),
(25, 'StaffACT_BGR', 'Staff Accounting & Tax (BGR)', '$2y$12$Plocue560nH6lLDJVZxxWuQHVB0rdxCNLI3N0hg0.1DYGg0HBQJ8G', 'staff', 'STAFF', 'ACT', 'BGR', 'ACT210701', '2026-03-04 23:28:24', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:05', '2026-03-04 23:44:42', '2026-03-04 23:44:42', NULL, NULL, NULL, NULL, NULL),
(26, 'MgrCRM_BGR', 'Manager Customer Relationship Management (BGR)', '$2y$12$w3u8T3xClqGdsZDNHp1oJuWJpi9Pla83yO7AHkSVOmSTDQLdM/jfq', 'manager', 'MANAGER', 'CRM', 'BGR', 'CRM230801', '2026-03-04 23:25:20', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:05', '2026-03-09 04:15:15', '2026-03-09 04:15:15', NULL, NULL, NULL, NULL, NULL),
(27, 'StaffCRM_BGR', 'Staff Customer Relationship Management (BGR)', '$2y$12$QgXLrknkhpmA0OEqOEOE4uSWzWYOFrZqlQvJqSF7C7w.X4w2oUUNy', 'staff', 'STAFF', 'CRM', 'BGR', 'CRM241101', '2026-03-04 23:25:54', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:06', '2026-03-09 04:21:40', '2026-03-09 04:21:40', NULL, NULL, NULL, NULL, NULL),
(28, 'MgrFIN_BGR', 'Manager Finance (BGR)', '$2y$12$fdSuoXzp/jZdWSXD2dU2IeqtBXW4dgDq.WRKV35zZQVMpWcjKG9JW', 'manager', 'MANAGER', 'FIN', 'BGR', 'FIN211101', '2026-03-04 23:31:11', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:06', '2026-03-09 04:17:44', '2026-03-09 04:17:44', NULL, NULL, NULL, NULL, NULL),
(29, 'StaffFIN_BGR', 'Staff Finance (BGR)', '$2y$12$ZF06V5Hry.Yr8X/GXIMDm.rLrE9/wjtC2bN5Cuq1tpcfyNgsqmrrS', 'staff', 'STAFF', 'FIN', 'BGR', 'FIN240901', '2026-03-04 23:31:42', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:06', '2026-03-04 21:22:25', '2026-03-04 21:22:25', NULL, NULL, NULL, NULL, NULL),
(30, 'MgrHRL_BGR', 'Manager Human Resource & Legal (BGR)', '$2y$12$olQEcDGgoviebIPEKj7sLegS8fgiX6vJ46FZSV2n4E44LuupOYOzu', 'manager', 'MANAGER', 'HRL', 'BGR', 'HRL241101', '2026-02-28 22:28:59', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:07', '2026-03-09 04:18:26', '2026-03-09 04:18:26', NULL, NULL, NULL, NULL, NULL),
(31, 'StaffHRL_BGR', 'Staff Human Resource & Legal (BGR)', '$2y$12$u8H0hnUxSbp5.FwfMlbGM.TBXoXnnktr/dlw2caXm3Nptyoo6wHse', 'staff', 'STAFF', 'HRL', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:07', '2026-03-04 21:23:33', '2026-03-04 21:23:33', NULL, NULL, NULL, NULL, NULL),
(32, 'StaffITC_BGR', 'Staff IT & Cloud (BGR)', '$2y$12$MnxrjPkG4ms9PYmwSBd6EOd39mgxndtb1LTwS/PrDWmYhBCTdnOCq', 'staff', 'STAFF', 'ITC', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:08', '2026-03-04 21:25:56', '2026-03-04 21:25:56', NULL, NULL, NULL, NULL, NULL),
(33, 'MgrMPR_BGR', 'Manager Marketing & Project (BGR)', '$2y$12$CHCU42uU3L27wyNEfKeW0u4yJD0aDBzmSM1icYfGiuhVX7edGF3Z2', 'manager', 'MANAGER', 'MPR', 'BGR', 'MPR231001', '2026-03-04 23:21:00', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:08', '2026-03-04 23:22:26', '2026-03-04 23:22:26', NULL, NULL, NULL, NULL, NULL),
(34, 'StaffMPR_BGR', 'Staff Marketing & Project (BGR)', '$2y$12$2RHY9QYM9AcAYcP0LKqRE.4hl.y0Toub9q73ypFrwG8fNKS46jW72', 'staff', 'STAFF', 'MPR', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:08', '2026-03-06 13:33:13', '2026-03-06 13:33:13', NULL, NULL, NULL, NULL, NULL),
(35, 'MgrPQP_BGR', 'Manager Product Quality & Purchasing (BGR)', '$2y$12$rQgUF/2hnoaiejuZYrjDfOe9acrYR71p4Nc27jFqGakvQJnCljEbq', 'manager', 'MANAGER', 'PQP', 'BGR', 'PQP250501', '2026-03-04 23:26:29', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:09', '2026-03-09 04:19:00', '2026-03-09 04:19:00', NULL, NULL, NULL, NULL, NULL),
(36, 'StaffPQP_BGR', 'Staff Product Quality & Purchasing (BGR)', '$2y$12$S6Spl0tc5phAL3kXBUIpbuwSIPGFj/zeR8ICJzPRXJtObM8f2aWx2', 'staff', 'STAFF', 'PQP', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:09', '2026-03-04 21:24:07', '2026-03-04 21:24:07', NULL, NULL, NULL, NULL, NULL),
(37, 'MgrSCM_BGR', 'Manager Supply Chain Management (BGR)', '$2y$12$VpDclQdHPG17z6IdXyMCxO/DYZ8M/CKfCKGYPXWzpnirBNT5y4XdO', 'manager', 'MANAGER', 'SCM', 'BGR', 'SCM240801', '2026-03-04 23:27:35', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:09', '2026-03-09 04:16:34', '2026-03-09 04:16:34', NULL, NULL, NULL, NULL, NULL),
(38, 'StaffSCM_BGR', 'Staff Supply Chain Management (BGR)', '$2y$12$TpuEILo63MSmkFmrqyal2.JVAIR6Tfz09SmRbmpr5gQxWlME7h76S', 'staff', 'STAFF', 'SCM', 'BGR', 'SCM221101', '2026-03-04 23:32:40', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:10', '2026-03-04 21:21:34', '2026-03-04 21:21:34', NULL, NULL, NULL, NULL, NULL),
(39, 'StaffSYS_BGR', 'Staff System (BGR)', '$2y$12$.uUJtyY6CPspomHPh7UsXuaaHGpI/KnzL.gg1UnNJ4rTtoSnVxIbK', 'staff', 'STAFF', 'SYS', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:10', '2026-02-26 08:02:10', NULL, NULL, NULL, NULL, NULL, NULL),
(40, 'MgrWQS_BGR', 'Manager Warehouse & Quality Stock (BGR)', '$2y$12$IbJP2BrA0FP/Xxi0DBRNbu.0TxCvVH3OneZqm0cnu8LYlF19xVRC6', 'manager', 'MANAGER', 'WQS', 'BGR', 'WQS200501', '2026-03-04 23:29:59', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:11', '2026-03-09 04:15:54', '2026-03-09 04:15:54', NULL, NULL, NULL, NULL, NULL),
(41, 'StaffWQS_BGR', 'Staff Warehouse & Quality Stock (BGR)', '$2y$12$12OcKc0h8JrHX8nn/9f4N.FVVK90lyUGTdqf2ChG/ufx/bhCSMe1C', 'staff', 'STAFF', 'WQS', 'BGR', 'WQS230101', '2026-03-04 23:30:38', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:11', '2026-03-04 21:20:34', '2026-03-04 21:20:34', NULL, NULL, NULL, NULL, NULL),
(42, 'MgrACT_BKS', 'Manager Accounting/Customs (ACT) (BKS)', '$2y$12$AqNcXkjqLAqWJH5lgX.NNOb4FYik1gfWDTefMuhyQ801Zx3S.uEsS', 'manager', 'MANAGER', 'ACT', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:12', '2026-02-26 08:02:12', NULL, NULL, NULL, NULL, NULL, NULL),
(43, 'StaffACT_BKS', 'Staff Accounting & Tax (BKS)', '$2y$12$FNrlg/sIYjuzMf19VTz4cepCluZohUG6g4pAnK/KBpm1o56KvQgXi', 'staff', 'STAFF', 'ACT', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:12', '2026-02-26 08:02:12', NULL, NULL, NULL, NULL, NULL, NULL),
(44, 'MgrCRM_BKS', 'Manager Sales/CRM (CRM) (BKS)', '$2y$12$jnwyt4o5LCHMmfxusiJXI.6GPt.800sdLTZf1J/BYOu4W8fSU2H6.', 'manager', 'MANAGER', 'CRM', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:12', '2026-02-26 08:02:12', NULL, NULL, NULL, NULL, NULL, NULL),
(45, 'StaffCRM_BKS', 'Staff Customer Relationship Management (BKS)', '$2y$12$H.YT2y32TIe9Be2ub6dErehKIpBwKgz8gzo/r8K541CgUP0ZIE5jW', 'staff', 'STAFF', 'CRM', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:13', '2026-02-26 08:02:13', NULL, NULL, NULL, NULL, NULL, NULL),
(46, 'MgrFIN_BKS', 'Manager Finance (FIN) (BKS)', '$2y$12$z5iBBAeT7srLaWqdD5XqDO.XakabIce1OzmvnimPKpONvSKPeqy8C', 'manager', 'MANAGER', 'FIN', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:13', '2026-02-26 08:02:13', NULL, NULL, NULL, NULL, NULL, NULL),
(47, 'StaffFIN_BKS', 'Staff Finance (BKS)', '$2y$12$1x2xYhdYU9XoYQxvBiY7aei.v5yDnbO7X.7sYcy3KWu8V68.puRVC', 'staff', 'STAFF', 'FIN', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:14', '2026-02-26 08:02:14', NULL, NULL, NULL, NULL, NULL, NULL),
(48, 'MgrHRL_BKS', 'Manager HR/Legal (HRL) (BKS)', '$2y$12$d08XfjmqgsrMvJRO7zAj/e4pW7Nj333jtHYZhBRAthmFiz7yps5r6', 'manager', 'MANAGER', 'HRL', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:14', '2026-02-26 08:02:14', NULL, NULL, NULL, NULL, NULL, NULL),
(49, 'StaffHRL_BKS', 'Staff Human Resource & Legal (BKS)', '$2y$12$FFiG2bCjgLSlyJuoNAGHR.s4nAEUtcpQu1ZWDSptgFY8OxmD2qbza', 'staff', 'STAFF', 'HRL', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:14', '2026-02-26 08:02:14', NULL, NULL, NULL, NULL, NULL, NULL),
(50, 'MgrITC_BKS', 'Manager IT/Communication (ITC) (BKS)', '$2y$12$abcxZsAFFhdQZ4LVPbPll.3bH3DNZ6Y0nmLGN0sjFk2iW8rasBpAq', 'manager', 'MANAGER', 'ITC', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:15', '2026-02-26 08:02:15', NULL, NULL, NULL, NULL, NULL, NULL),
(51, 'StaffITC_BKS', 'Staff IT & Cloud (BKS)', '$2y$12$6i/swsXbMIv1gMlFwcAWCeKFp0NWzqS1NLtOZNWCWxbM7kVtORFnK', 'staff', 'STAFF', 'ITC', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:15', '2026-02-26 08:02:15', NULL, NULL, NULL, NULL, NULL, NULL),
(52, 'MgrMPR_BKS', 'Manager Marketing/PR (MPR) (BKS)', '$2y$12$ZFHOgXlBf2/HwXLqwjxyDO1h23KLhjtkjEQKC0qmQjuVk7d0wedky', 'manager', 'MANAGER', 'MPR', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:16', '2026-02-26 08:02:16', NULL, NULL, NULL, NULL, NULL, NULL),
(53, 'StaffMPR_BKS', 'Staff Marketing & Project (BKS)', '$2y$12$SUJ0BlavtGqQHHOhjeDNiOXY0xRwcDbOb1pCM3/b5kvOXBQVqPp.K', 'staff', 'STAFF', 'MPR', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:16', '2026-02-26 08:02:16', NULL, NULL, NULL, NULL, NULL, NULL),
(54, 'MgrPQP_BKS', 'Manager Purchasing (PQP) (BKS)', '$2y$12$6AZuaQD7IF8k1wEFl8610ut7H6qC1mtibyQDbIpkBzuRsdkURgxUi', 'manager', 'MANAGER', 'PQP', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:16', '2026-02-26 08:02:16', NULL, NULL, NULL, NULL, NULL, NULL),
(55, 'StaffPQP_BKS', 'Staff Product Quality & Purchasing (BKS)', '$2y$12$Rzp68w.u/caf5PhBd/f6SumsL1t29z081KeiUghfKnhTBcbdzHzhK', 'staff', 'STAFF', 'PQP', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:17', '2026-02-26 08:02:17', NULL, NULL, NULL, NULL, NULL, NULL),
(56, 'MgrSCM_BKS', 'Manager Supply Chain (SCM) (BKS)', '$2y$12$LAkOWkQFMCTW9gpy.ySZnuYTwuec4/zwLaFyd5L8TY2o5yD6/fEVe', 'manager', 'MANAGER', 'SCM', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:17', '2026-02-26 08:02:17', NULL, NULL, NULL, NULL, NULL, NULL),
(57, 'StaffSCM_BKS', 'Staff Supply Chain Management (BKS)', '$2y$12$SL/g5F4/aqclslUCBB4oY.ZUKQ1v3U6FdC1hvaEaDF4oSIqvRCqgS', 'staff', 'STAFF', 'SCM', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:18', '2026-02-26 08:02:18', NULL, NULL, NULL, NULL, NULL, NULL),
(58, 'StaffSYS_BKS', 'Staff System (BKS)', '$2y$12$PGzGcibgtOOZJdUXbSwT0.w47JxMiOL5LBSh8f5BShOI4P1weDv9W', 'staff', 'STAFF', 'SYS', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:18', '2026-02-26 08:02:18', NULL, NULL, NULL, NULL, NULL, NULL),
(59, 'MgrWQS_BKS', 'Manager Warehouse (WQS) (BKS)', '$2y$12$bh0b19.qjQIi0zt7de0VV.s51pqPDNorpPDrcTpJNjMMR4hbcJgyW', 'manager', 'MANAGER', 'WQS', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:18', '2026-02-26 08:02:18', NULL, NULL, NULL, NULL, NULL, NULL),
(60, 'StaffWQS_BKS', 'Staff Warehouse & Quality Stock (BKS)', '$2y$12$NtNHE1awTDYUGLXGYqyVNe4pGOe0VZSb7K7/deG5J0/.shz3RagF2', 'staff', 'STAFF', 'WQS', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:19', '2026-02-26 08:02:19', NULL, NULL, NULL, NULL, NULL, NULL),
(61, 'MgrACT_JGY', 'Manager Accounting/Customs (ACT) (JGY)', '$2y$12$v16ggZtt5HY.YIUfqX6WgOHIRSZK5CjHBz82RQMuUUr2v7p9zhByq', 'manager', 'MANAGER', 'ACT', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:19', '2026-02-26 08:02:19', NULL, NULL, NULL, NULL, NULL, NULL),
(62, 'StaffACT_JGY', 'Staff Accounting & Tax (JGY)', '$2y$12$zO5EYi7COm/IlnIRs3KBaOHT6UxjeI6W4Fw2BRY4vu62JZrj/kLyy', 'staff', 'STAFF', 'ACT', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:20', '2026-02-26 08:02:20', NULL, NULL, NULL, NULL, NULL, NULL),
(63, 'MgrCRM_JGY', 'Manager Sales/CRM (CRM) (JGY)', '$2y$12$.7SlypzjJpDQRms4hPRT.ObxxNqKKNCExbCuRz/2XTl3fxMePa/wO', 'manager', 'MANAGER', 'CRM', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:20', '2026-02-26 08:02:20', NULL, NULL, NULL, NULL, NULL, NULL),
(64, 'StaffCRM_JGY', 'Staff Customer Relationship Management (JGY)', '$2y$12$4XY1pz3fcnfyYYXA534oI.4sxPBUTZ2XQZKtDp3s/TFlPolFCL0qG', 'staff', 'STAFF', 'CRM', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:21', '2026-02-26 08:02:21', NULL, NULL, NULL, NULL, NULL, NULL),
(65, 'MgrFIN_JGY', 'Manager Finance (FIN) (JGY)', '$2y$12$BNez2YVQo09bVHz9bYPW3OhzQvnS5cMwkkz/A8bv/zbn4V6kOldsi', 'manager', 'MANAGER', 'FIN', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:21', '2026-02-26 08:02:21', NULL, NULL, NULL, NULL, NULL, NULL),
(66, 'StaffFIN_JGY', 'Staff Finance (JGY)', '$2y$12$gk2sTyEkrH3QkE1izRQyBO1FnYfh/mksTggxTX/vTV9Ui8wvlv6Ue', 'staff', 'STAFF', 'FIN', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:21', '2026-02-26 08:02:21', NULL, NULL, NULL, NULL, NULL, NULL),
(67, 'MgrHRL_JGY', 'Manager HR/Legal (HRL) (JGY)', '$2y$12$rXWMGaiE1dFwKeDYJfQUEO0EAr6kGDBcy0kUekcnueJljcFeX9S4C', 'manager', 'MANAGER', 'HRL', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:22', '2026-02-26 08:02:22', NULL, NULL, NULL, NULL, NULL, NULL),
(68, 'StaffHRL_JGY', 'Staff Human Resource & Legal (JGY)', '$2y$12$wLfCHM7f1TTaP4Y7S/x7H.TCIoFp.JVMXCYlELAe9FFm3xPGBpGoS', 'staff', 'STAFF', 'HRL', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:22', '2026-02-26 08:02:22', NULL, NULL, NULL, NULL, NULL, NULL),
(69, 'MgrITC_JGY', 'Manager IT/Communication (ITC) (JGY)', '$2y$12$zzJOKFoMS3bBxrL/HZpRbOS3Joz1tWzrkMtTc/7k2giQuKA3f0L6m', 'manager', 'MANAGER', 'ITC', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:22', '2026-02-26 08:02:22', NULL, NULL, NULL, NULL, NULL, NULL),
(70, 'StaffITC_JGY', 'Staff IT & Cloud (JGY)', '$2y$12$wK4E/xHo6u91oSpGQOSdNODXHeTaFprZKJ7RC462Lk6AHID/uNgp6', 'staff', 'STAFF', 'ITC', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:23', '2026-02-26 08:02:23', NULL, NULL, NULL, NULL, NULL, NULL),
(71, 'MgrMPR_JGY', 'Manager Marketing/PR (MPR) (JGY)', '$2y$12$Ho9XDCFuHcLn5qNjPbHifeVPR1vviy7A8z4gont/bqEvraEc.Ud0O', 'manager', 'MANAGER', 'MPR', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:23', '2026-02-26 08:02:23', NULL, NULL, NULL, NULL, NULL, NULL),
(72, 'StaffMPR_JGY', 'Staff Marketing & Project (JGY)', '$2y$12$rn5/Ankk8nwOVTYvq/UhFu.9MHFRf14At/4a4bjnFcn5WCkANjxGu', 'staff', 'STAFF', 'MPR', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:24', '2026-02-26 08:02:24', NULL, NULL, NULL, NULL, NULL, NULL),
(73, 'MgrPQP_JGY', 'Manager Purchasing (PQP) (JGY)', '$2y$12$OIUGMySQ4MzGn1cHqGdSQ.pTAS4iFT1TMbQ/emUwTC1fQtPOZ3BFa', 'manager', 'MANAGER', 'PQP', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:24', '2026-02-26 08:02:24', NULL, NULL, NULL, NULL, NULL, NULL),
(74, 'StaffPQP_JGY', 'Staff Product Quality & Purchasing (JGY)', '$2y$12$2P8hN2LkLt3LRo1ecGf9cOBg6h7gAYoEUSgCZGdt.HGJGvFlAquCy', 'staff', 'STAFF', 'PQP', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:24', '2026-02-26 08:02:24', NULL, NULL, NULL, NULL, NULL, NULL),
(75, 'MgrSCM_JGY', 'Manager Supply Chain (SCM) (JGY)', '$2y$12$zuA2vYBaSWzQmO0awFPNsOWltYk07S7clhZui8TghYDSG2s4PfjnW', 'manager', 'MANAGER', 'SCM', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:25', '2026-02-26 08:02:25', NULL, NULL, NULL, NULL, NULL, NULL),
(76, 'StaffSCM_JGY', 'Staff Supply Chain Management (JGY)', '$2y$12$BJ..5xjDFHgbSujnxiy78.0IUVsX5Fsh47ckds/ckUzG1eLWTLzeW', 'staff', 'STAFF', 'SCM', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:25', '2026-02-26 08:02:25', NULL, NULL, NULL, NULL, NULL, NULL),
(77, 'StaffSYS_JGY', 'Staff System (JGY)', '$2y$12$Iht185xmgOGtHiauJ2FDIu0HVuJzeaKg6ITaDUEJuTqWPgLk60BCa', 'staff', 'STAFF', 'SYS', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:26', '2026-02-26 08:02:26', NULL, NULL, NULL, NULL, NULL, NULL),
(78, 'MgrWQS_JGY', 'Manager Warehouse (WQS) (JGY)', '$2y$12$0mZ5FDu3BbNVmflvMtWvIeKBEjK8B7Ms3ukeGyIO5cuRIV8BhipCi', 'manager', 'MANAGER', 'WQS', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:26', '2026-02-26 08:02:26', NULL, NULL, NULL, NULL, NULL, NULL),
(79, 'StaffWQS_JGY', 'Staff Warehouse & Quality Stock (JGY)', '$2y$12$lKVKJxfsUDwq0T5OXCAVoe3z9YzLgshd52qJ0b8PsBfLEfJAfXBIm', 'staff', 'STAFF', 'WQS', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:26', '2026-02-26 08:02:26', NULL, NULL, NULL, NULL, NULL, NULL),
(80, 'MgrACT_KAL', 'Manager Accounting/Customs (ACT) (KAL)', '$2y$12$yX.Iso2f/BNQPkQwEeDrn.ZqCLcipvGquWdoBW6KVIyJgZB3YQHY.', 'manager', 'MANAGER', 'ACT', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:27', '2026-02-26 08:02:27', NULL, NULL, NULL, NULL, NULL, NULL),
(81, 'StaffACT_KAL', 'Staff Accounting & Tax (KAL)', '$2y$12$eJ6OIPICZErQfZrzxCmUXe6K0dOVcsxEAh/rVflw8AJYeHNsgnnrS', 'staff', 'STAFF', 'ACT', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:27', '2026-02-26 08:02:27', NULL, NULL, NULL, NULL, NULL, NULL),
(82, 'MgrCRM_KAL', 'Manager Sales/CRM (CRM) (KAL)', '$2y$12$HMdbvmvP3J/KhUA7s2Jo8Oem9VpV8V8LdtDqQg23Vx7jm2qwYKGqa', 'manager', 'MANAGER', 'CRM', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:27', '2026-02-26 08:02:27', NULL, NULL, NULL, NULL, NULL, NULL),
(83, 'StaffCRM_KAL', 'Staff Customer Relationship Management (KAL)', '$2y$12$TITlPzaKy36rZvwgWksPsuxu4tYx0bkuxlXUonLspEE4eOJ9yK/Ge', 'staff', 'STAFF', 'CRM', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:28', '2026-02-26 08:02:28', NULL, NULL, NULL, NULL, NULL, NULL),
(84, 'MgrFIN_KAL', 'Manager Finance (FIN) (KAL)', '$2y$12$4EI3kDEJ741J8jFBHCCrBepuzjdUM1YieAuAkv/hrB31dmalQNO8u', 'manager', 'MANAGER', 'FIN', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:28', '2026-02-26 08:02:28', NULL, NULL, NULL, NULL, NULL, NULL),
(85, 'StaffFIN_KAL', 'Staff Finance (KAL)', '$2y$12$AXYUElEifAtlnQXi.Z9aXODeiI7g9A7l0gNl8gbm1BLX0BS6.Qf.a', 'staff', 'STAFF', 'FIN', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:29', '2026-02-26 08:02:29', NULL, NULL, NULL, NULL, NULL, NULL),
(86, 'MgrHRL_KAL', 'Manager HR/Legal (HRL) (KAL)', '$2y$12$2c.pPa3BV6IoiWGJnWGkI.o752h6uxfxiYHmfnRUrHMv66eTWPwY.', 'manager', 'MANAGER', 'HRL', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:29', '2026-02-26 08:02:29', NULL, NULL, NULL, NULL, NULL, NULL),
(87, 'StaffHRL_KAL', 'Staff Human Resource & Legal (KAL)', '$2y$12$DwPbEXAS9zql42wz1ITxsOg/V/Wb9juf3yPPVIoIkUSVC25BxWGay', 'staff', 'STAFF', 'HRL', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:29', '2026-02-26 08:02:29', NULL, NULL, NULL, NULL, NULL, NULL),
(88, 'MgrITC_KAL', 'Manager IT/Communication (ITC) (KAL)', '$2y$12$7ujQKNBxyqM1sJ5UCtBEiuNxrL.U02E71HBDGrefonLqiYCBFVcRW', 'manager', 'MANAGER', 'ITC', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:30', '2026-02-26 08:02:30', NULL, NULL, NULL, NULL, NULL, NULL),
(89, 'StaffITC_KAL', 'Staff IT & Cloud (KAL)', '$2y$12$5X.iuglpC5jStokfaGDasOYEhX68X0QryOD7fdmbXsDZQqXe9ODT6', 'staff', 'STAFF', 'ITC', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:30', '2026-02-26 08:02:30', NULL, NULL, NULL, NULL, NULL, NULL),
(90, 'MgrMPR_KAL', 'Manager Marketing/PR (MPR) (KAL)', '$2y$12$HDDc7WvWB942Sn7NFqkQDuXOo5nQqXEqH7pjhZweCzhHYaH1XBBdq', 'manager', 'MANAGER', 'MPR', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:30', '2026-02-26 08:02:30', NULL, NULL, NULL, NULL, NULL, NULL),
(91, 'StaffMPR_KAL', 'Staff Marketing & Project (KAL)', '$2y$12$jgAo4pZPpiRHHdojnKFMa.pAQ4yEg6KVM.hzketeJDfQV8Kblo2CW', 'staff', 'STAFF', 'MPR', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:31', '2026-02-26 08:02:31', NULL, NULL, NULL, NULL, NULL, NULL),
(92, 'MgrPQP_KAL', 'Manager Purchasing (PQP) (KAL)', '$2y$12$piHY2DA6KXdyWXhhzAE66OV0mXpbh6LaIe6OaKQBfSzaeC6yZa93u', 'manager', 'MANAGER', 'PQP', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:31', '2026-02-26 08:02:31', NULL, NULL, NULL, NULL, NULL, NULL),
(93, 'StaffPQP_KAL', 'Staff Product Quality & Purchasing (KAL)', '$2y$12$xNGdqYvc4zHsBWtv62rltOkSQqgsBoQJ4a3rvAlAx6k7kUC4nY3O2', 'staff', 'STAFF', 'PQP', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:32', '2026-02-26 08:02:32', NULL, NULL, NULL, NULL, NULL, NULL),
(94, 'MgrSCM_KAL', 'Manager Supply Chain (SCM) (KAL)', '$2y$12$SDBwHnmb535NYvirPHCYWOCtL8vRkj7/LwWBjipKFrJTgyUnVW84q', 'manager', 'MANAGER', 'SCM', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:32', '2026-02-26 08:02:32', NULL, NULL, NULL, NULL, NULL, NULL),
(95, 'StaffSCM_KAL', 'Staff Supply Chain Management (KAL)', '$2y$12$VYJcboU./6p4UonL0Kr7luA6.SGRKGU.wMMl1PfKTd1Cc7WsxlQz.', 'staff', 'STAFF', 'SCM', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:32', '2026-02-26 08:02:32', NULL, NULL, NULL, NULL, NULL, NULL),
(96, 'StaffSYS_KAL', 'Staff System (KAL)', '$2y$12$kBT11qbQZ.4.GjDxLaL93urwFFqmBeyGhWu38Np/k21noFaofVEU2', 'staff', 'STAFF', 'SYS', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:33', '2026-02-26 08:02:33', NULL, NULL, NULL, NULL, NULL, NULL),
(97, 'MgrWQS_KAL', 'Manager Warehouse (WQS) (KAL)', '$2y$12$91y05EFJ3xhLDR4rIT0Ri.qZh.FI4gTMuXW4uYlTW8gf.s1eIfrmO', 'manager', 'MANAGER', 'WQS', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:33', '2026-02-26 08:02:33', NULL, NULL, NULL, NULL, NULL, NULL),
(98, 'StaffWQS_KAL', 'Staff Warehouse & Quality Stock (KAL)', '$2y$12$Yilr7pOuviZ6fIj1lKP8Verfpxf3msgsk5qOR1kCuYn5bzi68Ddh6', 'staff', 'STAFF', 'WQS', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:34', '2026-02-26 08:02:34', NULL, NULL, NULL, NULL, NULL, NULL),
(99, 'MgrACT_SLO', 'Manager Accounting/Customs (ACT) (SLO)', '$2y$12$nRQcQe4MKJPrmuNBctPXMOsEx/6cfg1yWDihAhHNFQkGJpaZFfyqu', 'manager', 'MANAGER', 'ACT', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:34', '2026-02-26 08:02:34', NULL, NULL, NULL, NULL, NULL, NULL),
(100, 'StaffACT_SLO', 'Staff Accounting & Tax (SLO)', '$2y$12$XKogt07E73fj.IXHdkByI.I9TINaPS9SWekRisy/oBZTIRhDfMtua', 'staff', 'STAFF', 'ACT', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:34', '2026-02-26 08:02:34', NULL, NULL, NULL, NULL, NULL, NULL),
(101, 'MgrCRM_SLO', 'Manager Sales/CRM (CRM) (SLO)', '$2y$12$mGf5rEs60WLa88mpa1Vdk.buFrcHQOIpli/BX0v3lFCqDMze5QOie', 'manager', 'MANAGER', 'CRM', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:35', '2026-02-26 08:02:35', NULL, NULL, NULL, NULL, NULL, NULL),
(102, 'StaffCRM_SLO', 'Staff Customer Relationship Management (SLO)', '$2y$12$22xsjJaYB7AWP5SDJxXbbOg77utYrMS76ANbJBBrocQlTfCMq2UNm', 'staff', 'STAFF', 'CRM', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:35', '2026-02-26 08:02:35', NULL, NULL, NULL, NULL, NULL, NULL),
(103, 'MgrFIN_SLO', 'Manager Finance (FIN) (SLO)', '$2y$12$dMKqYVmNCN5Y/IZ6jDuNcebnWcSC6StcsWJEGCTGaM0eTIpalNaLG', 'manager', 'MANAGER', 'FIN', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:35', '2026-02-26 08:02:35', NULL, NULL, NULL, NULL, NULL, NULL),
(104, 'StaffFIN_SLO', 'Staff Finance (SLO)', '$2y$12$xEafw7wDr3cLn21y/ltLi.MUfUWdNoz8TkmGRfKzaA0wv88RKllF2', 'staff', 'STAFF', 'FIN', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:36', '2026-02-26 08:02:36', NULL, NULL, NULL, NULL, NULL, NULL),
(105, 'MgrHRL_SLO', 'Manager HR/Legal (HRL) (SLO)', '$2y$12$l2gJveLrqsockNHaBaTSouYFHxJ3L4FNmefFt6vZLa0i08v6qT.oq', 'manager', 'MANAGER', 'HRL', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:36', '2026-02-26 08:02:36', NULL, NULL, NULL, NULL, NULL, NULL),
(106, 'StaffHRL_SLO', 'Staff Human Resource & Legal (SLO)', '$2y$12$cAsjM8MbZuBULsoq0BIu1.0/tzfrpLBAdYqPbifuNewBfSWXPzMyi', 'staff', 'STAFF', 'HRL', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:37', '2026-02-26 08:02:37', NULL, NULL, NULL, NULL, NULL, NULL),
(107, 'MgrITC_SLO', 'Manager IT/Communication (ITC) (SLO)', '$2y$12$edDZAiN/qBRp0lbproOlxeH..g0BRxEHPMiISjSVOjMBpAByymQB.', 'manager', 'MANAGER', 'ITC', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:37', '2026-02-26 08:02:37', NULL, NULL, NULL, NULL, NULL, NULL),
(108, 'StaffITC_SLO', 'Staff IT & Cloud (SLO)', '$2y$12$UzRcf/RgljE3fk5Gvao7B.MZ.pNvq6HxQ0NOZcUPDMfuUpBApMTXS', 'staff', 'STAFF', 'ITC', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:37', '2026-02-26 08:02:37', NULL, NULL, NULL, NULL, NULL, NULL),
(109, 'MgrMPR_SLO', 'Manager Marketing/PR (MPR) (SLO)', '$2y$12$3McOlE7CQaZX/yLgVFygUuupaLOk52g58mypum/0922Rr/CpMLwyy', 'manager', 'MANAGER', 'MPR', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:38', '2026-02-26 08:02:38', NULL, NULL, NULL, NULL, NULL, NULL),
(110, 'StaffMPR_SLO', 'Staff Marketing & Project (SLO)', '$2y$12$Mt9O4EoLKFARgHMOyEwzm.0Q4WpGUyjcWLoDRq4ZWjkk0uqRIihQa', 'staff', 'STAFF', 'MPR', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:38', '2026-02-26 08:02:38', NULL, NULL, NULL, NULL, NULL, NULL),
(111, 'MgrPQP_SLO', 'Manager Purchasing (PQP) (SLO)', '$2y$12$IrhMmHWXCqIWZ8agMhm1aezL8UqG.RirFYLAXz93MNJtBAmWODQYS', 'manager', 'MANAGER', 'PQP', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:38', '2026-02-26 08:02:38', NULL, NULL, NULL, NULL, NULL, NULL),
(112, 'StaffPQP_SLO', 'Staff Product Quality & Purchasing (SLO)', '$2y$12$PDb/B/.5.b1aMYr.cMqGMupRCpsGCjGCOiUEQ0pbHe/Z/UgVD5gdq', 'staff', 'STAFF', 'PQP', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:39', '2026-02-26 08:02:39', NULL, NULL, NULL, NULL, NULL, NULL),
(113, 'MgrSCM_SLO', 'Manager Supply Chain (SCM) (SLO)', '$2y$12$Q4QOTTSfcdWfUcjUoAMdFeZU9WhpNPn0kwNuTJaamTL1EjLvk55xq', 'manager', 'MANAGER', 'SCM', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:39', '2026-02-26 08:02:39', NULL, NULL, NULL, NULL, NULL, NULL),
(114, 'StaffSCM_SLO', 'Staff Supply Chain Management (SLO)', '$2y$12$fjnFx7cLQVCNwNUFbvWkhu3gsFbT4CuaLnaSCMf7hmpiyqSL7gw1y', 'staff', 'STAFF', 'SCM', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:40', '2026-02-26 08:02:40', NULL, NULL, NULL, NULL, NULL, NULL),
(115, 'StaffSYS_SLO', 'Staff System/IT (SYS) (SLO)', '$2y$12$ocpyWY4Li7VJrJXxxCtuRe0CwMeqes6WN2JJsiI4c0yZc/SurbXyK', 'staff', 'STAFF', 'SYS', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:40', '2026-02-26 08:02:40', NULL, NULL, NULL, NULL, NULL, NULL),
(116, 'MgrWQS_SLO', 'Manager Warehouse (WQS) (SLO)', '$2y$12$SXbitKlYverDmfpmR201IOU7izcTTSiY93.TLBGFxKcqlQVncFH9y', 'manager', 'MANAGER', 'WQS', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:40', '2026-02-26 08:02:40', NULL, NULL, NULL, NULL, NULL, NULL),
(117, 'StaffWQS_SLO', 'Staff Warehouse & Quality Stock (SLO)', '$2y$12$FhAaYIAJhwJL/3Q.E7HgFeMiZ9hjcc6ntGCqpuVE3Marx0nrLi.Y2', 'staff', 'STAFF', 'WQS', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:41', '2026-02-26 08:02:41', NULL, NULL, NULL, NULL, NULL, NULL),
(118, 'MgrACT_SMG', 'Manager Accounting/Customs (ACT) (SMG)', '$2y$12$pjq4lZOUXW.Krub2pTI1kOMBKQxI049sEWcuGLYc0dKNC3sJezX5W', 'manager', 'MANAGER', 'ACT', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:41', '2026-02-26 08:02:41', NULL, NULL, NULL, NULL, NULL, NULL),
(119, 'StaffACT_SMG', 'Staff Accounting & Tax (SMG)', '$2y$12$qURXVjre.6Nw8g1BGgdF/OOTNWEnQy4EXEZ5sndNfhtRCN/IKGzhm', 'staff', 'STAFF', 'ACT', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:42', '2026-02-26 08:02:42', NULL, NULL, NULL, NULL, NULL, NULL),
(120, 'MgrCRM_SMG', 'Manager Sales/CRM (CRM) (SMG)', '$2y$12$xSTlpAN9J.F9GNj9qvZ6KeR9rQhZWQfK5tB0LFGxdGztVatn411Ky', 'manager', 'MANAGER', 'CRM', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:42', '2026-02-26 08:02:42', NULL, NULL, NULL, NULL, NULL, NULL),
(121, 'StaffCRM_SMG', 'Staff Customer Relationship Management (SMG)', '$2y$12$yewry27w/Zc.sG5dYCz0.OqZOedLtNIcyUEw4nYfNAZs6XlcTn7jy', 'staff', 'STAFF', 'CRM', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:42', '2026-02-26 08:02:42', NULL, NULL, NULL, NULL, NULL, NULL),
(122, 'MgrFIN_SMG', 'Manager Finance (FIN) (SMG)', '$2y$12$vEe4R07QEJlxfVXIbXvIxOTSpClqOkODz.JCAQK8fRJWgcRWIf2uy', 'manager', 'MANAGER', 'FIN', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:43', '2026-02-26 08:02:43', NULL, NULL, NULL, NULL, NULL, NULL),
(123, 'StaffFIN_SMG', 'Staff Finance (SMG)', '$2y$12$zrMyJaLqyHbTbWKtbXwkDun71tAILwiBtVQ4ZIfycfzAl8rWcC/sS', 'staff', 'STAFF', 'FIN', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:43', '2026-02-26 08:02:43', NULL, NULL, NULL, NULL, NULL, NULL),
(124, 'MgrHRL_SMG', 'Manager HR/Legal (HRL) (SMG)', '$2y$12$yeJiVNGfrYjh1gmlSVaEsO8Cxrj.IyyshgcpYNw0LUVn48KjTjdqu', 'manager', 'MANAGER', 'HRL', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:43', '2026-02-26 08:02:43', NULL, NULL, NULL, NULL, NULL, NULL),
(125, 'StaffHRL_SMG', 'Staff Human Resource & Legal (SMG)', '$2y$12$v.Gxrcrn9rIWcpbdvQUeV.9bcq0sLP09J8EnTaLh9bEanYwFusCvy', 'staff', 'STAFF', 'HRL', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:44', '2026-02-26 08:02:44', NULL, NULL, NULL, NULL, NULL, NULL),
(126, 'MgrITC_SMG', 'Manager IT/Communication (ITC) (SMG)', '$2y$12$FhFdxzfNZtK7sdjD5XWg5.Px.Z4098aaXTqVj6de4rf7f7NezY1jq', 'manager', 'MANAGER', 'ITC', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:44', '2026-02-26 08:02:44', NULL, NULL, NULL, NULL, NULL, NULL),
(127, 'StaffITC_SMG', 'Staff IT & Cloud (SMG)', '$2y$12$UAc22ExbZzGMv2ff91MJD.4/t2.qqiwleIkRh99wb9XKuQp/g/uDe', 'staff', 'STAFF', 'ITC', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:45', '2026-02-26 08:02:45', NULL, NULL, NULL, NULL, NULL, NULL),
(128, 'MgrMPR_SMG', 'Manager Marketing/PR (MPR) (SMG)', '$2y$12$6xnDRRMB7es6vnjFcNJ/iu.NNNWPrUAPGmez21rfuN1Zpvm4s9C0m', 'manager', 'MANAGER', 'MPR', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:45', '2026-02-26 08:02:45', NULL, NULL, NULL, NULL, NULL, NULL),
(129, 'StaffMPR_SMG', 'Staff Marketing & Project (SMG)', '$2y$12$1PaM5dEdYNnqEnlP8YgUh.OWByvX/0GxLOhjPfNZAWLnG1hNdig4a', 'staff', 'STAFF', 'MPR', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:45', '2026-02-26 08:02:45', NULL, NULL, NULL, NULL, NULL, NULL),
(130, 'MgrPQP_SMG', 'Manager Purchasing (PQP) (SMG)', '$2y$12$UWhGyae8dgGHv45aZlIwXOAPyD.A4L.YjQEXhJrNMr4EIydegdI1y', 'manager', 'MANAGER', 'PQP', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:46', '2026-02-26 08:02:46', NULL, NULL, NULL, NULL, NULL, NULL),
(131, 'StaffPQP_SMG', 'Staff Product Quality & Purchasing (SMG)', '$2y$12$gTzrwVCT6TLIHUf8eXVyB.0pISBb4FkaCuH19hVzHkJYN1TQTq97u', 'staff', 'STAFF', 'PQP', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:46', '2026-02-26 08:02:46', NULL, NULL, NULL, NULL, NULL, NULL),
(132, 'MgrSCM_SMG', 'Manager Supply Chain (SCM) (SMG)', '$2y$12$MszforeTtbclpX5lvuBi0.cYSkrKXH2FrAjFccri0Wf0hEboNXsPm', 'manager', 'MANAGER', 'SCM', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:46', '2026-02-26 08:02:46', NULL, NULL, NULL, NULL, NULL, NULL),
(133, 'StaffSCM_SMG', 'Staff Supply Chain Management (SMG)', '$2y$12$F1ONhk2mKbnLgx/OE6NcIe3gIrY4UKQXXLony0aUT.hZ3o.dIUA1a', 'staff', 'STAFF', 'SCM', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:47', '2026-02-26 08:02:47', NULL, NULL, NULL, NULL, NULL, NULL),
(134, 'StaffSYS_SMG', 'Staff System/IT (SYS) (SMG)', '$2y$12$.OALtrgnE.dgcCWaj3Qts.yUA4EE12RhHwjLI8xEp127Qbg/L2qJC', 'staff', 'STAFF', 'SYS', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:47', '2026-02-26 08:02:47', NULL, NULL, NULL, NULL, NULL, NULL),
(135, 'MgrWQS_SMG', 'Manager Warehouse (WQS) (SMG)', '$2y$12$hsQvYIPoj6MLOKjSRCNQGuvGSeVLsoZNWH5Yah.Z6rBIZ4pHCBCM6', 'manager', 'MANAGER', 'WQS', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:48', '2026-02-26 08:02:48', NULL, NULL, NULL, NULL, NULL, NULL),
(136, 'StaffWQS_SMG', 'Staff Warehouse & Quality Stock (SMG)', '$2y$12$jO60XO4OQZG0SObMlk4ItuR8zut1VAV75IxqObtd7scNmnCDc9x1e', 'staff', 'STAFF', 'WQS', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:48', '2026-02-26 08:02:48', NULL, NULL, NULL, NULL, NULL, NULL),
(137, 'StaffACT_SYS', 'Staff Accounting & Tax (SYS)', '$2y$12$ZEo7P.mfWGnnqtrJaLm/U.6.XdzAme0zhCFWXMIzDsXbl1Stk4R3m', 'staff', 'STAFF', 'ACT', 'SYS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:48', '2026-02-26 08:02:48', NULL, NULL, NULL, NULL, NULL, NULL),
(138, 'StaffCRM_SYS', 'Staff Customer Relationship Management (SYS)', '$2y$12$mOpuqhJHTUdEPpJ.ISDH5uS1tab9JU48P7S0ZC71Lbx7Dh6YX6QGS', 'staff', 'STAFF', 'CRM', 'SYS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:49', '2026-02-26 08:02:49', NULL, NULL, NULL, NULL, NULL, NULL),
(139, 'StaffFIN_SYS', 'Staff Finance (SYS)', '$2y$12$sijA3w3M5BRtKt60fwUjrO9PPY7S4kxScGPnh2rH5YPVflhN4U3hC', 'staff', 'STAFF', 'FIN', 'SYS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:49', '2026-02-26 08:02:49', NULL, NULL, NULL, NULL, NULL, NULL),
(140, 'StaffHRL_SYS', 'Staff Human Resource & Legal (SYS)', '$2y$12$vFZPXFPN445AtS1phiefFur.Y/SHPlspdCjToFX32OFZKxue8qIrC', 'staff', 'STAFF', 'HRL', 'SYS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:50', '2026-02-26 08:02:50', NULL, NULL, NULL, NULL, NULL, NULL),
(141, 'StaffITC_SYS', 'Staff IT & Cloud (SYS)', '$2y$12$qrGm/a9qtLQtn5Y7..ARM.jGu1922ZWbcJp0RyLLTcfrVrwaPiBrO', 'staff', 'STAFF', 'ITC', 'SYS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:50', '2026-02-26 08:02:50', NULL, NULL, NULL, NULL, NULL, NULL),
(142, 'StaffMPR_SYS', 'Staff Marketing & Project (SYS)', '$2y$12$Y8mzLmp6G9LcC6WhFCd9SOX0LLJumnMkHs7C5/wIeWIr1B3K61M6m', 'staff', 'STAFF', 'MPR', 'SYS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:50', '2026-02-26 08:02:50', NULL, NULL, NULL, NULL, NULL, NULL),
(143, 'StaffPQP_SYS', 'Staff Product Quality & Purchasing (SYS)', '$2y$12$CO5LChaN1aC7C4.2EfJwOOgpG3WP3uKzagjexQMgTnODt4wOfCkNe', 'staff', 'STAFF', 'PQP', 'SYS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:51', '2026-02-26 08:02:51', NULL, NULL, NULL, NULL, NULL, NULL),
(144, 'StaffSCM_SYS', 'Staff Supply Chain Management (SYS)', '$2y$12$quOpDOMBI4OyTPrWxaTt4eLYaK0A5gFcj1zToQeVmuGmfWiyE91Oa', 'staff', 'STAFF', 'SCM', 'SYS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:51', '2026-02-26 08:02:51', NULL, NULL, NULL, NULL, NULL, NULL),
(145, 'StaffWQS_SYS', 'Staff Warehouse & Quality Stock (SYS)', '$2y$12$ZabCl6OPuIxvFyr9WrHUju8XcxTM1O15E.CYUCYP5mCXn/IeECAnG', 'staff', 'STAFF', 'WQS', 'SYS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:52', '2026-02-26 08:02:52', NULL, NULL, NULL, NULL, NULL, NULL),
(146, 'MgrACT_TGR', 'Manager Accounting/Customs (ACT) (TGR)', '$2y$12$tY5yHctZD/7bi2kpPx7kdOrRVS4Z0NgmoUfCyeJq6uU5FQ4JqAyz6', 'manager', 'MANAGER', 'ACT', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:52', '2026-02-26 08:02:52', NULL, NULL, NULL, NULL, NULL, NULL),
(147, 'StaffACT_TGR', 'Staff Accounting & Tax (TGR)', '$2y$12$SNAfYdq5kzFGqgeN9JtaSuWSDheGVsCYGJhX5Obaib8Ui2OC7lMPi', 'staff', 'STAFF', 'ACT', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:52', '2026-02-26 08:02:52', NULL, NULL, NULL, NULL, NULL, NULL),
(148, 'MgrCRM_TGR', 'Manager Sales/CRM (CRM) (TGR)', '$2y$12$xCkYoa3BBkStZOpYA.PIauK5w2Nb2UhaCK6sOLfpKZpi7oK12/SD6', 'manager', 'MANAGER', 'CRM', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:53', '2026-02-26 08:02:53', NULL, NULL, NULL, NULL, NULL, NULL),
(149, 'StaffCRM_TGR', 'Staff Customer Relationship Management (TGR)', '$2y$12$A9v7jZnEtEv7Bw2ClbPAp.VgGML2/BejXGpx8MuXAXaKrGFsso4Ii', 'staff', 'STAFF', 'CRM', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:53', '2026-03-04 21:19:31', '2026-03-04 21:19:31', NULL, NULL, NULL, NULL, NULL),
(150, 'MgrFIN_TGR', 'Manager Finance (FIN) (TGR)', '$2y$12$JvdgmmWjTeIeLKT54lTurOlBrgTwcpGrU9NZGzStBzdlcAfCrItUG', 'manager', 'MANAGER', 'FIN', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:54', '2026-02-26 08:02:54', NULL, NULL, NULL, NULL, NULL, NULL),
(151, 'StaffFIN_TGR', 'Staff Finance (TGR)', '$2y$12$HNaHenhf4ZGPWnrQxGrZ4OKvh429p6mD2HNgmegERPtdQ1b6sVzFu', 'staff', 'STAFF', 'FIN', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:54', '2026-02-26 08:02:54', NULL, NULL, NULL, NULL, NULL, NULL),
(152, 'MgrHRL_TGR', 'Manager HR/Legal (HRL) (TGR)', '$2y$12$NwCxvBd0H9ST7zMGPU7jdeq6kQIwgtIOd6XyrSahPQD72TsgQ/4UW', 'manager', 'MANAGER', 'HRL', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:54', '2026-02-26 08:02:54', NULL, NULL, NULL, NULL, NULL, NULL),
(153, 'StaffHRL_TGR', 'Staff Human Resource & Legal (TGR)', '$2y$12$.zgzeHUdMTDig7opc9nGburl/HvHKViE4bxp5FOVki.Ye5Vi/n8gm', 'staff', 'STAFF', 'HRL', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:55', '2026-02-26 08:02:55', NULL, NULL, NULL, NULL, NULL, NULL),
(154, 'MgrITC_TGR', 'Manager IT/Communication (ITC) (TGR)', '$2y$12$QV252EkWt7Kcrkp0oM8neO9qvBQk8gwdkCCNqHdhXPXduJ0J7gLSW', 'manager', 'MANAGER', 'ITC', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:55', '2026-02-26 08:02:55', NULL, NULL, NULL, NULL, NULL, NULL),
(155, 'StaffITC_TGR', 'Staff IT & Cloud (TGR)', '$2y$12$CIobKyha4KyZhp69NHsubOm670xy.Rfjdlm5m4/4pENHo8gDBXjDC', 'staff', 'STAFF', 'ITC', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:56', '2026-02-26 08:02:56', NULL, NULL, NULL, NULL, NULL, NULL),
(156, 'MgrMPR_TGR', 'Manager Marketing/PR (MPR) (TGR)', '$2y$12$89S9vvGfZUIMlNUWLq56/e2kqklsCRszqWr.wgDogtxpz/dHNML9y', 'manager', 'MANAGER', 'MPR', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:56', '2026-02-26 08:02:56', NULL, NULL, NULL, NULL, NULL, NULL),
(157, 'StaffMPR_TGR', 'Staff Marketing & Project (TGR)', '$2y$12$qXiy0qRHOcX.NI2kIWYzcu1CFmeY8vy6xlrwXjJg4RoEXzojkFd6W', 'staff', 'STAFF', 'MPR', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:56', '2026-02-26 08:02:56', NULL, NULL, NULL, NULL, NULL, NULL),
(158, 'MgrPQP_TGR', 'Manager Purchasing (PQP) (TGR)', '$2y$12$yCFntxkSfYcf27.38D/ek.IoOpM7.21tbDlppqZc1L9l0Q5oYvdwS', 'manager', 'MANAGER', 'PQP', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:57', '2026-02-26 08:02:57', NULL, NULL, NULL, NULL, NULL, NULL),
(159, 'StaffPQP_TGR', 'Staff Product Quality & Purchasing (TGR)', '$2y$12$HvZeWGzeDpmLER8Xx1uB4u4Nc7BW9mOd8.WnH40ZNmlmqAivVofyi', 'staff', 'STAFF', 'PQP', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:57', '2026-02-26 08:02:57', NULL, NULL, NULL, NULL, NULL, NULL),
(160, 'MgrSCM_TGR', 'Manager Supply Chain (SCM) (TGR)', '$2y$12$uQwL.0.vgSrI7SVs2FjlMOzJE97sdoBq.ggUqg5hk1sC0F3UrKLae', 'manager', 'MANAGER', 'SCM', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:57', '2026-02-26 08:02:57', NULL, NULL, NULL, NULL, NULL, NULL),
(161, 'StaffSCM_TGR', 'Staff Supply Chain Management (TGR)', '$2y$12$zEdCaGWknwp4OLcVQmgC5uQgOk4f0Sl4Aeb5p3.0OK.4pDcZtO9Wa', 'staff', 'STAFF', 'SCM', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:58', '2026-02-26 08:02:58', NULL, NULL, NULL, NULL, NULL, NULL),
(162, 'StaffSYS_TGR', 'Staff System/IT (SYS) (TGR)', '$2y$12$yaOWqW6LhlwVBFc3hEAk.O.9xBdbN46qYTRP5PNTwLxG8V3WwuZKu', 'staff', 'STAFF', 'SYS', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:58', '2026-02-26 08:02:58', NULL, NULL, NULL, NULL, NULL, NULL),
(163, 'MgrWQS_TGR', 'Manager Warehouse (WQS) (TGR)', '$2y$12$6B.qlRaAvQ93yjK9bhU4IeHL2UwdA85iIi.i3xA941XkVl15NVmbW', 'manager', 'MANAGER', 'WQS', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:59', '2026-02-26 08:02:59', NULL, NULL, NULL, NULL, NULL, NULL),
(164, 'StaffWQS_TGR', 'Staff Warehouse & Quality Stock (TGR)', '$2y$12$lvDb7dwx46pa/IWddqarleTopqiohd3eCzgirBNQ81PZ9CaQpVPXy', 'staff', 'STAFF', 'WQS', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-26 08:02:59', '2026-02-26 08:02:59', NULL, NULL, NULL, NULL, NULL, NULL),
(166, 'StaffBRANCH_DEFAULT', 'Staff Branch / Depo Agent (DEFAULT)', '$2y$12$oZBcU19Oxkc89YNLdpf/d.vsbzfL1Yqc15LwxVvMCMWZi.ZLNTP7e', 'staff', 'STAFF', 'BRANCH', 'DEFAULT', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-28 16:32:44', '2026-02-28 16:32:44', NULL, NULL, NULL, NULL, NULL, NULL),
(167, 'StaffBRANCH_BDG', 'Staff Branch / Depo Agent (BDG)', '$2y$12$jTG55x1xcqnLdMlFbQQWmeVN0FoPHwsoBeFbKZc.n6grrZjYwFOBG', 'staff', 'STAFF', 'BRANCH', 'BDG', 'WQS260201', '2026-03-04 23:33:17', 'admin', 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-28 16:32:44', '2026-03-06 23:29:46', '2026-03-06 23:29:46', NULL, NULL, NULL, NULL, NULL),
(168, 'StaffBRANCH_BGR', 'Staff Branch / Depo Agent (BGR)', '$2y$12$SIwM9KkIDs3w0ePOTaFymOLRwyhywpI/xGqucwT0xeRu9JkyuobbW', 'staff', 'STAFF', 'BRANCH', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-28 16:32:45', '2026-03-09 04:50:08', '2026-03-09 04:50:08', NULL, NULL, NULL, NULL, NULL);
INSERT DELAYED IGNORE INTO `master_system_login` (`id`, `username`, `full_name`, `password_hash`, `role`, `level`, `department`, `office_code`, `holder_employee_code`, `holder_assigned_at`, `holder_assigned_by`, `status`, `mfa_enabled`, `mfa_secret`, `mfa_confirmed_at`, `mfa_backup_codes_hash`, `created_at`, `updated_at`, `last_login_at`, `deactivated_at`, `deactivated_by`, `deleted_at`, `deleted_by`, `delete_reason`) VALUES
(169, 'StaffBRANCH_BKS', 'Staff Branch / Depo Agent (BKS)', '$2y$12$AmFCtIVYrDNZeohBxARXwurs9cs57dWnzeQ9zbTRTifEBbKDDfcLO', 'staff', 'STAFF', 'BRANCH', 'BKS', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-28 16:32:45', '2026-02-28 16:32:45', NULL, NULL, NULL, NULL, NULL, NULL),
(170, 'StaffBRANCH_JGY', 'Staff Branch / Depo Agent (JGY)', '$2y$12$G2J2tEWoBd3WIHvm8rdZ0eAuWTZci4MMy.MGZIFE/7HKX/R1WM2Cy', 'staff', 'STAFF', 'BRANCH', 'JGY', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-28 16:32:45', '2026-02-28 16:32:45', NULL, NULL, NULL, NULL, NULL, NULL),
(171, 'StaffBRANCH_KAL', 'Staff Branch / Depo Agent (KAL)', '$2y$12$wQkrIksJSmgvUrosQUevYuzaoYU.vPv.1jy3XS3iM6ZPhUz5dvEQq', 'staff', 'STAFF', 'BRANCH', 'KAL', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-28 16:32:46', '2026-03-08 00:33:27', '2026-03-08 00:33:27', NULL, NULL, NULL, NULL, NULL),
(172, 'StaffBRANCH_SLO', 'Staff Branch / Depo Agent (SLO)', '$2y$12$730MtRHhLmQIjIWK.mQKDe8gu1ijrs553EtIKtBQStd6Ss83tgSJa', 'staff', 'STAFF', 'BRANCH', 'SLO', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-28 16:32:46', '2026-03-06 13:38:08', '2026-03-06 13:38:08', NULL, NULL, NULL, NULL, NULL),
(173, 'StaffBRANCH_SMG', 'Staff Branch / Depo Agent (SMG)', '$2y$12$phawmR/QPbAU6wVWayZSmu8aAlPxRqCw5YAZhKOZRq/cPTaed/VxG', 'staff', 'STAFF', 'BRANCH', 'SMG', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-28 16:32:46', '2026-02-28 16:32:46', NULL, NULL, NULL, NULL, NULL, NULL),
(174, 'StaffBRANCH_TGR', 'Staff Branch / Depo Agent (TGR)', '$2y$12$5Gvbpr3XO3.RQaDPvpcqA.CPdkE6BTfCJaevPWbh5wkJWEI9/363W', 'staff', 'STAFF', 'BRANCH', 'TGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-02-28 16:32:47', '2026-02-28 16:32:47', NULL, NULL, NULL, NULL, NULL, NULL),
(177, 'smoke_admin', 'smoke_admin', '$2y$12$9vmi8XO/06cc7Qntkl2MhOQd9cEKuW9IdhlMUbwaoL53mCehtahzK', 'ADMIN', 'ADMIN', 'ITC', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-04 05:56:26', '2026-03-09 06:38:57', '2026-03-09 06:38:57', NULL, NULL, NULL, NULL, NULL),
(178, 'smoke_staff', 'smoke_staff', '$2y$12$gEBU87c.S8tG6ThVGwOiVujtKeJRHbHNJh6OJt81k9nsLuePheTWK', 'STAFF', 'STAFF', 'ITC', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-04 05:56:27', '2026-03-09 06:38:57', '2026-03-09 06:38:57', NULL, NULL, NULL, NULL, NULL),
(179, 'uat_smoke_user', NULL, '$2y$12$1wGpchuiHpvBKC9JTqGL9ORIHxaliARgUzB7Eooaz8y6.o.ZVy1y2', 'staff', 'STAFF', 'CRM', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-08 22:42:42', '2026-03-08 23:30:33', NULL, NULL, NULL, NULL, NULL, NULL),
(180, 'qa_wqs', 'QA WQS', '$2y$12$u.wJ4jTKOXwYXxR/MsTso.CZ.AU7n/hkRLOHujo5Kpf7JcVCOoA86', 'STAFF', 'STAFF', 'WQS', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-08 23:01:20', '2026-03-08 23:01:20', NULL, NULL, NULL, NULL, NULL, NULL),
(181, 'qa_pqp', 'QA PQP', '$2y$12$u.wJ4jTKOXwYXxR/MsTso.CZ.AU7n/hkRLOHujo5Kpf7JcVCOoA86', 'STAFF', 'STAFF', 'PQP', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-08 23:01:20', '2026-03-08 23:01:20', NULL, NULL, NULL, NULL, NULL, NULL),
(182, 'qa_crm', 'QA CRM', '$2y$12$u.wJ4jTKOXwYXxR/MsTso.CZ.AU7n/hkRLOHujo5Kpf7JcVCOoA86', 'STAFF', 'STAFF', 'CRM', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-08 23:01:20', '2026-03-08 23:01:20', NULL, NULL, NULL, NULL, NULL, NULL),
(183, 'qa_fin', 'QA FIN', '$2y$12$u.wJ4jTKOXwYXxR/MsTso.CZ.AU7n/hkRLOHujo5Kpf7JcVCOoA86', 'STAFF', 'STAFF', 'FIN', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-08 23:01:20', '2026-03-08 23:01:20', NULL, NULL, NULL, NULL, NULL, NULL),
(184, 'qa_act', 'QA ACT', '$2y$12$u.wJ4jTKOXwYXxR/MsTso.CZ.AU7n/hkRLOHujo5Kpf7JcVCOoA86', 'STAFF', 'STAFF', 'ACT', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-08 23:01:20', '2026-03-08 23:01:20', NULL, NULL, NULL, NULL, NULL, NULL),
(185, 'qa_hrl', 'QA HRL', '$2y$12$u.wJ4jTKOXwYXxR/MsTso.CZ.AU7n/hkRLOHujo5Kpf7JcVCOoA86', 'STAFF', 'STAFF', 'HRL', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-08 23:01:20', '2026-03-08 23:01:20', NULL, NULL, NULL, NULL, NULL, NULL),
(186, 'qa_mpr', 'QA MPR', '$2y$12$u.wJ4jTKOXwYXxR/MsTso.CZ.AU7n/hkRLOHujo5Kpf7JcVCOoA86', 'STAFF', 'STAFF', 'MPR', 'BGR', NULL, NULL, NULL, 'ACTIVE', 0, NULL, NULL, NULL, '2026-03-08 23:01:20', '2026-03-08 23:01:20', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `master_system_login_handover`
--

DROP TABLE IF EXISTS `master_system_login_handover`;
CREATE TABLE `master_system_login_handover` (
  `id` bigint(20) NOT NULL,
  `username` varchar(50) NOT NULL,
  `old_holder_employee_code` varchar(50) DEFAULT NULL,
  `new_holder_employee_code` varchar(50) DEFAULT NULL,
  `changed_by` varchar(50) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_system_login_handover`
--

TRUNCATE TABLE `master_system_login_handover`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_tax`
--

DROP TABLE IF EXISTS `master_tax`;
CREATE TABLE `master_tax` (
  `id` int(11) NOT NULL,
  `tax_code` varchar(20) NOT NULL,
  `tax_name` varchar(100) NOT NULL,
  `tax_type` varchar(20) NOT NULL,
  `rate_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `level_type` varchar(20) NOT NULL DEFAULT 'Transaction',
  `office_scope` varchar(50) DEFAULT 'All',
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_tax`
--

TRUNCATE TABLE `master_tax`;
--
-- Dumping data untuk tabel `master_tax`
--

INSERT DELAYED IGNORE INTO `master_tax` (`id`, `tax_code`, `tax_name`, `tax_type`, `rate_percent`, `level_type`, `office_scope`, `description`, `status`, `created_at`, `updated_at`) VALUES
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
-- Struktur dari tabel `master_units`
--

DROP TABLE IF EXISTS `master_units`;
CREATE TABLE `master_units` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `unit_code` varchar(50) NOT NULL,
  `unit_name` varchar(120) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `source_system` varchar(30) DEFAULT NULL,
  `source_key` varchar(190) DEFAULT NULL,
  `migration_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `migrated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `master_units`
--

TRUNCATE TABLE `master_units`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_user`
--

DROP TABLE IF EXISTS `master_user`;
CREATE TABLE `master_user` (
  `id` int(11) NOT NULL,
  `customers_code` varchar(50) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `contact_name` varchar(150) NOT NULL,
  `role_title` varchar(100) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_user`
--

TRUNCATE TABLE `master_user`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `master_vendors`
--

DROP TABLE IF EXISTS `master_vendors`;
CREATE TABLE `master_vendors` (
  `id` int(11) NOT NULL,
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
  `maps_url` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `npwp` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `master_vendors`
--

TRUNCATE TABLE `master_vendors`;
--
-- Dumping data untuk tabel `master_vendors`
--

INSERT DELAYED IGNORE INTO `master_vendors` (`id`, `vendors_code`, `vendors_name`, `vendor_type`, `pic_name`, `pic_position`, `pic_phone`, `pic_email`, `bank_name`, `bank_account_name`, `bank_account_number`, `bank_swift_code`, `bank_iban`, `bank_currency`, `category`, `address`, `maps_url`, `city`, `phone`, `email`, `npwp`, `status`, `created_at`, `updated_at`) VALUES
(2, 'FOR001', 'PT NOATUM LOGISTIC INDONESIA', 'Forwarding', 'MUHAMMAD RICKY', 'MARKETING', '082210017749', 'ricky.kurniawan@noatumlogistics.com', 'MANDIRI', 'NOATUM LOGISTICS INDONESIA', '1270088820202', 'CENAIDJA', '', 'IDR', 'UMUM', 'Gedung Menara Anugrah . Lt 18 Kantor Taman E 3.3 Kawasan Mega Kuningan Jakarta', '', 'JAKARTA PUSAT', '021-57941901', 'ricky.kurniawan@noatumlogistics.com', '0018045336017000', 'active', '2026-03-02 22:00:25', '2026-03-04 11:41:27'),
(7, 'FOR003', 'PT MATS INTERNASIONAL  INDONESIA', 'Forwarding', 'HERI ABDUL RACHMAN', 'HEAD OF BUSINESS UNIT', '081310203474', 'heri@mii.id', 'MANDIRI', 'PT MATS INTERNASIONAL  INDONESIA', '1250012267779', 'CENAIDJA', '', 'IDR', 'LOGISTIC', 'Jln. MH Thamrin No.12 Central Jakarta 10340', '', 'JAKARTA PUSAT', '021-39837188', 'heri@mii.id', '0317560167003000', 'active', '2026-03-04 10:06:15', '2026-03-04 11:40:01'),
(8, 'FOR004', 'PT ZEIST GLOBAL SERVICE', 'Forwarding', 'ZAINAL PUTRA', 'MARKETING', '0895611741909', 'sales02@zeist.co.id', 'MANDIRI', 'PT ZEIST GLOBAL SERVICE', '1190001515004', 'CENAIDJA', '', 'IDR', 'LOGISTIC', 'Gading Lavender Jl.Pegangsaan Dua No.88B RT 004 / RW 003. KEC. KELAPA GADING.', '', 'JAKARTA UTARA', '021-22455468', 'sales02@zeist.co.id', '0536721616043000', 'active', '2026-03-04 10:15:11', '2026-03-04 11:42:09'),
(12, 'FOR005', 'PT GLOBAL LINK EXPRESS', 'Forwarding', 'HERWIN', 'MARKETING', '08138939941', 'pt.globallinkexpress@gmail.com', '', '', '', '', '', 'IDR', 'LOGISTIC', 'GEDUNG NILA KANDI LT IV/2. Jln ROA MALAKA UTARA.', '', 'JAKARTA BARAT', '021-21695069', 'pt.globallinkexpress@gmail.com', '', 'active', '2026-03-04 11:14:08', '2026-03-04 11:14:57'),
(16, 'FOR006', 'PT Forwarding Nusantara', 'Forwarding', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Contoh Raya No.1', NULL, 'Jakarta', '211112222', 'forwarding@example.com', '', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(17, 'VEN001', 'PT Lintas Ekspedisi', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Distribusi No.10', NULL, 'Surabaya', '318887777', 'ekspedisi@example.com', '', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(18, 'VEN002', 'PT Vendor Umum', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'General', 'Jl. Vendor Umum No.5', NULL, 'Bandung', '221234567', 'vendorumum@example.com', '', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(19, 'FOR007', 'PT MATS INTERNASIONAL INDONESIA', 'Forwarding', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. MH Thamrin 12. Central Jakarta', NULL, 'Jakarta Pusat', '081310203474', 'heri@mii.id', '0317560167003000', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(20, 'FOR008', 'PT NOATUM LOGISTICS INDONESIA', 'Forwarding', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Dr Ide Anak Agung Gde Agung Lot 8.6-8.7  Kawasan Mega Kuningan', NULL, 'Jakarta Pusat', '082210017749', 'ricky.kurniawan@noatumlogistics.com', '0018045336017000', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(21, 'FOR009', 'PT GEN LOGISTIK INDONESIA', 'Forwarding', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Raya Lenteng Agung, Tanjung Barat', NULL, 'Jakarta Selatan', '081289943020', 'fajar@genlog.co.id', '0824179485017000', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(22, 'FOR010', 'PT ZEIST GLOBAL SERVICE', 'Forwarding', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Pegangsaan Dua No.88B Kelapa Gading', NULL, 'Jakarta Utara', '0895611741909', 'sales02@zeist.co.id', '0536721616043000', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(23, 'FOR011', 'PT GLOBAL LINK EXPRESS', 'Forwarding', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Roa Malaka Utara No 1-3', NULL, 'Jakarta Barat', '08138939941', 'ptgloballinkexpress@gmail.com', '', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(24, 'VEN003', 'PT EMIRAD INTERNATIONAL LOGISTICS', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Tanah Baru No.78 Depok. Jawa Barat', NULL, 'Depok', '081904092805', 'mktg.assoc@emiradlogistics.co.id', '0025683335015000', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(25, 'VEN004', 'PT AKBAR PUTRA MANDIRI LOGISTICS', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Raya Karadenan No.99 Cibinong', NULL, 'Bogor', '085718293144', 'rudianto@apmlogistics.id', '0015216260201000', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(26, 'VEN005', 'PT MERPATI ALAM SEMESTA KARGO', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Villa Bogor Indah 3 AB 1/12 Kedung Halang', NULL, 'Bogor', '081317147644', 'reza@mas-kargo.co.id', '0019968734015000', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(27, 'VEN006', 'BARAKA EXPRESS', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Raya Jakarta-Bogor no.7-126. Cibinong', NULL, 'Bogor', '085770537397', 'customercare@baraka-express.com', '', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(28, 'VEN007', 'BARAKA EXPRESS', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Alteri Semarang', NULL, 'Semarang', '0895383162130', 'customercare@baraka-express.com', '', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(29, 'VEN008', 'BARAKA EXPRESS', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. Ir Juanda Pucang sawit', NULL, 'Solo', '089699114771', 'customercare@baraka-express.com', '', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(30, 'VEN009', 'J&T CARGO', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. KSR Dadi Kusmayadi No. 26', NULL, 'Bogor', '081287044235', 'jntcargocibinong@gmail.com', '', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27'),
(31, 'VEN010', 'LION PARCEL', 'Lainnya', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'IDR', 'SCM', 'Jl. KSR Dadi Kusmayadi No. 26', NULL, 'Bogor', '081287044235', '', '', 'active', '2026-03-05 09:12:27', '2026-03-05 09:12:27');

-- --------------------------------------------------------

--
-- Struktur dari tabel `migration_errors`
--

DROP TABLE IF EXISTS `migration_errors`;
CREATE TABLE `migration_errors` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `entity` varchar(60) NOT NULL,
  `source_key` varchar(190) DEFAULT NULL,
  `error_message` varchar(1000) NOT NULL,
  `payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `migration_errors`
--

TRUNCATE TABLE `migration_errors`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `migration_runs`
--

DROP TABLE IF EXISTS `migration_runs`;
CREATE TABLE `migration_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `source` varchar(30) NOT NULL,
  `mode` varchar(20) NOT NULL DEFAULT 'cutover',
  `from_date` date DEFAULT NULL,
  `to_date` date DEFAULT NULL,
  `is_dry_run` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'RUNNING',
  `file_hash` varchar(128) DEFAULT NULL,
  `summary_json` longtext DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `finished_at` datetime DEFAULT NULL,
  `executed_by` varchar(120) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `migration_runs`
--

TRUNCATE TABLE `migration_runs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `migration_sales_receipts`
--

DROP TABLE IF EXISTS `migration_sales_receipts`;
CREATE TABLE `migration_sales_receipts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `receipt_no` varchar(120) NOT NULL,
  `receipt_date` date NOT NULL,
  `sales_invoice_no` varchar(120) NOT NULL,
  `customer_code` varchar(80) DEFAULT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `method` varchar(30) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `source_system` varchar(30) NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `migration_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `migrated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `migration_sales_receipts`
--

TRUNCATE TABLE `migration_sales_receipts`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `migration_target_keys`
--

DROP TABLE IF EXISTS `migration_target_keys`;
CREATE TABLE `migration_target_keys` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `target_table` varchar(80) NOT NULL,
  `target_id` bigint(20) DEFAULT NULL,
  `source_system` varchar(30) NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `migration_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `migrated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `migration_target_keys`
--

TRUNCATE TABLE `migration_target_keys`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `mobile_audit_events`
--

DROP TABLE IF EXISTS `mobile_audit_events`;
CREATE TABLE `mobile_audit_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_name` varchar(140) NOT NULL,
  `actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `actor_username` varchar(120) NOT NULL,
  `request_id` varchar(80) DEFAULT NULL,
  `endpoint` varchar(200) DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL,
  `target_type` varchar(80) DEFAULT NULL,
  `target_id` varchar(120) DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `ip_masked` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mobile_audit_events`
--

TRUNCATE TABLE `mobile_audit_events`;
--
-- Dumping data untuk tabel `mobile_audit_events`
--

INSERT DELAYED IGNORE INTO `mobile_audit_events` (`id`, `event_name`, `actor_user_id`, `actor_username`, `request_id`, `endpoint`, `method`, `target_type`, `target_id`, `payload_json`, `ip_masked`, `created_at`) VALUES
(1, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '6ec7e0f2d402b63ee082d8a38597dddd', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 01:16:53'),
(2, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'e3484b1dca33ca7176ada8d449a8fe60', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 01:17:28'),
(3, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '8d3795c2e2189dc5261666ce5d6afa0d', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 01:21:47'),
(4, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '1fcb052b042512f65211e3745d7c4442', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 01:47:59'),
(5, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '96c1d1b6ccb74430f15bb3fbe1a20360', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 01:52:26'),
(6, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '6b156714dc52f97882fabdf1cc5a2e77', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 02:39:54'),
(7, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'a78a6a069509af38f3110c271a7ce9d0', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 02:45:47'),
(8, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '5a89320001ed401a5fd5f231053a05b6', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 02:51:28'),
(9, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'ed6d588bb392f10503170e6f250c89ee', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 02:59:20'),
(10, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'fe9640070e33398d4aa8db3b6b23003a', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 03:09:08'),
(11, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'd065ee5ba3b3bd6900ff242fd9ebf656', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 03:18:50'),
(12, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'baf67f80a312a918b4d4e917b4179860', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 04:40:26'),
(13, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'bb2e768ec153955843cf0124787ad3d0', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 04:41:40'),
(14, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'adb862716e462f76b968f2e9e656deb9', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 04:43:01'),
(15, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '3e43bef60ce54cf9b5b60f6d50f248b1', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 04:44:18'),
(16, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '55465e0dfcbd9485191c3c8a18fb5f1e', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-02 04:51:11'),
(17, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '07f24a4e9cca839b9d4253c3564d4486', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-03 11:10:51'),
(18, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '92b7ef9282770382da0a97545a103535', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-03 11:23:30'),
(19, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'e5d09905b30ca68d124a9be15c3fc0c0', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-03 15:51:33'),
(20, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '3d0356e8218e491560ee0bf6184adef3', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-03 18:51:45'),
(21, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '7594878bfff565d90af262a777a66909', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-03 19:11:37'),
(22, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '8f149e8adbfac4b3cfada6a4fef5209c', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-03 19:13:10'),
(23, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'b920f72922989de7e00469969c494bd5', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-03 19:13:45'),
(24, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '805e9b708e80e1e762a085d4fcafa17b', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-03 21:51:27'),
(25, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'cf10592748adbddbadfd0fb5744a14ab', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-04 05:19:20'),
(26, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'b90a24b09e035b7bb323b93541407b45', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-04 05:20:46'),
(27, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '65ff675e155ed7a5775ef9dfa9c7d858', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-04 05:21:35'),
(28, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'ff841018fc88ba8d67d790abbd607bf3', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-04 05:25:37'),
(29, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '05c607bc423cd9cae6073ea0a838958f', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-04 05:57:06'),
(30, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'ead59a1d1a70ff6a4a6c4135f9bf59bf', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-04 06:06:32'),
(31, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'a6b9e26a5fbd825ad4e28a24a4278e85', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 10:12:01'),
(32, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'bef4cb04090a3590107bce54b646a716', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 10:12:09'),
(33, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'f554933bfecb1055ec7865be72387b6f', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 10:28:06'),
(34, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '195f49ccb3a460d183d005dccc4e90c5', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 10:30:04'),
(35, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '64df5890d2cb655ca6626942940cc742', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 10:30:12'),
(36, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '684da4aea37aa6fd18ac1d9619ffb4f8', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 10:34:10'),
(37, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'c23c6f404cef4c53b6c91ee785306c88', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 10:34:18'),
(38, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '04e55206bc576441110765a636dbb832', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 10:44:28'),
(39, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '9df9f70943516492e2c03a26a692f69e', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 10:44:35'),
(40, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '7e62e9190f51baf95deca8361f281503', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 22:39:13'),
(41, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '06577e57cf21c717f7e682114895a543', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 22:39:29'),
(42, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '97e0db8697552f3e5eeaf5d5f7838ab8', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 23:24:49'),
(43, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', 'ce10e2e6b8ddead5daa2e045673cd0f9', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 23:24:57'),
(44, 'MOBILE.AUTH.LOGIN_FAIL', 0, '__smoke_invalid__', '2f321fd58eb2c35a24d990e8d717c51b', '/ERP_RMI_SOFULL/api/v1/mobile/auth/login.php', 'POST', 'USER', '__smoke_invalid__', '{\"device_id\":\"unknown-device\"}', '10.10.x.x', '2026-03-08 23:34:34');

-- --------------------------------------------------------

--
-- Struktur dari tabel `mobile_device_tokens`
--

DROP TABLE IF EXISTS `mobile_device_tokens`;
CREATE TABLE `mobile_device_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `fcm_token` text NOT NULL,
  `device_id` varchar(120) NOT NULL,
  `platform` varchar(20) NOT NULL DEFAULT 'android',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mobile_device_tokens`
--

TRUNCATE TABLE `mobile_device_tokens`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `mobile_idempotency`
--

DROP TABLE IF EXISTS `mobile_idempotency`;
CREATE TABLE `mobile_idempotency` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `endpoint_key` varchar(180) NOT NULL,
  `idem_key` varchar(120) NOT NULL,
  `request_hash` char(64) NOT NULL,
  `response_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mobile_idempotency`
--

TRUNCATE TABLE `mobile_idempotency`;
--
-- Dumping data untuk tabel `mobile_idempotency`
--

INSERT DELAYED IGNORE INTO `mobile_idempotency` (`id`, `user_id`, `endpoint_key`, `idem_key`, `request_hash`, `response_json`, `created_at`, `updated_at`) VALUES
(1, 0, 'auth/login', 'contract-check-01f9d17515a53fcb', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 01:16:53', '2026-03-02 01:16:53'),
(2, 0, 'auth/login', 'contract-check-fb9b00879c1f2ba6', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 01:17:28', '2026-03-02 01:17:28'),
(3, 0, 'auth/login', 'contract-check-69d7008eac78d554', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 01:21:47', '2026-03-02 01:21:47'),
(4, 0, 'auth/login', 'contract-check-4caa51602d5d73bc', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 01:47:58', '2026-03-02 01:47:58'),
(5, 0, 'auth/login', 'contract-check-73a35f1c2dd9e503', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 01:52:26', '2026-03-02 01:52:26'),
(6, 0, 'auth/login', 'contract-check-ba40db59a63c05f5', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 02:39:54', '2026-03-02 02:39:54'),
(7, 0, 'auth/login', 'contract-check-e302cc2f68fa1bff', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 02:45:47', '2026-03-02 02:45:47'),
(8, 0, 'auth/login', 'contract-check-b4ecd19cd1c75572', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 02:51:27', '2026-03-02 02:51:27'),
(9, 0, 'auth/login', 'contract-check-a6dc663cfdee6b4c', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 02:59:20', '2026-03-02 02:59:20'),
(10, 0, 'auth/login', 'contract-check-d1436258ab24f771', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 03:09:08', '2026-03-02 03:09:08'),
(11, 0, 'auth/login', 'contract-check-9de3345cdb135fd5', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 03:18:50', '2026-03-02 03:18:50'),
(12, 0, 'auth/refresh', 'neg-auth-refresh-idem', '9dc0354adbe4c36b4e55cbef19dc67ea9cb7221fe655f4542b27e6c498c2e5db', NULL, '2026-03-02 04:17:34', '2026-03-02 04:17:34'),
(13, 0, 'auth/login', 'contract-check-ec823bb4ae98c307', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 04:40:26', '2026-03-02 04:40:26'),
(14, 0, 'auth/login', 'contract-check-09dfb0b60e93af70', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 04:41:40', '2026-03-02 04:41:40'),
(15, 0, 'auth/login', 'contract-check-445cc7a153559a67', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 04:43:01', '2026-03-02 04:43:01'),
(16, 0, 'auth/login', 'contract-check-c1465504374b1933', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 04:44:17', '2026-03-02 04:44:17'),
(17, 0, 'auth/login', 'contract-check-e2b3db3359b96500', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-02 04:51:11', '2026-03-02 04:51:11'),
(18, 0, 'auth/login', 'contract-check-1b711251bff6c7da', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-03 11:10:50', '2026-03-03 11:10:50'),
(19, 0, 'auth/login', 'contract-check-bc4e334d8ffe2ff0', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-03 11:23:30', '2026-03-03 11:23:30'),
(20, 0, 'auth/login', 'contract-check-c2e5c4f58f246f62', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-03 15:51:33', '2026-03-03 15:51:33'),
(21, 0, 'auth/login', 'contract-check-227017f9bd9566b7', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-03 18:51:44', '2026-03-03 18:51:44'),
(22, 0, 'auth/login', 'contract-check-90040d4309907647', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-03 19:11:37', '2026-03-03 19:11:37'),
(23, 0, 'auth/login', 'contract-check-d483824a2cf2c24c', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-03 19:13:10', '2026-03-03 19:13:10'),
(24, 0, 'auth/login', 'contract-check-f2cd795ff8822b72', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-03 19:13:45', '2026-03-03 19:13:45'),
(25, 0, 'auth/login', 'contract-check-2ae86897bbd01234', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-03 21:51:27', '2026-03-03 21:51:27'),
(26, 0, 'auth/login', 'contract-check-9faf49c805a55027', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-04 05:19:20', '2026-03-04 05:19:20'),
(27, 0, 'auth/login', 'contract-check-6bd1557791ea3f9a', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-04 05:20:46', '2026-03-04 05:20:46'),
(28, 0, 'auth/login', 'contract-check-3e125568556cf8b9', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-04 05:21:35', '2026-03-04 05:21:35'),
(29, 0, 'auth/login', 'contract-check-76d211098b3a79bb', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-04 05:25:37', '2026-03-04 05:25:37'),
(30, 0, 'auth/login', 'contract-check-7ba2cb5160bc94e0', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-04 05:57:06', '2026-03-04 05:57:06'),
(31, 0, 'auth/login', 'contract-check-32fb025752a4ae3b', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-04 06:06:32', '2026-03-04 06:06:32'),
(32, 0, 'auth/login', 'contract-check-ac289d6a2a32db8a', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 10:12:01', '2026-03-08 10:12:01'),
(33, 0, 'auth/login', 'contract-check-d5e75df52ca55cf3', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 10:12:09', '2026-03-08 10:12:09'),
(34, 0, 'auth/login', 'contract-check-4932ea858b219c92', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 10:28:06', '2026-03-08 10:28:06'),
(35, 0, 'auth/login', 'contract-check-0a1d8dcefa2450d1', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 10:30:04', '2026-03-08 10:30:04'),
(36, 0, 'auth/login', 'contract-check-8195793815812541', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 10:30:12', '2026-03-08 10:30:12'),
(37, 0, 'auth/login', 'contract-check-62ff58b8ad1052ad', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 10:34:10', '2026-03-08 10:34:10'),
(38, 0, 'auth/login', 'contract-check-ff953499cd1d82d0', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 10:34:18', '2026-03-08 10:34:18'),
(39, 0, 'auth/login', 'contract-check-80d8216af0e1a4c9', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 10:44:28', '2026-03-08 10:44:28'),
(40, 0, 'auth/login', 'contract-check-bac85438f85a2ec2', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 10:44:35', '2026-03-08 10:44:35'),
(41, 0, 'auth/login', 'contract-check-ad69f60bd07f3457', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 22:39:13', '2026-03-08 22:39:13'),
(42, 0, 'auth/login', 'contract-check-bd0d84123e20c05f', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 22:39:29', '2026-03-08 22:39:29'),
(43, 0, 'auth/login', 'contract-check-5d817c23925914b6', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 23:24:49', '2026-03-08 23:24:49'),
(44, 0, 'auth/login', 'contract-check-58963cc184f4f271', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 23:24:57', '2026-03-08 23:24:57'),
(45, 0, 'auth/login', 'contract-check-df0f0319339ceef7', 'fa824ea32a1b1d8e72bd441b233de827ba2268e71a8be7e85de2ee149193c395', NULL, '2026-03-08 23:34:34', '2026-03-08 23:34:34');

-- --------------------------------------------------------

--
-- Struktur dari tabel `mobile_notifications`
--

DROP TABLE IF EXISTS `mobile_notifications`;
CREATE TABLE `mobile_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `notif_code` varchar(80) NOT NULL,
  `title` varchar(180) NOT NULL,
  `body` text DEFAULT NULL,
  `payload_json` longtext DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mobile_notifications`
--

TRUNCATE TABLE `mobile_notifications`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `mobile_refresh_tokens`
--

DROP TABLE IF EXISTS `mobile_refresh_tokens`;
CREATE TABLE `mobile_refresh_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `device_id` varchar(120) DEFAULT NULL,
  `ip_masked` varchar(80) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mobile_refresh_tokens`
--

TRUNCATE TABLE `mobile_refresh_tokens`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `mpr_budget_requests`
--

DROP TABLE IF EXISTS `mpr_budget_requests`;
CREATE TABLE `mpr_budget_requests` (
  `id` int(11) NOT NULL,
  `request_code` varchar(40) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `request_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `purpose` text DEFAULT NULL,
  `vendor_name` varchar(255) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `dept_code` varchar(20) NOT NULL DEFAULT 'MPR',
  `office_code` varchar(20) NOT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `approval_note` text DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mpr_budget_requests`
--

TRUNCATE TABLE `mpr_budget_requests`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `mpr_ops_payments`
--

DROP TABLE IF EXISTS `mpr_ops_payments`;
CREATE TABLE `mpr_ops_payments` (
  `id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `employee_code` varchar(50) NOT NULL,
  `office_code` varchar(20) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'PAID',
  `paid_amount` decimal(18,2) DEFAULT NULL,
  `paid_ref` varchar(100) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `paid_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mpr_ops_payments`
--

TRUNCATE TABLE `mpr_ops_payments`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `mpr_plans`
--

DROP TABLE IF EXISTS `mpr_plans`;
CREATE TABLE `mpr_plans` (
  `id` int(11) NOT NULL,
  `plan_code` varchar(40) NOT NULL,
  `title` varchar(255) NOT NULL,
  `objective` text DEFAULT NULL,
  `target` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `budget` decimal(18,2) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `dept_code` varchar(20) NOT NULL DEFAULT 'MPR',
  `office_code` varchar(20) NOT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `approval_note` text DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mpr_plans`
--

TRUNCATE TABLE `mpr_plans`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `mpr_progress`
--

DROP TABLE IF EXISTS `mpr_progress`;
CREATE TABLE `mpr_progress` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `progress_date` date NOT NULL,
  `progress_pct` int(11) DEFAULT NULL,
  `milestone` varchar(255) DEFAULT NULL,
  `issues` text DEFAULT NULL,
  `next_step` text DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mpr_progress`
--

TRUNCATE TABLE `mpr_progress`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `mpr_visits`
--

DROP TABLE IF EXISTS `mpr_visits`;
CREATE TABLE `mpr_visits` (
  `id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `visit_date` date NOT NULL,
  `partner_name` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `result` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `gps_accuracy_m` int(11) DEFAULT NULL,
  `gps_captured_at` datetime DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customers_code` varchar(50) DEFAULT NULL,
  `customer_name` varchar(255) DEFAULT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `contact_name` varchar(150) DEFAULT NULL,
  `contact_role_title` varchar(100) DEFAULT NULL,
  `contact_department` varchar(100) DEFAULT NULL,
  `employee_code` varchar(50) DEFAULT NULL,
  `employee_name` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `mpr_visits`
--

TRUNCATE TABLE `mpr_visits`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `office_warehouses`
--

DROP TABLE IF EXISTS `office_warehouses`;
CREATE TABLE `office_warehouses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `office_id` int(11) NOT NULL,
  `warehouse_id` varchar(80) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `office_warehouses`
--

TRUNCATE TABLE `office_warehouses`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `payments_webhook_inbox`
--

DROP TABLE IF EXISTS `payments_webhook_inbox`;
CREATE TABLE `payments_webhook_inbox` (
  `id` int(10) UNSIGNED NOT NULL,
  `source` varchar(60) NOT NULL DEFAULT 'unknown',
  `payload_json` longtext NOT NULL,
  `processed_at` datetime DEFAULT NULL,
  `processed_by` varchar(80) DEFAULT NULL,
  `error_msg` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `payments_webhook_inbox`
--

TRUNCATE TABLE `payments_webhook_inbox`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `payment_callbacks_inbox`
--

DROP TABLE IF EXISTS `payment_callbacks_inbox`;
CREATE TABLE `payment_callbacks_inbox` (
  `id` int(11) NOT NULL,
  `source` varchar(60) NOT NULL DEFAULT 'unknown',
  `payload_json` longtext NOT NULL,
  `processed_at` datetime DEFAULT NULL,
  `processed_by` varchar(80) DEFAULT NULL,
  `error_msg` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `payment_callbacks_inbox`
--

TRUNCATE TABLE `payment_callbacks_inbox`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_employee_settings`
--

DROP TABLE IF EXISTS `payroll_employee_settings`;
CREATE TABLE `payroll_employee_settings` (
  `id` bigint(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `login_user_id` int(11) DEFAULT NULL,
  `pay_type` varchar(20) NOT NULL DEFAULT 'MONTHLY',
  `salary_basic` decimal(18,2) NOT NULL DEFAULT 0.00,
  `op_rate_day` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_position` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_child` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_transport` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_quota` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_fixed` decimal(18,2) NOT NULL DEFAULT 0.00,
  `deduction_fixed` decimal(18,2) NOT NULL DEFAULT 0.00,
  `overtime_rate_per_hour` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `payroll_employee_settings`
--

TRUNCATE TABLE `payroll_employee_settings`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_loans`
--

DROP TABLE IF EXISTS `payroll_loans`;
CREATE TABLE `payroll_loans` (
  `id` bigint(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `loan_type` varchar(20) NOT NULL DEFAULT 'LOAN',
  `principal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tenor_months` int(11) NOT NULL DEFAULT 1,
  `installment_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `start_period_ym` varchar(7) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ACTIVE',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `payroll_loans`
--

TRUNCATE TABLE `payroll_loans`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_runs`
--

DROP TABLE IF EXISTS `payroll_runs`;
CREATE TABLE `payroll_runs` (
  `id` bigint(20) NOT NULL,
  `period_ym` varchar(7) NOT NULL,
  `office_code` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `posted_by` int(11) DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `payroll_runs`
--

TRUNCATE TABLE `payroll_runs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_run_items`
--

DROP TABLE IF EXISTS `payroll_run_items`;
CREATE TABLE `payroll_run_items` (
  `id` bigint(20) NOT NULL,
  `run_id` bigint(20) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `login_user_id` int(11) DEFAULT NULL,
  `pay_type` varchar(20) NOT NULL DEFAULT 'MONTHLY',
  `matrix_year` int(11) DEFAULT NULL,
  `matrix_status` varchar(20) DEFAULT NULL,
  `matrix_level` varchar(5) DEFAULT NULL,
  `matrix_take_home` decimal(18,2) NOT NULL DEFAULT 0.00,
  `work_days` int(11) NOT NULL DEFAULT 0,
  `days_present` int(11) NOT NULL DEFAULT 0,
  `leave_days` int(11) NOT NULL DEFAULT 0,
  `absent_days` int(11) NOT NULL DEFAULT 0,
  `salary_basic` decimal(18,2) NOT NULL DEFAULT 0.00,
  `base_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `op_rate_day` decimal(18,2) NOT NULL DEFAULT 0.00,
  `op_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_position` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_child` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_transport` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_quota` decimal(18,2) NOT NULL DEFAULT 0.00,
  `allowance_fixed` decimal(18,2) NOT NULL DEFAULT 0.00,
  `deduction_fixed` decimal(18,2) NOT NULL DEFAULT 0.00,
  `overtime_rate_per_hour` decimal(18,2) NOT NULL DEFAULT 0.00,
  `overtime_hours` decimal(18,2) NOT NULL DEFAULT 0.00,
  `overtime_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `other_allowance` decimal(18,2) NOT NULL DEFAULT 0.00,
  `other_deduction` decimal(18,2) NOT NULL DEFAULT 0.00,
  `absence_deduction` decimal(18,2) NOT NULL DEFAULT 0.00,
  `kasbon_deduction` decimal(18,2) NOT NULL DEFAULT 0.00,
  `loan_deduction` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_pph21` decimal(18,2) NOT NULL DEFAULT 0.00,
  `bpjs_tk` decimal(18,2) NOT NULL DEFAULT 0.00,
  `bpjs_kes` decimal(18,2) NOT NULL DEFAULT 0.00,
  `gross_pay` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_deduction` decimal(18,2) NOT NULL DEFAULT 0.00,
  `net_pay` decimal(18,2) NOT NULL DEFAULT 0.00,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `payroll_run_items`
--

TRUNCATE TABLE `payroll_run_items`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `payroll_salary_matrix`
--

DROP TABLE IF EXISTS `payroll_salary_matrix`;
CREATE TABLE `payroll_salary_matrix` (
  `id` bigint(20) NOT NULL,
  `matrix_year` int(11) NOT NULL,
  `payroll_status` varchar(20) NOT NULL,
  `payroll_level` varchar(5) NOT NULL,
  `job_title` varchar(50) DEFAULT NULL,
  `take_home_pay` decimal(18,2) NOT NULL DEFAULT 0.00,
  `basic_salary` decimal(18,2) NOT NULL DEFAULT 0.00,
  `op_rate_day` decimal(18,2) NOT NULL DEFAULT 0.00,
  `work_days_default` int(11) NOT NULL DEFAULT 21,
  `tunj_jabatan` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tunj_anak` decimal(18,2) NOT NULL DEFAULT 0.00,
  `transport` decimal(18,2) NOT NULL DEFAULT 0.00,
  `kuota` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `payroll_salary_matrix`
--

TRUNCATE TABLE `payroll_salary_matrix`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `procurement_match_rules`
--

DROP TABLE IF EXISTS `procurement_match_rules`;
CREATE TABLE `procurement_match_rules` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `rule_name` varchar(80) NOT NULL,
  `qty_tolerance_pct` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `price_tolerance_pct` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `procurement_match_rules`
--

TRUNCATE TABLE `procurement_match_rules`;
--
-- Dumping data untuk tabel `procurement_match_rules`
--

INSERT DELAYED IGNORE INTO `procurement_match_rules` (`id`, `rule_name`, `qty_tolerance_pct`, `price_tolerance_pct`, `is_active`, `created_at`) VALUES
(1, 'DEFAULT', 0.0000, 0.0000, 1, '2026-02-25 22:07:27');

-- --------------------------------------------------------

--
-- Struktur dari tabel `products_media`
--

DROP TABLE IF EXISTS `products_media`;
CREATE TABLE `products_media` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `media_type` enum('image','video') NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `products_media`
--

TRUNCATE TABLE `products_media`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_audit_log`
--

DROP TABLE IF EXISTS `purchases_audit_log`;
CREATE TABLE `purchases_audit_log` (
  `id` int(11) NOT NULL,
  `module` varchar(20) NOT NULL,
  `ref_code` varchar(80) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `details_json` longtext DEFAULT NULL,
  `user_name` varchar(120) DEFAULT NULL,
  `user_role` varchar(50) DEFAULT NULL,
  `user_level` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_audit_log`
--

TRUNCATE TABLE `purchases_audit_log`;
--
-- Dumping data untuk tabel `purchases_audit_log`
--

INSERT DELAYED IGNORE INTO `purchases_audit_log` (`id`, `module`, `ref_code`, `action`, `details_json`, `user_name`, `user_role`, `user_level`, `created_at`) VALUES
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

DROP TABLE IF EXISTS `purchases_ceisa_payment`;
CREATE TABLE `purchases_ceisa_payment` (
  `id` int(11) NOT NULL,
  `pay_code` varchar(60) NOT NULL,
  `pib_id` int(11) NOT NULL,
  `pay_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `method` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_ceisa_payment`
--

TRUNCATE TABLE `purchases_ceisa_payment`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_ceisa_pib`
--

DROP TABLE IF EXISTS `purchases_ceisa_pib`;
CREATE TABLE `purchases_ceisa_pib` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `ceisa_status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `submitted_date` date DEFAULT NULL,
  `reject_count` int(11) NOT NULL DEFAULT 0,
  `reject_reason` text DEFAULT NULL,
  `bc11_no` varchar(80) DEFAULT NULL,
  `bc11_date` date DEFAULT NULL,
  `noa_no` varchar(80) DEFAULT NULL,
  `noa_date` date DEFAULT NULL,
  `billing_aju_no` varchar(80) DEFAULT NULL,
  `billing_aju_date` date DEFAULT NULL,
  `billing_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `billing_currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `sppb_no` varchar(80) DEFAULT NULL,
  `sppb_date` date DEFAULT NULL,
  `final_pib_no` varchar(80) DEFAULT NULL,
  `final_pib_date` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_ceisa_pib`
--

TRUNCATE TABLE `purchases_ceisa_pib`;
--
-- Dumping data untuk tabel `purchases_ceisa_pib`
--

INSERT DELAYED IGNORE INTO `purchases_ceisa_pib` (`id`, `po_id`, `ceisa_status`, `submitted_date`, `reject_count`, `reject_reason`, `bc11_no`, `bc11_date`, `noa_no`, `noa_date`, `billing_aju_no`, `billing_aju_date`, `billing_amount`, `billing_currency`, `sppb_no`, `sppb_date`, `final_pib_no`, `final_pib_date`, `note`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, 'DRAFT', NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0.00, 'IDR', NULL, NULL, NULL, NULL, NULL, 'admin', '2026-01-02 01:03:36', '2026-01-02 01:03:36');

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_forwarder_invoice`
--

DROP TABLE IF EXISTS `purchases_forwarder_invoice`;
CREATE TABLE `purchases_forwarder_invoice` (
  `id` int(11) NOT NULL,
  `fap_code` varchar(60) NOT NULL,
  `invoice_type` varchar(30) NOT NULL DEFAULT 'FORWARDER',
  `invoice_number` varchar(80) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `po_id` int(11) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'UNPAID',
  `note` text DEFAULT NULL,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_forwarder_invoice`
--

TRUNCATE TABLE `purchases_forwarder_invoice`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_forwarder_payment`
--

DROP TABLE IF EXISTS `purchases_forwarder_payment`;
CREATE TABLE `purchases_forwarder_payment` (
  `id` int(11) NOT NULL,
  `pay_code` varchar(60) NOT NULL,
  `fap_id` int(11) NOT NULL,
  `pay_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `method` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_forwarder_payment`
--

TRUNCATE TABLE `purchases_forwarder_payment`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_forwarder_quotes`
--

DROP TABLE IF EXISTS `purchases_forwarder_quotes`;
CREATE TABLE `purchases_forwarder_quotes` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `quote_date` date NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `total_cost` decimal(18,2) NOT NULL DEFAULT 0.00,
  `leadtime_days` int(11) NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_forwarder_quotes`
--

TRUNCATE TABLE `purchases_forwarder_quotes`;
--
-- Dumping data untuk tabel `purchases_forwarder_quotes`
--

INSERT DELAYED IGNORE INTO `purchases_forwarder_quotes` (`id`, `po_id`, `vendor_id`, `quote_date`, `currency`, `total_cost`, `leadtime_days`, `note`, `status`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 1, '2026-01-01', 'IDR', 100000000.00, 20, '', 'SELECTED', 'admin', '2026-01-02 01:53:51', '2026-01-02 01:53:56', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_forwarding_docs`
--

DROP TABLE IF EXISTS `purchases_forwarding_docs`;
CREATE TABLE `purchases_forwarding_docs` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `doc_type` varchar(30) NOT NULL,
  `doc_number` varchar(80) DEFAULT NULL,
  `doc_date` date DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_forwarding_docs`
--

TRUNCATE TABLE `purchases_forwarding_docs`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_import_control`
--

DROP TABLE IF EXISTS `purchases_import_control`;
CREATE TABLE `purchases_import_control` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `production_start_date` date DEFAULT NULL,
  `production_done_date` date DEFAULT NULL,
  `pickup_date` date DEFAULT NULL,
  `etd` date DEFAULT NULL,
  `eta` date DEFAULT NULL,
  `arrived_id_date` date DEFAULT NULL,
  `arrived_warehouse_date` date DEFAULT NULL,
  `note` text DEFAULT NULL,
  `updated_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_import_control`
--

TRUNCATE TABLE `purchases_import_control`;
--
-- Dumping data untuk tabel `purchases_import_control`
--

INSERT DELAYED IGNORE INTO `purchases_import_control` (`id`, `po_id`, `production_start_date`, `production_done_date`, `pickup_date`, `etd`, `eta`, `arrived_id_date`, `arrived_warehouse_date`, `note`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, '2026-02-02', NULL, NULL, NULL, NULL, NULL, '', 'admin', '2026-01-02 01:03:30', '2026-01-02 01:06:26');

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_invoice_ap`
--

DROP TABLE IF EXISTS `purchases_invoice_ap`;
CREATE TABLE `purchases_invoice_ap` (
  `id` int(11) NOT NULL,
  `ap_code` varchar(60) NOT NULL,
  `invoice_type` varchar(30) NOT NULL DEFAULT 'PROFORMA',
  `invoice_number` varchar(80) DEFAULT NULL,
  `invoice_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `manufacture_id` int(11) DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `po_id` int(11) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_percent` decimal(6,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(30) NOT NULL DEFAULT 'UNPAID',
  `note` text DEFAULT NULL,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_invoice_ap`
--

TRUNCATE TABLE `purchases_invoice_ap`;
--
-- Dumping data untuk tabel `purchases_invoice_ap`
--

INSERT DELAYED IGNORE INTO `purchases_invoice_ap` (`id`, `ap_code`, `invoice_type`, `invoice_number`, `invoice_date`, `due_date`, `manufacture_id`, `office_code`, `po_id`, `currency`, `subtotal`, `tax_percent`, `tax_amount`, `total_amount`, `status`, `note`, `doc_path`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'RMI-AP-BGR-260101-001', 'PROFORMA', 'Yaxin', '2026-01-01', NULL, 1, 'BGR', 1, 'CNY', 30300.00, 0.00, 0.00, 30300.00, 'UNPAID', '', NULL, 'admin', '2026-01-02 01:08:13', '2026-01-02 07:54:27', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_invoice_ap_lines`
--

DROP TABLE IF EXISTS `purchases_invoice_ap_lines`;
CREATE TABLE `purchases_invoice_ap_lines` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `ap_id` int(11) NOT NULL,
  `po_item_id` int(11) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_invoice_ap_lines`
--

TRUNCATE TABLE `purchases_invoice_ap_lines`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_payment_ap`
--

DROP TABLE IF EXISTS `purchases_payment_ap`;
CREATE TABLE `purchases_payment_ap` (
  `id` int(11) NOT NULL,
  `pay_code` varchar(60) NOT NULL,
  `ap_id` int(11) NOT NULL,
  `pay_date` date NOT NULL,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `method` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `doc_path` varchar(255) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_payment_ap`
--

TRUNCATE TABLE `purchases_payment_ap`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_po`
--

DROP TABLE IF EXISTS `purchases_po`;
CREATE TABLE `purchases_po` (
  `id` int(11) NOT NULL,
  `po_code` varchar(60) NOT NULL,
  `po_date` date NOT NULL,
  `pr_id` int(11) DEFAULT NULL,
  `manufacture_id` int(11) DEFAULT NULL,
  `factory_forwarding_info` text DEFAULT NULL,
  `forwarder_vendor_id` int(11) DEFAULT NULL,
  `forwarder_status` varchar(30) NOT NULL DEFAULT 'PENDING',
  `forwarder_note` text DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `payment_term` varchar(30) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `total_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_po`
--

TRUNCATE TABLE `purchases_po`;
--
-- Dumping data untuk tabel `purchases_po`
--

INSERT DELAYED IGNORE INTO `purchases_po` (`id`, `po_code`, `po_date`, `pr_id`, `manufacture_id`, `factory_forwarding_info`, `forwarder_vendor_id`, `forwarder_status`, `forwarder_note`, `office_code`, `currency`, `payment_term`, `note`, `status`, `total_amount`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'RMI-PO-BGR-260101-001', '2026-01-01', 1, 1, NULL, 1, 'IN_PROGRESS', NULL, 'BGR', 'CNY', '', '', 'IN_PRODUCTION', 101000.00, 'admin', '2026-01-02 01:02:50', '2026-01-02 07:15:49', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchases_po_items`
--

DROP TABLE IF EXISTS `purchases_po_items`;
CREATE TABLE `purchases_po_items` (
  `id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `line_no` int(11) NOT NULL DEFAULT 1,
  `product_id` int(11) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `products_name` varchar(255) DEFAULT NULL,
  `qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(30) DEFAULT NULL,
  `unit_price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(18,2) NOT NULL DEFAULT 0.00,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `purchases_po_items`
--

TRUNCATE TABLE `purchases_po_items`;
--
-- Dumping data untuk tabel `purchases_po_items`
--

INSERT DELAYED IGNORE INTO `purchases_po_items` (`id`, `po_id`, `line_no`, `product_id`, `sku`, `products_name`, `qty`, `unit`, `unit_price`, `subtotal`, `deleted_at`, `created_at`) VALUES
(1, 1, 1, 3, 'ALK-001', 'Alkes A', 10.00, 'unit', 10000.00, 100000.00, NULL, '2026-01-02 01:02:50'),
(2, 1, 2, 1, 'OBT-001', 'Obat A', 10.00, 'unit', 100.00, 1000.00, NULL, '2026-01-02 01:02:50');

-- --------------------------------------------------------

--
-- Struktur dari tabel `rbac_dept_role_permissions`
--

DROP TABLE IF EXISTS `rbac_dept_role_permissions`;
CREATE TABLE `rbac_dept_role_permissions` (
  `dept_code` varchar(40) NOT NULL,
  `role_code` varchar(40) NOT NULL,
  `perm_code` varchar(80) NOT NULL,
  `allow_flag` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `rbac_dept_role_permissions`
--

TRUNCATE TABLE `rbac_dept_role_permissions`;
--
-- Dumping data untuk tabel `rbac_dept_role_permissions`
--

INSERT DELAYED IGNORE INTO `rbac_dept_role_permissions` (`dept_code`, `role_code`, `perm_code`, `allow_flag`, `created_at`) VALUES
('ACT', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'ACT.CEISA.AUDIT_NOTE', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'ACT.CEISA.CREATE', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'ACT.CEISA.RESUBMIT', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'ACT.CEISA.SUBMIT', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'ACT.CEISA.UPDATE_STATUS', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'ACT.CEISA.VIEW', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'ACT.GL.POST', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'ACT.PERIOD.CLOSE', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'ACT.REVERSAL.CREATE', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('ACT', 'MANAGER', 'DASHBOARD.ACT_VIEW', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'DOC.DOWNLOAD', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'DOC.UPLOAD', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'FIXED_ASSET.ASSET_CRUD', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'FIXED_ASSET.ASSET_DELETE', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'FIXED_ASSET.ASSET_EDIT', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'FIXED_ASSET.AUDIT_VIEW', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'FIXED_ASSET.DEPRECIATION_RUN', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'FIXED_ASSET.OPERATIONS', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'FIXED_ASSET.TAX_ANNUAL', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'FIXED_ASSET.VIEW', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('ACT', 'MANAGER', 'MASTER.TAX_EDIT', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'SALES.AUDIT_VIEW', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'SALES.EXPORT', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'SALES.KPI_VIEW', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'SALES.TASK_ACT', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'SALES.VIEW', 1, '2026-03-04 16:43:05'),
('ACT', 'MANAGER', 'SYS.AUDIT.VIEW', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'WF.OUTBOX.VIEW', 1, '2026-03-08 16:09:17'),
('ACT', 'MANAGER', 'WF.TASK.VIEW', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:53:27'),
('ACT', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:53:27'),
('ACT', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:53:27'),
('ACT', 'STAFF', 'ACT.CEISA.AUDIT_NOTE', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'ACT.CEISA.CREATE', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'ACT.CEISA.RESUBMIT', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'ACT.CEISA.SUBMIT', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'ACT.CEISA.UPDATE_STATUS', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'ACT.CEISA.VIEW', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('ACT', 'STAFF', 'DASHBOARD.ACT_VIEW', 1, '2026-03-04 16:53:27'),
('ACT', 'STAFF', 'DOC.DOWNLOAD', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'DOC.UPLOAD', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('ACT', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:53:27'),
('ACT', 'STAFF', 'SALES.TASK_ACT', 1, '2026-03-04 16:53:27'),
('ACT', 'STAFF', 'SALES.VIEW', 1, '2026-03-04 16:53:27'),
('ACT', 'STAFF', 'WF.OUTBOX.VIEW', 1, '2026-03-08 16:09:17'),
('ACT', 'STAFF', 'WF.TASK.VIEW', 1, '2026-03-08 16:09:17'),
('BRANCH', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:37'),
('BRANCH', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:37'),
('BRANCH', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:37'),
('BRANCH', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:37'),
('BRANCH', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'API.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'CHAT.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.ACT_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.BRANCH_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.DETAIL_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.FINANCE_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.HRL_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.ITC_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.OWNER_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.PROCUREMENT_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.QUALITY_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.REGULATORY_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.SALES_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.SCM_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DASHBOARD.WAREHOUSE_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'DOCS.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'FIXED_ASSET.AUDIT_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'FIXED_ASSET.DISPOSAL_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'FIXED_ASSET.OPS_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'FIXED_ASSET.TAX_ANNUAL_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'FIXED_ASSET.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'HRL_COMPLIANCE_EXPORT.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'HRL_REG_ALKES.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'HRL.DOC_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'HRL.PROCESS_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'HRL.REG_ALKES_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'HRL.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'KPI.DO_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'KPI.EMPLOYEE_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'KPI.OFFICE_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'KPI.PURCHASES_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'KPI.STOCK_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'KPI.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'MASTER.VENDOR_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'MASTER.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'MPR.PLAN_CREATE', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'MPR.PLAN_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'MPR.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'PAYROLL.PAYSLIP_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'PAYROLL.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'PQP.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'PURCHASES.CEISA_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'PURCHASES.PAYMENT_AP_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'PURCHASES.REPORTS_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'PURCHASES.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'RBAC.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'SALES.AUDIT_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'SALES.KPI_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'SALES.TRACKING_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'SALES.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'STOCK.AUDIT_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'STOCK.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'SYSTEM.SECURITY_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'SYSTEM.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'TOOLS.COMPLIANCE_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'TOOLS.CONTRACT_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'TOOLS.DR_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'TOOLS.OPS_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'TOOLS.RELEASE_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'TOOLS.RESTORE_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'TOOLS.RFC_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'TOOLS.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'WEB_ADMIN.OPS_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'WEB_ADMIN.RFC_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'WEB_ADMIN.VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'WQS.INCOMING_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'WQS.PICKING_VIEW', 1, '2026-03-08 10:05:06'),
('BRANCH', 'STAFF', 'WQS.VIEW', 1, '2026-03-08 10:05:06'),
('CRM', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('CRM', 'MANAGER', 'DASHBOARD.SALES_VIEW', 1, '2026-03-04 16:14:39'),
('CRM', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('CRM', 'MANAGER', 'MASTER.CUSTOMER_CRUD', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'MASTER.CUSTOMER_EXPORT', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'MASTER.IMPORT_CUSTOMERS', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'MASTER.PIC_CUSTOMER_CRUD', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'MASTER.PRICELIST_SELL_CRUD', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'SALES.AUDIT_VIEW', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'SALES.CREATE', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'SALES.DELETE', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'SALES.EDIT', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'SALES.EXPORT', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'SALES.KPI_VIEW', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'SALES.PRINT', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'SALES.VIEW', 1, '2026-03-04 16:14:38'),
('CRM', 'MANAGER', 'STOCK.VIEW', 1, '2026-03-04 16:14:38'),
('CRM', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('CRM', 'STAFF', 'DASHBOARD.SALES_VIEW', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('CRM', 'STAFF', 'MASTER.CUSTOMER_CRUD', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'MASTER.CUSTOMER_EXPORT', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'MASTER.PIC_CUSTOMER_CRUD', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'MASTER.PRICELIST_SELL_CRUD', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'SALES.CREATE', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'SALES.EDIT', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'SALES.PRINT', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'SALES.VIEW', 1, '2026-03-04 16:14:39'),
('CRM', 'STAFF', 'STOCK.VIEW', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('FIN', 'MANAGER', 'DASHBOARD.FINANCE_DETAIL', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'DASHBOARD.FINANCE_VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'DASHBOARD.OWNER_SUMMARY', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'DASHBOARD.OWNER_VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'DOC.DOWNLOAD', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'DOC.UPLOAD', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.AP.INVOICE.CREATE', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.AP.INVOICE.EDIT', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.AP.INVOICE.VIEW', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.AP.PAYMENT.APPROVE', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.AP.PAYMENT.DRAFT', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.AP.PAYMENT.VIEW', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.FORWARDER.INVOICE.CREATE', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.FORWARDER.PAYMENT.APPROVE', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.FORWARDER.PAYMENT.DRAFT', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIN.PIB.PAY', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'FIXED_ASSET.ASSET_CRUD', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'FIXED_ASSET.AUDIT_VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'FIXED_ASSET.DEPRECIATION_RUN', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'FIXED_ASSET.OPERATIONS', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'FIXED_ASSET.TAX_ANNUAL', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'FIXED_ASSET.VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('FIN', 'MANAGER', 'MASTER.COMPANY_BANK_CRUD', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'MASTER.PAYMENT_TERMS_CRUD', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'MASTER.TAX_CRUD', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'PAYROLL.AUDIT', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'PAYROLL.EXPORT_BANK', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'PAYROLL.RUN_PAID', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'PAYROLL.VIEW', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'PURCHASES.ADMIN_GL_AUTO', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'PURCHASES.AP_INVOICE_CRUD', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'PURCHASES.AP_PAYMENT_CRUD', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'PURCHASES.EXPORT', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'PURCHASES.REPORTS_VIEW', 1, '2026-03-04 16:14:39'),
('FIN', 'MANAGER', 'SALES.TASK_FIN', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'SALES.VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'STOCK.VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'MANAGER', 'SYS.AUDIT.VIEW', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'WF.OUTBOX.VIEW', 1, '2026-03-08 16:09:33'),
('FIN', 'MANAGER', 'WF.TASK.VIEW', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('FIN', 'STAFF', 'DASHBOARD.FINANCE_DETAIL', 1, '2026-03-04 16:14:41'),
('FIN', 'STAFF', 'DASHBOARD.FINANCE_VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'DOC.DOWNLOAD', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'DOC.UPLOAD', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'FIN.AP.INVOICE.CREATE', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'FIN.AP.INVOICE.EDIT', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'FIN.AP.INVOICE.VIEW', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'FIN.AP.PAYMENT.DRAFT', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'FIN.AP.PAYMENT.VIEW', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'FIN.FORWARDER.INVOICE.CREATE', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'FIN.FORWARDER.PAYMENT.DRAFT', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'FIN.PIB.PAY', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'FIXED_ASSET.ASSET_CRUD', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'FIXED_ASSET.VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('FIN', 'STAFF', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'PURCHASES.AP_INVOICE_CRUD', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'PURCHASES.AP_PAYMENT_CRUD', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'PURCHASES.REPORTS_VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'SALES.TASK_FIN', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'SALES.VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'STOCK.VIEW', 1, '2026-03-04 16:14:40'),
('FIN', 'STAFF', 'WF.OUTBOX.VIEW', 1, '2026-03-08 16:09:33'),
('FIN', 'STAFF', 'WF.TASK.VIEW', 1, '2026-03-08 16:09:33'),
('HRL', 'MANAGER', 'ABSENSI.ADMIN_PINS', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'ABSENSI.ADMIN_USERS', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'ABSENSI.APPROVE', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'ABSENSI.RECAP', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('HRL', 'MANAGER', 'DASHBOARD.HRL_VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'HRL.COMPLIANCE_EXPORT', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'HRL.IMPORT_REKENING', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'HRL.REG_ALKES_VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'HRL.VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('HRL', 'MANAGER', 'MASTER.COMPANY_BANK_CRUD', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'MASTER.DEPARTMENT_CRUD', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'MASTER.EMPLOYEE_CRUD', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'PAYROLL.AUDIT', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'PAYROLL.LOANS', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'PAYROLL.MATRIX_MANAGE', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'PAYROLL.PAYSLIP_VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'PAYROLL.RUN_CREATE', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'PAYROLL.RUN_EDIT', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'PAYROLL.RUN_POST', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'PAYROLL.SETTINGS', 1, '2026-03-04 16:14:41'),
('HRL', 'MANAGER', 'PAYROLL.VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:41'),
('HRL', 'STAFF', 'ABSENSI.RECAP', 1, '2026-03-04 16:14:42'),
('HRL', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:41'),
('HRL', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('HRL', 'STAFF', 'DASHBOARD.HRL_VIEW', 1, '2026-03-04 16:14:42'),
('HRL', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'STAFF', 'HRL.REG_ALKES_VIEW', 1, '2026-03-04 16:14:42'),
('HRL', 'STAFF', 'HRL.VIEW', 1, '2026-03-04 16:14:42'),
('HRL', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('HRL', 'STAFF', 'MASTER.EMPLOYEE_CRUD', 1, '2026-03-04 16:14:41'),
('HRL', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:14:41'),
('HRL', 'STAFF', 'PAYROLL.LOANS', 1, '2026-03-04 16:14:42'),
('HRL', 'STAFF', 'PAYROLL.PAYSLIP_VIEW', 1, '2026-03-04 16:14:42'),
('HRL', 'STAFF', 'PAYROLL.VIEW', 1, '2026-03-04 16:14:41'),
('ITC', 'MANAGER', 'ABSENSI.ADMIN_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.ADMIN_PINS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.ADMIN_USERS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.APPROVE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.OFFICE_SETTINGS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.RECAP', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.REQUEST_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.REQUEST_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'API.INTERNAL_ACCESS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'API.MOBILE_ACCESS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'API.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'CHAT.ADMIN_SETTINGS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.ACT_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.BRANCH_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.DETAIL_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.FINANCE_DETAIL', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.FINANCE_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.HRL_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.ITC_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.OWNER_SUMMARY', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.OWNER_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.PROCUREMENT_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.QUALITY_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.REGULATORY_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.SALES_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.SCM_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DASHBOARD.WAREHOUSE_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DOCS.EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'DOCS.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.ASSET_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.ASSET_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.ASSET_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.AUDIT_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.DEPRECIATION_RUN', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.DISPOSAL_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.DISPOSAL_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.OPERATIONS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.OPS_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.OPS_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.TAX_ANNUAL', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.TAX_ANNUAL_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.TAX_ANNUAL_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'FIXED_ASSET.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL_COMPLIANCE_EXPORT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL_REG_ALKES.EXPORT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.COMPLIANCE_EXPORT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.DOC_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.DOC_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.DOC_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.DOCS_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.IMPORT_REKENING', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.PROCESS_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.PROCESS_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.PROCESS_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.REG_ALKES_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.REG_ALKES_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.REG_ALKES_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'HRL.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'KPI.DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'KPI.DO_AUDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'KPI.DO_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'KPI.EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'KPI.EMPLOYEE_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'KPI.OFFICE_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'KPI.PURCHASES_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'KPI.STOCK_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'KPI.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.ADMIN_CENTER', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.COMPANY_BANK_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.COMPANY_BANK_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.COMPANY_BANK_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.CUSTOMER_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.CUSTOMER_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.CUSTOMER_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.CUSTOMER_EXPORT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.DEPARTMENT_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.DEPARTMENT_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.DEPARTMENT_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.EMAIL_COMPANY_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.EMAIL_COMPANY_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.EMAIL_COMPANY_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.EMPLOYEE_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.EMPLOYEE_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.EMPLOYEE_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.IMPORT_CUSTOMERS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.IMPORT_PRODUCTS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.IMPORT_VENDORS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.MANUFACTURE_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.MANUFACTURE_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.MANUFACTURE_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.OFFICE_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.OFFICE_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.OFFICE_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PAYMENT_TERMS_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PAYMENT_TERMS_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PAYMENT_TERMS_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PIC_CUSTOMER_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PIC_CUSTOMER_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PIC_CUSTOMER_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRICELIST_BUY_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRICELIST_BUY_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRICELIST_SELL_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRICELIST_SELL_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRICELIST_SELL_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRODUCT_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRODUCT_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRODUCT_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRODUCT_MEDIA_UPLOAD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRODUCT_PACKAGE_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRODUCT_PACKAGE_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.PRODUCT_PACKAGE_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.TAX_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.TAX_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.TAX_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.VENDOR_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.VENDOR_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.VENDOR_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.VENDOR_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MPR.PLAN_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MPR.PLAN_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'MPR.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.AUDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.EXPORT_BANK', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.LOANS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.LOANS_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.LOANS_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.MATRIX_MANAGE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.PAYSLIP_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.RUN_CREATE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.RUN_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.RUN_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.RUN_PAID', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.RUN_POST', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.SETTINGS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PAYROLL.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PQP.DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PQP.EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PQP.QUALITY_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PQP.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.ADMIN_GL_AUTO', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.ADMIN_STOCK_UPDATE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.AP_INVOICE_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.AP_INVOICE_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.AP_INVOICE_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.AP_PAYMENT_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.AP_PAYMENT_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.AP_PAYMENT_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.API_PR', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.APPROVE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.CEISA_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.CEISA_PIB', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.CEISA_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.CREATE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.EXPORT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.FORWARDING_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.FORWARDING_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.FORWARDING_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.GR_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.GR_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.GR_PROCESS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.IMPORT_CONTROL', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.IMPORT_CONTROL_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.PAYMENT_AP_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.PAYMENT_AP_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.PO_APPROVE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.PO_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.PO_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.PO_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.PO_PRINT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.REPORTS_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'PURCHASES.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'RBAC.ROLE_ASSIGN', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'RBAC.USER_PERMISSIONS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.CREATE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.EXPORT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.KPI_AUDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.PRINT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.TASK_ACT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.TASK_FIN', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.TASK_SCM', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.TASK_WQS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SALES.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'STOCK.ADJUST', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'STOCK.AUDIT_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'STOCK.DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'STOCK.EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'STOCK.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.ACCOUNT_READINESS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.API_PARTNER_KEYS', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.CONFIG_MANAGE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.JOBS_MONITOR', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.MFA_BYPASS_MANAGE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.MFA_POLICY_MANAGE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.RATE_LIMIT_MANAGE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.RBAC_MANAGE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.SECURITY_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'SYSTEM.USER_MANAGE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.BACKUP_MANAGE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.COMPLIANCE_EVIDENCE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.COMPLIANCE_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.CONTRACT_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.DR_BACKUP', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.DR_RESTORE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.DR_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.ENTERPRISE_AUDIT_EXPORT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.ITC_RESET_PASSWORD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.OPS_CONTROL_CENTER', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.OPS_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.PURCHASES_M2_APPLY', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.READINESS_AUDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.RELEASE_DEPLOY', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.RELEASE_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.RESTORE_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.RESTORE_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.REVIEW_KIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.RFC_APPROVE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.RFC_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.SECURITY_AUDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'TOOLS.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WEB_ADMIN.OPS_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WEB_ADMIN.RFC_APPROVE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WEB_ADMIN.RFC_VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WEB_ADMIN.THRESHOLDS_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WEB_ADMIN.VIEW', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.ALLOCATION', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.ALLOCATION_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.ALLOCATION_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.API_INCOMING_PO', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.INCOMING_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.INCOMING_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.INCOMING_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.PICKING_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.PICKING_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.PICKING_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.PR_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.PR_DELETE', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.PR_EDIT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.PR_PRINT', 1, '2026-03-08 10:30:40'),
('ITC', 'MANAGER', 'WQS.TRANSFER_CRUD', 1, '2026-03-08 10:30:40'),
('ITC', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'ABSENSI.OFFICE_SETTINGS', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('ITC', 'STAFF', 'DASHBOARD.ITC_VIEW', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('ITC', 'STAFF', 'MASTER.EMAIL_COMPANY_CRUD', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'MASTER.OFFICE_CRUD', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'SYSTEM.SECURITY_VIEW', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'TOOLS.ITC_RESET_PASSWORD', 1, '2026-03-04 16:14:43'),
('ITC', 'STAFF', 'TOOLS.VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('MPR', 'MANAGER', 'DASHBOARD.SALES_VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('MPR', 'MANAGER', 'MASTER.CUSTOMER_EXPORT', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'SALES.AUDIT_VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'SALES.EXPORT', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'SALES.PRINT', 1, '2026-03-04 16:14:43'),
('MPR', 'MANAGER', 'SALES.VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:43'),
('MPR', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:43'),
('MPR', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('MPR', 'STAFF', 'DASHBOARD.SALES_VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('MPR', 'STAFF', 'MASTER.CUSTOMER_EXPORT', 1, '2026-03-04 16:14:43'),
('MPR', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:14:43'),
('MPR', 'STAFF', 'SALES.PRINT', 1, '2026-03-04 16:14:43'),
('MPR', 'STAFF', 'SALES.VIEW', 1, '2026-03-04 16:14:43'),
('PQP', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:43'),
('PQP', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('PQP', 'MANAGER', 'DASHBOARD.PROCUREMENT_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'DASHBOARD.QUALITY_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'DASHBOARD.REGULATORY_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'DASHBOARD.SCM_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'HRL.COMPLIANCE_EXPORT', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'HRL.REG_ALKES_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('PQP', 'MANAGER', 'MASTER.IMPORT_PRODUCTS', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'MASTER.MANUFACTURE_CRUD', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'MASTER.PRODUCT_CRUD', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'MASTER.PRODUCT_MEDIA_UPLOAD', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'MASTER.PRODUCT_PACKAGE_CRUD', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'PQP.VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'MANAGER', 'STOCK.VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('PQP', 'STAFF', 'DASHBOARD.PROCUREMENT_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'DASHBOARD.QUALITY_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'DASHBOARD.REGULATORY_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'DASHBOARD.SCM_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'HRL.REG_ALKES_VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('PQP', 'STAFF', 'MASTER.MANUFACTURE_CRUD', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'MASTER.PRODUCT_CRUD', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'MASTER.PRODUCT_MEDIA_UPLOAD', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'PQP.VIEW', 1, '2026-03-04 16:14:44'),
('PQP', 'STAFF', 'STOCK.VIEW', 1, '2026-03-04 16:14:44'),
('REG', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('REG', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('REG', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('REG', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('SCM', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:44'),
('SCM', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:44'),
('SCM', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:44'),
('SCM', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('SCM', 'MANAGER', 'DASHBOARD.PROCUREMENT_VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'DASHBOARD.SCM_VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'DOC.DOWNLOAD', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'DOC.UPLOAD', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('SCM', 'MANAGER', 'MASTER.IMPORT_VENDORS', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'MASTER.VENDOR_CRUD', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.CEISA_PIB', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.EXPORT', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.FORWARDING_CRUD', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.GR_PROCESS', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.IMPORT_CONTROL', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.PO_APPROVE', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.PO_CRUD', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.PO_PRINT', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.REPORTS_VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'PURCHASES.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'SALES.TASK_SCM', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'SALES.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'MANAGER', 'SCM.BL.FINALIZE', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'SCM.BL.UPLOAD_DRAFT', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'SCM.BL.VIEW', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'SCM.FWD.QUOTES.APPROVE', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'SCM.FWD.QUOTES.EDIT', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'SCM.FWD.QUOTES.VIEW', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'SCM.PURCHASES.DOC.UPLOAD', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'SCM.SHIPMENT.TRACK.UPDATE', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'SCM.SHIPMENT.TRACK.VIEW', 1, '2026-03-08 16:10:13'),
('SCM', 'MANAGER', 'STOCK.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('SCM', 'STAFF', 'DASHBOARD.PROCUREMENT_VIEW', 1, '2026-03-04 16:14:46'),
('SCM', 'STAFF', 'DASHBOARD.SCM_VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'DOC.DOWNLOAD', 1, '2026-03-08 16:10:13'),
('SCM', 'STAFF', 'DOC.UPLOAD', 1, '2026-03-08 16:10:13'),
('SCM', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('SCM', 'STAFF', 'MASTER.VENDOR_CRUD', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'PURCHASES.CEISA_PIB', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'PURCHASES.FORWARDING_CRUD', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'PURCHASES.GR_PROCESS', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'PURCHASES.IMPORT_CONTROL', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'PURCHASES.PO_CRUD', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'PURCHASES.PO_PRINT', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'PURCHASES.REPORTS_VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'PURCHASES.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'SALES.TASK_SCM', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'SALES.VIEW', 1, '2026-03-04 16:14:45'),
('SCM', 'STAFF', 'SCM.BL.UPLOAD_DRAFT', 1, '2026-03-08 16:10:13'),
('SCM', 'STAFF', 'SCM.BL.VIEW', 1, '2026-03-08 16:10:13'),
('SCM', 'STAFF', 'SCM.FWD.QUOTES.EDIT', 1, '2026-03-08 16:10:13'),
('SCM', 'STAFF', 'SCM.FWD.QUOTES.VIEW', 1, '2026-03-08 16:10:13'),
('SCM', 'STAFF', 'SCM.PURCHASES.DOC.UPLOAD', 1, '2026-03-08 16:10:13'),
('SCM', 'STAFF', 'SCM.SHIPMENT.TRACK.UPDATE', 1, '2026-03-08 16:10:13'),
('SCM', 'STAFF', 'SCM.SHIPMENT.TRACK.VIEW', 1, '2026-03-08 16:10:13'),
('SCM', 'STAFF', 'STOCK.VIEW', 1, '2026-03-04 16:14:45'),
('SYS', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:46'),
('SYS', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:46'),
('SYS', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:46'),
('SYS', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('SYS', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:46'),
('SYS', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('SYS', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-04 16:14:46'),
('SYS', 'MANAGER', 'WQS.TRANSFER_CRUD', 1, '2026-03-05 21:06:31'),
('SYS', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:46'),
('SYS', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:46'),
('SYS', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:46'),
('SYS', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('SYS', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:46'),
('SYS', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('SYS', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:14:46'),
('SYS', 'STAFF', 'WQS.TRANSFER_CRUD', 1, '2026-03-05 21:06:31'),
('SYS', 'SYS', 'ABSENSI.ADMIN_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.ADMIN_PINS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.ADMIN_USERS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.APPROVE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.CHECKIN', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.OFFICE_SETTINGS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.RECAP', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.REQUEST', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.REQUEST_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.REQUEST_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ABSENSI.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ACT.CEISA.AUDIT_NOTE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ACT.CEISA.CREATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ACT.CEISA.RESUBMIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ACT.CEISA.SUBMIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ACT.CEISA.UPDATE_STATUS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ACT.CEISA.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ACT.GL.POST', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ACT.PERIOD.CLOSE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'ACT.REVERSAL.CREATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'API.INTERNAL_ACCESS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'API.MOBILE_ACCESS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'API.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'CHAT.ADMIN_SETTINGS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'CHAT.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.ACT_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.BRANCH_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.DETAIL_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.FINANCE_DETAIL', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.FINANCE_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.HRL_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.ITC_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.KPI_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.OWNER_SUMMARY', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.OWNER_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.PROCUREMENT_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.QUALITY_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.REGULATORY_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.SALES_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.SCM_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DASHBOARD.WAREHOUSE_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DOC.DOWNLOAD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DOC.UPLOAD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DOCS.EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'DOCS.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.AP.INVOICE.CREATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.AP.INVOICE.EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.AP.INVOICE.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.AP.PAYMENT.APPROVE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.AP.PAYMENT.DRAFT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.AP.PAYMENT.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.FORWARDER.INVOICE.CREATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.FORWARDER.PAYMENT.APPROVE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.FORWARDER.PAYMENT.DRAFT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIN.PIB.PAY', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.ASSET_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.ASSET_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.ASSET_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.AUDIT_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.DEPRECIATION_RUN', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.DISPOSAL_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.DISPOSAL_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.OPERATIONS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.OPS_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.OPS_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.TAX_ANNUAL', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.TAX_ANNUAL_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'FIXED_ASSET.TAX_ANNUAL_VIEW', 1, '2026-03-08 16:43:49');
INSERT DELAYED IGNORE INTO `rbac_dept_role_permissions` (`dept_code`, `role_code`, `perm_code`, `allow_flag`, `created_at`) VALUES
('SYS', 'SYS', 'FIXED_ASSET.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL_COMPLIANCE_EXPORT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL_COMPLIANCE_EXPORT.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL_REG_ALKES.EXPORT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL_REG_ALKES.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.COMPLIANCE_EXPORT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.DOC_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.DOC_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.DOC_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.DOCS_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.IMPORT_REKENING', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.PROCESS_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.PROCESS_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.PROCESS_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.REG_ALKES_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.REG_ALKES_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.REG_ALKES_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'HRL.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'KPI.DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'KPI.DO_AUDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'KPI.DO_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'KPI.EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'KPI.EMPLOYEE_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'KPI.OFFICE_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'KPI.PURCHASES_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'KPI.STOCK_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'KPI.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.ADMIN_CENTER', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.COMPANY_BANK_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.COMPANY_BANK_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.COMPANY_BANK_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.CUSTOMER_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.CUSTOMER_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.CUSTOMER_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.CUSTOMER_EXPORT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.DEPARTMENT_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.DEPARTMENT_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.DEPARTMENT_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.EMAIL_COMPANY_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.EMAIL_COMPANY_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.EMAIL_COMPANY_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.EMPLOYEE_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.EMPLOYEE_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.EMPLOYEE_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.IMPORT_CUSTOMERS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.IMPORT_PRODUCTS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.IMPORT_VENDORS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.MANUFACTURE_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.MANUFACTURE_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.MANUFACTURE_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.OFFICE_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.OFFICE_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.OFFICE_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PAYMENT_TERMS_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PAYMENT_TERMS_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PAYMENT_TERMS_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PIC_CUSTOMER_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PIC_CUSTOMER_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PIC_CUSTOMER_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRICELIST_BUY_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRICELIST_BUY_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRICELIST_BUY_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRICELIST_SELL_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRICELIST_SELL_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRICELIST_SELL_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRODUCT_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRODUCT_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRODUCT_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRODUCT_MEDIA_UPLOAD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRODUCT_PACKAGE_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRODUCT_PACKAGE_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.PRODUCT_PACKAGE_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.TAX_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.TAX_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.TAX_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.VENDOR_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.VENDOR_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.VENDOR_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.VENDOR_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MASTER.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MPR.PLAN_APPROVE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MPR.PLAN_CREATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MPR.PLAN_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MPR.PLAN_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MPR.PLAN_EXPORT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MPR.PLAN_IMPORT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MPR.PLAN_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'MPR.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.AUDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.EXPORT_BANK', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.LOANS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.LOANS_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.LOANS_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.MATRIX_MANAGE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.PAYSLIP_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.RUN_CREATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.RUN_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.RUN_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.RUN_PAID', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.RUN_POST', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.SETTINGS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PAYROLL.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PQP.DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PQP.EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PQP.QUALITY_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PQP.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.ADMIN_GL_AUTO', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.ADMIN_STOCK_UPDATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.AP_INVOICE_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.AP_INVOICE_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.AP_INVOICE_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.AP_PAYMENT_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.AP_PAYMENT_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.AP_PAYMENT_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.API_PR', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.APPROVE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.CEISA_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.CEISA_PIB', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.CEISA_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.CREATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.EXPORT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.FORWARDING_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.FORWARDING_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.FORWARDING_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.GR_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.GR_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.GR_PROCESS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.IMPORT_CONTROL', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.IMPORT_CONTROL_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.PAYMENT_AP_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.PAYMENT_AP_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.PO_APPROVE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.PO_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.PO_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.PO_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.PO_PRINT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.REPORTS_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'PURCHASES.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'RBAC.ROLE_ASSIGN', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'RBAC.USER_PERMISSIONS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'RBAC.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.AUDIT_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.CREATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.EXPORT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.KPI_AUDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.KPI_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.PRINT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.TASK_ACT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.TASK_FIN', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.TASK_SCM', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.TASK_WQS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.TRACKING_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SALES.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SCM.BL.FINALIZE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SCM.BL.UPLOAD_DRAFT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SCM.BL.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SCM.FWD.QUOTES.APPROVE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SCM.FWD.QUOTES.EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SCM.FWD.QUOTES.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SCM.PURCHASES.DOC.UPLOAD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SCM.SHIPMENT.TRACK.UPDATE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SCM.SHIPMENT.TRACK.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'STOCK.ADJUST', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'STOCK.AUDIT_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'STOCK.DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'STOCK.EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'STOCK.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYS.AUDIT.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.ACCOUNT_READINESS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.API_PARTNER_KEYS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.CONFIG_MANAGE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.JOBS_MONITOR', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.MFA_BYPASS_MANAGE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.MFA_POLICY_MANAGE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.RATE_LIMIT_MANAGE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.RBAC_MANAGE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.SECURITY_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.USER_MANAGE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'SYSTEM.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.BACKUP_MANAGE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.COMPLIANCE_EVIDENCE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.COMPLIANCE_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.CONTRACT_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.DR_BACKUP', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.DR_RESTORE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.DR_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.ENTERPRISE_AUDIT_EXPORT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.ENTERPRISE_AUDIT_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.ITC_RESET_PASSWORD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.OPS_CONTROL_CENTER', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.OPS_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.PURCHASES_M2_APPLY', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.READINESS_AUDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.RELEASE_DEPLOY', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.RELEASE_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.RESTORE_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.RESTORE_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.REVIEW_KIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.RFC_APPROVE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.RFC_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.SECURITY_AUDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'TOOLS.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WEB_ADMIN.OPS_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WEB_ADMIN.RFC_APPROVE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WEB_ADMIN.RFC_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WEB_ADMIN.THRESHOLDS_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WEB_ADMIN.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WF.OUTBOX.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WF.TASK.VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.ALLOCATION', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.ALLOCATION_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.ALLOCATION_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.API_INCOMING_PO', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.DO_TASKS', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.INCOMING_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.INCOMING_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.INCOMING_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.INCOMING_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.PICKING_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.PICKING_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.PICKING_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.PICKING_VIEW', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.PR_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.PR_DELETE', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.PR_EDIT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.PR_PRINT', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.TRANSFER_CRUD', 1, '2026-03-08 16:43:49'),
('SYS', 'SYS', 'WQS.VIEW', 1, '2026-03-08 16:43:49'),
('WQS', 'MANAGER', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('WQS', 'MANAGER', 'DASHBOARD.QUALITY_VIEW', 1, '2026-03-04 16:14:47'),
('WQS', 'MANAGER', 'DASHBOARD.SCM_VIEW', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'DASHBOARD.WAREHOUSE_VIEW', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('WQS', 'MANAGER', 'MASTER.VIEW', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'PURCHASES.GR_PROCESS', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'SALES.TASK_WQS', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'SALES.VIEW', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'STOCK.ADJUST', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'STOCK.AUDIT_VIEW', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'STOCK.VIEW', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'WQS.ALLOCATION', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'WQS.DO_TASKS', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'WQS.INCOMING_CRUD', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'WQS.PICKING_CRUD', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'WQS.PR_CRUD', 1, '2026-03-04 16:14:46'),
('WQS', 'MANAGER', 'WQS.PR_PRINT', 1, '2026-03-04 16:14:46'),
('WQS', 'STAFF', 'ABSENSI.CHECKIN', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'ABSENSI.REQUEST', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'ABSENSI.VIEW', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'CHAT.VIEW', 1, '2026-03-04 17:04:22'),
('WQS', 'STAFF', 'DASHBOARD.QUALITY_VIEW', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'DASHBOARD.SCM_VIEW', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'DASHBOARD.VIEW', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'DASHBOARD.WAREHOUSE_VIEW', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'KPI.VIEW', 1, '2026-03-04 17:27:56'),
('WQS', 'STAFF', 'MASTER.VIEW', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'PURCHASES.GR_PROCESS', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'SALES.TASK_WQS', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'SALES.VIEW', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'STOCK.VIEW', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'WQS.DO_TASKS', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'WQS.INCOMING_CRUD', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'WQS.PICKING_CRUD', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'WQS.PR_CRUD', 1, '2026-03-04 16:14:47'),
('WQS', 'STAFF', 'WQS.PR_PRINT', 1, '2026-03-04 16:14:47');

-- --------------------------------------------------------

--
-- Struktur dari tabel `rbac_permissions`
--

DROP TABLE IF EXISTS `rbac_permissions`;
CREATE TABLE `rbac_permissions` (
  `perm_code` varchar(80) NOT NULL,
  `perm_name` varchar(120) NOT NULL,
  `module` varchar(40) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `rbac_permissions`
--

TRUNCATE TABLE `rbac_permissions`;
--
-- Dumping data untuk tabel `rbac_permissions`
--

INSERT DELAYED IGNORE INTO `rbac_permissions` (`perm_code`, `perm_name`, `module`, `description`, `is_active`) VALUES
('ABSENSI.ADMIN_EDIT', 'Absensi Admin - Edit', 'ABSENSI', 'Edit pengaturan admin absensi.', 1),
('ABSENSI.ADMIN_PINS', 'Absensi - Admin Pins', 'ABSENSI', 'Kelola PIN absensi.', 1),
('ABSENSI.ADMIN_USERS', 'Absensi - Admin Users', 'ABSENSI', 'Kelola user absensi (mapping pin).', 1),
('ABSENSI.APPROVE', 'Absensi - Approval', 'ABSENSI', 'Approve request absensi.', 1),
('ABSENSI.CHECKIN', 'Absensi - Check-in/Check-out', 'ABSENSI', 'Check-in/out by photo.', 1),
('ABSENSI.OFFICE_SETTINGS', 'Absensi - Office Settings', 'ABSENSI', 'GeoFence/Office settings.', 1),
('ABSENSI.RECAP', 'Absensi - Rekap HR', 'ABSENSI', 'Rekap & laporan absensi.', 1),
('ABSENSI.REQUEST', 'Absensi - Request Izin/Sakit/Dinas', 'ABSENSI', 'Pengajuan izin/sakit/dinas.', 1),
('ABSENSI.REQUEST_DELETE', 'Absensi Request - Delete', 'ABSENSI', 'Hapus pengajuan absensi.', 1),
('ABSENSI.REQUEST_EDIT', 'Absensi Request - Edit', 'ABSENSI', 'Edit pengajuan izin/sakit/dinas.', 1),
('ABSENSI.VIEW', 'Absensi - View Dashboard', 'ABSENSI', 'Melihat dashboard absensi.', 1),
('ACT.CEISA.AUDIT_NOTE', 'ACT: Ceisa Audit Note', 'ACT', 'Wajib isi note saat reject/resubmit untuk audit trail', 1),
('ACT.CEISA.CREATE', 'ACT: Ceisa Create', 'ACT', 'Membuat draft PIB', 1),
('ACT.CEISA.RESUBMIT', 'ACT: Ceisa Re-Submit', 'ACT', 'Resubmit PIB saat reject (wajib audit note)', 1),
('ACT.CEISA.SUBMIT', 'ACT: Ceisa Submit', 'ACT', 'Submit PIB ke Ceisa (pertama kali)', 1),
('ACT.CEISA.UPDATE_STATUS', 'ACT: Ceisa Update Status', 'ACT', 'Update status (arrived, accepted, rejected, billing issued, SPPB, final PIB)', 1),
('ACT.CEISA.VIEW', 'ACT: Ceisa View', 'ACT', 'Melihat dokumen & status PIB/Ceisa', 1),
('ACT.GL.POST', 'ACT: GL Post', 'ACT', 'Posting jurnal (immutable; audit wajib)', 1),
('ACT.PERIOD.CLOSE', 'ACT: Period Close', 'ACT', 'Tutup periode akuntansi (audit wajib)', 1),
('ACT.REVERSAL.CREATE', 'ACT: Reversal Create', 'ACT', 'Buat reversal/cancel untuk transaksi posted', 1),
('API.INTERNAL_ACCESS', 'API Internal Access', 'API', 'Akses API internal.', 1),
('API.MOBILE_ACCESS', 'API Mobile Access', 'API', 'Akses API mobile.', 1),
('API.VIEW', 'API - View', 'API', 'Akses view API.', 1),
('CHAT.ADMIN_SETTINGS', 'Chat Admin Settings', 'CHAT', 'Kelola pengaturan chat (reactions, retention, ACL, exports, audit).', 1),
('CHAT.VIEW', 'Chat - View', 'CHAT', 'Akses modul Internal Chat (baca, kirim pesan).', 1),
('DASHBOARD.ACT_VIEW', 'Dashboard ACT - View', 'DASHBOARD', 'Akses ACT Dashboard.', 1),
('DASHBOARD.BRANCH_VIEW', 'Dashboard Branch - View', 'DASHBOARD', 'Akses Branch Dashboard (landing staff cabang).', 1),
('DASHBOARD.DETAIL_VIEW', 'Dashboard Detail - View', 'DASHBOARD', 'Lihat dashboard detail.', 1),
('DASHBOARD.FINANCE_DETAIL', 'Dashboard Finance Detail', 'DASHBOARD', 'Dashboard Detail Excel-style (target vs pencapaian per office).', 1),
('DASHBOARD.FINANCE_VIEW', 'Dashboard Finance - View', 'DASHBOARD', 'Akses Finance Dashboard.', 1),
('DASHBOARD.HRL_VIEW', 'Dashboard HRL - View', 'DASHBOARD', 'Akses HRL Dashboard.', 1),
('DASHBOARD.ITC_VIEW', 'Dashboard ITC - View', 'DASHBOARD', 'Akses ITC Dashboard.', 1),
('DASHBOARD.KPI_VIEW', 'Dashboard KPI - View', 'DASHBOARD', 'Akses KPI dashboard via menu dashboard.', 1),
('DASHBOARD.OWNER_SUMMARY', 'Dashboard Owner Executive Summary', 'DASHBOARD', 'Ringkasan bisnis untuk Owner.', 1),
('DASHBOARD.OWNER_VIEW', 'Dashboard Owner - View', 'DASHBOARD', 'Akses Owner Dashboard.', 1),
('DASHBOARD.PROCUREMENT_VIEW', 'Dashboard Procurement - View', 'DASHBOARD', 'Akses Procurement/Purchases Dashboard.', 1),
('DASHBOARD.QUALITY_VIEW', 'Dashboard Quality - View', 'DASHBOARD', 'Akses Quality Dashboard.', 1),
('DASHBOARD.REGULATORY_VIEW', 'Dashboard Regulatory - View', 'DASHBOARD', 'Akses Regulatory Dashboard.', 1),
('DASHBOARD.SALES_VIEW', 'Dashboard Sales - View', 'DASHBOARD', 'Akses Sales Dashboard.', 1),
('DASHBOARD.SCM_VIEW', 'Dashboard SCM - View', 'DASHBOARD', 'Akses SCM Dashboard (Supply Chain, Import, Procurement).', 1),
('DASHBOARD.VIEW', 'Dashboard Center - View', 'DASHBOARD', 'Akses semua dashboard (override section-specific).', 1),
('DASHBOARD.WAREHOUSE_VIEW', 'Dashboard Warehouse - View', 'DASHBOARD', 'Akses Warehouse/WQS Dashboard.', 1),
('DOC.DOWNLOAD', 'Documents: Download', 'DOC', 'Download dokumen', 1),
('DOC.UPLOAD', 'Documents: Upload', 'DOC', 'Upload dokumen (sesuai guard folder)', 1),
('DOCS.EDIT', 'Docs - Edit', 'DOCS', 'Edit dokumentasi.', 1),
('DOCS.VIEW', 'Docs - View', 'DOCS', 'Lihat dokumentasi.', 1),
('FIN.AP.INVOICE.CREATE', 'FIN: AP Invoice Create', 'FIN', 'Membuat invoice AP (draft)', 1),
('FIN.AP.INVOICE.EDIT', 'FIN: AP Invoice Edit', 'FIN', 'Edit invoice AP (sebelum posted/locked)', 1),
('FIN.AP.INVOICE.VIEW', 'FIN: AP Invoice View', 'FIN', 'Melihat daftar invoice AP (supplier/proforma/final/PIB)', 1),
('FIN.AP.PAYMENT.APPROVE', 'FIN: AP Payment Approve', 'FIN', 'Approve/finalize pembayaran AP (opsional bila maker-checker)', 1),
('FIN.AP.PAYMENT.DRAFT', 'FIN: AP Payment Draft', 'FIN', 'Membuat draft pembayaran AP (termasuk upload bukti bayar)', 1),
('FIN.AP.PAYMENT.VIEW', 'FIN: AP Payment View', 'FIN', 'Melihat daftar pembayaran AP', 1),
('FIN.FORWARDER.INVOICE.CREATE', 'FIN: Forwarder Invoice Create', 'FIN', 'Membuat invoice forwarder (jasa) + upload invoice', 1),
('FIN.FORWARDER.PAYMENT.APPROVE', 'FIN: Forwarder Payment Approve', 'FIN', 'Approve/finalize pembayaran forwarder (opsional)', 1),
('FIN.FORWARDER.PAYMENT.DRAFT', 'FIN: Forwarder Payment Draft', 'FIN', 'Membuat draft pembayaran forwarder + upload bukti bayar', 1),
('FIN.PIB.PAY', 'FIN: PIB Pay', 'FIN', 'Membayar Billing Aju PIB + upload bukti bayar', 1),
('FIXED_ASSET.ASSET_CRUD', 'Fixed Asset - Assets CRUD', 'FIXED_ASSET', 'Kelola master aset (acquisition, data aset).', 1),
('FIXED_ASSET.ASSET_DELETE', 'Fixed Asset - Delete Asset', 'FIXED_ASSET', 'Hapus data aset (high risk).', 1),
('FIXED_ASSET.ASSET_EDIT', 'Fixed Asset - Edit Asset', 'FIXED_ASSET', 'Edit data aset.', 1),
('FIXED_ASSET.AUDIT_VIEW', 'Fixed Asset - Audit View', 'FIXED_ASSET', 'Lihat audit fixed asset (read-only).', 1),
('FIXED_ASSET.DEPRECIATION_RUN', 'Fixed Asset - Depreciation Run', 'FIXED_ASSET', 'Hitung depresiasi periodik.', 1),
('FIXED_ASSET.DISPOSAL_EDIT', 'Fixed Asset Disposal - Edit', 'FIXED_ASSET', 'Edit disposisi aset.', 1),
('FIXED_ASSET.DISPOSAL_VIEW', 'Fixed Asset Disposal - View', 'FIXED_ASSET', 'Lihat disposisi aset.', 1),
('FIXED_ASSET.OPERATIONS', 'Fixed Asset - Operations', 'FIXED_ASSET', 'Operasional aset (move, repair, dispose).', 1),
('FIXED_ASSET.OPS_EDIT', 'Fixed Asset Ops - Edit', 'FIXED_ASSET', 'Edit operasional aset.', 1),
('FIXED_ASSET.OPS_VIEW', 'Fixed Asset Ops - View', 'FIXED_ASSET', 'Lihat operasional aset.', 1),
('FIXED_ASSET.TAX_ANNUAL', 'Fixed Asset - Tax Annual', 'FIXED_ASSET', 'Perhitungan pajak tahunan aset.', 1),
('FIXED_ASSET.TAX_ANNUAL_EDIT', 'Fixed Asset Tax Annual - Edit', 'FIXED_ASSET', 'Edit perhitungan pajak tahunan.', 1),
('FIXED_ASSET.TAX_ANNUAL_VIEW', 'Fixed Asset Tax Annual - View', 'FIXED_ASSET', 'Lihat perhitungan pajak tahunan.', 1),
('FIXED_ASSET.VIEW', 'Fixed Asset - View', 'FIXED_ASSET', 'Melihat dashboard fixed asset.', 1),
('HRL_COMPLIANCE_EXPORT', 'HRL - Compliance Export Reg Alkes', 'HRL', 'Export laporan compliance reg alkes (CSV/Excel) — alias underscore.', 1),
('HRL_COMPLIANCE_EXPORT.VIEW', 'HRL Compliance Export - View (Alias)', 'HRL', 'Alias view permission untuk kompatibilitas legacy compliance export.', 1),
('HRL_REG_ALKES.EXPORT', 'HRL Reg Alkes - Export', 'HRL_REG_ALKES', 'Export data registrasi alat kesehatan.', 1),
('HRL_REG_ALKES.VIEW', 'HRL Reg Alkes - View (Module)', 'HRL_REG_ALKES', 'Akses modul HRL Reg Alkes secara read-only.', 1),
('HRL.COMPLIANCE_EXPORT', 'HRL - Compliance Export Reg Alkes', 'HRL', 'Export laporan compliance reg alkes (CSV/Excel).', 1),
('HRL.DOC_DELETE', 'HRL Document - Delete', 'HRL', 'Hapus dokumen HRL.', 1),
('HRL.DOC_EDIT', 'HRL Document - Edit', 'HRL', 'Edit dokumen HRL.', 1),
('HRL.DOC_VIEW', 'HRL Document - View', 'HRL', 'Lihat dokumen HRL.', 1),
('HRL.DOCS_EDIT', 'HRL Docs - Edit', 'HRL', 'Edit dokumen HRL.', 1),
('HRL.IMPORT_REKENING', 'HRL Import Rekening', 'HRL', 'Import rekening bank karyawan (final).', 1),
('HRL.PROCESS_DELETE', 'HRL Process - Delete', 'HRL', 'Hapus/batalkan request HRL Process (soft delete).', 1),
('HRL.PROCESS_EDIT', 'HRL Process - Edit', 'HRL', 'Buat, edit, submit request HRL Process. Approve sesuai role/dept.', 1),
('HRL.PROCESS_VIEW', 'HRL Process - View', 'HRL', 'Akses modul HRL Process (tower, request, download).', 1),
('HRL.REG_ALKES_DELETE', 'HRL Reg Alkes - Delete', 'HRL', 'Hapus data registrasi alat kesehatan.', 1),
('HRL.REG_ALKES_EDIT', 'HRL Reg Alkes - Edit', 'HRL', 'Edit data registrasi alat kesehatan.', 1),
('HRL.REG_ALKES_VIEW', 'HRL Reg Alkes - View', 'HRL', 'Lihat data registrasi alat kesehatan.', 1),
('HRL.VIEW', 'HRL - View', 'HRL', 'Akses modul HRL (dokumen, reg alkes, compliance).', 1),
('KPI.DELETE', 'KPI Center - Delete', 'KPI', 'Hapus data KPI (high risk).', 1),
('KPI.DO_AUDIT', 'KPI DO - Audit', 'KPI', 'Audit KPI DO.', 1),
('KPI.DO_VIEW', 'KPI DO - View', 'KPI', 'Lihat KPI DO.', 1),
('KPI.EDIT', 'KPI Center - Edit', 'KPI', 'Edit konfigurasi/target KPI.', 1),
('KPI.EMPLOYEE_VIEW', 'KPI Employee - View', 'KPI', 'Lihat KPI employee.', 1),
('KPI.OFFICE_VIEW', 'KPI Office - View', 'KPI', 'Lihat KPI office.', 1),
('KPI.PURCHASES_VIEW', 'KPI Purchases - View', 'KPI', 'Lihat KPI purchases.', 1),
('KPI.STOCK_VIEW', 'KPI Stock - View', 'KPI', 'Lihat KPI stock.', 1),
('KPI.VIEW', 'KPI Center - View', 'KPI', 'Akses KPI Center & laporan KPI.', 1),
('MASTER.ADMIN_CENTER', 'Master Admin Center', 'MASTER', 'Akses Master Data Center (admin-level: products, customers, vendors, office).', 1),
('MASTER.COMPANY_BANK_CRUD', 'Rekening Perusahaan CRUD', 'MASTER', 'Kelola rekening perusahaan (FIN).', 1),
('MASTER.COMPANY_BANK_DELETE', 'Rekening Perusahaan - Delete', 'MASTER', 'Hapus rekening perusahaan.', 1),
('MASTER.COMPANY_BANK_EDIT', 'Rekening Perusahaan - Edit', 'MASTER', 'Edit rekening perusahaan.', 1),
('MASTER.CUSTOMER_CRUD', 'Master Customers CRUD', 'MASTER', 'Kelola pelanggan/RS/klinik (create/edit/delete).', 1),
('MASTER.CUSTOMER_DELETE', 'Master Customers - Delete', 'MASTER', 'Hapus data pelanggan (high risk).', 1),
('MASTER.CUSTOMER_EDIT', 'Master Customers - Edit', 'MASTER', 'Edit data pelanggan/RS/klinik.', 1),
('MASTER.CUSTOMER_EXPORT', 'Export Customers', 'MASTER', 'Export customer list (CSV/Excel).', 1),
('MASTER.DEPARTMENT_CRUD', 'Master Departments CRUD', 'MASTER', 'Kelola master departemen.', 1),
('MASTER.DEPARTMENT_DELETE', 'Master Department - Delete', 'MASTER', 'Hapus data departemen.', 1),
('MASTER.DEPARTMENT_EDIT', 'Master Department - Edit', 'MASTER', 'Edit data departemen.', 1),
('MASTER.EMAIL_COMPANY_CRUD', 'Master Email Company CRUD', 'MASTER', 'Kelola email perusahaan (SMTP/from).', 1),
('MASTER.EMAIL_COMPANY_DELETE', 'Master Email Company - Delete', 'MASTER', 'Hapus konfigurasi email.', 1),
('MASTER.EMAIL_COMPANY_EDIT', 'Master Email Company - Edit', 'MASTER', 'Edit konfigurasi email.', 1),
('MASTER.EMPLOYEE_CRUD', 'Master Employees CRUD', 'MASTER', 'Create/Read/Update/Delete karyawan.', 1),
('MASTER.EMPLOYEE_DELETE', 'Master Employees - Delete', 'MASTER', 'Hapus data karyawan.', 1),
('MASTER.EMPLOYEE_EDIT', 'Master Employees - Edit', 'MASTER', 'Edit data karyawan.', 1),
('MASTER.IMPORT_CUSTOMERS', 'Master Import Customers', 'MASTER', 'Import data customer dari CSV.', 1),
('MASTER.IMPORT_PRODUCTS', 'Master Import Products', 'MASTER', 'Import data produk dari CSV.', 1),
('MASTER.IMPORT_VENDORS', 'Master Import Vendors', 'MASTER', 'Import data vendor dari CSV.', 1),
('MASTER.MANUFACTURE_CRUD', 'Master Manufactures (Pabrik) CRUD', 'MASTER', 'Kelola data pabrik/manufacturer.', 1),
('MASTER.MANUFACTURE_DELETE', 'Master Manufacture - Delete', 'MASTER', 'Hapus data pabrik.', 1),
('MASTER.MANUFACTURE_EDIT', 'Master Manufacture - Edit', 'MASTER', 'Edit data pabrik.', 1),
('MASTER.OFFICE_CRUD', 'Master Office CRUD', 'MASTER', 'Kelola master kantor/office code.', 1),
('MASTER.OFFICE_DELETE', 'Master Office - Delete', 'MASTER', 'Hapus data kantor.', 1),
('MASTER.OFFICE_EDIT', 'Master Office - Edit', 'MASTER', 'Edit data kantor.', 1),
('MASTER.PAYMENT_TERMS_CRUD', 'Master Payment Terms CRUD', 'MASTER', 'Kelola termin pembayaran.', 1),
('MASTER.PAYMENT_TERMS_DELETE', 'Master Payment Terms - Delete', 'MASTER', 'Hapus termin pembayaran.', 1),
('MASTER.PAYMENT_TERMS_EDIT', 'Master Payment Terms - Edit', 'MASTER', 'Edit termin pembayaran.', 1),
('MASTER.PIC_CUSTOMER_CRUD', 'Master User/PIC Customers CRUD', 'MASTER', 'Kelola PIC customer (mapping user ↔ customer).', 1),
('MASTER.PIC_CUSTOMER_DELETE', 'Master PIC Customer - Delete', 'MASTER', 'Hapus mapping PIC customer.', 1),
('MASTER.PIC_CUSTOMER_EDIT', 'Master PIC Customer - Edit', 'MASTER', 'Edit mapping PIC customer.', 1),
('MASTER.PRICELIST_BUY_CRUD', 'Master Pricelist Buy CRUD', 'MASTER', 'Kelola harga beli (untuk PQP/FIN).', 1),
('MASTER.PRICELIST_BUY_DELETE', 'Master Pricelist Buy - Delete', 'MASTER', 'Hapus harga beli.', 1),
('MASTER.PRICELIST_BUY_EDIT', 'Master Pricelist Buy - Edit', 'MASTER', 'Edit harga beli.', 1),
('MASTER.PRICELIST_SELL_CRUD', 'Master Pricelist Sell CRUD', 'MASTER', 'Kelola harga jual (untuk CRM/MPR).', 1),
('MASTER.PRICELIST_SELL_DELETE', 'Master Pricelist Sell - Delete', 'MASTER', 'Hapus harga jual.', 1),
('MASTER.PRICELIST_SELL_EDIT', 'Master Pricelist Sell - Edit', 'MASTER', 'Edit harga jual.', 1),
('MASTER.PRODUCT_CRUD', 'Master Products CRUD', 'MASTER', 'Kelola master products single.', 1),
('MASTER.PRODUCT_DELETE', 'Master Products - Delete', 'MASTER', 'Hapus data produk (high risk).', 1),
('MASTER.PRODUCT_EDIT', 'Master Products - Edit', 'MASTER', 'Edit data produk.', 1),
('MASTER.PRODUCT_MEDIA_UPLOAD', 'Products Media Upload', 'MASTER', 'Upload foto/video produk.', 1),
('MASTER.PRODUCT_PACKAGE_CRUD', 'Master Products Package CRUD', 'MASTER', 'Kelola paket produk (bundle).', 1),
('MASTER.PRODUCT_PACKAGE_DELETE', 'Master Product Package - Delete', 'MASTER', 'Hapus paket produk.', 1),
('MASTER.PRODUCT_PACKAGE_EDIT', 'Master Product Package - Edit', 'MASTER', 'Edit paket produk.', 1),
('MASTER.TAX_CRUD', 'Master Tax CRUD', 'MASTER', 'Kelola pajak/PPN/withholding.', 1),
('MASTER.TAX_DELETE', 'Master Tax - Delete', 'MASTER', 'Hapus data pajak.', 1),
('MASTER.TAX_EDIT', 'Master Tax - Edit', 'MASTER', 'Edit data pajak.', 1),
('MASTER.VENDOR_CRUD', 'Master Vendors CRUD', 'MASTER', 'Kelola vendor jasa/logistik/forwarder.', 1),
('MASTER.VENDOR_DELETE', 'Master Vendors - Delete', 'MASTER', 'Hapus data vendor.', 1),
('MASTER.VENDOR_EDIT', 'Master Vendors - Edit', 'MASTER', 'Edit data vendor.', 1),
('MASTER.VENDOR_VIEW', 'Master Vendors - View', 'MASTER', 'Lihat data vendor (read-only).', 1),
('MASTER.VIEW', 'Master Data Center - View', 'MASTER', 'Akses dashboard Master Data Center.', 1),
('MPR.PLAN_APPROVE', 'MPR Plan - Approve', 'MPR', 'Approve/reject plan MPR.', 1),
('MPR.PLAN_CREATE', 'MPR Plan - Create', 'MPR', 'Buat plan MPR baru.', 1),
('MPR.PLAN_DELETE', 'MPR Plan - Delete', 'MPR', 'Hapus/restore plan MPR (soft delete).', 1),
('MPR.PLAN_EDIT', 'MPR Plan - Edit', 'MPR', 'Edit plan MPR.', 1),
('MPR.PLAN_EXPORT', 'MPR Plan - Export', 'MPR', 'Export report/rekap plan MPR.', 1),
('MPR.PLAN_IMPORT', 'MPR Plan - Import', 'MPR', 'Import plan MPR dari CSV.', 1),
('MPR.PLAN_VIEW', 'MPR Plan - View', 'MPR', 'Lihat plan MPR.', 1),
('MPR.VIEW', 'MPR - View', 'MPR', 'Akses modul MPR (Marketing & Project: plans, daily ops, budget).', 1),
('PAYROLL.AUDIT', 'Payroll - Audit Log', 'PAYROLL', 'Lihat audit payroll.', 1),
('PAYROLL.EXPORT_BANK', 'Payroll - Export Bank', 'PAYROLL', 'Export file pembayaran bank.', 1),
('PAYROLL.LOANS', 'Payroll - Pinjaman/Kasbon', 'PAYROLL', 'Kelola pinjaman/kasbon.', 1),
('PAYROLL.LOANS_DELETE', 'Payroll Loans - Delete', 'PAYROLL', 'Hapus data pinjaman/kasbon.', 1),
('PAYROLL.LOANS_EDIT', 'Payroll Loans - Edit', 'PAYROLL', 'Edit data pinjaman/kasbon.', 1),
('PAYROLL.MATRIX_MANAGE', 'Payroll - Master Golongan Gaji', 'PAYROLL', 'Import/edit salary matrix.', 1),
('PAYROLL.PAYSLIP_VIEW', 'Payroll - Payslip View', 'PAYROLL', 'Lihat/print payslip.', 1),
('PAYROLL.RUN_CREATE', 'Payroll - Generate Run', 'PAYROLL', 'Generate payroll run per periode.', 1),
('PAYROLL.RUN_DELETE', 'Payroll - Delete Run', 'PAYROLL', 'Hapus payroll run (high risk).', 1),
('PAYROLL.RUN_EDIT', 'Payroll - Edit Run Items', 'PAYROLL', 'Edit item run (tunj/potongan/lembur).', 1),
('PAYROLL.RUN_PAID', 'Payroll - Mark Paid', 'PAYROLL', 'Set run paid / final.', 1),
('PAYROLL.RUN_POST', 'Payroll - Post/Lock Run', 'PAYROLL', 'Lock/post payroll run (finalisasi).', 1),
('PAYROLL.SETTINGS', 'Payroll - Settings', 'PAYROLL', 'Konfigurasi payroll & mapping.', 1),
('PAYROLL.VIEW', 'Payroll - View', 'PAYROLL', 'Melihat dashboard payroll & history runs.', 1),
('PQP.DELETE', 'PQP - Delete', 'PQP', 'Hapus data quality PQP.', 1),
('PQP.EDIT', 'PQP - Edit', 'PQP', 'Edit data quality/produk PQP.', 1),
('PQP.QUALITY_CRUD', 'PQP Quality CRUD', 'PQP', 'Kelola data quality assurance.', 1),
('PQP.VIEW', 'PQP - View', 'PQP', 'Akses modul PQP (produk, quality, reg alkes).', 1),
('PURCHASES.ADMIN_GL_AUTO', 'GL Auto Posting', 'PURCHASES', 'Generate jurnal otomatis (high risk).', 1),
('PURCHASES.ADMIN_STOCK_UPDATE', 'Stock Update from GR', 'PURCHASES', 'Update stock dari GR (high risk).', 1),
('PURCHASES.AP_INVOICE_CRUD', 'Invoice AP CRUD', 'PURCHASES', 'Input/edit Invoice AP.', 1),
('PURCHASES.AP_INVOICE_DELETE', 'Invoice AP - Delete', 'PURCHASES', 'Hapus Invoice AP.', 1),
('PURCHASES.AP_INVOICE_EDIT', 'Invoice AP - Edit', 'PURCHASES', 'Edit Invoice AP.', 1),
('PURCHASES.AP_PAYMENT_CRUD', 'Payment AP CRUD', 'PURCHASES', 'Input/edit pembayaran AP.', 1),
('PURCHASES.AP_PAYMENT_DELETE', 'Payment AP - Delete', 'PURCHASES', 'Hapus pembayaran AP.', 1),
('PURCHASES.AP_PAYMENT_EDIT', 'Payment AP - Edit', 'PURCHASES', 'Edit pembayaran AP.', 1),
('PURCHASES.API_PR', 'Purchases PR API', 'PURCHASES', 'Endpoint API PR (internal).', 1),
('PURCHASES.APPROVE', 'Purchases - Approve', 'PURCHASES', 'Approve purchases/PO.', 1),
('PURCHASES.CEISA_EDIT', 'CEISA - Edit', 'PURCHASES', 'Edit dokumen CEISA/PIB.', 1),
('PURCHASES.CEISA_PIB', 'CEISA PIB', 'PURCHASES', 'Entry/view dokumen PIB/CEISA.', 1),
('PURCHASES.CEISA_VIEW', 'CEISA - View', 'PURCHASES', 'Lihat dokumen CEISA/PIB.', 1),
('PURCHASES.CREATE', 'Purchases - Create', 'PURCHASES', 'Membuat purchases/PO.', 1),
('PURCHASES.DELETE', 'Purchases - Delete', 'PURCHASES', 'Menghapus purchases/PO (manager only).', 1),
('PURCHASES.EDIT', 'Purchases - Edit', 'PURCHASES', 'Mengubah purchases/PO.', 1),
('PURCHASES.EXPORT', 'Purchases - Export', 'PURCHASES', 'Export laporan/data purchases.', 1),
('PURCHASES.FORWARDING_CRUD', 'Forwarding/Logistik CRUD', 'PURCHASES', 'Quotes, invoice forwarder, payment forwarder, tasks forwarding.', 1),
('PURCHASES.FORWARDING_DELETE', 'Forwarding - Delete', 'PURCHASES', 'Hapus data forwarder.', 1),
('PURCHASES.FORWARDING_EDIT', 'Forwarding - Edit', 'PURCHASES', 'Edit quotes/invoice/payment forwarder.', 1),
('PURCHASES.GR_DELETE', 'GR - Delete', 'PURCHASES', 'Hapus/batalkan GR.', 1),
('PURCHASES.GR_EDIT', 'GR - Edit', 'PURCHASES', 'Edit Goods Receipt.', 1),
('PURCHASES.GR_PROCESS', 'Goods Receipt (GR)', 'PURCHASES', 'Input/terima barang dari PO.', 1),
('PURCHASES.IMPORT_CONTROL', 'Import Control Tower', 'PURCHASES', 'Monitoring import & compliance (control tower).', 1),
('PURCHASES.IMPORT_CONTROL_EDIT', 'Import Control - Edit', 'PURCHASES', 'Edit import control tower.', 1),
('PURCHASES.PAYMENT_AP_EDIT', 'Payment AP - Edit', 'PURCHASES', 'Edit pembayaran AP.', 1),
('PURCHASES.PAYMENT_AP_VIEW', 'Payment AP - View', 'PURCHASES', 'Lihat pembayaran AP.', 1),
('PURCHASES.PO_APPROVE', 'PO Approve', 'PURCHASES', 'Approve/lock PO sebelum proses lanjut.', 1),
('PURCHASES.PO_CRUD', 'PO CRUD', 'PURCHASES', 'Create/edit/delete PO & detail item.', 1),
('PURCHASES.PO_DELETE', 'PO - Delete', 'PURCHASES', 'Hapus/batalkan PO (high risk).', 1),
('PURCHASES.PO_EDIT', 'PO - Edit', 'PURCHASES', 'Edit PO & detail item.', 1),
('PURCHASES.PO_PRINT', 'PO Print', 'PURCHASES', 'Print PO.', 1),
('PURCHASES.REPORTS_VIEW', 'Purchases Reports View', 'PURCHASES', 'Lihat laporan purchases.', 1),
('PURCHASES.VIEW', 'Purchases - View', 'PURCHASES', 'Melihat dashboard & daftar transaksi purchases.', 1),
('RBAC.ROLE_ASSIGN', 'RBAC Role Assign', 'RBAC', 'Assign role ke user/dept.', 1),
('RBAC.USER_PERMISSIONS', 'RBAC User Permissions', 'RBAC', 'Kelola override permission per user.', 1),
('RBAC.VIEW', 'RBAC - View', 'RBAC', 'Akses halaman RBAC Center (read-only).', 1),
('SALES.AUDIT_VIEW', 'Sales - Audit View', 'SALES', 'Lihat audit log DO & SLA KPI.', 1),
('SALES.CREATE', 'Sales - Create DO', 'SALES', 'Membuat DO / transaksi sales.', 1),
('SALES.DELETE', 'Sales - Delete/Cancel DO', 'SALES', 'Delete/cancel DO (high risk).', 1),
('SALES.EDIT', 'Sales - Edit DO', 'SALES', 'Edit data DO sebelum lock/approve.', 1),
('SALES.EXPORT', 'Sales - Export', 'SALES', 'Export data sales/KPI CSV.', 1),
('SALES.KPI_AUDIT', 'Sales KPI - Audit', 'SALES', 'Audit KPI sales/DO.', 1),
('SALES.KPI_VIEW', 'Sales KPI & SLA View', 'SALES', 'Lihat KPI DO, SLA, audit DO.', 1),
('SALES.PRINT', 'Sales - Print DO', 'SALES', 'Print CF/DO.', 1),
('SALES.TASK_ACT', 'Sales - ACT Tasks', 'SALES', 'Task DO tahap ACT (dokumen, invoice/tax file, amount).', 1),
('SALES.TASK_FIN', 'Sales - FIN Tasks', 'SALES', 'Task DO tahap Finance (payment, AR).', 1),
('SALES.TASK_SCM', 'Sales - SCM Tasks', 'SALES', 'Task DO tahap SCM (vendor/forwarding).', 1),
('SALES.TASK_WQS', 'Sales - WQS Tasks', 'SALES', 'Task DO tahap gudang (packing/picking/dispatch).', 1),
('SALES.TRACKING_VIEW', 'Sales Tracking - View', 'SALES', 'Lihat tracking DO/shipment.', 1),
('SALES.VIEW', 'Sales - View', 'SALES', 'Melihat dashboard, control tower, daftar & detail DO.', 1),
('SCM.BL.FINALIZE', 'SCM Upload/Finalize BL Final', 'SCM', 'SCM Upload/Finalize BL Final', 1),
('SCM.BL.UPLOAD_DRAFT', 'SCM Upload BL Draft', 'SCM', 'SCM Upload BL Draft', 1),
('SCM.BL.VIEW', 'SCM View Bill of Lading docs', 'SCM', 'SCM View Bill of Lading docs', 1),
('SCM.FWD.QUOTES.APPROVE', 'SCM Approve/Lock Forwarder Quote Selection', 'SCM', 'SCM Approve/Lock Forwarder Quote Selection', 1),
('SCM.FWD.QUOTES.EDIT', 'SCM Edit Forwarder Quotes', 'SCM', 'SCM Edit Forwarder Quotes', 1),
('SCM.FWD.QUOTES.VIEW', 'SCM View Forwarder Quotes', 'SCM', 'SCM View Forwarder Quotes', 1),
('SCM.PURCHASES.DOC.UPLOAD', 'SCM Upload Purchases Forwarder Docs (QUOTES/BL/TRACKING)', 'SCM', 'SCM Upload Purchases Forwarder Docs (QUOTES/BL/TRACKING)', 1),
('SCM.SHIPMENT.TRACK.UPDATE', 'SCM Update Shipment Tracking & Forwarder Fields', 'SCM', 'SCM Update Shipment Tracking & Forwarder Fields', 1),
('SCM.SHIPMENT.TRACK.VIEW', 'SCM View Shipment Tracking', 'SCM', 'SCM View Shipment Tracking', 1),
('STOCK.ADJUST', 'Stock - Adjustment', 'STOCK', 'Input penyesuaian stok (high impact).', 1),
('STOCK.AUDIT_VIEW', 'Stock - Audit View', 'STOCK', 'Lihat audit stok (movement log).', 1),
('STOCK.DELETE', 'Stock - Delete', 'STOCK', 'Hapus penyesuaian stok (high risk).', 1),
('STOCK.EDIT', 'Stock - Edit', 'STOCK', 'Edit penyesuaian stok.', 1),
('STOCK.VIEW', 'Stock - View', 'STOCK', 'Melihat stok (list/summary).', 1),
('SYS.AUDIT.VIEW', 'System: Audit View', 'SYS', 'Melihat audit log (read-only)', 1),
('SYSTEM.ACCOUNT_READINESS', 'Account Readiness', 'SYSTEM', 'Cek kesiapan akun (MFA, password, dll).', 1),
('SYSTEM.API_PARTNER_KEYS', 'API Partner Keys', 'SYSTEM', 'Kelola API key untuk partner eksternal.', 1),
('SYSTEM.CONFIG_MANAGE', 'System Config', 'SYSTEM', 'Global configuration keys (seed KPI, office code, feature toggles).', 1),
('SYSTEM.JOBS_MONITOR', 'Jobs Monitor', 'SYSTEM', 'Monitoring worker queue, retry/run-now/cancel job.', 1),
('SYSTEM.MFA_BYPASS_MANAGE', 'MFA Bypass Manage', 'SYSTEM', 'Kelola MFA bypass tickets (approve/reject/revoke).', 1),
('SYSTEM.MFA_POLICY_MANAGE', 'MFA Policy Manage', 'SYSTEM', 'Kelola policy MFA per role/department (wajib MFA).', 1),
('SYSTEM.RATE_LIMIT_MANAGE', 'Rate Limit Policies', 'SYSTEM', 'Konfigurasi threshold API write per scope.', 1),
('SYSTEM.RBAC_MANAGE', 'Manage Role & Permission', 'SYSTEM', 'Manage permission registry & Dept+Role matrix (RBAC Center).', 1),
('SYSTEM.SECURITY_VIEW', 'Security & Session Diagnostics', 'SYSTEM', 'View security/session info (read-only).', 1),
('SYSTEM.USER_MANAGE', 'Manage Users (Master System Login)', 'SYSTEM', 'Create/update user login, dept/role/office assignment; reset password; lock/unlock user.', 1),
('SYSTEM.VIEW', 'System - View', 'SYSTEM', 'Akses view halaman sistem (read-only).', 1),
('TOOLS.BACKUP_MANAGE', 'Tools Backup Manage', 'TOOLS', 'Backup, restore, schedule, retention.', 1),
('TOOLS.COMPLIANCE_EVIDENCE', 'Tools Compliance - Evidence', 'TOOLS', 'Lihat/upload evidence compliance.', 1),
('TOOLS.COMPLIANCE_VIEW', 'Tools Compliance - View', 'TOOLS', 'Lihat compliance.', 1),
('TOOLS.CONTRACT_VIEW', 'Tools Contract - View', 'TOOLS', 'Lihat kontrak/agreement.', 1),
('TOOLS.DR_BACKUP', 'Tools DR - Backup', 'TOOLS', 'Jalankan DR backup.', 1),
('TOOLS.DR_RESTORE', 'Tools DR - Restore', 'TOOLS', 'Jalankan DR restore.', 1),
('TOOLS.DR_VIEW', 'Tools DR - View', 'TOOLS', 'Lihat Disaster Recovery.', 1),
('TOOLS.ENTERPRISE_AUDIT_EXPORT', 'Tools - Enterprise Audit Export', 'TOOLS', 'Export audit report (CSV/JSON).', 1),
('TOOLS.ENTERPRISE_AUDIT_VIEW', 'Tools - Enterprise Audit View', 'TOOLS', 'Lihat hasil static scan audit keamanan/konsistensi.', 1),
('TOOLS.ITC_RESET_PASSWORD', 'Tools - ITC Reset Password', 'TOOLS', 'Reset password user (ITC support).', 1),
('TOOLS.OPS_CONTROL_CENTER', 'Tools Ops Control Center', 'TOOLS', 'Akses Ops Control Center.', 1),
('TOOLS.OPS_VIEW', 'Tools Ops - View', 'TOOLS', 'Lihat operasional/ops.', 1),
('TOOLS.PURCHASES_M2_APPLY', 'Tools - Apply Purchases M2 Patch', 'TOOLS', 'Jalankan patch/repair purchases (high risk).', 1),
('TOOLS.READINESS_AUDIT', 'Tools Readiness Audit', 'TOOLS', 'Audit kesiapan deploy & cutover.', 1),
('TOOLS.RELEASE_DEPLOY', 'Tools Release - Deploy', 'TOOLS', 'Jalankan deploy.', 1),
('TOOLS.RELEASE_VIEW', 'Tools Release - View', 'TOOLS', 'Lihat release/deploy.', 1),
('TOOLS.RESTORE_EDIT', 'Tools Restore - Edit', 'TOOLS', 'Jalankan restore.', 1),
('TOOLS.RESTORE_VIEW', 'Tools Restore - View', 'TOOLS', 'Lihat restore/backup.', 1),
('TOOLS.REVIEW_KIT', 'Tools Review Kit', 'TOOLS', 'Review kit workspace & signoff.', 1),
('TOOLS.RFC_APPROVE', 'Tools RFC - Approve', 'TOOLS', 'Approve Request for Change.', 1),
('TOOLS.RFC_VIEW', 'Tools RFC - View', 'TOOLS', 'Lihat Request for Change.', 1),
('TOOLS.SECURITY_AUDIT', 'Tools Security Audit', 'TOOLS', 'Static scan keamanan & konsistensi.', 1),
('TOOLS.VIEW', 'Tools - View', 'TOOLS', 'Akses menu Tools/Diagnostics.', 1),
('WEB_ADMIN.OPS_VIEW', 'Web Admin Ops - View', 'WEB_ADMIN', 'Lihat ops di Web Admin.', 1),
('WEB_ADMIN.RFC_APPROVE', 'Web Admin RFC - Approve', 'WEB_ADMIN', 'Approve RFC di Web Admin.', 1),
('WEB_ADMIN.RFC_VIEW', 'Web Admin RFC - View', 'WEB_ADMIN', 'Lihat RFC di Web Admin.', 1),
('WEB_ADMIN.THRESHOLDS_EDIT', 'Web Admin Thresholds - Edit', 'WEB_ADMIN', 'Edit thresholds di Web Admin.', 1),
('WEB_ADMIN.VIEW', 'Web Admin - View', 'WEB_ADMIN', 'Akses Web Admin.', 1),
('WF.OUTBOX.VIEW', 'Workflow: View Outbox', 'WF', 'Melihat antrian notifikasi/outbox', 1),
('WF.TASK.VIEW', 'Workflow: View Tasks', 'WF', 'Melihat inbox task workflow', 1),
('WQS.ALLOCATION', 'WQS Allocation', 'WQS', 'Alokasi stock untuk order/DO.', 1),
('WQS.ALLOCATION_DELETE', 'WQS Allocation - Delete', 'WQS', 'Hapus alokasi.', 1),
('WQS.ALLOCATION_EDIT', 'WQS Allocation - Edit', 'WQS', 'Edit alokasi.', 1),
('WQS.API_INCOMING_PO', 'WQS Incoming PO API', 'WQS', 'API untuk load item PO ke incoming.', 1),
('WQS.DO_TASKS', 'WQS DO Tasks (Sales)', 'WQS', 'Task DO yang dilakukan gudang (dari modul sales).', 1),
('WQS.INCOMING_CRUD', 'WQS Incoming CRUD', 'WQS', 'Penerimaan barang (incoming) + upload dokumen.', 1),
('WQS.INCOMING_DELETE', 'WQS Incoming - Delete', 'WQS', 'Hapus data incoming.', 1),
('WQS.INCOMING_EDIT', 'WQS Incoming - Edit', 'WQS', 'Edit data incoming.', 1),
('WQS.INCOMING_VIEW', 'WQS Incoming - View', 'WQS', 'Lihat data incoming.', 1),
('WQS.PICKING_CRUD', 'WQS Picking CRUD', 'WQS', 'Picking barang untuk DO/Order.', 1),
('WQS.PICKING_DELETE', 'WQS Picking - Delete', 'WQS', 'Hapus data picking.', 1),
('WQS.PICKING_EDIT', 'WQS Picking - Edit', 'WQS', 'Edit data picking.', 1),
('WQS.PICKING_VIEW', 'WQS Picking - View', 'WQS', 'Lihat data picking.', 1),
('WQS.PR_CRUD', 'WQS PR CRUD', 'WQS', 'Create & manage Purchase Request.', 1),
('WQS.PR_DELETE', 'WQS PR - Delete', 'WQS', 'Hapus Purchase Request.', 1),
('WQS.PR_EDIT', 'WQS PR - Edit', 'WQS', 'Edit Purchase Request.', 1),
('WQS.PR_PRINT', 'WQS PR Print', 'WQS', 'Print PR.', 1),
('WQS.TRANSFER_CRUD', 'WQS Transfer Antar Kantor', 'WQS', 'Transfer/mutasi stok antar kantor. Hanya Admin/SYS. Kantor gunakan Pembelian.', 1),
('WQS.VIEW', 'WQS - View', 'WQS', 'Akses view modul WQS.', 1);

-- --------------------------------------------------------

--
-- Struktur dari tabel `rbac_roles`
--

DROP TABLE IF EXISTS `rbac_roles`;
CREATE TABLE `rbac_roles` (
  `role_code` varchar(50) NOT NULL,
  `role_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `rbac_roles`
--

TRUNCATE TABLE `rbac_roles`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `rbac_user_permissions`
--

DROP TABLE IF EXISTS `rbac_user_permissions`;
CREATE TABLE `rbac_user_permissions` (
  `user_id` bigint(20) NOT NULL,
  `perm_code` varchar(80) NOT NULL,
  `allow_flag` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `rbac_user_permissions`
--

TRUNCATE TABLE `rbac_user_permissions`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `salary_matrix_2025_all`
--

DROP TABLE IF EXISTS `salary_matrix_2025_all`;
CREATE TABLE `salary_matrix_2025_all` (
  `COL 1` varchar(11) DEFAULT NULL,
  `COL 2` varchar(14) DEFAULT NULL,
  `COL 3` varchar(13) DEFAULT NULL,
  `COL 4` varchar(10) DEFAULT NULL,
  `COL 5` varchar(13) DEFAULT NULL,
  `COL 6` varchar(12) DEFAULT NULL,
  `COL 7` varchar(11) DEFAULT NULL,
  `COL 8` varchar(17) DEFAULT NULL,
  `COL 9` varchar(12) DEFAULT NULL,
  `COL 10` varchar(9) DEFAULT NULL,
  `COL 11` varchar(9) DEFAULT NULL,
  `COL 12` varchar(5) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `salary_matrix_2025_all`
--

TRUNCATE TABLE `salary_matrix_2025_all`;
--
-- Dumping data untuk tabel `salary_matrix_2025_all`
--

INSERT DELAYED IGNORE INTO `salary_matrix_2025_all` (`COL 1`, `COL 2`, `COL 3`, `COL 4`, `COL 5`, `COL 6`, `COL 7`, `COL 8`, `COL 9`, `COL 10`, `COL 11`, `COL 12`) VALUES
('matrix_year', 'payroll_status', 'payroll_level', 'job_title', 'take_home_pay', 'basic_salary', 'op_rate_day', 'work_days_default', 'tunj_jabatan', 'tunj_anak', 'transport', 'kuota'),
('2025', 'KONTRAK', '1A', 'Helper', '1800000', '750000', '50000', '21', '0', '0', '0', '0'),
('2025', 'KONTRAK', '1B', 'Helper', '2300000', '1250000', '50000', '21', '0', '0', '0', '0'),
('2025', 'KONTRAK', '1C', 'Helper', '3625000', '1500000', '75000', '21', '0', '0', '500000', '50000'),
('2025', 'KONTRAK', '2A', 'Staff', '4827211', '2437211', '90000', '21', '0', '0', '500000', '50000'),
('2025', 'KONTRAK', '2B', 'Staff', '5077211', '2687211', '90000', '21', '0', '0', '500000', '50000'),
('2025', 'KONTRAK', '2C', 'Staff', '5327211', '2937211', '90000', '21', '0', '0', '500000', '50000'),
('2025', 'KONTRAK', '3A', 'Supervisor', '6777211', '3187211', '90000', '21', '1200000', '0', '500000', '50000'),
('2025', 'KONTRAK', '3B', 'Supervisor', '6977211', '3187211', '90000', '21', '1400000', '0', '500000', '50000'),
('2025', 'KONTRAK', '3C', 'Supervisor', '7927211', '3187211', '90000', '21', '2350000', '0', '500000', '50000'),
('2025', 'KONTRAK', '4A', 'Manager', '8622211', '3637211', '85000', '21', '2700000', '0', '500000', '50000'),
('2025', 'KONTRAK', '4B', 'Manager', '8922211', '3637211', '85000', '21', '3000000', '0', '500000', '50000'),
('2025', 'KONTRAK', '4C', 'Manager', '9922211', '3637211', '85000', '21', '4000000', '0', '500000', '50000'),
('2025', 'KONTRAK', '5A', 'Direktur', '10472211', '4187211', '85000', '21', '2500000', '1500000', '500000', '50000'),
('2025', 'KONTRAK', '5B', 'Direktur', '10972211', '4187211', '85000', '21', '2750000', '1750000', '500000', '50000'),
('2025', 'KONTRAK', '5C', 'Direktur', '11472211', '4187211', '85000', '21', '3000000', '2000000', '500000', '50000'),
('2025', 'PROBATION', '1A', 'Helper', '1800000', '750000', '50000', '21', '0', '0', '0', '0'),
('2025', 'PROBATION', '1B', 'Helper', '2300000', '1250000', '50000', '21', '0', '0', '0', '0'),
('2025', 'PROBATION', '1C', 'Helper', '3625000', '1500000', '75000', '21', '0', '0', '500000', '50000'),
('2025', 'PROBATION', '2A', 'Staff', '4529541', '2349541', '80000', '21', '0', '0', '500000', '50000'),
('2025', 'PROBATION', '2B', 'Staff', '4779541', '2599541', '80000', '21', '0', '0', '500000', '50000'),
('2025', 'PROBATION', '2C', 'Staff', '5029541', '2849541', '80000', '21', '0', '0', '500000', '50000'),
('2025', 'PROBATION', '3A', 'Supervisor', '6479541', '3099541', '80000', '21', '1200000', '0', '500000', '50000'),
('2025', 'PROBATION', '3B', 'Supervisor', '6679541', '3099541', '80000', '21', '1400000', '0', '500000', '50000'),
('2025', 'PROBATION', '3C', 'Supervisor', '7629541', '3099541', '80000', '21', '2350000', '0', '500000', '50000'),
('2025', 'PROBATION', '4A', 'Manager', '8429541', '3549541', '80000', '21', '2700000', '0', '500000', '50000'),
('2025', 'PROBATION', '4B', 'Manager', '8729541', '3549541', '80000', '21', '3000000', '0', '500000', '50000'),
('2025', 'PROBATION', '4C', 'Manager', '9729541', '3549541', '80000', '21', '4000000', '0', '500000', '50000'),
('2025', 'PROBATION', '5A', 'Direktur', '10279541', '4099541', '80000', '21', '2500000', '1500000', '500000', '50000'),
('2025', 'PROBATION', '5B', 'Direktur', '10779541', '4099541', '80000', '21', '2750000', '1750000', '500000', '50000'),
('2025', 'PROBATION', '5C', 'Direktur', '11279541', '4099541', '80000', '21', '3000000', '2000000', '500000', '50000');

-- --------------------------------------------------------

--
-- Struktur dari tabel `salary_matrix_2025_kontrak`
--

DROP TABLE IF EXISTS `salary_matrix_2025_kontrak`;
CREATE TABLE `salary_matrix_2025_kontrak` (
  `COL 1` varchar(11) DEFAULT NULL,
  `COL 2` varchar(14) DEFAULT NULL,
  `COL 3` varchar(13) DEFAULT NULL,
  `COL 4` varchar(10) DEFAULT NULL,
  `COL 5` varchar(13) DEFAULT NULL,
  `COL 6` varchar(12) DEFAULT NULL,
  `COL 7` varchar(11) DEFAULT NULL,
  `COL 8` varchar(17) DEFAULT NULL,
  `COL 9` varchar(12) DEFAULT NULL,
  `COL 10` varchar(9) DEFAULT NULL,
  `COL 11` varchar(9) DEFAULT NULL,
  `COL 12` varchar(5) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `salary_matrix_2025_kontrak`
--

TRUNCATE TABLE `salary_matrix_2025_kontrak`;
--
-- Dumping data untuk tabel `salary_matrix_2025_kontrak`
--

INSERT DELAYED IGNORE INTO `salary_matrix_2025_kontrak` (`COL 1`, `COL 2`, `COL 3`, `COL 4`, `COL 5`, `COL 6`, `COL 7`, `COL 8`, `COL 9`, `COL 10`, `COL 11`, `COL 12`) VALUES
('matrix_year', 'payroll_status', 'payroll_level', 'job_title', 'take_home_pay', 'basic_salary', 'op_rate_day', 'work_days_default', 'tunj_jabatan', 'tunj_anak', 'transport', 'kuota'),
('2025', 'KONTRAK', '1A', 'Helper', '1800000', '750000', '50000', '21', '0', '0', '0', '0'),
('2025', 'KONTRAK', '1B', 'Helper', '2300000', '1250000', '50000', '21', '0', '0', '0', '0'),
('2025', 'KONTRAK', '1C', 'Helper', '3625000', '1500000', '75000', '21', '0', '0', '500000', '50000'),
('2025', 'KONTRAK', '2A', 'Staff', '4827211', '2437211', '90000', '21', '0', '0', '500000', '50000'),
('2025', 'KONTRAK', '2B', 'Staff', '5077211', '2687211', '90000', '21', '0', '0', '500000', '50000'),
('2025', 'KONTRAK', '2C', 'Staff', '5327211', '2937211', '90000', '21', '0', '0', '500000', '50000'),
('2025', 'KONTRAK', '3A', 'Supervisor', '6777211', '3187211', '90000', '21', '1200000', '0', '500000', '50000'),
('2025', 'KONTRAK', '3B', 'Supervisor', '6977211', '3187211', '90000', '21', '1400000', '0', '500000', '50000'),
('2025', 'KONTRAK', '3C', 'Supervisor', '7927211', '3187211', '90000', '21', '2350000', '0', '500000', '50000'),
('2025', 'KONTRAK', '4A', 'Manager', '8622211', '3637211', '85000', '21', '2700000', '0', '500000', '50000'),
('2025', 'KONTRAK', '4B', 'Manager', '8922211', '3637211', '85000', '21', '3000000', '0', '500000', '50000'),
('2025', 'KONTRAK', '4C', 'Manager', '9922211', '3637211', '85000', '21', '4000000', '0', '500000', '50000'),
('2025', 'KONTRAK', '5A', 'Direktur', '10472211', '4187211', '85000', '21', '2500000', '1500000', '500000', '50000'),
('2025', 'KONTRAK', '5B', 'Direktur', '10972211', '4187211', '85000', '21', '2750000', '1750000', '500000', '50000'),
('2025', 'KONTRAK', '5C', 'Direktur', '11472211', '4187211', '85000', '21', '3000000', '2000000', '500000', '50000');

-- --------------------------------------------------------

--
-- Struktur dari tabel `salary_matrix_2025_probation`
--

DROP TABLE IF EXISTS `salary_matrix_2025_probation`;
CREATE TABLE `salary_matrix_2025_probation` (
  `COL 1` varchar(11) DEFAULT NULL,
  `COL 2` varchar(14) DEFAULT NULL,
  `COL 3` varchar(13) DEFAULT NULL,
  `COL 4` varchar(10) DEFAULT NULL,
  `COL 5` varchar(13) DEFAULT NULL,
  `COL 6` varchar(12) DEFAULT NULL,
  `COL 7` varchar(11) DEFAULT NULL,
  `COL 8` varchar(17) DEFAULT NULL,
  `COL 9` varchar(12) DEFAULT NULL,
  `COL 10` varchar(9) DEFAULT NULL,
  `COL 11` varchar(9) DEFAULT NULL,
  `COL 12` varchar(5) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `salary_matrix_2025_probation`
--

TRUNCATE TABLE `salary_matrix_2025_probation`;
--
-- Dumping data untuk tabel `salary_matrix_2025_probation`
--

INSERT DELAYED IGNORE INTO `salary_matrix_2025_probation` (`COL 1`, `COL 2`, `COL 3`, `COL 4`, `COL 5`, `COL 6`, `COL 7`, `COL 8`, `COL 9`, `COL 10`, `COL 11`, `COL 12`) VALUES
('matrix_year', 'payroll_status', 'payroll_level', 'job_title', 'take_home_pay', 'basic_salary', 'op_rate_day', 'work_days_default', 'tunj_jabatan', 'tunj_anak', 'transport', 'kuota'),
('2025', 'PROBATION', '1A', 'Helper', '1800000', '750000', '50000', '21', '0', '0', '0', '0'),
('2025', 'PROBATION', '1B', 'Helper', '2300000', '1250000', '50000', '21', '0', '0', '0', '0'),
('2025', 'PROBATION', '1C', 'Helper', '3625000', '1500000', '75000', '21', '0', '0', '500000', '50000'),
('2025', 'PROBATION', '2A', 'Staff', '4529541', '2349541', '80000', '21', '0', '0', '500000', '50000'),
('2025', 'PROBATION', '2B', 'Staff', '4779541', '2599541', '80000', '21', '0', '0', '500000', '50000'),
('2025', 'PROBATION', '2C', 'Staff', '5029541', '2849541', '80000', '21', '0', '0', '500000', '50000'),
('2025', 'PROBATION', '3A', 'Supervisor', '6479541', '3099541', '80000', '21', '1200000', '0', '500000', '50000'),
('2025', 'PROBATION', '3B', 'Supervisor', '6679541', '3099541', '80000', '21', '1400000', '0', '500000', '50000'),
('2025', 'PROBATION', '3C', 'Supervisor', '7629541', '3099541', '80000', '21', '2350000', '0', '500000', '50000'),
('2025', 'PROBATION', '4A', 'Manager', '8429541', '3549541', '80000', '21', '2700000', '0', '500000', '50000'),
('2025', 'PROBATION', '4B', 'Manager', '8729541', '3549541', '80000', '21', '3000000', '0', '500000', '50000'),
('2025', 'PROBATION', '4C', 'Manager', '9729541', '3549541', '80000', '21', '4000000', '0', '500000', '50000'),
('2025', 'PROBATION', '5A', 'Direktur', '10279541', '4099541', '80000', '21', '2500000', '1500000', '500000', '50000'),
('2025', 'PROBATION', '5B', 'Direktur', '10779541', '4099541', '80000', '21', '2750000', '1750000', '500000', '50000'),
('2025', 'PROBATION', '5C', 'Direktur', '11279541', '4099541', '80000', '21', '3000000', '2000000', '500000', '50000');

-- --------------------------------------------------------

--
-- Struktur dari tabel `sales_do`
--

DROP TABLE IF EXISTS `sales_do`;
CREATE TABLE `sales_do` (
  `id` int(11) NOT NULL,
  `do_code` varchar(50) NOT NULL,
  `tracking_code` varchar(30) DEFAULT NULL,
  `carrier_provider` varchar(30) DEFAULT NULL,
  `carrier_tracking_no` varchar(100) DEFAULT NULL,
  `carrier_courier_code` varchar(50) DEFAULT NULL,
  `tracking_public_token` varchar(80) DEFAULT NULL,
  `tracking_last_sync_at` datetime DEFAULT NULL,
  `tracking_last_status` varchar(100) DEFAULT NULL,
  `tracking_last_payload_json` longtext DEFAULT NULL,
  `fallback_live_location_url` varchar(1000) DEFAULT NULL,
  `scm_live_lat` decimal(10,7) DEFAULT NULL,
  `scm_live_lng` decimal(10,7) DEFAULT NULL,
  `scm_live_accuracy_m` decimal(8,2) DEFAULT NULL,
  `scm_live_at` datetime DEFAULT NULL,
  `do_date` date NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customers_code` varchar(50) NOT NULL,
  `office_code` varchar(20) NOT NULL,
  `sales_emp_code` varchar(50) DEFAULT NULL,
  `shipping_address` text DEFAULT NULL,
  `customer_pic` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `crm_start_time` datetime DEFAULT current_timestamp(),
  `crm_end_time` datetime DEFAULT NULL,
  `status_wqs` varchar(20) NOT NULL DEFAULT 'pending',
  `wqs_note` text DEFAULT NULL,
  `wqs_updated_at` datetime DEFAULT NULL,
  `status_scm` varchar(20) NOT NULL DEFAULT 'pending',
  `scm_note` text DEFAULT NULL,
  `scm_updated_at` datetime DEFAULT NULL,
  `status_act` varchar(20) NOT NULL DEFAULT 'pending',
  `act_note` text DEFAULT NULL,
  `act_updated_at` datetime DEFAULT NULL,
  `status_fin` varchar(20) NOT NULL DEFAULT 'pending',
  `fin_due_date` date DEFAULT NULL,
  `fin_paid_date` date DEFAULT NULL,
  `fin_paid_amount` decimal(18,2) DEFAULT 0.00,
  `fin_note` text DEFAULT NULL,
  `fin_updated_at` datetime DEFAULT NULL,
  `wqs_status` varchar(20) NOT NULL DEFAULT 'pending',
  `scm_status` varchar(20) NOT NULL DEFAULT 'pending',
  `act_status` varchar(20) NOT NULL DEFAULT 'pending',
  `fin_status` varchar(20) NOT NULL DEFAULT 'pending',
  `note` text DEFAULT NULL,
  `total_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_code` varchar(20) DEFAULT NULL,
  `tax_included` tinyint(1) DEFAULT 0,
  `tax_rate_percent` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(18,2) DEFAULT 0.00,
  `grand_total` decimal(18,2) DEFAULT 0.00,
  `price_include_tax` tinyint(1) DEFAULT 0,
  `crm_status` varchar(20) NOT NULL DEFAULT 'crm_to_wqs',
  `flow_status` varchar(20) NOT NULL DEFAULT 'CRM',
  `crm_duration_seconds` int(11) DEFAULT NULL,
  `crm_started_at` datetime DEFAULT NULL,
  `crm_finished_at` datetime DEFAULT NULL,
  `is_price_include_tax` tinyint(1) DEFAULT 0,
  `crm_created_at` datetime DEFAULT NULL,
  `crm_start_at` datetime DEFAULT NULL,
  `crm_finish_at` datetime DEFAULT NULL,
  `crm_duration_sec` int(11) DEFAULT NULL,
  `wqs_picked_at` datetime DEFAULT NULL,
  `scm_delivered_at` datetime DEFAULT NULL,
  `act_invoiced_at` datetime DEFAULT NULL,
  `fin_paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `wqs_stock_before` varchar(255) DEFAULT NULL,
  `wqs_stock_after` varchar(255) DEFAULT NULL,
  `wqs_started_at` datetime DEFAULT NULL,
  `wqs_ready_at` datetime DEFAULT NULL,
  `scm_on_delivery_at` datetime DEFAULT NULL,
  `delivery_mode` varchar(20) DEFAULT NULL,
  `delivery_vendor_id` int(11) DEFAULT NULL,
  `act_due_date` date DEFAULT NULL,
  `act_amount` decimal(15,2) DEFAULT NULL,
  `act_ready_fin_at` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `last_updated_at` datetime DEFAULT NULL,
  `fin_payment_file` varchar(255) DEFAULT NULL,
  `source` varchar(30) DEFAULT 'crm' COMMENT 'crm|portal|api|marketplace',
  `portal_user_id` int(11) DEFAULT NULL COMMENT 'FK customer_portal_users.id'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `sales_do`
--

TRUNCATE TABLE `sales_do`;
--
-- Dumping data untuk tabel `sales_do`
--

INSERT DELAYED IGNORE INTO `sales_do` (`id`, `do_code`, `tracking_code`, `carrier_provider`, `carrier_tracking_no`, `carrier_courier_code`, `tracking_public_token`, `tracking_last_sync_at`, `tracking_last_status`, `tracking_last_payload_json`, `fallback_live_location_url`, `scm_live_lat`, `scm_live_lng`, `scm_live_accuracy_m`, `scm_live_at`, `do_date`, `customer_id`, `customers_code`, `office_code`, `sales_emp_code`, `shipping_address`, `customer_pic`, `customer_phone`, `status`, `crm_start_time`, `crm_end_time`, `status_wqs`, `wqs_note`, `wqs_updated_at`, `status_scm`, `scm_note`, `scm_updated_at`, `status_act`, `act_note`, `act_updated_at`, `status_fin`, `fin_due_date`, `fin_paid_date`, `fin_paid_amount`, `fin_note`, `fin_updated_at`, `wqs_status`, `scm_status`, `act_status`, `fin_status`, `note`, `total_amount`, `tax_code`, `tax_included`, `tax_rate_percent`, `tax_amount`, `grand_total`, `price_include_tax`, `crm_status`, `flow_status`, `crm_duration_seconds`, `crm_started_at`, `crm_finished_at`, `is_price_include_tax`, `crm_created_at`, `crm_start_at`, `crm_finish_at`, `crm_duration_sec`, `wqs_picked_at`, `scm_delivered_at`, `act_invoiced_at`, `fin_paid_at`, `created_at`, `updated_at`, `wqs_stock_before`, `wqs_stock_after`, `wqs_started_at`, `wqs_ready_at`, `scm_on_delivery_at`, `delivery_mode`, `delivery_vendor_id`, `act_due_date`, `act_amount`, `act_ready_fin_at`, `last_updated_by`, `last_updated_at`, `fin_payment_file`, `source`, `portal_user_id`) VALUES
(15, 'RMI-BGR-20251211-007', 'RMI-BGR-20251211-007', NULL, NULL, NULL, 't-v5q2pXPcoFB7FyxlWZP_yCIcXSJWQra-c-DsyZxTM4ct6z', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'fin_done', '2025-12-11 20:36:46', NULL, 'done', NULL, '2025-12-11 15:32:12', 'done', NULL, '2025-12-11 15:49:52', 'done', NULL, '2025-12-11 16:09:56', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 16:18:46', 'pending', 'pending', 'pending', 'pending', '', 75000.00, 'PPN11', 0, 11.00, 8250.00, 83250.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 13:43:15', NULL, NULL, NULL, NULL, '2025-12-11 13:43:15', NULL, '2025-12-11 13:53:15', '2025-12-11 13:43:15', '2026-03-08 22:39:13', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11 13:48:15', NULL, NULL, NULL, 'crm', NULL),
(16, 'RMI-BGR-20251211-008', 'RMI-BGR-20251211-008', NULL, NULL, NULL, '-dQ_ZzgE-HkIOf1zQ72_ONmUhVPzEWMgHw2XCsLNuA-_C2W8', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'fin_done', '2025-12-11 20:36:46', NULL, 'open', NULL, '2025-12-11 15:32:06', 'open', NULL, '2025-12-11 15:49:46', 'done', NULL, '2025-12-11 16:19:21', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 16:19:32', 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 13:44:54', NULL, NULL, NULL, NULL, '2025-12-11 13:44:54', NULL, '2025-12-11 13:54:54', '2025-12-11 13:44:54', '2026-03-08 22:39:13', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11 13:49:54', NULL, NULL, NULL, 'crm', NULL),
(17, 'RMI-BGR-251211-001', 'RMI-BGR-251211-001', NULL, NULL, NULL, 'DmYQb0aGDXVDDvLeFIiF1gZGrO-MrMZW2nUhErZhSl1Pa-JH', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'wqs_done', '2025-12-11 20:36:46', NULL, 'done', NULL, '2025-12-11 19:48:56', 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', 'Cito', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 19:47:56', '2025-12-11 19:45:56', '2025-12-11 19:47:56', 120, NULL, NULL, NULL, NULL, '2025-12-11 19:47:56', '2026-03-03 03:54:18', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'crm', NULL),
(18, 'RMI-BGR-251211-002', 'RMI-BGR-251211-002', NULL, NULL, NULL, 'D9bBXfjcdKkQgAty5mwU-SDcozAA4sehv4D9A7T9OnPEbjDD', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'wqs_processing', '2025-12-11 20:36:46', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 20:20:29', '2025-12-11 20:19:59', '2025-12-11 20:20:29', 30, NULL, NULL, NULL, NULL, '2025-12-11 20:20:29', '2026-03-06 21:08:26', NULL, NULL, '2026-03-06 21:08:26', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'WQS', '2026-03-06 21:08:26', NULL, 'crm', NULL),
(19, 'RMI-BGR-251211-003', 'RMI-BGR-251211-003', NULL, NULL, NULL, 'irYaU8uiKaEUXw3YN_sqIKkI8IRwi3BBQtD-Uz-hoGLweNTH', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'wqs_processing', '2025-12-11 20:39:43', '2025-12-11 20:39:43', 'pending', '', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'Open', 'pending', 'pending', 'pending', '', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 20:39:43', NULL, NULL, 0, NULL, NULL, NULL, NULL, '2025-12-11 20:39:43', '2026-03-08 00:27:26', NULL, NULL, '2026-03-06 14:52:23', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'WQS', '2026-03-08 00:27:26', NULL, 'crm', NULL),
(20, 'RMI-TGR-251211-001', 'RMI-TGR-251211-001', NULL, NULL, NULL, '2rWw3L7qiDqvYrE6n6VwSsa5rp1DBY6j5S3PyiWcPu3LG3mN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11', NULL, 'H042;RS HERMINA SERPONG;RS Swasta;Hermina;Kota Tan', 'TGR', NULL, '', '', '', 'fin_done', '2025-12-11 23:51:36', NULL, 'done', NULL, '2025-12-11 23:52:06', 'done', NULL, '2025-12-11 23:52:19', 'done', NULL, '2025-12-11 23:52:40', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 23:52:53', 'pending', 'pending', 'pending', 'pending', '', 0.00, 'PPN11', 0, 11.00, 0.00, 0.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 23:51:36', '2025-12-11 23:51:05', '2025-12-11 23:51:36', 31, NULL, '2025-12-11 23:51:36', NULL, '2025-12-12 00:01:36', '2025-12-11 23:51:36', '2026-03-08 22:39:13', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11 23:56:36', NULL, NULL, NULL, 'crm', NULL),
(21, 'RMI-BGR-260304-001', 'RMI-BGR-260304-001', 'BITESHIP', NULL, NULL, 'el9hHNpTKMhlk8bLhW5aXVW0UygQx6meI8VAGk7KODsErIWQ', '2026-03-04 15:33:17', 'fallback_only', '{\"error\":\"BITESHIP_API_KEY not configured.\"}', NULL, NULL, NULL, NULL, NULL, '2026-03-04', NULL, 'Hoo1', 'BGR', NULL, 'Jl. Kesehatan No.1\r\nDepok', 'rs@demo.local', '021123456', 'paid', '2026-03-04 14:58:28', NULL, 'pending', '', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, '2026-03-06', 0.00, '', NULL, 'Done', 'pending', 'pending', 'Done', '', 250000.00, '', 0, 0.00, 0.00, 250000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-04 14:58:28', '2026-03-04 14:57:09', '2026-03-04 14:58:28', 79, NULL, '2026-03-06 21:07:37', NULL, '2026-03-06 21:17:37', '2026-03-04 14:58:28', '2026-03-08 22:39:13', '/uploads/sales_wqs/image_1_20260304_150329_5fd434.png', '/uploads/sales_wqs/image_2_20260304_150329_6c1594.png', '2026-03-04 15:00:59', '2026-03-04 15:03:33', NULL, NULL, NULL, NULL, NULL, '2026-03-06 21:12:37', 'FIN', '2026-03-06 21:07:37', '/uploads/sales_fin/tessss_20260306_210737_8c5802.pdf', 'crm', NULL),
(22, 'RMI-BGR-260306-001', 'RMI-BGR-260306-001', 'BITESHIP', NULL, NULL, 'qf8s95Bip14CtqJApPDydGTXUowuw6crn-5upqG3xq2cswcp', '2026-03-06 15:00:31', 'fallback_only', '{\"error\":\"BITESHIP_API_KEY not configured.\"}', NULL, NULL, NULL, NULL, NULL, '2026-03-06', NULL, 'BDG-INT', 'BGR', NULL, '', '', '', 'paid', '2026-03-06 14:31:14', NULL, 'pending', '', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, '2026-04-07', 0.00, '', NULL, 'Done', 'pending', 'pending', 'Done', '', 100000.00, 'NONPPN', 0, 0.00, 0.00, 100000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-06 14:31:14', '2026-03-06 14:27:06', '2026-03-06 14:31:14', 248, NULL, '2026-03-06 20:59:49', NULL, '2026-03-06 21:09:49', '2026-03-06 14:31:14', '2026-03-08 22:39:13', '/uploads/sales_wqs/20250214_075606000_iOS_20260306_145304_f20401.jpg', '/uploads/sales_wqs/20250214_075606000_iOS_20260306_145304_9c61ce.jpg', '2026-03-06 14:52:08', '2026-03-06 14:53:09', NULL, NULL, NULL, NULL, NULL, '2026-03-06 21:04:49', 'FIN', '2026-03-06 20:59:49', '/uploads/sales_fin/tessss_20260306_205949_30bbc8.pdf', 'crm', NULL),
(23, 'RMI-KAL-260308-001', 'RMI-KAL-260308-001', NULL, NULL, NULL, 'QLR8gj0fWwvhWjSuAJEsBf4PrAm5zNxakqODME_rzA-iyRj-', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-08', NULL, 'KAL-INT', 'KAL', NULL, 'Samarinda', 'rs 1', '082222222', 'WQS_PICKED', '2026-03-08 00:18:00', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 300000.00, 'NONPPN', 0, 0.00, 0.00, 300000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-08 00:18:00', '2026-03-08 00:12:24', '2026-03-08 00:18:00', 336, NULL, NULL, NULL, NULL, '2026-03-08 00:18:00', '2026-03-08 00:24:43', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'crm', NULL),
(24, 'RMI-BGR-260308-001', 'RMI-BGR-260308-001', NULL, NULL, NULL, '1fn7Dgvr5ZwyuNfbi-VseaR_etMnCL5ZVm4emRR1uwQXhlso', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-08', NULL, 'HOO1', 'BGR', NULL, 'Jl. Kesehatan No.1', '', '', 'crm_to_wqs', '2026-03-08 00:45:09', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-08 00:45:09', '2026-03-08 00:45:09', '2026-03-08 00:45:09', 0, NULL, NULL, NULL, NULL, '2026-03-08 00:45:09', '2026-03-08 00:48:49', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'h2h', NULL),
(25, 'RMI-BGR-260308-002', 'RMI-BGR-260308-002', NULL, NULL, NULL, 'TOjDz_C_nQ_wh8mFhNwiIoIZaHtsxRpdcJy01-yIFEDNZYfN', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-08', NULL, 'HOO1', 'BGR', NULL, 'Jl. Kesehatan No.1', '', '', 'crm_to_wqs', '2026-03-08 00:45:09', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-08 00:45:09', '2026-03-08 00:45:09', '2026-03-08 00:45:09', 0, NULL, NULL, NULL, NULL, '2026-03-08 00:45:09', '2026-03-08 00:48:49', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'h2h', NULL),
(26, 'RMI-BGR-260308-003', 'RMI-BGR-260308-003', NULL, NULL, NULL, 'byaXuRBVItNfFk2f6Ic330EVcSBBZFLN31BdW2FDhi9nts_S', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-08', NULL, 'HOO1', 'BGR', NULL, 'Jl. Kesehatan No.1', '', '', 'crm_to_wqs', '2026-03-08 00:45:55', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-08 00:45:55', '2026-03-08 00:45:55', '2026-03-08 00:45:55', 0, NULL, NULL, NULL, NULL, '2026-03-08 00:45:55', '2026-03-08 00:48:49', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'h2h', NULL),
(27, 'RMI-BGR-260308-004', 'RMI-BGR-260308-004', NULL, NULL, NULL, 'o6jlI0CfbCcQXJsrPkL_NSBVKKsmJq0siGt3fieis-ipB9XI', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-08', NULL, 'HOO1', 'BGR', NULL, 'Jl. Kesehatan No.1', '', '', 'crm_to_wqs', '2026-03-08 00:45:55', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-08 00:45:55', '2026-03-08 00:45:55', '2026-03-08 00:45:55', 0, NULL, NULL, NULL, NULL, '2026-03-08 00:45:55', '2026-03-08 00:48:49', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'h2h', NULL),
(28, 'RMI-BGR-260308-005', 'RMI-BGR-260308-005', NULL, NULL, NULL, 'QX4XgAJX_PvYsNRwThF80cjp_xYBsORwkWpTPiryY0SIx3xZ', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-08', NULL, 'HOO1', 'BGR', NULL, 'Jl. Kesehatan No.1', '', '', 'crm_to_wqs', '2026-03-08 01:48:29', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-08 01:48:29', '2026-03-08 01:48:29', '2026-03-08 01:48:29', 0, NULL, NULL, NULL, NULL, '2026-03-08 01:48:29', '2026-03-08 02:41:37', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'h2h', NULL),
(29, 'RMI-BGR-260308-006', 'RMI-BGR-260308-006', NULL, NULL, NULL, '0vf00yDum5j4sFxPIqn7_TnRYJKZUs2cqxYYksCGnqWqOtli', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-08', NULL, 'HOO1', 'BGR', NULL, 'Jl. Kesehatan No.1', '', '', 'crm_to_wqs', '2026-03-08 01:48:29', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-08 01:48:29', '2026-03-08 01:48:29', '2026-03-08 01:48:29', 0, NULL, NULL, NULL, NULL, '2026-03-08 01:48:29', '2026-03-08 02:41:37', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'h2h', NULL),
(30, 'RMI-BGR-260308-007', 'RMI-BGR-260308-007', NULL, NULL, NULL, 'x7IsyUMXXm2CtZYnjQb-5zePLK5wHTIAcCeBnnSvFv3H_Eq6', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-03-08', NULL, 'HOO1', 'BGR', NULL, 'Jl. Kesehatan No.1', '', '', 'wqs_processing', '2026-03-08 02:20:47', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2026-03-08 02:20:47', '2026-03-08 02:20:47', '2026-03-08 02:20:47', 0, NULL, NULL, NULL, NULL, '2026-03-08 02:20:47', '2026-03-08 02:55:46', NULL, NULL, '2026-03-08 02:55:46', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'WQS', '2026-03-08 02:55:46', NULL, 'h2h', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `sales_do_audit`
--

DROP TABLE IF EXISTS `sales_do_audit`;
CREATE TABLE `sales_do_audit` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `do_id` int(11) NOT NULL,
  `status_from` varchar(50) DEFAULT NULL,
  `status_to` varchar(50) DEFAULT NULL,
  `actor_dept` varchar(50) DEFAULT NULL,
  `actor_name` varchar(100) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `sales_do_audit`
--

TRUNCATE TABLE `sales_do_audit`;
--
-- Dumping data untuk tabel `sales_do_audit`
--

INSERT DELAYED IGNORE INTO `sales_do_audit` (`id`, `do_id`, `status_from`, `status_to`, `actor_dept`, `actor_name`, `note`, `created_at`) VALUES
(1, 21, 'NEW', 'crm_to_wqs', 'CRM', 'admin', 'CREATE_DO', '2026-03-04 14:58:28'),
(2, 21, 'crm_to_wqs', 'wqs_processing', 'WQS', 'admin', '', '2026-03-04 15:00:59'),
(3, 21, 'wqs_processing', 'ready_scm', 'WQS', 'admin', '', '2026-03-04 15:03:33'),
(4, 22, 'NEW', 'crm_to_wqs', 'CRM', 'admin', 'CREATE_DO', '2026-03-06 14:31:14'),
(5, 22, 'crm_to_wqs', 'crm_to_wqs', 'CRM', 'admin', 'EDIT_DO', '2026-03-06 14:37:15'),
(6, 22, 'crm_to_wqs', 'wqs_processing', 'WQS', 'admin', '', '2026-03-06 14:52:08'),
(7, 19, 'crm_to_wqs', 'wqs_processing', 'WQS', 'admin', '', '2026-03-06 14:52:23'),
(8, 22, 'wqs_processing', 'ready_scm', 'WQS', 'admin', '', '2026-03-06 14:53:09'),
(9, 22, 'delivered', 'wait_payment', 'ACT', 'admin', '', '2026-03-06 16:13:30'),
(10, 22, 'wait_payment', 'paid', 'FIN', 'admin', '', '2026-03-06 20:59:49'),
(11, 21, 'delivered', 'wait_payment', 'ACT', 'admin', '', '2026-03-06 21:01:16'),
(12, 21, 'wait_payment', 'paid', 'FIN', 'admin', '', '2026-03-06 21:07:37'),
(13, 18, 'crm_to_wqs', 'wqs_processing', 'WQS', 'admin', '', '2026-03-06 21:08:26'),
(14, 23, 'NEW', 'crm_to_wqs', 'CRM', 'StaffBRANCH_KAL', 'CREATE_DO', '2026-03-08 00:18:00'),
(15, 24, 'NEW', 'crm_to_wqs', 'API', 'api_partner:Hermina Group UAT', 'CREATE_DO_H2H', '2026-03-08 00:45:09'),
(16, 25, 'NEW', 'crm_to_wqs', 'API', 'api_partner:Hermina Group UAT', 'CREATE_DO_H2H', '2026-03-08 00:45:09'),
(17, 26, 'NEW', 'crm_to_wqs', 'API', 'api_partner:Hermina Group UAT', 'CREATE_DO_H2H', '2026-03-08 00:45:55'),
(18, 27, 'NEW', 'crm_to_wqs', 'API', 'api_partner:Hermina Group UAT', 'CREATE_DO_H2H', '2026-03-08 00:45:55'),
(19, 28, 'NEW', 'crm_to_wqs', 'API', 'api_partner:Hermina Group UAT', 'CREATE_DO_H2H', '2026-03-08 01:48:29'),
(20, 29, 'NEW', 'crm_to_wqs', 'API', 'api_partner:Hermina Group UAT', 'CREATE_DO_H2H', '2026-03-08 01:48:30'),
(21, 30, 'NEW', 'crm_to_wqs', 'API', 'api_partner:Hermina Group UAT', 'CREATE_DO_H2H', '2026-03-08 02:20:47'),
(22, 30, 'crm_to_wqs', 'wqs_processing', 'WQS', 'admin', '', '2026-03-08 02:55:46');

-- --------------------------------------------------------

--
-- Struktur dari tabel `sales_do_items`
--

DROP TABLE IF EXISTS `sales_do_items`;
CREATE TABLE `sales_do_items` (
  `id` int(11) NOT NULL,
  `do_id` int(11) NOT NULL,
  `line_no` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `is_package` tinyint(1) DEFAULT 0,
  `show_package` tinyint(1) DEFAULT 0,
  `stock_at_crm` int(11) DEFAULT NULL,
  `show_package_items` tinyint(1) DEFAULT 0,
  `products_name` varchar(255) DEFAULT NULL,
  `qty` int(11) DEFAULT 0,
  `unit` varchar(20) DEFAULT NULL,
  `unit_price` decimal(18,2) DEFAULT 0.00,
  `disc_percent` decimal(5,2) DEFAULT 0.00,
  `exp_date` date DEFAULT NULL,
  `serial_lot` varchar(100) DEFAULT NULL,
  `subtotal` decimal(18,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `sales_do_items`
--

TRUNCATE TABLE `sales_do_items`;
--
-- Dumping data untuk tabel `sales_do_items`
--

INSERT DELAYED IGNORE INTO `sales_do_items` (`id`, `do_id`, `line_no`, `product_id`, `sku`, `barcode`, `is_package`, `show_package`, `stock_at_crm`, `show_package_items`, `products_name`, `qty`, `unit`, `unit_price`, `disc_percent`, `exp_date`, `serial_lot`, `subtotal`) VALUES
(5, 15, 1, 2, 'OBT-002', NULL, 0, 0, NULL, 0, 'Obat B', 1, 'unit', 75000.00, 0.00, NULL, NULL, 75000.00),
(6, 16, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, NULL, NULL, 50000.00),
(7, 17, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, NULL, NULL, 50000.00),
(8, 18, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, NULL, NULL, 50000.00),
(9, 19, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 50000.00, 0.00, '2025-12-14', '66666', 50000.00),
(10, 20, 1, 1, 'OBT-001', '8991234567890', 0, 0, 0, 0, 'Obat A', 1, 'unit', 0.00, 0.00, NULL, NULL, 0.00),
(11, 21, 1, 2, 'OBT-002', '', 0, 0, 10, 0, 'OBAT B', 2, 'unit', 75000.00, 0.00, '2026-03-04', '030426', 150000.00),
(12, 21, 2, 1, 'OBT-001', '8991234567890', 0, 0, 10, 0, 'OBAT A', 2, 'unit', 50000.00, 0.00, '2026-03-05', '121212', 100000.00),
(14, 22, 1, 1, 'OBT-001', '8991234567890', 0, 0, 10, 0, 'OBAT A', 2, 'unit', 50000.00, 0.00, NULL, NULL, 100000.00),
(15, 23, 1, 3, 'ALK-001', '', 0, 0, 10, 0, 'ALKES A', 2, 'unit', 150000.00, 0.00, '2026-03-19', 'lo1', 300000.00),
(16, 24, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'pcs', 50000.00, 0.00, NULL, NULL, 50000.00),
(17, 25, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'pcs', 50000.00, 0.00, NULL, NULL, 50000.00),
(18, 26, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'pcs', 50000.00, 0.00, NULL, NULL, 50000.00),
(19, 27, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'pcs', 50000.00, 0.00, NULL, NULL, 50000.00),
(20, 28, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'pcs', 50000.00, 0.00, NULL, NULL, 50000.00),
(21, 29, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'pcs', 50000.00, 0.00, NULL, NULL, 50000.00),
(22, 30, 1, 1, 'OBT-001', '8991234567890', 0, 0, NULL, 0, 'Obat A', 1, 'pcs', 50000.00, 0.00, NULL, NULL, 50000.00);

-- --------------------------------------------------------

--
-- Struktur dari tabel `sales_do_tracking_events`
--

DROP TABLE IF EXISTS `sales_do_tracking_events`;
CREATE TABLE `sales_do_tracking_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `do_id` int(11) NOT NULL,
  `event_time` datetime NOT NULL,
  `provider_status` varchar(100) DEFAULT NULL,
  `internal_status` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `raw_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `sales_do_tracking_events`
--

TRUNCATE TABLE `sales_do_tracking_events`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `sales_marketplace_staging`
--

DROP TABLE IF EXISTS `sales_marketplace_staging`;
CREATE TABLE `sales_marketplace_staging` (
  `id` int(10) UNSIGNED NOT NULL,
  `inbox_id` int(10) UNSIGNED DEFAULT NULL,
  `external_order_id` varchar(120) NOT NULL,
  `source` varchar(60) NOT NULL DEFAULT 'marketplace',
  `customer_name` varchar(255) DEFAULT NULL,
  `customer_phone` varchar(80) DEFAULT NULL,
  `total_amount` decimal(18,2) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'NEW',
  `payload_json` longtext DEFAULT NULL,
  `sales_do_id` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `sales_marketplace_staging`
--

TRUNCATE TABLE `sales_marketplace_staging`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `stg_coa`
--

DROP TABLE IF EXISTS `stg_coa`;
CREATE TABLE `stg_coa` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `source_system` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `account_code` varchar(40) NOT NULL,
  `account_name` varchar(255) NOT NULL,
  `account_type` varchar(30) NOT NULL,
  `parent_code` varchar(40) DEFAULT NULL,
  `is_postable` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(20) NOT NULL DEFAULT 'ACTIVE',
  `currency` varchar(10) DEFAULT NULL,
  `raw_payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `stg_coa`
--

TRUNCATE TABLE `stg_coa`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `stg_documents`
--

DROP TABLE IF EXISTS `stg_documents`;
CREATE TABLE `stg_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `source_system` varchar(30) NOT NULL,
  `doc_type` enum('SALES_INVOICE','SALES_PAYMENT','PURCHASE_INVOICE','PURCHASE_PAYMENT','GENERAL_JOURNAL','INVENTORY_ADJUSTMENT') NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `doc_no` varchar(120) NOT NULL,
  `doc_date` date NOT NULL,
  `party_code` varchar(80) DEFAULT NULL,
  `warehouse_code` varchar(50) DEFAULT NULL,
  `item_code` varchar(80) DEFAULT NULL,
  `account_code` varchar(40) DEFAULT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `status` varchar(30) DEFAULT NULL,
  `raw_payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `stg_documents`
--

TRUNCATE TABLE `stg_documents`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `stg_items`
--

DROP TABLE IF EXISTS `stg_items`;
CREATE TABLE `stg_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `source_system` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `item_code` varchar(80) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `unit_code` varchar(50) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `price` decimal(18,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `raw_payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `stg_items`
--

TRUNCATE TABLE `stg_items`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `stg_opening_balances`
--

DROP TABLE IF EXISTS `stg_opening_balances`;
CREATE TABLE `stg_opening_balances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `source_system` varchar(30) NOT NULL,
  `entity_type` enum('GL','AR','AP','STOCK','BANK') NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `balance_date` date NOT NULL,
  `ref_code` varchar(120) DEFAULT NULL,
  `account_code` varchar(40) DEFAULT NULL,
  `party_code` varchar(80) DEFAULT NULL,
  `item_code` varchar(80) DEFAULT NULL,
  `warehouse_code` varchar(50) DEFAULT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT 0.0000,
  `amount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `raw_payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `stg_opening_balances`
--

TRUNCATE TABLE `stg_opening_balances`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `stg_parties`
--

DROP TABLE IF EXISTS `stg_parties`;
CREATE TABLE `stg_parties` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `source_system` varchar(30) NOT NULL,
  `entity_type` enum('CUSTOMER','VENDOR') NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `party_code` varchar(80) NOT NULL,
  `party_name` varchar(255) NOT NULL,
  `npwp` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `raw_payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `stg_parties`
--

TRUNCATE TABLE `stg_parties`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `stg_tax_codes`
--

DROP TABLE IF EXISTS `stg_tax_codes`;
CREATE TABLE `stg_tax_codes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `source_system` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `tax_code` varchar(40) NOT NULL,
  `tax_name` varchar(255) NOT NULL,
  `tax_type` varchar(30) NOT NULL DEFAULT 'PPN',
  `rate_percent` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `raw_payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `stg_tax_codes`
--

TRUNCATE TABLE `stg_tax_codes`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `stg_units`
--

DROP TABLE IF EXISTS `stg_units`;
CREATE TABLE `stg_units` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `source_system` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `unit_code` varchar(50) NOT NULL,
  `unit_name` varchar(120) NOT NULL,
  `raw_payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `stg_units`
--

TRUNCATE TABLE `stg_units`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `stg_warehouses`
--

DROP TABLE IF EXISTS `stg_warehouses`;
CREATE TABLE `stg_warehouses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `source_system` varchar(30) NOT NULL,
  `source_id` varchar(120) NOT NULL,
  `source_key` varchar(190) NOT NULL,
  `warehouse_code` varchar(50) NOT NULL,
  `warehouse_name` varchar(120) NOT NULL,
  `city` varchar(120) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `raw_payload_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `stg_warehouses`
--

TRUNCATE TABLE `stg_warehouses`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `system_audit_logs`
--

DROP TABLE IF EXISTS `system_audit_logs`;
CREATE TABLE `system_audit_logs` (
  `id` int(11) NOT NULL,
  `module` varchar(100) NOT NULL,
  `action` varchar(50) NOT NULL,
  `record_table` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `record_code` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `details` longtext DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `level` varchar(50) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `system_audit_logs`
--

TRUNCATE TABLE `system_audit_logs`;
--
-- Dumping data untuk tabel `system_audit_logs`
--

INSERT DELAYED IGNORE INTO `system_audit_logs` (`id`, `module`, `action`, `record_table`, `record_id`, `record_code`, `description`, `details`, `user_id`, `username`, `role`, `level`, `ip`, `user_agent`, `created_at`) VALUES
(1, 'master_manufactures', 'insert', 'master_manufactures', 1, 'YAXIN', 'Insert manufacture', '{\"manufacture_code\":\"YAXIN\",\"manufacture_name\":\"Suzhou Yaxin Medical Products Co., Ltd\"}', 2, 'admin', 'admin', 'ADMIN', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Safari/605.1.15', '2026-01-02 01:00:42'),
(2, 'master_manufactures', 'insert', 'master_manufactures', 11, 'NANCHANG', 'Insert manufacture', '{\"manufacture_code\":\"NANCHANG\",\"manufacture_name\":\"Nanchang Kanghua Health Materials Co., Ltd\"}', 35, 'MgrPQP_BGR', 'MANAGER', 'MANAGER', '172.71.124.225', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-06 07:37:01'),
(3, 'master_manufactures', 'insert', 'master_manufactures', 12, 'CATHAY', 'Insert manufacture', '{\"manufacture_code\":\"CATHAY\",\"manufacture_name\":\"Cathay Menufacturing Corp\"}', 35, 'MgrPQP_BGR', 'MANAGER', 'MANAGER', '104.23.175.178', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-06 07:58:44'),
(4, 'master_manufactures', 'insert', 'master_manufactures', 13, 'EXCELLENTCARE', 'Insert manufacture', '{\"manufacture_code\":\"EXCELLENTCARE\",\"manufacture_name\":\"EXCELLENTCARE MEDICAL (HUIZHOU) LTD\"}', 35, 'MgrPQP_BGR', 'MANAGER', 'MANAGER', '172.68.164.147', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-06 09:08:02'),
(5, 'master_manufactures', 'update', 'master_manufactures', 12, 'CATHAY', 'Update manufacture', '{\"manufacture_code\":\"CATHAY\",\"manufacture_name\":\"CATHAY MANUFACTURING CORP\"}', 35, 'MgrPQP_BGR', 'MANAGER', 'MANAGER', '162.158.218.146', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-06 09:52:54'),
(6, 'master_manufactures', 'insert', 'master_manufactures', 14, 'CELECARE', 'Insert manufacture', '{\"manufacture_code\":\"CELECARE\",\"manufacture_name\":\"WENZHOU CELECARE MEDICAL INSTRUMENTS CO., LTD\"}', 35, 'MgrPQP_BGR', 'MANAGER', 'MANAGER', '162.158.218.146', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-06 09:57:56'),
(7, 'master_manufactures', 'insert', 'master_manufactures', 15, 'MEDPLUS', 'Insert manufacture', '{\"manufacture_code\":\"MEDPLUS\",\"manufacture_name\":\"MEDPLUS INC\"}', 35, 'MgrPQP_BGR', 'MANAGER', 'MANAGER', '172.70.208.138', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36 Edg/145.0.0.0', '2026-03-06 10:22:24');

-- --------------------------------------------------------

--
-- Struktur dari tabel `system_config`
--

DROP TABLE IF EXISTS `system_config`;
CREATE TABLE `system_config` (
  `id` int(11) NOT NULL,
  `config_group` varchar(50) DEFAULT 'general',
  `config_key` varchar(100) NOT NULL,
  `config_value` text DEFAULT NULL,
  `office_code` varchar(10) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `system_config`
--

TRUNCATE TABLE `system_config`;
--
-- Dumping data untuk tabel `system_config`
--

INSERT DELAYED IGNORE INTO `system_config` (`id`, `config_group`, `config_key`, `config_value`, `office_code`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
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
(33, 'KPI_FA_POLICY', 'SALVAGE_DEFAULT_PERCENT', '0', NULL, 'Default salvage value percent (GLOBAL).', 1, '2026-01-01 22:01:14', '2026-01-01 22:01:14'),
(34, 'SALES_TRACKING', 'PROVIDER_DEFAULT', 'BITESHIP', NULL, 'Default provider tracking SCM', 1, '2026-02-25 20:28:52', '2026-02-25 20:28:52'),
(35, 'SALES_TRACKING', 'BITESHIP_TRACKING_ENDPOINT', '', NULL, 'Endpoint template. Use {tracking_no} and optional {courier_code}.', 1, '2026-02-25 20:28:52', '2026-02-25 20:28:52'),
(36, 'SALES_TRACKING', 'BITESHIP_API_KEY', '', NULL, 'Optional API key fallback (prefer ENV BITESHIP_API_KEY).', 0, '2026-02-25 20:28:53', '2026-02-25 20:28:53'),
(37, 'SALES_TRACKING', 'ONLINE_SYNC_ENABLED', '0', NULL, '1=enable scheduled sync to provider', 1, '2026-02-25 20:28:53', '2026-02-25 20:28:53'),
(38, 'SALES_TRACKING', 'PUBLIC_BASE_URL', '', NULL, 'Optional public base URL override for customer tracking link', 1, '2026-02-25 20:28:53', '2026-02-25 20:28:53'),
(39, 'TAX_INVOICE', 'REQUIRE_ISSUED_BEFORE_FIN_PAID', '1', NULL, '1=true, FIN paid requires tax invoice ISSUED', 1, '2026-03-01 13:38:02', '2026-03-01 13:38:02'),
(40, 'SALES_TRACKING', 'BITESHIP_TIMEOUT_SECONDS', '8', NULL, 'Timeout per request to Biteship API in seconds', 1, '2026-03-01 13:38:28', '2026-03-01 13:38:28'),
(41, 'SALES_TRACKING', 'CRON_SYNC_LIMIT', '50', NULL, 'Default row limit per scheduled sync run', 1, '2026-03-01 13:38:28', '2026-03-01 13:38:28');

-- --------------------------------------------------------

--
-- Struktur dari tabel `tax_invoices`
--

DROP TABLE IF EXISTS `tax_invoices`;
CREATE TABLE `tax_invoices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `sales_invoice_ref` varchar(120) NOT NULL,
  `tax_no` varchar(80) NOT NULL,
  `tax_date` date NOT NULL,
  `status` enum('DRAFT','ISSUED','REVISED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `file_path` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `tax_invoices`
--

TRUNCATE TABLE `tax_invoices`;
--
-- Dumping data untuk tabel `tax_invoices`
--

INSERT DELAYED IGNORE INTO `tax_invoices` (`id`, `sales_invoice_ref`, `tax_no`, `tax_date`, `status`, `file_path`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'RMI-BGR-260306-001', 'TAX-202603-00001', '2026-03-06', 'ISSUED', '/uploads/tax_invoices/tax_20260306_165438_1b3cb5.pdf', 2, '2026-03-06 16:49:06', '2026-03-06 17:55:33'),
(2, 'RMI-BGR-260306-001', 'TAX-202603-00002', '2026-03-06', 'CANCELLED', NULL, 2, '2026-03-06 17:00:27', '2026-03-06 17:46:16'),
(3, 'RMI-BGR-260306-001', 'TAX-202603-00003', '2026-03-06', 'CANCELLED', NULL, 2, '2026-03-06 17:01:39', '2026-03-06 17:46:17'),
(4, 'RMI-BGR-260306-001', 'TAX-202603-00004', '2026-03-06', 'REVISED', NULL, 2, '2026-03-06 17:45:56', '2026-03-06 17:54:02'),
(5, 'RMI-BGR-260304-001', 'TAX-202603-00005', '2026-03-06', 'ISSUED', NULL, 2, '2026-03-06 21:02:39', '2026-03-06 21:06:16');

-- --------------------------------------------------------

--
-- Struktur dari tabel `tax_invoice_logs`
--

DROP TABLE IF EXISTS `tax_invoice_logs`;
CREATE TABLE `tax_invoice_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tax_invoice_id` bigint(20) UNSIGNED NOT NULL,
  `action_name` varchar(60) NOT NULL,
  `action_by` bigint(20) DEFAULT NULL,
  `action_at` datetime NOT NULL DEFAULT current_timestamp(),
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `tax_invoice_logs`
--

TRUNCATE TABLE `tax_invoice_logs`;
--
-- Dumping data untuk tabel `tax_invoice_logs`
--

INSERT DELAYED IGNORE INTO `tax_invoice_logs` (`id`, `tax_invoice_id`, `action_name`, `action_by`, `action_at`, `note`) VALUES
(1, 1, 'CREATE_DRAFT', 2, '2026-03-06 16:49:06', 'Draft created'),
(2, 1, 'UPLOAD_FILE', 2, '2026-03-06 16:54:38', '/uploads/tax_invoices/tax_20260306_165438_1b3cb5.pdf'),
(3, 1, 'SET_STATUS_DRAFT', 2, '2026-03-06 16:56:11', NULL),
(4, 1, 'SET_STATUS_ISSUED', 2, '2026-03-06 16:56:37', NULL),
(5, 1, 'SET_STATUS_ISSUED', 2, '2026-03-06 16:57:05', NULL),
(6, 1, 'SET_STATUS_DRAFT', 2, '2026-03-06 16:57:53', NULL),
(7, 1, 'SET_STATUS_ISSUED', 2, '2026-03-06 16:57:55', NULL),
(8, 1, 'SET_STATUS_REVISED', 2, '2026-03-06 16:57:58', NULL),
(9, 2, 'CREATE_DRAFT', 2, '2026-03-06 17:00:27', 'Draft created'),
(10, 3, 'CREATE_DRAFT', 2, '2026-03-06 17:01:39', 'Draft created'),
(11, 4, 'CREATE_DRAFT', 2, '2026-03-06 17:45:56', 'Draft created'),
(12, 1, 'SET_STATUS_CANCELLED', 2, '2026-03-06 17:46:12', NULL),
(13, 2, 'SET_STATUS_CANCELLED', 2, '2026-03-06 17:46:16', NULL),
(14, 3, 'SET_STATUS_CANCELLED', 2, '2026-03-06 17:46:17', NULL),
(15, 4, 'SET_STATUS_REVISED', 2, '2026-03-06 17:46:24', NULL),
(16, 4, 'SET_STATUS_ISSUED', 2, '2026-03-06 17:46:27', NULL),
(17, 4, 'SET_STATUS_REVISED', 2, '2026-03-06 17:46:29', NULL),
(18, 4, 'SET_STATUS_REVISED', 2, '2026-03-06 17:54:02', NULL),
(19, 1, 'SET_STATUS_ISSUED', 2, '2026-03-06 17:55:33', NULL),
(20, 5, 'CREATE_DRAFT', 2, '2026-03-06 21:02:39', 'Draft created'),
(21, 5, 'SET_STATUS_ISSUED', 2, '2026-03-06 21:03:45', NULL),
(22, 5, 'SET_STATUS_ISSUED', 2, '2026-03-06 21:03:47', NULL),
(23, 5, 'SET_STATUS_ISSUED', 2, '2026-03-06 21:03:49', NULL),
(24, 5, 'SET_STATUS_ISSUED', 2, '2026-03-06 21:04:23', NULL),
(25, 5, 'SET_STATUS_ISSUED', 2, '2026-03-06 21:05:39', NULL),
(26, 5, 'SET_STATUS_ISSUED', 2, '2026-03-06 21:05:45', NULL),
(27, 5, 'SET_STATUS_REVISED', 2, '2026-03-06 21:05:55', NULL),
(28, 5, 'SET_STATUS_CANCELLED', 2, '2026-03-06 21:06:02', NULL),
(29, 5, 'SET_STATUS_CANCELLED', 2, '2026-03-06 21:06:04', NULL),
(30, 5, 'SET_STATUS_ISSUED', 2, '2026-03-06 21:06:16', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `tax_profiles`
--

DROP TABLE IF EXISTS `tax_profiles`;
CREATE TABLE `tax_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `company_name` varchar(200) NOT NULL,
  `company_npwp` varchar(64) NOT NULL,
  `address` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `tax_profiles`
--

TRUNCATE TABLE `tax_profiles`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_allocations`
--

DROP TABLE IF EXISTS `wqs_allocations`;
CREATE TABLE `wqs_allocations` (
  `id` int(11) NOT NULL,
  `incoming_id` int(11) NOT NULL,
  `incoming_item_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `lot_number` varchar(80) DEFAULT NULL,
  `serial_number` varchar(80) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `depo_name` varchar(60) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `note` varchar(255) DEFAULT NULL,
  `allocated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_allocations`
--

TRUNCATE TABLE `wqs_allocations`;
--
-- Dumping data untuk tabel `wqs_allocations`
--

INSERT DELAYED IGNORE INTO `wqs_allocations` (`id`, `incoming_id`, `incoming_item_id`, `product_id`, `sku`, `lot_number`, `serial_number`, `exp_date`, `office_code`, `depo_name`, `qty`, `note`, `allocated_at`) VALUES
(1, 2, 2, 3, 'ALK-001', NULL, NULL, NULL, 'BGR', 'BGR', 1, NULL, '2026-03-05 22:03:08');

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_incoming`
--

DROP TABLE IF EXISTS `wqs_incoming`;
CREATE TABLE `wqs_incoming` (
  `id` int(11) NOT NULL,
  `incoming_code` varchar(50) NOT NULL,
  `received_date` date NOT NULL,
  `po_id` int(11) DEFAULT NULL,
  `po_code` varchar(60) DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `depo_name` varchar(60) DEFAULT NULL,
  `ref_note` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `wqs_stock_before` varchar(255) DEFAULT NULL,
  `wqs_stock_after` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_incoming`
--

TRUNCATE TABLE `wqs_incoming`;
--
-- Dumping data untuk tabel `wqs_incoming`
--

INSERT DELAYED IGNORE INTO `wqs_incoming` (`id`, `incoming_code`, `received_date`, `po_id`, `po_code`, `office_code`, `depo_name`, `ref_note`, `deleted_at`, `created_at`, `wqs_stock_before`, `wqs_stock_after`) VALUES
(1, 'INC-20260305-0001', '2026-03-05', 1, 'RMI-PO-BGR-260101-001', 'BGR', 'BGR', NULL, NULL, '2026-03-05 21:56:02', NULL, NULL),
(2, 'INC-20260305-0002', '2026-03-05', 1, 'RMI-PO-BGR-260101-001', 'BGR', 'BGR', NULL, NULL, '2026-03-05 22:02:31', NULL, NULL),
(3, 'INC-20260305-0003', '2026-03-05', 1, 'RMI-PO-BGR-260101-001', 'BGR', 'BGR', NULL, NULL, '2026-03-05 22:03:42', NULL, NULL),
(4, 'INC-20260305-0004', '2026-03-05', 1, 'RMI-PO-BGR-260101-001', 'BGR', 'BGR', NULL, NULL, '2026-03-05 22:03:57', NULL, NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_incoming_items`
--

DROP TABLE IF EXISTS `wqs_incoming_items`;
CREATE TABLE `wqs_incoming_items` (
  `id` int(11) NOT NULL,
  `incoming_id` int(11) NOT NULL,
  `po_item_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `lot_number` varchar(80) DEFAULT NULL,
  `serial_number` varchar(80) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_incoming_items`
--

TRUNCATE TABLE `wqs_incoming_items`;
--
-- Dumping data untuk tabel `wqs_incoming_items`
--

INSERT DELAYED IGNORE INTO `wqs_incoming_items` (`id`, `incoming_id`, `po_item_id`, `product_id`, `sku`, `lot_number`, `serial_number`, `exp_date`, `qty`, `created_at`) VALUES
(1, 1, 1, 3, 'ALK-001', NULL, NULL, NULL, 1, '2026-03-05 21:56:02'),
(2, 2, 1, 3, 'ALK-001', NULL, NULL, NULL, 1, '2026-03-05 22:02:31'),
(3, 3, 2, 1, 'OBT-001', NULL, NULL, NULL, 1, '2026-03-05 22:03:42'),
(4, 4, 2, 1, 'OBT-001', NULL, NULL, NULL, 1, '2026-03-05 22:03:57');

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_picking`
--

DROP TABLE IF EXISTS `wqs_picking`;
CREATE TABLE `wqs_picking` (
  `id` int(11) NOT NULL,
  `do_code` varchar(60) NOT NULL,
  `do_id` int(11) DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `depo_name` varchar(60) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `picked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_picking`
--

TRUNCATE TABLE `wqs_picking`;
--
-- Dumping data untuk tabel `wqs_picking`
--

INSERT DELAYED IGNORE INTO `wqs_picking` (`id`, `do_code`, `do_id`, `office_code`, `depo_name`, `note`, `picked_at`, `created_at`) VALUES
(1, 'RMI-KAL-260308-001', 23, 'KAL', NULL, NULL, '2026-03-08 00:24:43', '2026-03-08 00:24:43');

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_picking_items`
--

DROP TABLE IF EXISTS `wqs_picking_items`;
CREATE TABLE `wqs_picking_items` (
  `id` int(11) NOT NULL,
  `picking_id` int(11) NOT NULL,
  `allocation_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `lot_number` varchar(80) DEFAULT NULL,
  `serial_number` varchar(80) DEFAULT NULL,
  `exp_date` date DEFAULT NULL,
  `office_code` varchar(30) DEFAULT NULL,
  `depo_name` varchar(60) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_picking_items`
--

TRUNCATE TABLE `wqs_picking_items`;
--
-- Dumping data untuk tabel `wqs_picking_items`
--

INSERT DELAYED IGNORE INTO `wqs_picking_items` (`id`, `picking_id`, `allocation_id`, `product_id`, `sku`, `qty`, `lot_number`, `serial_number`, `exp_date`, `office_code`, `depo_name`, `created_at`) VALUES
(1, 1, 1, 3, 'ALK-001', 1, NULL, NULL, NULL, 'BGR', 'BGR', '2026-03-08 00:24:43');

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_pr`
--

DROP TABLE IF EXISTS `wqs_pr`;
CREATE TABLE `wqs_pr` (
  `id` int(11) NOT NULL,
  `pr_code` varchar(60) NOT NULL,
  `pr_date` date NOT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `note` text DEFAULT NULL,
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_pr`
--

TRUNCATE TABLE `wqs_pr`;
--
-- Dumping data untuk tabel `wqs_pr`
--

INSERT DELAYED IGNORE INTO `wqs_pr` (`id`, `pr_code`, `pr_date`, `office_code`, `status`, `note`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'RMI-PR-BGR-260101-001', '2026-01-01', 'BGR', 'PO_CREATED', '', 'admin', '2026-01-02 00:35:42', '2026-01-02 01:02:50', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_pr_items`
--

DROP TABLE IF EXISTS `wqs_pr_items`;
CREATE TABLE `wqs_pr_items` (
  `id` int(11) NOT NULL,
  `pr_id` int(11) NOT NULL,
  `line_no` int(11) NOT NULL DEFAULT 1,
  `product_id` int(11) DEFAULT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `products_name` varchar(255) DEFAULT NULL,
  `qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(30) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_pr_items`
--

TRUNCATE TABLE `wqs_pr_items`;
--
-- Dumping data untuk tabel `wqs_pr_items`
--

INSERT DELAYED IGNORE INTO `wqs_pr_items` (`id`, `pr_id`, `line_no`, `product_id`, `sku`, `products_name`, `qty`, `unit`, `deleted_at`, `created_at`) VALUES
(1, 1, 1, 3, 'ALK-001', 'Alkes A', 10.00, 'unit', NULL, '2026-01-02 00:35:42'),
(2, 1, 2, 1, 'OBT-001', 'Obat A', 10.00, 'unit', NULL, '2026-01-02 00:35:42');

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock`
--

DROP TABLE IF EXISTS `wqs_stock`;
CREATE TABLE `wqs_stock` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `stock_qty` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock`
--

TRUNCATE TABLE `wqs_stock`;
--
-- Dumping data untuk tabel `wqs_stock`
--

INSERT DELAYED IGNORE INTO `wqs_stock` (`id`, `product_id`, `stock_qty`, `updated_at`) VALUES
(1, 3, 10, '2026-03-04 14:49:16'),
(2, 1, 10, '2026-03-04 14:49:24'),
(3, 2, 10, '2026-03-04 14:49:30'),
(4, 3, 0, '2026-03-04 17:14:39');

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_adjustments`
--

DROP TABLE IF EXISTS `wqs_stock_adjustments`;
CREATE TABLE `wqs_stock_adjustments` (
  `id` int(11) NOT NULL,
  `adj_code` varchar(40) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `delta_qty` decimal(18,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(60) DEFAULT NULL,
  `office_code` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_adjustments`
--

TRUNCATE TABLE `wqs_stock_adjustments`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_adjustment_approvals`
--

DROP TABLE IF EXISTS `wqs_stock_adjustment_approvals`;
CREATE TABLE `wqs_stock_adjustment_approvals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `adjustment_id` bigint(20) UNSIGNED NOT NULL,
  `requested_by` varchar(120) NOT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `approved_by` varchar(120) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `note` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_adjustment_approvals`
--

TRUNCATE TABLE `wqs_stock_adjustment_approvals`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_baseline_lock`
--

DROP TABLE IF EXISTS `wqs_stock_baseline_lock`;
CREATE TABLE `wqs_stock_baseline_lock` (
  `id` tinyint(4) NOT NULL,
  `locked_at` datetime NOT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_baseline_lock`
--

TRUNCATE TABLE `wqs_stock_baseline_lock`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_by_office`
--

DROP TABLE IF EXISTS `wqs_stock_by_office`;
CREATE TABLE `wqs_stock_by_office` (
  `product_id` int(11) NOT NULL,
  `office_code` varchar(30) NOT NULL DEFAULT 'HO',
  `stock_qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_by_office`
--

TRUNCATE TABLE `wqs_stock_by_office`;
--
-- Dumping data untuk tabel `wqs_stock_by_office`
--

INSERT DELAYED IGNORE INTO `wqs_stock_by_office` (`product_id`, `office_code`, `stock_qty`, `updated_at`) VALUES
(1, 'BGR', 10.00, '2026-03-04 14:49:24'),
(1, 'HO', 10.00, '2026-03-04 14:49:24'),
(2, 'BGR', 10.00, '2026-03-04 14:49:30'),
(2, 'HO', 10.00, '2026-03-04 14:49:30'),
(3, 'BGR', 0.00, '2026-03-08 00:24:43'),
(3, 'HO', 0.00, '2026-03-04 17:14:39');

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_opname`
--

DROP TABLE IF EXISTS `wqs_stock_opname`;
CREATE TABLE `wqs_stock_opname` (
  `id` int(11) NOT NULL,
  `opname_code` varchar(48) NOT NULL,
  `opname_date` date NOT NULL,
  `office_code` varchar(32) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `note` varchar(500) DEFAULT NULL,
  `created_by` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `submitted_by` varchar(80) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `applied_by` varchar(80) DEFAULT NULL,
  `applied_at` datetime DEFAULT NULL,
  `verified_by` varchar(80) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `depo_name` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_opname`
--

TRUNCATE TABLE `wqs_stock_opname`;
--
-- Dumping data untuk tabel `wqs_stock_opname`
--

INSERT DELAYED IGNORE INTO `wqs_stock_opname` (`id`, `opname_code`, `opname_date`, `office_code`, `status`, `note`, `created_by`, `created_at`, `submitted_by`, `submitted_at`, `applied_by`, `applied_at`, `verified_by`, `verified_at`, `signature_path`, `depo_name`) VALUES
(1, 'OPN-BGR-260306-001', '2026-03-06', 'BGR', 'DRAFT', NULL, 'admin', '2026-03-06 02:22:26', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'OPN-KAL-260306-001', '2026-03-06', 'KAL', 'DRAFT', NULL, 'admin', '2026-03-06 03:49:06', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_opname_attachments`
--

DROP TABLE IF EXISTS `wqs_stock_opname_attachments`;
CREATE TABLE `wqs_stock_opname_attachments` (
  `id` int(11) NOT NULL,
  `opname_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `uploaded_by` varchar(80) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_opname_attachments`
--

TRUNCATE TABLE `wqs_stock_opname_attachments`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_opname_items`
--

DROP TABLE IF EXISTS `wqs_stock_opname_items`;
CREATE TABLE `wqs_stock_opname_items` (
  `id` int(11) NOT NULL,
  `opname_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `qty_system` decimal(18,2) NOT NULL DEFAULT 0.00,
  `qty_fisik` decimal(18,2) DEFAULT NULL,
  `delta_qty` decimal(18,2) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_opname_items`
--

TRUNCATE TABLE `wqs_stock_opname_items`;
--
-- Dumping data untuk tabel `wqs_stock_opname_items`
--

INSERT DELAYED IGNORE INTO `wqs_stock_opname_items` (`id`, `opname_id`, `product_id`, `sku`, `product_name`, `unit`, `qty_system`, `qty_fisik`, `delta_qty`, `note`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'OBT-001', 'Obat A', 'unit', 10.00, NULL, NULL, NULL, '2026-03-06 02:22:42', NULL),
(2, 1, 2, 'OBT-002', 'Obat B', 'unit', 10.00, NULL, NULL, NULL, '2026-03-06 02:22:42', NULL);

-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_snapshot`
--

DROP TABLE IF EXISTS `wqs_stock_snapshot`;
CREATE TABLE `wqs_stock_snapshot` (
  `id` int(11) NOT NULL,
  `locked_at` datetime NOT NULL,
  `product_id` int(11) NOT NULL,
  `sku` varchar(80) DEFAULT NULL,
  `stock_qty` decimal(18,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_snapshot`
--

TRUNCATE TABLE `wqs_stock_snapshot`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_transfer`
--

DROP TABLE IF EXISTS `wqs_stock_transfer`;
CREATE TABLE `wqs_stock_transfer` (
  `id` int(11) NOT NULL,
  `transfer_code` varchar(50) NOT NULL,
  `from_office` varchar(30) NOT NULL,
  `to_office` varchar(30) NOT NULL,
  `transfer_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `note` text DEFAULT NULL,
  `wqs_stock_before` varchar(255) DEFAULT NULL,
  `wqs_stock_after` varchar(255) DEFAULT NULL,
  `foto_fisik_keluar` varchar(255) DEFAULT NULL,
  `wqs_stock_before_penerima` varchar(255) DEFAULT NULL,
  `wqs_stock_after_penerima` varchar(255) DEFAULT NULL,
  `foto_fisik_masuk` varchar(255) DEFAULT NULL,
  `created_by` varchar(80) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  `sent_by` varchar(80) DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `received_by` varchar(80) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_transfer`
--

TRUNCATE TABLE `wqs_stock_transfer`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_transfer_attachments`
--

DROP TABLE IF EXISTS `wqs_stock_transfer_attachments`;
CREATE TABLE `wqs_stock_transfer_attachments` (
  `id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `attachment_type` varchar(30) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_transfer_attachments`
--

TRUNCATE TABLE `wqs_stock_transfer_attachments`;
-- --------------------------------------------------------

--
-- Struktur dari tabel `wqs_stock_transfer_items`
--

DROP TABLE IF EXISTS `wqs_stock_transfer_items`;
CREATE TABLE `wqs_stock_transfer_items` (
  `id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `qty` decimal(18,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Potong tabel sebelum dimasukkan `wqs_stock_transfer_items`
--

TRUNCATE TABLE `wqs_stock_transfer_items`;
--
-- Indexes for dumped tables
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
-- Indeks untuk tabel `absensi_offices`
--
ALTER TABLE `absensi_offices`
  ADD PRIMARY KEY (`office_code`);

--
-- Indeks untuk tabel `absensi_requests`
--
ALTER TABLE `absensi_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_absensi_requests_user_id` (`user_id`),
  ADD KEY `idx_absensi_requests_deleted_at` (`deleted_at`),
  ADD KEY `idx_absensi_requests_status` (`status`),
  ADD KEY `idx_absensi_requests_start_end` (`start_date`,`end_date`),
  ADD KEY `idx_absensi_requests_user_status` (`user_id`,`status`);

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
-- Indeks untuk tabel `api_partner_keys`
--
ALTER TABLE `api_partner_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_partner_env` (`partner_name`,`environment`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_env` (`environment`);

--
-- Indeks untuk tabel `api_partner_order_idempotency`
--
ALTER TABLE `api_partner_order_idempotency`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_partner_idem` (`partner_id`,`idempotency_key`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indeks untuk tabel `api_rate_limits`
--
ALTER TABLE `api_rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_api_rate_scope_actor` (`scope_key`,`actor_key`),
  ADD KEY `idx_api_rate_reset` (`reset_at`);

--
-- Indeks untuk tabel `api_rate_limit_policies`
--
ALTER TABLE `api_rate_limit_policies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rate_limit_scope` (`scope_key`);

--
-- Indeks untuk tabel `auth_login_attempts`
--
ALTER TABLE `auth_login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_auth_attempt_ip_user` (`ip_address`,`username`),
  ADD KEY `idx_auth_attempt_locked` (`locked_until`);

--
-- Indeks untuk tabel `auth_mfa_bypass_tickets`
--
ALTER TABLE `auth_mfa_bypass_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mfa_bypass_user` (`user_id`),
  ADD KEY `idx_mfa_bypass_exp` (`expires_at`),
  ADD KEY `idx_mfa_bypass_active` (`is_active`);

--
-- Indeks untuk tabel `auth_mfa_policies`
--
ALTER TABLE `auth_mfa_policies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mfa_policy_role_dept` (`role_code`,`dept_code`),
  ADD KEY `idx_mfa_policy_active` (`is_active`);

--
-- Indeks untuk tabel `auth_webauthn_credentials`
--
ALTER TABLE `auth_webauthn_credentials`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_webauthn_cred_id` (`credential_id`(255)),
  ADD KEY `idx_webauthn_user` (`user_id`);

--
-- Indeks untuk tabel `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bank_accounts_code` (`account_code`),
  ADD KEY `idx_bank_accounts_acc_no` (`account_number`),
  ADD KEY `idx_bank_accounts_purpose` (`purpose`);

--
-- Indeks untuk tabel `bank_reconciliations`
--
ALTER TABLE `bank_reconciliations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bank_recon_period` (`bank_account_id`,`period_key`);

--
-- Indeks untuk tabel `bank_recon_matches`
--
ALTER TABLE `bank_recon_matches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bank_recon_statement_line` (`statement_line_id`),
  ADD KEY `idx_bank_recon_match_recon` (`reconciliation_id`),
  ADD KEY `idx_bank_recon_match_stmt` (`statement_line_id`);

--
-- Indeks untuk tabel `bank_statements`
--
ALTER TABLE `bank_statements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bank_statement_period` (`bank_account_id`,`period_key`);

--
-- Indeks untuk tabel `bank_statement_lines`
--
ALTER TABLE `bank_statement_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bank_statement_lines_statement` (`statement_id`),
  ADD KEY `idx_bank_statement_lines_date` (`txn_date`);

--
-- Indeks untuk tabel `bank_statement_raw`
--
ALTER TABLE `bank_statement_raw`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bank_statement_raw_batch` (`batch_code`),
  ADD KEY `idx_bank_statement_raw_account` (`bank_account_id`);

--
-- Indeks untuk tabel `chat_acl`
--
ALTER TABLE `chat_acl`
  ADD PRIMARY KEY (`channel_id`,`subject_type`,`subject_key`);

--
-- Indeks untuk tabel `chat_attachments`
--
ALTER TABLE `chat_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_attachments_message` (`message_id`),
  ADD KEY `idx_chat_attachments_channel` (`channel_id`);

--
-- Indeks untuk tabel `chat_channels`
--
ALTER TABLE `chat_channels`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `channel_key` (`channel_key`),
  ADD KEY `idx_chat_channels_type` (`channel_type`),
  ADD KEY `idx_chat_channels_ctx` (`context_entity_type`,`context_entity_id`);

--
-- Indeks untuk tabel `chat_channel_acl`
--
ALTER TABLE `chat_channel_acl`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_acl_channel_role` (`channel_id`,`role_code`),
  ADD KEY `idx_chat_acl_channel` (`channel_id`);

--
-- Indeks untuk tabel `chat_channel_members`
--
ALTER TABLE `chat_channel_members`
  ADD PRIMARY KEY (`channel_id`,`user_id`),
  ADD KEY `idx_chat_members_user` (`user_id`),
  ADD KEY `idx_chat_members_channel_read` (`channel_id`,`last_read_message_id`);

--
-- Indeks untuk tabel `chat_config`
--
ALTER TABLE `chat_config`
  ADD PRIMARY KEY (`config_key`);

--
-- Indeks untuk tabel `chat_custom_emojis`
--
ALTER TABLE `chat_custom_emojis`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_custom_emoji_code` (`emoji_code`),
  ADD KEY `idx_chat_custom_emoji_active` (`is_active`);

--
-- Indeks untuk tabel `chat_exports`
--
ALTER TABLE `chat_exports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_exports_channel` (`channel_id`,`requested_at`);

--
-- Indeks untuk tabel `chat_mentions`
--
ALTER TABLE `chat_mentions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_mentions_user_message` (`mentioned_user_id`,`message_id`),
  ADD KEY `idx_chat_mentions_message` (`message_id`);

--
-- Indeks untuk tabel `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_idem` (`channel_id`,`sender_user_id`,`idempotency_key`),
  ADD KEY `idx_chat_messages_channel_id` (`channel_id`,`id`),
  ADD KEY `idx_chat_messages_created` (`channel_id`,`created_at`),
  ADD KEY `idx_chat_messages_reply_to` (`reply_to_message_id`),
  ADD KEY `idx_chat_messages_thread_root` (`thread_root_message_id`,`id`);
ALTER TABLE `chat_messages` ADD FULLTEXT KEY `ft_chat_messages_text` (`message_text`);

--
-- Indeks untuk tabel `chat_message_context`
--
ALTER TABLE `chat_message_context`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_chat_message_context_message` (`message_id`),
  ADD KEY `idx_chat_message_context_entity` (`entity_type`,`entity_id`);

--
-- Indeks untuk tabel `chat_message_idempotency`
--
ALTER TABLE `chat_message_idempotency`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_msg_idemp` (`user_id`,`channel_id`,`idempotency_key`),
  ADD KEY `idx_chat_msg_idemp_created` (`created_at`);

--
-- Indeks untuk tabel `chat_message_mentions`
--
ALTER TABLE `chat_message_mentions`
  ADD PRIMARY KEY (`message_id`,`mentioned_user_id`),
  ADD KEY `idx_chat_message_mentions_user` (`mentioned_user_id`);

--
-- Indeks untuk tabel `chat_message_reactions`
--
ALTER TABLE `chat_message_reactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_reactions` (`message_id`,`user_id`,`emoji`),
  ADD KEY `idx_chat_reactions_message` (`message_id`),
  ADD KEY `idx_chat_reactions_user` (`user_id`);

--
-- Indeks untuk tabel `chat_pins`
--
ALTER TABLE `chat_pins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_pin_active` (`channel_id`,`message_id`,`is_active`),
  ADD KEY `idx_chat_pins_channel_active` (`channel_id`,`is_active`,`pinned_at`);

--
-- Indeks untuk tabel `chat_presence`
--
ALTER TABLE `chat_presence`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_presence_user` (`user_id`),
  ADD KEY `idx_chat_presence_seen` (`last_seen_at`);

--
-- Indeks untuk tabel `chat_quota_config`
--
ALTER TABLE `chat_quota_config`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `chat_quota_usage`
--
ALTER TABLE `chat_quota_usage`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_quota_usage` (`usage_date`,`user_id`,`channel_id`),
  ADD KEY `idx_chat_quota_usage_channel` (`usage_date`,`channel_id`);

--
-- Indeks untuk tabel `chat_rate_limits`
--
ALTER TABLE `chat_rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_rate_window` (`user_id`,`ip_address`,`window_minute`),
  ADD KEY `idx_chat_rate_updated` (`updated_at`);

--
-- Indeks untuk tabel `chat_typing_status`
--
ALTER TABLE `chat_typing_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_typing` (`channel_id`,`user_id`),
  ADD KEY `idx_chat_typing_recent` (`channel_id`,`updated_at`);

--
-- Indeks untuk tabel `chat_user_channel_prefs`
--
ALTER TABLE `chat_user_channel_prefs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_chat_user_channel_pref` (`channel_id`,`user_id`),
  ADD KEY `idx_chat_pref_user` (`user_id`,`updated_at`);

--
-- Indeks untuk tabel `chat_user_channel_settings`
--
ALTER TABLE `chat_user_channel_settings`
  ADD PRIMARY KEY (`channel_id`,`user_id`);

--
-- Indeks untuk tabel `chat_user_presence`
--
ALTER TABLE `chat_user_presence`
  ADD PRIMARY KEY (`user_id`);

--
-- Indeks untuk tabel `crm_leads`
--
ALTER TABLE `crm_leads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_crm_lead_no` (`lead_no`),
  ADD KEY `idx_crm_leads_status` (`status`),
  ADD KEY `idx_crm_leads_followup` (`next_followup_date`),
  ADD KEY `idx_crm_leads_created_at` (`created_at`),
  ADD KEY `idx_crm_leads_status_priority` (`status`,`priority`),
  ADD KEY `idx_crm_leads_norm_email` (`normalized_email`),
  ADD KEY `idx_crm_leads_norm_phone` (`normalized_phone`);

--
-- Indeks untuk tabel `crm_lead_dedupe_rules`
--
ALTER TABLE `crm_lead_dedupe_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_crm_dedupe_rule_name` (`rule_name`);

--
-- Indeks untuk tabel `crm_lead_logs`
--
ALTER TABLE `crm_lead_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_crm_lead_logs_lead_id` (`lead_id`),
  ADD KEY `idx_crm_lead_logs_action` (`action`),
  ADD KEY `idx_crm_lead_logs_created_at` (`created_at`);

--
-- Indeks untuk tabel `customer_portal_users`
--
ALTER TABLE `customer_portal_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_username` (`username`),
  ADD KEY `idx_customers_code` (`customers_code`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `erp_audit_log`
--
ALTER TABLE `erp_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_module` (`module`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_entity` (`entity_key`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_username_created` (`username`,`created_at`);

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
-- Indeks untuk tabel `gl_accounts`
--
ALTER TABLE `gl_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gl_accounts_code` (`code`),
  ADD KEY `idx_gl_accounts_parent` (`parent_id`);

--
-- Indeks untuk tabel `gl_journal_headers`
--
ALTER TABLE `gl_journal_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gl_journal_no` (`journal_no`),
  ADD UNIQUE KEY `uq_gl_source_ref` (`source_module`,`source_event`,`source_ref`),
  ADD KEY `idx_gl_journal_date` (`journal_date`),
  ADD KEY `idx_gl_header_date_status` (`journal_date`,`status`);

--
-- Indeks untuk tabel `gl_journal_lines`
--
ALTER TABLE `gl_journal_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gl_lines_header` (`header_id`),
  ADD KEY `idx_gl_lines_account` (`account_id`),
  ADD KEY `idx_gl_lines_account_header` (`account_id`,`header_id`);

--
-- Indeks untuk tabel `gl_mappings`
--
ALTER TABLE `gl_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gl_mapping_event` (`module_name`,`event_name`);

--
-- Indeks untuk tabel `gl_periods`
--
ALTER TABLE `gl_periods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_gl_period` (`year_no`,`month_no`);

--
-- Indeks untuk tabel `gl_posting_batches`
--
ALTER TABLE `gl_posting_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gl_batches_posted_at` (`posted_at`);

--
-- Indeks untuk tabel `gl_reversal_requests`
--
ALTER TABLE `gl_reversal_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_gl_rev_req_header` (`header_id`),
  ADD KEY `idx_gl_rev_req_status` (`status`);

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
-- Indeks untuk tabel `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_jobs_status_run_at` (`status`,`run_at`);

--
-- Indeks untuk tabel `kpi_adjustments`
--
ALTER TABLE `kpi_adjustments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kpi_adjustments_period` (`month_no`,`year_no`),
  ADD KEY `idx_kpi_adjustments_office` (`office_id`);

--
-- Indeks untuk tabel `kpi_audit_log`
--
ALTER TABLE `kpi_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kpi_audit_module` (`module`),
  ADD KEY `idx_kpi_audit_action` (`action`),
  ADD KEY `idx_kpi_audit_created` (`created_at`);

--
-- Indeks untuk tabel `kpi_corporate_rates`
--
ALTER TABLE `kpi_corporate_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kpi_corporate_rates_segment` (`segment`);

--
-- Indeks untuk tabel `kpi_customer_segment_map`
--
ALTER TABLE `kpi_customer_segment_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kpi_customer_segment` (`customer_code`);

--
-- Indeks untuk tabel `kpi_daily_snapshots`
--
ALTER TABLE `kpi_daily_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kpi_daily_snapshot` (`snapshot_date`,`office_id`,`segment`),
  ADD KEY `idx_kpi_daily_snapshot_segment` (`segment`);

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
-- Indeks untuk tabel `kpi_gl_category_map`
--
ALTER TABLE `kpi_gl_category_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kpi_gl_category_account` (`gl_account_id`);

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
-- Indeks untuk tabel `kpi_snapshot_approvals`
--
ALTER TABLE `kpi_snapshot_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kpi_snap_appr_sid` (`snapshot_id`),
  ADD KEY `idx_kpi_snap_appr_month` (`snapshot_month`),
  ADD KEY `idx_kpi_snap_appr_checker` (`checker_username`),
  ADD KEY `idx_kpi_snap_appr_approved` (`approved_at`);

--
-- Indeks untuk tabel `kpi_snapshot_items`
--
ALTER TABLE `kpi_snapshot_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_kpi_snapshot_items_sid` (`snapshot_id`);

--
-- Indeks untuk tabel `kpi_targets`
--
ALTER TABLE `kpi_targets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_kpi_targets_month_year_segment_office` (`month_no`,`year_no`,`segment`,`office_id`),
  ADD KEY `idx_kpi_targets_segment` (`segment`);

--
-- Indeks untuk tabel `manufacturer_portal_users`
--
ALTER TABLE `manufacturer_portal_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_username` (`username`),
  ADD KEY `idx_manufacture_code` (`manufacture_code`),
  ADD KEY `idx_manufacture_id` (`manufacture_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `map_accounts`
--
ALTER TABLE `map_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_map_accounts_src` (`source`,`source_id`),
  ADD KEY `idx_map_accounts_target` (`target_account_id`);

--
-- Indeks untuk tabel `map_customers`
--
ALTER TABLE `map_customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_map_customers_src` (`source`,`source_id`),
  ADD KEY `idx_map_customers_target` (`target_customer_id`);

--
-- Indeks untuk tabel `map_items`
--
ALTER TABLE `map_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_map_items_src` (`source`,`source_id`),
  ADD KEY `idx_map_items_target` (`target_item_id`);

--
-- Indeks untuk tabel `map_vendors`
--
ALTER TABLE `map_vendors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_map_vendors_src` (`source`,`source_id`),
  ADD KEY `idx_map_vendors_target` (`target_vendor_id`);

--
-- Indeks untuk tabel `map_warehouses`
--
ALTER TABLE `map_warehouses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_map_warehouses_src` (`source`,`source_id`),
  ADD KEY `idx_map_warehouses_target` (`target_warehouse_id`);

--
-- Indeks untuk tabel `marketplace_orders_inbox`
--
ALTER TABLE `marketplace_orders_inbox`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_marketplace_inbox_order` (`source`,`external_order_id`),
  ADD KEY `idx_processed` (`processed_at`),
  ADD KEY `idx_created` (`created_at`);

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
-- Indeks untuk tabel `master_import_runs`
--
ALTER TABLE `master_import_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_master_import_runs_module` (`module`),
  ADD KEY `idx_master_import_runs_created` (`created_at`);

--
-- Indeks untuk tabel `master_import_run_errors`
--
ALTER TABLE `master_import_run_errors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_master_import_err_run` (`run_id`),
  ADD KEY `idx_master_import_err_created` (`created_at`);

--
-- Indeks untuk tabel `master_manufactures`
--
ALTER TABLE `master_manufactures`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `master_manufactures_docs`
--
ALTER TABLE `master_manufactures_docs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manu` (`manufacture_id`),
  ADD KEY `idx_type` (`doc_type`);

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
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_msl_status` (`status`),
  ADD KEY `idx_msl_role` (`role`),
  ADD KEY `idx_msl_office` (`office_code`),
  ADD KEY `idx_msl_deleted_at` (`deleted_at`);

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
-- Indeks untuk tabel `master_units`
--
ALTER TABLE `master_units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_master_units_code` (`unit_code`),
  ADD UNIQUE KEY `uq_master_units_source` (`source_system`,`source_key`);

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
-- Indeks untuk tabel `migration_errors`
--
ALTER TABLE `migration_errors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_migration_errors_run` (`run_id`),
  ADD KEY `idx_migration_errors_entity` (`entity`);

--
-- Indeks untuk tabel `migration_runs`
--
ALTER TABLE `migration_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_migration_runs_source` (`source`),
  ADD KEY `idx_migration_runs_status` (`status`),
  ADD KEY `idx_migration_runs_started` (`started_at`);

--
-- Indeks untuk tabel `migration_sales_receipts`
--
ALTER TABLE `migration_sales_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_migration_sales_receipts_source` (`source_system`,`source_key`),
  ADD KEY `idx_migration_sales_receipts_invoice` (`sales_invoice_no`),
  ADD KEY `fk_migration_sales_receipts_run` (`migration_run_id`);

--
-- Indeks untuk tabel `migration_target_keys`
--
ALTER TABLE `migration_target_keys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_migration_target_key` (`target_table`,`source_system`,`source_key`),
  ADD KEY `idx_migration_target_run` (`migration_run_id`);

--
-- Indeks untuk tabel `mobile_audit_events`
--
ALTER TABLE `mobile_audit_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mobile_audit_event_name` (`event_name`),
  ADD KEY `idx_mobile_audit_actor` (`actor_username`),
  ADD KEY `idx_mobile_audit_created_at` (`created_at`);

--
-- Indeks untuk tabel `mobile_device_tokens`
--
ALTER TABLE `mobile_device_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mobile_device_user` (`user_id`,`device_id`),
  ADD KEY `idx_mobile_device_last_seen` (`last_seen_at`);

--
-- Indeks untuk tabel `mobile_idempotency`
--
ALTER TABLE `mobile_idempotency`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mobile_idem` (`user_id`,`endpoint_key`,`idem_key`),
  ADD KEY `idx_mobile_idem_created_at` (`created_at`);

--
-- Indeks untuk tabel `mobile_notifications`
--
ALTER TABLE `mobile_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mobile_notif_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_mobile_notif_user_read` (`user_id`,`is_read`);

--
-- Indeks untuk tabel `mobile_refresh_tokens`
--
ALTER TABLE `mobile_refresh_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mobile_refresh_token_hash` (`token_hash`),
  ADD KEY `idx_mobile_refresh_user_id` (`user_id`),
  ADD KEY `idx_mobile_refresh_expires_at` (`expires_at`);

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
-- Indeks untuk tabel `office_warehouses`
--
ALTER TABLE `office_warehouses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_office_warehouse` (`office_id`,`warehouse_id`);

--
-- Indeks untuk tabel `payments_webhook_inbox`
--
ALTER TABLE `payments_webhook_inbox`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_payments_inbox_processed` (`processed_at`),
  ADD KEY `idx_payments_inbox_created` (`created_at`);

--
-- Indeks untuk tabel `payment_callbacks_inbox`
--
ALTER TABLE `payment_callbacks_inbox`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_processed` (`processed_at`),
  ADD KEY `idx_created` (`created_at`);

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
-- Indeks untuk tabel `procurement_match_rules`
--
ALTER TABLE `procurement_match_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_proc_match_rule_name` (`rule_name`);

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
  ADD UNIQUE KEY `uq_ap_code` (`ap_code`),
  ADD KEY `idx_ap_date_status_office` (`invoice_date`,`status`,`office_code`);

--
-- Indeks untuk tabel `purchases_invoice_ap_lines`
--
ALTER TABLE `purchases_invoice_ap_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ap_lines_ap_id` (`ap_id`),
  ADD KEY `idx_ap_lines_po_item` (`po_item_id`);

--
-- Indeks untuk tabel `purchases_payment_ap`
--
ALTER TABLE `purchases_payment_ap`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pay_code` (`pay_code`),
  ADD KEY `idx_ap` (`ap_id`),
  ADD KEY `idx_ap_payment_ap_date` (`ap_id`,`pay_date`);

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
-- Indeks untuk tabel `rbac_roles`
--
ALTER TABLE `rbac_roles`
  ADD PRIMARY KEY (`role_code`);

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
  ADD UNIQUE KEY `uq_do_code` (`do_code`),
  ADD UNIQUE KEY `uq_sales_do_tracking_public_token` (`tracking_public_token`),
  ADD KEY `idx_sales_do_carrier_tracking` (`carrier_tracking_no`),
  ADD KEY `idx_sales_do_scm_live_at` (`scm_live_at`),
  ADD KEY `idx_sales_do_date_status_office` (`do_date`,`status`,`office_code`),
  ADD KEY `idx_sales_do_customer_date` (`customers_code`,`do_date`);

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
-- Indeks untuk tabel `sales_do_tracking_events`
--
ALTER TABLE `sales_do_tracking_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sdt_do_time` (`do_id`,`event_time`),
  ADD KEY `idx_sdt_created_at` (`created_at`);

--
-- Indeks untuk tabel `sales_marketplace_staging`
--
ALTER TABLE `sales_marketplace_staging`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_marketplace_staging_order` (`external_order_id`,`source`),
  ADD KEY `idx_marketplace_staging_inbox` (`inbox_id`),
  ADD KEY `idx_marketplace_staging_status` (`status`);

--
-- Indeks untuk tabel `stg_coa`
--
ALTER TABLE `stg_coa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stg_coa_source_key` (`source_system`,`source_key`),
  ADD KEY `idx_stg_coa_run` (`run_id`);

--
-- Indeks untuk tabel `stg_documents`
--
ALTER TABLE `stg_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stg_docs_source_key` (`source_system`,`source_key`),
  ADD KEY `idx_stg_docs_run` (`run_id`),
  ADD KEY `idx_stg_docs_type` (`doc_type`),
  ADD KEY `idx_stg_docs_date` (`doc_date`);

--
-- Indeks untuk tabel `stg_items`
--
ALTER TABLE `stg_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stg_items_source_key` (`source_system`,`source_key`),
  ADD KEY `idx_stg_items_run` (`run_id`);

--
-- Indeks untuk tabel `stg_opening_balances`
--
ALTER TABLE `stg_opening_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stg_opening_source_key` (`source_system`,`source_key`),
  ADD KEY `idx_stg_opening_run` (`run_id`),
  ADD KEY `idx_stg_opening_entity` (`entity_type`);

--
-- Indeks untuk tabel `stg_parties`
--
ALTER TABLE `stg_parties`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stg_parties_source_key` (`source_system`,`source_key`),
  ADD KEY `idx_stg_parties_run` (`run_id`),
  ADD KEY `idx_stg_parties_type` (`entity_type`);

--
-- Indeks untuk tabel `stg_tax_codes`
--
ALTER TABLE `stg_tax_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stg_tax_source_key` (`source_system`,`source_key`),
  ADD KEY `idx_stg_tax_run` (`run_id`);

--
-- Indeks untuk tabel `stg_units`
--
ALTER TABLE `stg_units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stg_units_source_key` (`source_system`,`source_key`),
  ADD KEY `idx_stg_units_run` (`run_id`);

--
-- Indeks untuk tabel `stg_warehouses`
--
ALTER TABLE `stg_warehouses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stg_wh_source_key` (`source_system`,`source_key`),
  ADD KEY `idx_stg_wh_run` (`run_id`);

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
-- Indeks untuk tabel `tax_invoices`
--
ALTER TABLE `tax_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tax_invoice_no` (`tax_no`),
  ADD KEY `idx_tax_invoice_sales_ref` (`sales_invoice_ref`);

--
-- Indeks untuk tabel `tax_invoice_logs`
--
ALTER TABLE `tax_invoice_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tax_invoice_logs_invoice` (`tax_invoice_id`);

--
-- Indeks untuk tabel `tax_profiles`
--
ALTER TABLE `tax_profiles`
  ADD PRIMARY KEY (`id`);

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
  ADD KEY `idx_po_id` (`po_id`),
  ADD KEY `idx_po` (`po_code`),
  ADD KEY `idx_office` (`office_code`);

--
-- Indeks untuk tabel `wqs_incoming_items`
--
ALTER TABLE `wqs_incoming_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_incoming` (`incoming_id`),
  ADD KEY `idx_po_item_id` (`po_item_id`),
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
-- Indeks untuk tabel `wqs_stock_adjustment_approvals`
--
ALTER TABLE `wqs_stock_adjustment_approvals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_wqs_adj_approval_adjustment_id` (`adjustment_id`),
  ADD KEY `idx_wqs_adj_approval_status` (`status`);

--
-- Indeks untuk tabel `wqs_stock_baseline_lock`
--
ALTER TABLE `wqs_stock_baseline_lock`
  ADD PRIMARY KEY (`id`);

--
-- Indeks untuk tabel `wqs_stock_by_office`
--
ALTER TABLE `wqs_stock_by_office`
  ADD PRIMARY KEY (`product_id`,`office_code`),
  ADD KEY `idx_office` (`office_code`);

--
-- Indeks untuk tabel `wqs_stock_opname`
--
ALTER TABLE `wqs_stock_opname`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_opname_code` (`opname_code`),
  ADD KEY `idx_opname_date` (`opname_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `wqs_stock_opname_attachments`
--
ALTER TABLE `wqs_stock_opname_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_opname_id` (`opname_id`);

--
-- Indeks untuk tabel `wqs_stock_opname_items`
--
ALTER TABLE `wqs_stock_opname_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_opname_id` (`opname_id`),
  ADD KEY `idx_product` (`product_id`),
  ADD KEY `idx_sku` (`sku`);

--
-- Indeks untuk tabel `wqs_stock_snapshot`
--
ALTER TABLE `wqs_stock_snapshot`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pid` (`product_id`),
  ADD KEY `idx_sku` (`sku`);

--
-- Indeks untuk tabel `wqs_stock_transfer`
--
ALTER TABLE `wqs_stock_transfer`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_transfer_code` (`transfer_code`),
  ADD KEY `idx_from_office` (`from_office`),
  ADD KEY `idx_to_office` (`to_office`),
  ADD KEY `idx_transfer_date` (`transfer_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `wqs_stock_transfer_attachments`
--
ALTER TABLE `wqs_stock_transfer_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transfer` (`transfer_id`),
  ADD KEY `idx_type` (`attachment_type`);

--
-- Indeks untuk tabel `wqs_stock_transfer_items`
--
ALTER TABLE `wqs_stock_transfer_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_transfer` (`transfer_id`),
  ADD KEY `idx_product` (`product_id`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `absensi_audit`
--
ALTER TABLE `absensi_audit`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT untuk tabel `absensi_logs`
--
ALTER TABLE `absensi_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `absensi_requests`
--
ALTER TABLE `absensi_requests`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `api_partner_keys`
--
ALTER TABLE `api_partner_keys`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `api_partner_order_idempotency`
--
ALTER TABLE `api_partner_order_idempotency`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `api_rate_limits`
--
ALTER TABLE `api_rate_limits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=100;

--
-- AUTO_INCREMENT untuk tabel `api_rate_limit_policies`
--
ALTER TABLE `api_rate_limit_policies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `auth_login_attempts`
--
ALTER TABLE `auth_login_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT untuk tabel `auth_mfa_bypass_tickets`
--
ALTER TABLE `auth_mfa_bypass_tickets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `auth_mfa_policies`
--
ALTER TABLE `auth_mfa_policies`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT untuk tabel `auth_webauthn_credentials`
--
ALTER TABLE `auth_webauthn_credentials`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `bank_accounts`
--
ALTER TABLE `bank_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `bank_reconciliations`
--
ALTER TABLE `bank_reconciliations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `bank_recon_matches`
--
ALTER TABLE `bank_recon_matches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `bank_statements`
--
ALTER TABLE `bank_statements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `bank_statement_lines`
--
ALTER TABLE `bank_statement_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `bank_statement_raw`
--
ALTER TABLE `bank_statement_raw`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `chat_attachments`
--
ALTER TABLE `chat_attachments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `chat_channels`
--
ALTER TABLE `chat_channels`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT untuk tabel `chat_channel_acl`
--
ALTER TABLE `chat_channel_acl`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT untuk tabel `chat_custom_emojis`
--
ALTER TABLE `chat_custom_emojis`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `chat_exports`
--
ALTER TABLE `chat_exports`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `chat_mentions`
--
ALTER TABLE `chat_mentions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `chat_message_context`
--
ALTER TABLE `chat_message_context`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `chat_message_idempotency`
--
ALTER TABLE `chat_message_idempotency`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `chat_message_reactions`
--
ALTER TABLE `chat_message_reactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `chat_pins`
--
ALTER TABLE `chat_pins`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `chat_presence`
--
ALTER TABLE `chat_presence`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `chat_quota_usage`
--
ALTER TABLE `chat_quota_usage`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `chat_rate_limits`
--
ALTER TABLE `chat_rate_limits`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT untuk tabel `chat_typing_status`
--
ALTER TABLE `chat_typing_status`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT untuk tabel `chat_user_channel_prefs`
--
ALTER TABLE `chat_user_channel_prefs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `crm_leads`
--
ALTER TABLE `crm_leads`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `crm_lead_dedupe_rules`
--
ALTER TABLE `crm_lead_dedupe_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `crm_lead_logs`
--
ALTER TABLE `crm_lead_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `customer_portal_users`
--
ALTER TABLE `customer_portal_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `erp_audit_log`
--
ALTER TABLE `erp_audit_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=348;

--
-- AUTO_INCREMENT untuk tabel `fa_assets`
--
ALTER TABLE `fa_assets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_audits`
--
ALTER TABLE `fa_audits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_audit_lines`
--
ALTER TABLE `fa_audit_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_audit_log`
--
ALTER TABLE `fa_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_dep_lines`
--
ALTER TABLE `fa_dep_lines`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_dep_runs`
--
ALTER TABLE `fa_dep_runs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_disposals`
--
ALTER TABLE `fa_disposals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_maintenance`
--
ALTER TABLE `fa_maintenance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `fa_transfers`
--
ALTER TABLE `fa_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `gl_accounts`
--
ALTER TABLE `gl_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `gl_journal_headers`
--
ALTER TABLE `gl_journal_headers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `gl_journal_lines`
--
ALTER TABLE `gl_journal_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `gl_mappings`
--
ALTER TABLE `gl_mappings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `gl_periods`
--
ALTER TABLE `gl_periods`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `gl_posting_batches`
--
ALTER TABLE `gl_posting_batches`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `gl_reversal_requests`
--
ALTER TABLE `gl_reversal_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_docs`
--
ALTER TABLE `hrl_docs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT untuk tabel `hrl_doc_acks`
--
ALTER TABLE `hrl_doc_acks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_doc_versions`
--
ALTER TABLE `hrl_doc_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `hrl_reg_alkes_cases`
--
ALTER TABLE `hrl_reg_alkes_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_reg_alkes_case_docs`
--
ALTER TABLE `hrl_reg_alkes_case_docs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_requests`
--
ALTER TABLE `hrl_requests`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `hrl_request_files`
--
ALTER TABLE `hrl_request_files`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_adjustments`
--
ALTER TABLE `kpi_adjustments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_audit_log`
--
ALTER TABLE `kpi_audit_log`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_corporate_rates`
--
ALTER TABLE `kpi_corporate_rates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `kpi_customer_segment_map`
--
ALTER TABLE `kpi_customer_segment_map`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_daily_snapshots`
--
ALTER TABLE `kpi_daily_snapshots`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_employee`
--
ALTER TABLE `kpi_employee`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_gl_category_map`
--
ALTER TABLE `kpi_gl_category_map`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_office`
--
ALTER TABLE `kpi_office`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_snapshot`
--
ALTER TABLE `kpi_snapshot`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_snapshot_approvals`
--
ALTER TABLE `kpi_snapshot_approvals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_snapshot_items`
--
ALTER TABLE `kpi_snapshot_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `kpi_targets`
--
ALTER TABLE `kpi_targets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT untuk tabel `manufacturer_portal_users`
--
ALTER TABLE `manufacturer_portal_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `map_accounts`
--
ALTER TABLE `map_accounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `map_customers`
--
ALTER TABLE `map_customers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `map_items`
--
ALTER TABLE `map_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `map_vendors`
--
ALTER TABLE `map_vendors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `map_warehouses`
--
ALTER TABLE `map_warehouses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `marketplace_orders_inbox`
--
ALTER TABLE `marketplace_orders_inbox`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_company_bank_accounts`
--
ALTER TABLE `master_company_bank_accounts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_customers`
--
ALTER TABLE `master_customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=165;

--
-- AUTO_INCREMENT untuk tabel `master_departements`
--
ALTER TABLE `master_departements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2109;

--
-- AUTO_INCREMENT untuk tabel `master_discount_policy`
--
ALTER TABLE `master_discount_policy`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_emailcompany`
--
ALTER TABLE `master_emailcompany`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT untuk tabel `master_employees`
--
ALTER TABLE `master_employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=297;

--
-- AUTO_INCREMENT untuk tabel `master_import_runs`
--
ALTER TABLE `master_import_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_import_run_errors`
--
ALTER TABLE `master_import_run_errors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_manufactures`
--
ALTER TABLE `master_manufactures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT untuk tabel `master_manufactures_docs`
--
ALTER TABLE `master_manufactures_docs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_mpr`
--
ALTER TABLE `master_mpr`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_office`
--
ALTER TABLE `master_office`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `master_payment_terms`
--
ALTER TABLE `master_payment_terms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `master_pricelist`
--
ALTER TABLE `master_pricelist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_products`
--
ALTER TABLE `master_products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT untuk tabel `master_products_doc`
--
ALTER TABLE `master_products_doc`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_products_print`
--
ALTER TABLE `master_products_print`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_system_login`
--
ALTER TABLE `master_system_login`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=187;

--
-- AUTO_INCREMENT untuk tabel `master_system_login_handover`
--
ALTER TABLE `master_system_login_handover`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_tax`
--
ALTER TABLE `master_tax`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT untuk tabel `master_units`
--
ALTER TABLE `master_units`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_user`
--
ALTER TABLE `master_user`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `master_vendors`
--
ALTER TABLE `master_vendors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT untuk tabel `migration_errors`
--
ALTER TABLE `migration_errors`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `migration_runs`
--
ALTER TABLE `migration_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `migration_sales_receipts`
--
ALTER TABLE `migration_sales_receipts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `migration_target_keys`
--
ALTER TABLE `migration_target_keys`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mobile_audit_events`
--
ALTER TABLE `mobile_audit_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT untuk tabel `mobile_device_tokens`
--
ALTER TABLE `mobile_device_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mobile_idempotency`
--
ALTER TABLE `mobile_idempotency`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT untuk tabel `mobile_notifications`
--
ALTER TABLE `mobile_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mobile_refresh_tokens`
--
ALTER TABLE `mobile_refresh_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mpr_budget_requests`
--
ALTER TABLE `mpr_budget_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mpr_ops_payments`
--
ALTER TABLE `mpr_ops_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mpr_plans`
--
ALTER TABLE `mpr_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mpr_progress`
--
ALTER TABLE `mpr_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `mpr_visits`
--
ALTER TABLE `mpr_visits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `office_warehouses`
--
ALTER TABLE `office_warehouses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payments_webhook_inbox`
--
ALTER TABLE `payments_webhook_inbox`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payment_callbacks_inbox`
--
ALTER TABLE `payment_callbacks_inbox`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_employee_settings`
--
ALTER TABLE `payroll_employee_settings`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_loans`
--
ALTER TABLE `payroll_loans`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_runs`
--
ALTER TABLE `payroll_runs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_run_items`
--
ALTER TABLE `payroll_run_items`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `payroll_salary_matrix`
--
ALTER TABLE `payroll_salary_matrix`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `procurement_match_rules`
--
ALTER TABLE `procurement_match_rules`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `products_media`
--
ALTER TABLE `products_media`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_audit_log`
--
ALTER TABLE `purchases_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT untuk tabel `purchases_ceisa_payment`
--
ALTER TABLE `purchases_ceisa_payment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_ceisa_pib`
--
ALTER TABLE `purchases_ceisa_pib`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarder_invoice`
--
ALTER TABLE `purchases_forwarder_invoice`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarder_payment`
--
ALTER TABLE `purchases_forwarder_payment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarder_quotes`
--
ALTER TABLE `purchases_forwarder_quotes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_forwarding_docs`
--
ALTER TABLE `purchases_forwarding_docs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_import_control`
--
ALTER TABLE `purchases_import_control`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT untuk tabel `purchases_invoice_ap`
--
ALTER TABLE `purchases_invoice_ap`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_invoice_ap_lines`
--
ALTER TABLE `purchases_invoice_ap_lines`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_payment_ap`
--
ALTER TABLE `purchases_payment_ap`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchases_po`
--
ALTER TABLE `purchases_po`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `purchases_po_items`
--
ALTER TABLE `purchases_po_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `sales_do`
--
ALTER TABLE `sales_do`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT untuk tabel `sales_do_audit`
--
ALTER TABLE `sales_do_audit`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT untuk tabel `sales_do_items`
--
ALTER TABLE `sales_do_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT untuk tabel `sales_do_tracking_events`
--
ALTER TABLE `sales_do_tracking_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `sales_marketplace_staging`
--
ALTER TABLE `sales_marketplace_staging`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stg_coa`
--
ALTER TABLE `stg_coa`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stg_documents`
--
ALTER TABLE `stg_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stg_items`
--
ALTER TABLE `stg_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stg_opening_balances`
--
ALTER TABLE `stg_opening_balances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stg_parties`
--
ALTER TABLE `stg_parties`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stg_tax_codes`
--
ALTER TABLE `stg_tax_codes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stg_units`
--
ALTER TABLE `stg_units`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stg_warehouses`
--
ALTER TABLE `stg_warehouses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `system_audit_logs`
--
ALTER TABLE `system_audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `system_config`
--
ALTER TABLE `system_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT untuk tabel `tax_invoices`
--
ALTER TABLE `tax_invoices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT untuk tabel `tax_invoice_logs`
--
ALTER TABLE `tax_invoice_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT untuk tabel `tax_profiles`
--
ALTER TABLE `tax_profiles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_allocations`
--
ALTER TABLE `wqs_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `wqs_incoming`
--
ALTER TABLE `wqs_incoming`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `wqs_incoming_items`
--
ALTER TABLE `wqs_incoming_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `wqs_picking`
--
ALTER TABLE `wqs_picking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `wqs_picking_items`
--
ALTER TABLE `wqs_picking_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `wqs_pr`
--
ALTER TABLE `wqs_pr`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `wqs_pr_items`
--
ALTER TABLE `wqs_pr_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock`
--
ALTER TABLE `wqs_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_adjustments`
--
ALTER TABLE `wqs_stock_adjustments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_adjustment_approvals`
--
ALTER TABLE `wqs_stock_adjustment_approvals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_opname`
--
ALTER TABLE `wqs_stock_opname`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_opname_attachments`
--
ALTER TABLE `wqs_stock_opname_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_opname_items`
--
ALTER TABLE `wqs_stock_opname_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_snapshot`
--
ALTER TABLE `wqs_stock_snapshot`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_transfer`
--
ALTER TABLE `wqs_stock_transfer`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_transfer_attachments`
--
ALTER TABLE `wqs_stock_transfer_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `wqs_stock_transfer_items`
--
ALTER TABLE `wqs_stock_transfer_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

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
-- Ketidakleluasaan untuk tabel `kpi_snapshot_approvals`
--
ALTER TABLE `kpi_snapshot_approvals`
  ADD CONSTRAINT `fk_kpi_snapshot_approvals_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `kpi_snapshot` (`id`) ON DELETE SET NULL;

--
-- Ketidakleluasaan untuk tabel `kpi_snapshot_items`
--
ALTER TABLE `kpi_snapshot_items`
  ADD CONSTRAINT `fk_kpi_snapshot_items_snapshot` FOREIGN KEY (`snapshot_id`) REFERENCES `kpi_snapshot` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `master_user`
--
ALTER TABLE `master_user`
  ADD CONSTRAINT `fk_mpr_customer` FOREIGN KEY (`customer_id`) REFERENCES `master_customers` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `migration_errors`
--
ALTER TABLE `migration_errors`
  ADD CONSTRAINT `fk_migration_errors_run` FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `migration_sales_receipts`
--
ALTER TABLE `migration_sales_receipts`
  ADD CONSTRAINT `fk_migration_sales_receipts_run` FOREIGN KEY (`migration_run_id`) REFERENCES `migration_runs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `migration_target_keys`
--
ALTER TABLE `migration_target_keys`
  ADD CONSTRAINT `fk_migration_target_run` FOREIGN KEY (`migration_run_id`) REFERENCES `migration_runs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

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
-- Ketidakleluasaan untuk tabel `stg_coa`
--
ALTER TABLE `stg_coa`
  ADD CONSTRAINT `fk_stg_coa_run` FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stg_documents`
--
ALTER TABLE `stg_documents`
  ADD CONSTRAINT `fk_stg_docs_run` FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stg_items`
--
ALTER TABLE `stg_items`
  ADD CONSTRAINT `fk_stg_items_run` FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stg_opening_balances`
--
ALTER TABLE `stg_opening_balances`
  ADD CONSTRAINT `fk_stg_opening_run` FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stg_parties`
--
ALTER TABLE `stg_parties`
  ADD CONSTRAINT `fk_stg_parties_run` FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stg_tax_codes`
--
ALTER TABLE `stg_tax_codes`
  ADD CONSTRAINT `fk_stg_tax_run` FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stg_units`
--
ALTER TABLE `stg_units`
  ADD CONSTRAINT `fk_stg_units_run` FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stg_warehouses`
--
ALTER TABLE `stg_warehouses`
  ADD CONSTRAINT `fk_stg_wh_run` FOREIGN KEY (`run_id`) REFERENCES `migration_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `wqs_stock_opname_attachments`
--
ALTER TABLE `wqs_stock_opname_attachments`
  ADD CONSTRAINT `fk_opname_att_opname` FOREIGN KEY (`opname_id`) REFERENCES `wqs_stock_opname` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `wqs_stock_opname_items`
--
ALTER TABLE `wqs_stock_opname_items`
  ADD CONSTRAINT `fk_opname_items_opname` FOREIGN KEY (`opname_id`) REFERENCES `wqs_stock_opname` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `wqs_stock_transfer_attachments`
--
ALTER TABLE `wqs_stock_transfer_attachments`
  ADD CONSTRAINT `fk_transfer_att_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `wqs_stock_transfer` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `wqs_stock_transfer_items`
--
ALTER TABLE `wqs_stock_transfer_items`
  ADD CONSTRAINT `fk_transfer_items_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `wqs_stock_transfer` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
