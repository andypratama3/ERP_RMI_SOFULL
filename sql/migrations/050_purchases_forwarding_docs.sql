-- 050_purchases_forwarding_docs.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `purchases_forwarding_docs`
--

CREATE TABLE `purchases_forwarding_docs` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `doc_type` varchar(30) NOT NULL,
  `doc_number` varchar(80) DEFAULT NULL,
  `doc_date` date DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `note` text,
  `uploaded_by` varchar(100) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
