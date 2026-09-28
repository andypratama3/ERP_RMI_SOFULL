-- 038_payroll_employee_settings.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

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

SET FOREIGN_KEY_CHECKS=1;
