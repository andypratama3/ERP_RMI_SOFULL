-- 026_master_employees.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

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

SET FOREIGN_KEY_CHECKS=1;
