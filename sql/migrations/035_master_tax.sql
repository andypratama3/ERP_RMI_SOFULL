-- 035_master_tax.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_tax`
--

CREATE TABLE `master_tax` (
  `id` int NOT NULL,
  `tax_code` varchar(20) NOT NULL,
  `tax_name` varchar(100) NOT NULL,
  `tax_type` varchar(20) NOT NULL,
  `rate_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `level_type` varchar(20) NOT NULL DEFAULT 'Transaction',
  `office_scope` varchar(50) DEFAULT 'All',
  `description` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_tax`
--

INSERT INTO `master_tax` (`id`, `tax_code`, `tax_name`, `tax_type`, `rate_percent`, `level_type`, `office_scope`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'NONPPN', 'Non PPN', 'PPN', 0.00, 'Transaction', 'All', 'Tanpa PPN', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(2, 'PPN11', 'PPN 11%', 'PPN', 11.00, 'Transaction', 'All', 'PPN 11%', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(3, 'PPN12', 'PPN 12%', 'PPN', 12.00, 'Transaction', 'All', 'Siap jika aturan berubah', 'inactive', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(4, 'PPh21_ALL', 'PPh 21 (All Office)', 'PPh', 0.00, 'Other', 'All', 'Untuk payroll / gaji karyawan', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(5, 'PPN_ALL', 'PPN (All Office)', 'PPN', 0.00, 'Other', 'All', 'Umum PPN by office', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(6, 'PPhFINAL_SS', 'PPh Final (Semarang & Solo)', 'PPh', 0.00, 'Other', 'Semarang,Solo', 'PPh Final cabang tertentu', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(7, 'PPh25_BBB', 'PPh 25 (Bogor, Bekasi, Bandung)', 'PPh', 0.00, 'Other', 'Bogor,Bekasi,Bandung', 'PPh 25 bulanan', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(8, 'PPh23_BOG', 'PPh 23 (Bogor)', 'PPh', 2.00, 'Other', 'Bogor', 'Contoh PPh 23 cabang Bogor', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52'),
(9, 'SPT_TAHUNAN', 'SPT Tahunan (All Cabang)', 'SPT', 0.00, 'Other', 'All', 'SPT Tahunan perusahaan', 'active', '2025-12-11 03:48:52', '2025-12-11 03:48:52');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
