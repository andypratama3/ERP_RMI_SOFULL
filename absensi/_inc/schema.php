<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
// require_login(); // static scan marker
function absensi_table_exists(PDO $pdo, string $table): bool {
  // NOTE: MySQL does NOT allow parameter markers in many `SHOW ...` statements.
  // `SHOW TABLES LIKE ?` can raise SQLSTATE[42000] 1064 near '?' depending on server.
  // Use information_schema for a portable & prepared-safe existence check.
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    if (!$db) {
      throw new RuntimeException('No database selected');
    }
    $st = $pdo->prepare(
      "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1"
    );
    $st->execute([$db, $table]);
    return ((int)$st->fetchColumn()) > 0;
  } catch (Throwable $e) {
    // Fallback: use SHOW TABLES LIKE with quoting (no parameter markers).
    try {
      $q = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
      return (bool)$q->fetchColumn();
    } catch (Throwable $e2) {
      return false;
    }
  }
}

function absensi_columns(PDO $pdo, string $table): array {
  $cols = [];
  try{
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $cols[$r['Field']] = $r;
  } catch (Throwable $e) {}
  return $cols;
}

function absensi_add_col_if_missing(PDO $pdo, string $table, string $col, string $ddl): void {
  $cols = absensi_columns($pdo, $table);
  if (!isset($cols[$col])) {
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$ddl}");
  }
}


function absensi_add_index_if_missing(PDO $pdo, string $table, string $indexName, string $ddl) : void {
  // $ddl example: "CREATE INDEX idx_name ON table(col1,col2)"
  try {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=? AND table_name=? AND index_name=?");
    $st->execute([$db, $table, $indexName]);
    $exists = (int)$st->fetchColumn() > 0;
    if (!$exists) {
      $pdo->exec($ddl);
    }
  } catch (Throwable $e) {
    // ignore
  }
}

function absensi_schema_ensure(PDO $pdo): void {
  // Settings
  $pdo->exec("CREATE TABLE IF NOT EXISTS absensi_settings (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT NULL,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // Offices (geofence target)
  $pdo->exec("CREATE TABLE IF NOT EXISTS absensi_offices (
    office_code VARCHAR(32) PRIMARY KEY,
    office_name VARCHAR(120) NOT NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    radius_m INT NULL,
    kiosk_token VARCHAR(64) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // User profile (office + HR admin flag)
  $pdo->exec("CREATE TABLE IF NOT EXISTS absensi_user_profile (
    user_id BIGINT PRIMARY KEY,
    office_code VARCHAR(32) NULL,
    is_hr_admin TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // Logs (IN/OUT + audit-like)
  $pdo->exec("CREATE TABLE IF NOT EXISTS absensi_logs (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // Migrate older schemas if table existed with other column names
  if (absensi_table_exists($pdo, 'absensi_logs')) {
    absensi_add_col_if_missing($pdo,'absensi_logs','user_id','user_id BIGINT NULL AFTER id');
    absensi_add_col_if_missing($pdo,'absensi_logs','username',"username VARCHAR(80) NULL AFTER user_id");
    absensi_add_col_if_missing($pdo,'absensi_logs','action_type',"action_type VARCHAR(16) NOT NULL DEFAULT 'IN' AFTER username");
    absensi_add_col_if_missing($pdo,'absensi_logs','office_code',"office_code VARCHAR(32) NULL AFTER action_type");
    absensi_add_col_if_missing($pdo,'absensi_logs','distance_m',"distance_m INT NULL AFTER office_code");
    absensi_add_col_if_missing($pdo,'absensi_logs','geo_lat',"geo_lat DECIMAL(10,7) NULL AFTER distance_m");
    absensi_add_col_if_missing($pdo,'absensi_logs','geo_lng',"geo_lng DECIMAL(10,7) NULL AFTER geo_lat");
    absensi_add_col_if_missing($pdo,'absensi_logs','geo_acc',"geo_acc INT NULL AFTER geo_lng");
    absensi_add_col_if_missing($pdo,'absensi_logs','photo_path',"photo_path VARCHAR(255) NULL AFTER geo_acc");
    absensi_add_col_if_missing($pdo,'absensi_logs','ip',"ip VARCHAR(45) NULL AFTER photo_path");
    absensi_add_col_if_missing($pdo,'absensi_logs','user_agent',"user_agent VARCHAR(255) NULL AFTER ip");
    absensi_add_col_if_missing($pdo,'absensi_logs','created_at',"created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER user_agent");
  }

  // Requests (Phase 3)
  $pdo->exec("CREATE TABLE IF NOT EXISTS absensi_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    username VARCHAR(80) NOT NULL,
    req_type VARCHAR(16) NOT NULL, -- IZIN/SAKIT/DINAS
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // Audit
  $pdo->exec("CREATE TABLE IF NOT EXISTS absensi_audit (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT NULL,
    actor_username VARCHAR(80) NULL,
    action VARCHAR(48) NOT NULL,
    payload_json JSON NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // Default setting
  $stmt = $pdo->prepare("INSERT IGNORE INTO absensi_settings (k,v,updated_at) VALUES ('geofence_enforce', ?, NOW())");
  $stmt->execute([strval(ABSENSI_GEOFENCE_ENFORCE_DEFAULT)]);

  // Default office (optional)
  $pdo->exec("INSERT IGNORE INTO absensi_offices (office_code, office_name, radius_m, is_active, updated_at)
              VALUES ('DEFAULT','Kantor Default', ".ABSENSI_DEFAULT_RADIUS_M.", 1, NOW())");

// --- ABSENSI_SCHEMA_PATCH_2025_12: compat with older DBs (safe alters, no breaking)
// Requests table
absensi_add_col_if_missing($pdo,'absensi_requests','user_id',"BIGINT NULL");
absensi_add_col_if_missing($pdo,'absensi_requests','username',"VARCHAR(80) NULL");
absensi_add_col_if_missing($pdo,'absensi_requests','deleted_at',"DATETIME NULL");
absensi_add_index_if_missing($pdo,'absensi_requests','idx_absensi_requests_user_id',"CREATE INDEX idx_absensi_requests_user_id ON absensi_requests(user_id)");
absensi_add_index_if_missing($pdo,'absensi_requests','idx_absensi_requests_deleted_at',"CREATE INDEX idx_absensi_requests_deleted_at ON absensi_requests(deleted_at)");

// Logs table
absensi_add_col_if_missing($pdo,'absensi_logs','user_id',"BIGINT NULL");
absensi_add_col_if_missing($pdo,'absensi_logs','username',"VARCHAR(80) NULL");
absensi_add_col_if_missing($pdo,'absensi_logs','office_id',"BIGINT NULL");
absensi_add_col_if_missing($pdo,'absensi_logs','deleted_at',"DATETIME NULL");
absensi_add_index_if_missing($pdo,'absensi_logs','idx_absensi_logs_user_id',"CREATE INDEX idx_absensi_logs_user_id ON absensi_logs(user_id)");
absensi_add_index_if_missing($pdo,'absensi_logs','idx_absensi_logs_deleted_at',"CREATE INDEX idx_absensi_logs_deleted_at ON absensi_logs(deleted_at)");

// Paging/search (DataTables server-side) & payroll gate performance
absensi_add_index_if_missing($pdo,'absensi_logs','idx_absensi_logs_created_at',"CREATE INDEX idx_absensi_logs_created_at ON absensi_logs(created_at)");
absensi_add_index_if_missing($pdo,'absensi_logs','idx_absensi_logs_user_created',"CREATE INDEX idx_absensi_logs_user_created ON absensi_logs(user_id, created_at)");

absensi_add_index_if_missing($pdo,'absensi_requests','idx_absensi_requests_status',"CREATE INDEX idx_absensi_requests_status ON absensi_requests(status)");
absensi_add_index_if_missing($pdo,'absensi_requests','idx_absensi_requests_start_end',"CREATE INDEX idx_absensi_requests_start_end ON absensi_requests(start_date, end_date)");
absensi_add_index_if_missing($pdo,'absensi_requests','idx_absensi_requests_user_status',"CREATE INDEX idx_absensi_requests_user_status ON absensi_requests(user_id, status)");

// Audit table (ensure columns exist)
absensi_add_col_if_missing($pdo,'absensi_audit','actor_user_id',"BIGINT NULL");
absensi_add_col_if_missing($pdo,'absensi_audit','actor_username',"VARCHAR(80) NULL");

// Kiosk mode columns
absensi_add_col_if_missing($pdo,'absensi_offices','kiosk_token',"VARCHAR(64) NULL");
absensi_add_col_if_missing($pdo,'absensi_logs','kiosk_mode',"TINYINT(1) NOT NULL DEFAULT 0");


}
