-- Migration 112: Marketplace & Payment webhook inbox tables
-- For Phase 3 compliance: store incoming webhook payloads for async processing

CREATE TABLE IF NOT EXISTS marketplace_orders_inbox (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(60) NOT NULL DEFAULT 'unknown',
  payload_json LONGTEXT NOT NULL,
  processed_at DATETIME NULL,
  processed_by VARCHAR(80) NULL,
  error_msg VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_processed (processed_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_callbacks_inbox (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source VARCHAR(60) NOT NULL DEFAULT 'unknown',
  payload_json LONGTEXT NOT NULL,
  processed_at DATETIME NULL,
  processed_by VARCHAR(80) NULL,
  error_msg VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_processed (processed_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
