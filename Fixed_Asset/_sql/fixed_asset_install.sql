-- Fixed_Asset_Lite installer (MySQL)
-- Jalankan di database ERP_RMI_SOFULL
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS fa_assets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_code VARCHAR(50) NOT NULL UNIQUE,
  asset_name VARCHAR(200) NOT NULL,
  category VARCHAR(100) DEFAULT '',
  office_code VARCHAR(30) DEFAULT '',
  dept_code VARCHAR(30) DEFAULT '',
  custodian_emp_id INT DEFAULT NULL,
  vendor_name VARCHAR(200) DEFAULT '',
  purchase_ref VARCHAR(100) DEFAULT '',
  invoice_no VARCHAR(100) DEFAULT '',
  acq_date DATE NOT NULL,
  acq_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
  salvage_value DECIMAL(18,2) NOT NULL DEFAULT 0,
  tax_group_code VARCHAR(20) NOT NULL,
  dep_method ENUM('SL','DDB') NOT NULL DEFAULT 'SL',
  status ENUM('ACTIVE','INACTIVE','DISPOSED') NOT NULL DEFAULT 'ACTIVE',
  disposed_at DATE DEFAULT NULL,
  notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  deleted_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fa_dep_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_ym VARCHAR(7) NOT NULL,
  run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  run_by INT DEFAULT NULL,
  total_assets INT NOT NULL DEFAULT 0,
  total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_dep_period (period_ym)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fa_dep_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  asset_id INT NOT NULL,
  period_ym VARCHAR(7) NOT NULL,
  opening_book DECIMAL(18,2) NOT NULL DEFAULT 0,
  dep_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  closing_book DECIMAL(18,2) NOT NULL DEFAULT 0,
  accum_after DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dep_asset_period (asset_id, period_ym),
  CONSTRAINT fk_dep_run FOREIGN KEY (run_id) REFERENCES fa_dep_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_dep_asset FOREIGN KEY (asset_id) REFERENCES fa_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fa_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  transfer_date DATE NOT NULL,
  from_office VARCHAR(30) DEFAULT '',
  to_office VARCHAR(30) DEFAULT '',
  from_dept VARCHAR(30) DEFAULT '',
  to_dept VARCHAR(30) DEFAULT '',
  from_custodian INT DEFAULT NULL,
  to_custodian INT DEFAULT NULL,
  notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tr_asset FOREIGN KEY(asset_id) REFERENCES fa_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fa_maintenance (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  maint_date DATE NOT NULL,
  vendor VARCHAR(200) DEFAULT '',
  cost DECIMAL(18,2) NOT NULL DEFAULT 0,
  downtime_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
  description TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mt_asset FOREIGN KEY(asset_id) REFERENCES fa_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fa_disposals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  asset_id INT NOT NULL,
  disposal_date DATE NOT NULL,
  disposal_type ENUM('SOLD','DAMAGED','LOST') NOT NULL,
  proceeds DECIMAL(18,2) NOT NULL DEFAULT 0,
  doc_ref VARCHAR(120) DEFAULT '',
  notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ds_asset FOREIGN KEY(asset_id) REFERENCES fa_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fa_audits (
  id INT AUTO_INCREMENT PRIMARY KEY,
  audit_code VARCHAR(50) NOT NULL UNIQUE,
  office_code VARCHAR(30) DEFAULT '',
  audit_date DATE NOT NULL,
  status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  created_by INT DEFAULT NULL,
  closed_by INT DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  notes TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fa_audit_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  audit_id INT NOT NULL,
  asset_id INT NOT NULL,
  physical_status ENUM('OK','MISSING','DAMAGED','NOT_FOUND') NOT NULL DEFAULT 'NOT_FOUND',
  note VARCHAR(255) DEFAULT '',
  updated_at DATETIME DEFAULT NULL,
  KEY idx_audit_asset (audit_id, asset_id),
  CONSTRAINT fk_al_audit FOREIGN KEY(audit_id) REFERENCES fa_audits(id) ON DELETE CASCADE,
  CONSTRAINT fk_al_asset FOREIGN KEY(asset_id) REFERENCES fa_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fa_audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  user_id INT DEFAULT NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(50) NOT NULL DEFAULT 'SYSTEM',
  entity_id INT NOT NULL DEFAULT 0,
  meta_json LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- optional: indexes for speed
-- CREATE INDEX IF NOT EXISTS idx_assets_office ON fa_assets(office_code);  -- disabled for MySQL compatibility
-- CREATE INDEX IF NOT EXISTS idx_assets_dept ON fa_assets(dept_code);  -- disabled for MySQL compatibility
-- CREATE INDEX IF NOT EXISTS idx_assets_status ON fa_assets(status);  -- disabled for MySQL compatibility