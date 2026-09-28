-- 052_purchases_invoice_ap.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

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

SET FOREIGN_KEY_CHECKS=1;
