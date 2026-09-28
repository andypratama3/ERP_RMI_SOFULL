-- 059_sales_do.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `sales_do`
--

CREATE TABLE `sales_do` (
  `id` int NOT NULL,
  `do_code` varchar(50) NOT NULL,
  `tracking_code` varchar(30) DEFAULT NULL,
  `do_date` date NOT NULL,
  `customer_id` int DEFAULT NULL,
  `customers_code` varchar(50) NOT NULL,
  `office_code` varchar(20) NOT NULL,
  `sales_emp_code` varchar(50) DEFAULT NULL,
  `shipping_address` text,
  `customer_pic` varchar(150) DEFAULT NULL,
  `customer_phone` varchar(50) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'DRAFT',
  `crm_start_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `crm_end_time` datetime DEFAULT NULL,
  `status_wqs` varchar(20) NOT NULL DEFAULT 'pending',
  `wqs_note` text,
  `wqs_updated_at` datetime DEFAULT NULL,
  `status_scm` varchar(20) NOT NULL DEFAULT 'pending',
  `scm_note` text,
  `scm_updated_at` datetime DEFAULT NULL,
  `status_act` varchar(20) NOT NULL DEFAULT 'pending',
  `act_note` text,
  `act_updated_at` datetime DEFAULT NULL,
  `status_fin` varchar(20) NOT NULL DEFAULT 'pending',
  `fin_due_date` date DEFAULT NULL,
  `fin_paid_date` date DEFAULT NULL,
  `fin_paid_amount` decimal(18,2) DEFAULT '0.00',
  `fin_note` text,
  `fin_updated_at` datetime DEFAULT NULL,
  `wqs_status` varchar(20) NOT NULL DEFAULT 'pending',
  `scm_status` varchar(20) NOT NULL DEFAULT 'pending',
  `act_status` varchar(20) NOT NULL DEFAULT 'pending',
  `fin_status` varchar(20) NOT NULL DEFAULT 'pending',
  `note` text,
  `total_amount` decimal(18,2) NOT NULL DEFAULT '0.00',
  `tax_code` varchar(20) DEFAULT NULL,
  `tax_included` tinyint(1) DEFAULT '0',
  `tax_rate_percent` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(18,2) DEFAULT '0.00',
  `grand_total` decimal(18,2) DEFAULT '0.00',
  `price_include_tax` tinyint(1) DEFAULT '0',
  `crm_status` varchar(20) NOT NULL DEFAULT 'crm_to_wqs',
  `flow_status` varchar(20) NOT NULL DEFAULT 'CRM',
  `crm_duration_seconds` int DEFAULT NULL,
  `crm_started_at` datetime DEFAULT NULL,
  `crm_finished_at` datetime DEFAULT NULL,
  `is_price_include_tax` tinyint(1) DEFAULT '0',
  `crm_created_at` datetime DEFAULT NULL,
  `crm_start_at` datetime DEFAULT NULL,
  `crm_finish_at` datetime DEFAULT NULL,
  `crm_duration_sec` int DEFAULT NULL,
  `wqs_picked_at` datetime DEFAULT NULL,
  `scm_delivered_at` datetime DEFAULT NULL,
  `act_invoiced_at` datetime DEFAULT NULL,
  `fin_paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `wqs_stock_before` varchar(255) DEFAULT NULL,
  `wqs_stock_after` varchar(255) DEFAULT NULL,
  `wqs_started_at` datetime DEFAULT NULL,
  `wqs_ready_at` datetime DEFAULT NULL,
  `scm_on_delivery_at` datetime DEFAULT NULL,
  `delivery_mode` varchar(20) DEFAULT NULL,
  `delivery_vendor_id` int DEFAULT NULL,
  `act_due_date` date DEFAULT NULL,
  `act_amount` decimal(15,2) DEFAULT NULL,
  `act_ready_fin_at` datetime DEFAULT NULL,
  `last_updated_by` varchar(50) DEFAULT NULL,
  `last_updated_at` datetime DEFAULT NULL,
  `fin_payment_file` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `sales_do`
--

INSERT INTO `sales_do` (`id`, `do_code`, `tracking_code`, `do_date`, `customer_id`, `customers_code`, `office_code`, `sales_emp_code`, `shipping_address`, `customer_pic`, `customer_phone`, `status`, `crm_start_time`, `crm_end_time`, `status_wqs`, `wqs_note`, `wqs_updated_at`, `status_scm`, `scm_note`, `scm_updated_at`, `status_act`, `act_note`, `act_updated_at`, `status_fin`, `fin_due_date`, `fin_paid_date`, `fin_paid_amount`, `fin_note`, `fin_updated_at`, `wqs_status`, `scm_status`, `act_status`, `fin_status`, `note`, `total_amount`, `tax_code`, `tax_included`, `tax_rate_percent`, `tax_amount`, `grand_total`, `price_include_tax`, `crm_status`, `flow_status`, `crm_duration_seconds`, `crm_started_at`, `crm_finished_at`, `is_price_include_tax`, `crm_created_at`, `crm_start_at`, `crm_finish_at`, `crm_duration_sec`, `wqs_picked_at`, `scm_delivered_at`, `act_invoiced_at`, `fin_paid_at`, `created_at`, `updated_at`, `wqs_stock_before`, `wqs_stock_after`, `wqs_started_at`, `wqs_ready_at`, `scm_on_delivery_at`, `delivery_mode`, `delivery_vendor_id`, `act_due_date`, `act_amount`, `act_ready_fin_at`, `last_updated_by`, `last_updated_at`, `fin_payment_file`) VALUES
(15, 'RMI-BGR-20251211-007', 'RMI-BGR-20251211-007', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'fin_done', '2025-12-11 20:36:46', NULL, 'done', NULL, '2025-12-11 15:32:12', 'done', NULL, '2025-12-11 15:49:52', 'done', NULL, '2025-12-11 16:09:56', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 16:18:46', 'pending', 'pending', 'pending', 'pending', '', 75000.00, 'PPN11', 0, 11.00, 8250.00, 83250.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 13:43:15', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11 13:43:15', '2025-12-11 16:18:46', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(16, 'RMI-BGR-20251211-008', 'RMI-BGR-20251211-008', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'fin_done', '2025-12-11 20:36:46', NULL, 'open', NULL, '2025-12-11 15:32:06', 'open', NULL, '2025-12-11 15:49:46', 'done', NULL, '2025-12-11 16:19:21', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 16:19:32', 'pending', 'pending', 'pending', 'pending', '', 50000.00, '', 0, 0.00, 0.00, 50000.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 13:44:54', NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2025-12-11 13:44:54', '2025-12-11 16:19:32', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'RMI-BGR-251211-001', 'RMI-BGR-251211-001', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'wqs_done', '2025-12-11 20:36:46', NULL, 'done', NULL, '2025-12-11 19:48:56', 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', 'Cito', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 19:47:56', '2025-12-11 19:45:56', '2025-12-11 19:47:56', 120, NULL, NULL, NULL, NULL, '2025-12-11 19:47:56', '2025-12-11 19:48:56', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'RMI-BGR-251211-002', 'RMI-BGR-251211-002', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'crm_to_wqs', '2025-12-11 20:36:46', NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 20:20:29', '2025-12-11 20:19:59', '2025-12-11 20:20:29', 30, NULL, NULL, NULL, NULL, '2025-12-11 20:20:29', '2025-12-11 20:20:29', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 'RMI-BGR-251211-003', 'RMI-BGR-251211-003', '2025-12-11', NULL, 'Hoo1', 'BGR', NULL, '', '', '', 'crm_to_wqs', '2025-12-11 20:39:43', '2025-12-11 20:39:43', 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 'pending', NULL, NULL, 0.00, NULL, NULL, 'pending', 'pending', 'pending', 'pending', '', 50000.00, 'PPN11', 0, 11.00, 5500.00, 55500.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 20:39:43', NULL, NULL, 0, NULL, NULL, NULL, NULL, '2025-12-11 20:39:43', '2025-12-11 20:39:43', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(20, 'RMI-TGR-251211-001', 'RMI-TGR-251211-001', '2025-12-11', NULL, 'H042;RS HERMINA SERPONG;RS Swasta;Hermina;Kota Tan', 'TGR', NULL, '', '', '', 'fin_done', '2025-12-11 23:51:36', NULL, 'done', NULL, '2025-12-11 23:52:06', 'done', NULL, '2025-12-11 23:52:19', 'done', NULL, '2025-12-11 23:52:40', 'paid', NULL, NULL, 0.00, NULL, '2025-12-11 23:52:53', 'pending', 'pending', 'pending', 'pending', '', 0.00, 'PPN11', 0, 11.00, 0.00, 0.00, 0, 'crm_to_wqs', 'CRM', NULL, NULL, NULL, 0, '2025-12-11 23:51:36', '2025-12-11 23:51:05', '2025-12-11 23:51:36', 31, NULL, NULL, NULL, NULL, '2025-12-11 23:51:36', '2025-12-11 23:52:53', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
