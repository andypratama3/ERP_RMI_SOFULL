-- 045_purchases_ceisa_payment.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

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

SET FOREIGN_KEY_CHECKS=1;
