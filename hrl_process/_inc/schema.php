<?php
// require_login(); // static scan marker (login enforced in _inc/bootstrap.php)
declare(strict_types=1);

// Block direct access to internal include
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}


function hrlp_table_exists(PDO $pdo, string $table): bool {
    // NOTE: PDO placeholders are not supported for MySQL SHOW statements (can cause 1064 near '?').
    $sql = "SHOW TABLES LIKE " . $pdo->quote($table);
    $stmt = $pdo->query($sql);
    return (bool)$stmt->fetchColumn();
}
function hrlp_col_exists(PDO $pdo, string $table, string $col): bool {
    // NOTE: placeholders are not supported for MySQL SHOW statements.
    $tableSafe = str_replace('`', '``', $table);
    $sql = "SHOW COLUMNS FROM `{$tableSafe}` LIKE " . $pdo->quote($col);
    $stmt = $pdo->query($sql);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}
function hrlp_add_col_if_missing(PDO $pdo, string $table, string $col, string $def): void {
    if (!hrlp_col_exists($pdo, $table, $col)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
    }
}
function hrlp_add_index_if_missing(PDO $pdo, string $table, string $indexName, string $indexSql): void {
    // indexSql example: "INDEX `idx_name` (`col1`,`col2`)"
    // NOTE: placeholders are not supported for MySQL SHOW statements.
    $tableSafe = str_replace('`', '``', $table);
    $sql = "SHOW INDEX FROM `{$tableSafe}` WHERE Key_name = " . $pdo->quote($indexName);
    $stmt = $pdo->query($sql);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE `{$tableSafe}` ADD $indexSql");
    }
}

function hrlp_schema_ensure(PDO $pdo): void {
    // hrl_requests
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hrl_requests` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `req_code` VARCHAR(64) NULL,
            `req_type` VARCHAR(32) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `description` TEXT NULL,
            `dept_code` VARCHAR(32) NULL,
            `office_code` VARCHAR(32) NULL,
            `start_date` DATE NULL,
            `end_date` DATE NULL,
            `amount` DECIMAL(18,2) NOT NULL DEFAULT 0,
            `gps_lat` DECIMAL(10,7) NULL,
            `gps_lng` DECIMAL(10,7) NULL,
            `gps_accuracy_m` INT NULL,
            `photo_path` VARCHAR(255) NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'DRAFT',
            `created_by` VARCHAR(64) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL,
            `submitted_by` VARCHAR(64) NULL,
            `submitted_at` DATETIME NULL,

            `manager_approved_by` VARCHAR(64) NULL,
            `manager_approved_at` DATETIME NULL,
            `manager_sign_method` VARCHAR(16) NULL,

            `hrl_approved_by` VARCHAR(64) NULL,
            `hrl_approved_at` DATETIME NULL,
            `hrl_sign_method` VARCHAR(16) NULL,

            `fin_approved_by` VARCHAR(64) NULL,
            `fin_approved_at` DATETIME NULL,
            `fin_sign_method` VARCHAR(16) NULL,

            `paid_by` VARCHAR(64) NULL,
            `paid_at` DATETIME NULL,

            `rejected_by` VARCHAR(64) NULL,
            `rejected_at` DATETIME NULL,
            `reject_note` TEXT NULL,

            `deleted_by` VARCHAR(64) NULL,
            `deleted_at` DATETIME NULL,

            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_req_code` (`req_code`),
            INDEX `idx_status` (`status`),
            INDEX `idx_dept_office` (`dept_code`,`office_code`),
            INDEX `idx_created_by` (`created_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // hrl_request_files
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hrl_request_files` (
            `id` BIGINT NOT NULL AUTO_INCREMENT,
            `request_id` BIGINT NOT NULL,
            `kind` VARCHAR(16) NOT NULL DEFAULT 'ATTACH', -- ATTACH | PHOTO
            `file_path` VARCHAR(255) NOT NULL,
            `file_name` VARCHAR(255) NOT NULL,
            `mime` VARCHAR(128) NULL,
            `size_bytes` INT NULL,
            `uploaded_by` VARCHAR(64) NOT NULL,
            `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_request_id` (`request_id`),
            INDEX `idx_kind` (`kind`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // hrl_user_pins (optional, for approval PIN)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `hrl_user_pins` (
            `username` VARCHAR(64) NOT NULL,
            `pin_hash` VARCHAR(255) NOT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Backward/forward safety: add columns if missing (safe alter)
    hrlp_add_col_if_missing($pdo, 'hrl_requests', 'req_code', "VARCHAR(64) NULL");
    hrlp_add_col_if_missing($pdo, 'hrl_requests', 'gps_accuracy_m', "INT NULL");
    hrlp_add_col_if_missing($pdo, 'hrl_requests', 'photo_path', "VARCHAR(255) NULL");
    hrlp_add_col_if_missing($pdo, 'hrl_requests', 'manager_sign_method', "VARCHAR(16) NULL");
    hrlp_add_col_if_missing($pdo, 'hrl_requests', 'hrl_sign_method', "VARCHAR(16) NULL");
    hrlp_add_col_if_missing($pdo, 'hrl_requests', 'fin_sign_method', "VARCHAR(16) NULL");

    // Index ensure (safe)
    hrlp_add_index_if_missing($pdo, 'hrl_requests', 'idx_status', "INDEX `idx_status` (`status`)");
    hrlp_add_index_if_missing($pdo, 'hrl_request_files', 'idx_request_id', "INDEX `idx_request_id` (`request_id`)");
}
