-- 143_api_partner_order_idempotency.sql
-- Idempotency untuk H2H order_create (cegah duplikat saat retry)

CREATE TABLE IF NOT EXISTS api_partner_order_idempotency (
    id INT NOT NULL AUTO_INCREMENT,
    partner_id INT NOT NULL,
    idempotency_key VARCHAR(120) NOT NULL,
    do_id INT NOT NULL,
    do_code VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_partner_idem (partner_id, idempotency_key),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
