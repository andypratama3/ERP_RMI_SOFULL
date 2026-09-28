-- 151_pqp_rfq_quotations.sql
-- RFQ (Request for Quotation) - PQP buat RFQ, manufacturers submit quotation, PQP bandingkan harga

-- Tabel RFQ
CREATE TABLE IF NOT EXISTS pqp_rfq (
    id INT NOT NULL AUTO_INCREMENT,
    rfq_code VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    product_description TEXT,
    product_sku VARCHAR(50) DEFAULT NULL,
    quantity DECIMAL(15,2) DEFAULT NULL,
    unit VARCHAR(20) DEFAULT 'unit',
    spec_notes TEXT,
    deadline DATETIME NOT NULL,
    status ENUM('draft','open','closed','cancelled') NOT NULL DEFAULT 'draft',
    created_by VARCHAR(64) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rfq_code (rfq_code),
    KEY idx_status (status),
    KEY idx_deadline (deadline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel Quotation dari Manufacturer
CREATE TABLE IF NOT EXISTS pqp_rfq_quotations (
    id INT NOT NULL AUTO_INCREMENT,
    rfq_id INT NOT NULL,
    manufacture_id INT NOT NULL,
    manufacture_code VARCHAR(50) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    lead_time_days INT DEFAULT NULL,
    payment_terms VARCHAR(100) DEFAULT NULL,
    notes TEXT,
    file_rel VARCHAR(255) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'submitted',
    submitted_by VARCHAR(64) DEFAULT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_rfq_manufacture (rfq_id, manufacture_id),
    KEY idx_rfq_id (rfq_id),
    KEY idx_manufacture_id (manufacture_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
