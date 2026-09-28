-- 046_purchases_ceisa_pib.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

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

SET FOREIGN_KEY_CHECKS=1;
