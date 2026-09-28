-- 140_manufacturer_portal_users.sql
-- Manufacturer Portal: user login untuk pabrikan (Reg Alkes, upload dokumen)

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `manufacturer_portal_users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `manufacture_code` varchar(50) NOT NULL,
  `manufacture_id` int DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_manufacture_code` (`manufacture_code`),
  KEY `idx_manufacture_id` (`manufacture_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
