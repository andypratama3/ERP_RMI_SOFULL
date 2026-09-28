-- 019_hrl_doc_acks.sql
-- Extracted from ERP_RMI_SOFULL.sql

SET FOREIGN_KEY_CHECKS=0;

--
-- Struktur dari tabel `hrl_doc_acks`
--

CREATE TABLE `hrl_doc_acks` (
  `id` int NOT NULL,
  `doc_id` int NOT NULL,
  `version_no` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `employee_code` varchar(50) DEFAULT NULL,
  `department` varchar(10) DEFAULT NULL,
  `office_code` varchar(20) DEFAULT NULL,
  `ack_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ack_ip` varchar(45) DEFAULT NULL,
  `ack_user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

SET FOREIGN_KEY_CHECKS=1;
