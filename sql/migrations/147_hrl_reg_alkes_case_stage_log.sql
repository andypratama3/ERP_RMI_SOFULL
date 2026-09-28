-- 147_hrl_reg_alkes_case_stage_log.sql
-- Stage log untuk time-in-stage Reg Alkes (funnel avg days)
-- Log: case masuk stage X (entered_at), keluar stage X (exited_at)

CREATE TABLE IF NOT EXISTS hrl_reg_alkes_case_stage_log (
  id INT NOT NULL AUTO_INCREMENT,
  case_id INT NOT NULL,
  stage_no INT NOT NULL,
  entered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  exited_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_case_stage (case_id, stage_no),
  KEY idx_case_exited (case_id, exited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
