-- 152_pqp_rfq_extras.sql
-- Chat/komentar RFQ, currency rates, audit support

-- Chat/komentar per RFQ
CREATE TABLE IF NOT EXISTS pqp_rfq_comments (
    id INT NOT NULL AUTO_INCREMENT,
    rfq_id INT NOT NULL,
    author_type VARCHAR(20) NOT NULL COMMENT 'pqp|manufacturer',
    author_id INT DEFAULT NULL COMMENT 'user_id or manufacturer_portal_users.id',
    author_name VARCHAR(100) NOT NULL,
    manufacture_code VARCHAR(50) DEFAULT NULL COMMENT 'untuk manufacturer: manufacture mana',
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rfq_id (rfq_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Currency rates: gunakan system_config atau fallback di PHP
