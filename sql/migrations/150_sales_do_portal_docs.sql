-- 150_sales_do_portal_docs.sql
-- Dokumen pendukung dari Customer Portal (upload setelah order dibuat)

CREATE TABLE IF NOT EXISTS sales_do_portal_docs (
  id INT NOT NULL AUTO_INCREMENT,
  do_id INT NOT NULL,
  doc_type VARCHAR(40) NOT NULL DEFAULT 'OTHER' COMMENT 'PO|SURAT_PESANAN|LAINNYA',
  file_name VARCHAR(255) NOT NULL,
  file_rel VARCHAR(500) NOT NULL,
  note VARCHAR(255) DEFAULT NULL,
  uploaded_by VARCHAR(100) NOT NULL,
  portal_user_id INT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_do_id (do_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
