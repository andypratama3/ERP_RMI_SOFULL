-- 029_master_office.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `master_office`
--

CREATE TABLE `master_office` (
  `id` int NOT NULL,
  `office_code` varchar(10) NOT NULL,
  `office_name` varchar(100) NOT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` varchar(100) DEFAULT 'SYSTEM',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `office_lat` decimal(10,7) DEFAULT NULL,
  `office_lng` decimal(10,7) DEFAULT NULL,
  `office_radius_m` int DEFAULT '120'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `master_office`
--

INSERT INTO `master_office` (`id`, `office_code`, `office_name`, `city`, `address`, `phone`, `is_active`, `created_by`, `created_at`, `updated_at`, `office_lat`, `office_lng`, `office_radius_m`) VALUES
(1, 'bgr', 'Rizqullah Mediska Indonesia', 'Kab. Bogor', 'Jalan Pondok Rajeg, Ruko Sentra Pondok Rajeg No. 7 & 8, Kel. Pondok Rajeg, Kec. Cibinong, Kabupaten Bogor, Provinsi Jawa Barat, 16914', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.5971470, 106.8060390, 150),
(2, 'bks', 'Rizqullah Mediska Indonesia Bekasi', 'Kota Bekasi', 'Perumahan The East View Residence Blok F 17, Jl. Raya Mustika Sari, Kel. Mustikasari, Kec. Mustikajaya, Kota Bekasi, Provinsi Jawa Barat, 17157', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.2382700, 106.9755700, 150),
(3, 'tgr', 'Rizqullah Mediska Indonesia Tangerang', 'Kota Tangerang', 'Jalan Muhamad Toha No. B26 Km. 0,6, Kel. Periuk, Kec. Periuk, Kota Tangerang, Provinsi Banten, 15131', '0813-8425-1574', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.1783060, 106.6318890, 150),
(4, 'bdg', 'Rizqullah Mediska Indonesia Bandung', 'Kota Bandung', 'Ruko Puri Dago Mas Unit 428, Jalan Terusan Jakarta, Kota Bandung, Provinsi Jawa Barat, 40293', '0812-1067-5863', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.9174640, 107.6191230, 150),
(5, 'slo', 'Rizqullah Mediska Indonesia Jawa Tengah (Solo)', 'Kota Surakarta', 'Jalan Pakel No. 06, Kel. Banyuanyar, Kec. Banjarsari, Kota Surakarta, Provinsi Jawa Tengah, 57137', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -7.5666670, 110.8166670, 150),
(6, 'smg', 'Rizqullah Mediska Indonesia Semarang', 'Kota Semarang', 'Ruko Tlogo Timun Mas No. 1A Kav. C, Kel. Tlogosari Kulon, Kec. Pedurungan, Kota Semarang, Provinsi Jawa Tengah, 50196', '0852-8336-4900', 1, 'SYSTEM', '2025-12-08 09:22:59', '2026-01-02 01:40:23', -6.9666670, 110.4166670, 150),
(7, 'kal', 'Depo Kalimantan', 'Kalimantan', '', NULL, 1, 'SYSTEM', '2025-12-08 09:22:59', '2025-12-08 09:22:59', NULL, NULL, 120),
(8, 'jgy', 'Depo Yogyakarta', 'Yogyakarta', '', NULL, 1, 'SYSTEM', '2025-12-08 09:22:59', '2025-12-08 09:22:59', NULL, NULL, 120),
(9, 'sys', 'SYS', 'SYS', '', '', 0, 'SYSTEM', '2026-01-02 05:55:45', '2026-01-02 07:22:59', NULL, NULL, 120);

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
