-- 042_payroll_salary_matrix.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

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

SET FOREIGN_KEY_CHECKS=1;
