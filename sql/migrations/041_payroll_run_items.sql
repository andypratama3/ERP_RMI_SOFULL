-- 041_payroll_run_items.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

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

SET FOREIGN_KEY_CHECKS=1;
