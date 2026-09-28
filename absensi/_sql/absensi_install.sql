-- RMI Absensi by Photo (Enterprise+++ Fase 1-3) + GeoFence
-- Catatan: Modul juga auto-create table saat dibuka, jadi SQL ini opsional.
-- Charset: utf8mb4

CREATE TABLE IF NOT EXISTS absensi_settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS absensi_offices (
  office_code VARCHAR(32) PRIMARY KEY,
  office_name VARCHAR(120) NOT NULL,
  lat DECIMAL(10,7) NULL,
  lng DECIMAL(10,7) NULL,
  radius_m INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS absensi_user_profile (
  user_id BIGINT PRIMARY KEY,
  office_code VARCHAR(32) NULL,
  is_hr_admin TINYINT(1) NOT NULL DEFAULT 0,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS absensi_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NULL,
  username VARCHAR(80) NULL,
  action_type VARCHAR(16) NOT NULL,
  office_code VARCHAR(32) NULL,
  distance_m INT NULL,
  geo_lat DECIMAL(10,7) NULL,
  geo_lng DECIMAL(10,7) NULL,
  geo_acc INT NULL,
  photo_path VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS absensi_requests (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT NOT NULL,
  username VARCHAR(80) NOT NULL,
  req_type VARCHAR(16) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  reason TEXT NULL,
  photo_path VARCHAR(255) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  approver_id BIGINT NULL,
  approver_name VARCHAR(80) NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS absensi_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT NULL,
  actor_username VARCHAR(80) NULL,
  action VARCHAR(48) NOT NULL,
  payload_json JSON NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Shift: definisi jadwal kerja (bisa lebih dari 1 shift)
CREATE TABLE IF NOT EXISTS absensi_shifts (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  shift_name      VARCHAR(80)  NOT NULL,
  checkin_time    TIME         NOT NULL COMMENT 'jam masuk standar, format HH:MM:SS',
  checkout_time   TIME         NOT NULL COMMENT 'jam pulang standar',
  late_tolerance_min    INT    NOT NULL DEFAULT 0  COMMENT 'toleransi keterlambatan (menit)',
  overtime_threshold_min INT   NOT NULL DEFAULT 30 COMMENT 'minimal menit checkout setelah jam pulang agar dihitung lembur',
  is_overnight    TINYINT(1)   NOT NULL DEFAULT 0  COMMENT '1 jika shift melewati tengah malam (mis 22:00-06:00)',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  note            VARCHAR(255) NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Penugasan shift per user (bisa diatur per tanggal efektif)
-- Jika user tidak punya row di sini → pakai shift default (default_shift_id di absensi_settings)
CREATE TABLE IF NOT EXISTS absensi_user_shifts (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(80)  NOT NULL,
  shift_id       INT          NOT NULL,
  effective_date DATE         NOT NULL COMMENT 'berlaku mulai tanggal ini',
  end_date       DATE         NULL      COMMENT 'NULL = permanen',
  note           VARCHAR(255) NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_date (username, effective_date),
  FOREIGN KEY (shift_id) REFERENCES absensi_shifts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: 2 shift default (Pagi + Siang) sesuai kebutuhan umum
INSERT IGNORE INTO absensi_settings (k,v,updated_at) VALUES ('geofence_enforce','1',NOW());
INSERT IGNORE INTO absensi_settings (k,v,updated_at) VALUES ('checkin_std_time','08:30',NOW());
INSERT IGNORE INTO absensi_settings (k,v,updated_at) VALUES ('checkout_std_time','17:00',NOW());
INSERT IGNORE INTO absensi_settings (k,v,updated_at) VALUES ('late_tolerance_min','0',NOW());
INSERT IGNORE INTO absensi_settings (k,v,updated_at) VALUES ('default_shift_id','1',NOW());

-- Shift default: Pagi (id=1) dan Siang (id=2)
INSERT IGNORE INTO absensi_shifts (id,shift_name,checkin_time,checkout_time,late_tolerance_min,overtime_threshold_min,is_overnight,is_active,note)
VALUES (1,'Shift Pagi','08:00:00','17:00:00',30,30,0,1,'Jam kerja normal kantor'),
       (2,'Shift Siang','13:00:00','22:00:00',30,30,0,1,'Shift siang/sore');
INSERT IGNORE INTO absensi_offices (office_code, office_name, radius_m, is_active, updated_at)
VALUES ('DEFAULT','Kantor Default',120,1,NOW());
