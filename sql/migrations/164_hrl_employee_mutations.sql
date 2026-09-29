-- 164_hrl_employee_mutations.sql
-- Tabel workflow mutasi karyawan (HRL Process) + riwayat penugasan.
-- Keduanya dipakai hrl_process/employee_mutations.php, employee_mutation_view.php,
-- employee_mutation_apply_due.php dan dashboards/hrl/hrl_dashboard.php, tetapi
-- belum pernah dibuat di dump SQL mana pun -> halaman fatal 1146 table doesn't exist.
-- Semua kolom di bawah diturunkan dari query INSERT/UPDATE/SELECT di file-file tersebut.

CREATE TABLE IF NOT EXISTS hrl_employee_mutations (
  id                  BIGINT NOT NULL AUTO_INCREMENT,
  mutation_code       VARCHAR(50) NULL,
  employee_id         BIGINT NOT NULL,
  employee_code       VARCHAR(50) NOT NULL DEFAULT '',

  from_dept_code      VARCHAR(50) NULL,
  from_office_code    VARCHAR(50) NULL,
  from_position_name  VARCHAR(150) NULL,
  from_role_code      VARCHAR(50) NULL,

  to_dept_code        VARCHAR(50) NULL,
  to_office_code      VARCHAR(50) NULL,
  to_position_name    VARCHAR(150) NULL,
  to_role_code        VARCHAR(50) NULL,

  effective_date      DATE NOT NULL,
  reason              TEXT NULL,

  status              VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  submitted_by        VARCHAR(80) NULL,
  submitted_at        DATETIME NULL,
  approved_by         VARCHAR(80) NULL,
  approved_at         DATETIME NULL,
  scheduled_at        DATETIME NULL,
  rejected_by         VARCHAR(80) NULL,
  rejected_at         DATETIME NULL,
  reject_reason       TEXT NULL,
  cancelled_by        VARCHAR(80) NULL,
  cancelled_at        DATETIME NULL,
  cancel_reason       TEXT NULL,
  effective_by        VARCHAR(80) NULL,
  effective_at        DATETIME NULL,

  created_by          VARCHAR(80) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NULL,

  PRIMARY KEY (id),
  KEY idx_hrlm_employee (employee_id, status),
  KEY idx_hrlm_code (mutation_code),
  KEY idx_hrlm_status_due (status, effective_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS hrl_employee_assignment_history (
  id              BIGINT NOT NULL AUTO_INCREMENT,
  employee_id     BIGINT NOT NULL,
  employee_code   VARCHAR(50) NOT NULL DEFAULT '',
  dept_code       VARCHAR(50) NULL,
  office_code     VARCHAR(50) NULL,
  position_name   VARCHAR(150) NULL,
  role_code       VARCHAR(50) NULL,
  effective_from   DATE NOT NULL,
  effective_to    DATE NULL,
  source          VARCHAR(20) NOT NULL DEFAULT 'MUTATION',
  source_id       BIGINT NULL,
  created_by      VARCHAR(80) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hrlah_employee (employee_id, effective_from),
  KEY idx_hrlah_open (employee_id, effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
