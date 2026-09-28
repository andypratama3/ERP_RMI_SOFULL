-- 054_purchases_po.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `purchases_po`
--

CREATE TABLE `purchases_po` (
  `id` int NOT NULL,
  `po_code` varchar(60) NOT NULL,
  `po_date` date NOT NULL,
  `pr_id` int DEFAULT NULL,
  `manufacture_id` int DEFAULT NULL,
  `factory_forwarding_info` text,
  `forwarder_vendor_id` int DEFAULT NULL,
  `forwarder_status` varchar(30) NOT NULL DEFAULT 'PENDING',
  `forwarder_note` text,
  `office_code` varchar(20) DEFAULT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `payment_term` varchar(30) DEFAULT NULL,
  `note` text,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_po`
--

INSERT INTO `purchases_po` (`id`, `po_code`, `po_date`, `pr_id`, `manufacture_id`, `factory_forwarding_info`, `forwarder_vendor_id`, `forwarder_status`, `forwarder_note`, `office_code`, `currency`, `payment_term`, `note`, `status`, `total_amount`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 'RMI-PO-BGR-260101-001', '2026-01-01', 1, 1, NULL, 1, 'IN_PROGRESS', NULL, 'BGR', 'CNY', '', '', 'IN_PRODUCTION', 101000.00, 'admin', '2026-01-02 01:02:50', '2026-01-02 07:15:49', NULL);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
