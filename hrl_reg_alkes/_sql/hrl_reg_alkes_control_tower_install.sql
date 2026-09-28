-- HRL REG Alkes Control Tower installer
-- Membuat tabel (cases + docs) untuk tower.
-- Jika sudah ada tabel, akan di-skip.
-- COLLATE utf8mb4_general_ci: selaras dengan erp_rmi_sofull.sql & menghindari mix collation error.

CREATE TABLE IF NOT EXISTS hrl_reg_alkes_cases (
            id INT NOT NULL AUTO_INCREMENT,
            case_code VARCHAR(40) NOT NULL,
            manufacture_id INT NULL,
            manufacture_code VARCHAR(60) NOT NULL,
            manufacture_name VARCHAR(200) NULL,
            product_name VARCHAR(200) NOT NULL,
            is_oem TINYINT(1) NOT NULL DEFAULT 0,
            stage_no INT NOT NULL DEFAULT 1,
            stage_code VARCHAR(40) NULL,
            next_pic_dept VARCHAR(10) NULL,
            revision_count TINYINT NOT NULL DEFAULT 0,
            revision_deadline DATE DEFAULT NULL,
            oss_pb_umku VARCHAR(120) DEFAULT NULL,
            regalkes_ref VARCHAR(120) DEFAULT NULL,
            nie_type VARCHAR(10) DEFAULT NULL,
            nie_no VARCHAR(150) DEFAULT NULL,
            nie_issue_date DATE DEFAULT NULL,
            nie_file_rel VARCHAR(255) DEFAULT NULL,
            sku_imported_count INT NOT NULL DEFAULT 0,
            sku_last_import_at DATETIME DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
            note TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by VARCHAR(64) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by VARCHAR(64) DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            closed_by VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_case_code (case_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS hrl_reg_alkes_case_docs (
            id INT NOT NULL AUTO_INCREMENT,
            case_id INT NOT NULL,
            doc_type VARCHAR(40) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_rel VARCHAR(255) NOT NULL,
            note VARCHAR(255) DEFAULT NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            uploaded_by VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_case (case_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
