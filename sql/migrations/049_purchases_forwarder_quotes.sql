-- 049_purchases_forwarder_quotes.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `purchases_forwarder_quotes`
--

CREATE TABLE `purchases_forwarder_quotes` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `vendor_id` int NOT NULL,
  `quote_date` date NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'IDR',
  `total_cost` decimal(18,2) NOT NULL DEFAULT '0.00',
  `leadtime_days` int NOT NULL DEFAULT '0',
  `note` text,
  `status` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `purchases_forwarder_quotes`
--

INSERT INTO `purchases_forwarder_quotes` (`id`, `po_id`, `vendor_id`, `quote_date`, `currency`, `total_cost`, `leadtime_days`, `note`, `status`, `created_by`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, 1, 1, '2026-01-01', 'IDR', 100000000.00, 20, '', 'SELECTED', 'admin', '2026-01-02 01:53:51', '2026-01-02 01:53:56', NULL);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
