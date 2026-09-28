-- 030_master_payment_terms.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_payment_terms`
--

CREATE TABLE `master_payment_terms` (
  `id` int NOT NULL,
  `payment_terms_code` varchar(30) NOT NULL,
  `payment_terms_name` varchar(150) NOT NULL,
  `days_due` int DEFAULT '0',
  `description` text,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_payment_terms`
--

INSERT INTO `master_payment_terms` (`id`, `payment_terms_code`, `payment_terms_name`, `days_due`, `description`, `status`, `created_at`, `updated_at`) VALUES
(1, 'COD', 'Cash on Delivery', 0, 'Pembayaran saat barang diterima', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(2, 'CBD', 'Cash Before Delivery', 0, 'Harus lunas sebelum pengiriman', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(3, 'TOP7', 'TOP 7 Hari', 7, 'Jatuh tempo 7 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(4, 'TOP14', 'TOP 14 Hari', 14, 'Jatuh tempo 14 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(5, 'TOP30', 'TOP 30 Hari', 30, 'Jatuh tempo 30 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(6, 'TOP45', 'TOP 45 Hari', 45, 'Jatuh tempo 45 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50'),
(7, 'TOP60', 'TOP 60 Hari', 60, 'Jatuh tempo 60 hari dari invoice', 'active', '2025-12-10 21:17:50', '2025-12-10 21:17:50');

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
