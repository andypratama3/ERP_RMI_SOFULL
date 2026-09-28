-- 047_purchases_forwarder_invoice.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

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

SET FOREIGN_KEY_CHECKS=1;
