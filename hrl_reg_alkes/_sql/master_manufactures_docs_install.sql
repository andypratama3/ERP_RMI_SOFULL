-- MASTER Manufactures Docs installer
-- Membuat tabel master_manufactures_docs untuk dokumen pabrikan (PKS/LOA/LOA_KBRI)
CREATE TABLE IF NOT EXISTS master_manufactures_docs (
    id INT NOT NULL AUTO_INCREMENT,
    manufacture_id INT NOT NULL,
    doc_type VARCHAR(40) NOT NULL,
    doc_status VARCHAR(20) DEFAULT NULL,
    doc_no VARCHAR(80) DEFAULT NULL,
    doc_date DATE DEFAULT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_rel VARCHAR(255) NOT NULL,
    note VARCHAR(255) DEFAULT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uploaded_by VARCHAR(64) DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_manu (manufacture_id),
    KEY idx_type (doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
