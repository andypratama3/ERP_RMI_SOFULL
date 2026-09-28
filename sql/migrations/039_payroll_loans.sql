-- 039_payroll_loans.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

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

SET FOREIGN_KEY_CHECKS=1;
