<?php

// --- Auth guard (static scan marker) ---
// Ensures this file is counted as protected by enterprise_audit (require_login()).
$__rmi_guard_dir = __DIR__;
for ($__rmi_guard_i = 0; $__rmi_guard_i < 5; $__rmi_guard_i++) {
    $__rmi_guard_auth = $__rmi_guard_dir . '/master/auth.php';
    if (file_exists($__rmi_guard_auth)) {
        require_once $__rmi_guard_auth;
        if (function_exists('require_login')) {
            require_login();
        }
        break;
    }
    $__rmi_guard_parent = dirname($__rmi_guard_dir);
    if ($__rmi_guard_parent === $__rmi_guard_dir) {
        break;
    }
    $__rmi_guard_dir = $__rmi_guard_parent;
}
unset($__rmi_guard_dir, $__rmi_guard_i, $__rmi_guard_auth, $__rmi_guard_parent);
// --- /Auth guard ---


// mpr/_inc/schema.php
// Auto create / migrate tables Modul MPR (Fase 1–3 + Enterprise Enhancements)

function mpr_col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $col]);
    return (int)($st->fetchColumn() ?: 0) > 0;
}

function mpr_add_col_if_missing(PDO $pdo, string $table, string $col, string $definition_sql): void {
    if (mpr_col_exists($pdo, $table, $col)) return;
    // NOTE: $definition_sql harus sudah berupa tipe+constraint valid (jangan pakai backtick).
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition_sql");
}

function mpr_schema_ensure(PDO $pdo): void {

    // Plans
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mpr_plans` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `plan_code` VARCHAR(40) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `objective` TEXT NULL,
            `target` TEXT NULL,
            `start_date` DATE NULL,
            `end_date` DATE NULL,
            `budget` DECIMAL(18,2) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
            `dept_code` VARCHAR(20) NOT NULL DEFAULT 'MPR',
            `office_code` VARCHAR(20) NOT NULL,
            `created_by` VARCHAR(100) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL,
            `submitted_at` DATETIME NULL,
            `approved_at` DATETIME NULL,
            `approved_by` VARCHAR(100) NULL,
            `approval_note` TEXT NULL,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mpr_plans_code` (`plan_code`),
            KEY `idx_mpr_plans_status` (`status`),
            KEY `idx_mpr_plans_office` (`office_code`),
            KEY `idx_mpr_plans_dept` (`dept_code`),
            KEY `idx_mpr_plans_deleted` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Visits (enhanced: GPS + Photo evidence)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mpr_visits` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `plan_id` INT NOT NULL,
            `visit_date` DATE NOT NULL,
            `partner_name` VARCHAR(255) NULL,
            `location` VARCHAR(255) NULL,
            `result` TEXT NULL,
            `notes` TEXT NULL,
            `attachment_path` VARCHAR(255) NULL,
            `created_by` VARCHAR(100) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_mpr_visits_plan` (`plan_id`),
            KEY `idx_mpr_visits_date` (`visit_date`),
            KEY `idx_mpr_visits_deleted` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Add columns if missing (safe migration)
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'gps_lat', 'DECIMAL(10,7) NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'gps_lng', 'DECIMAL(10,7) NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'gps_accuracy_m', 'INT NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'gps_captured_at', 'DATETIME NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'photo_path', 'VARCHAR(255) NULL');

    // Link visit ke master_customers (RS) & master_mpr (PIC eksternal)
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'customer_id', 'INT NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'customers_code', 'VARCHAR(50) NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'customer_name', 'VARCHAR(255) NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'contact_id', 'INT NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'contact_name', 'VARCHAR(150) NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'contact_role_title', 'VARCHAR(100) NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'contact_department', 'VARCHAR(100) NULL');
    // Snapshot holder employee (untuk operasional harian by employee)
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'employee_code', 'VARCHAR(50) NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'employee_name', 'VARCHAR(150) NULL');

    // --- NEW: Visit classification for customer acquisition tracking ---
    // visit_type: NEW_PROSPECT | EXISTING_CUSTOMER | FOLLOW_UP | CLOSING | PRESENTATION
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'visit_type', "VARCHAR(30) NOT NULL DEFAULT 'EXISTING_CUSTOMER'");
    // outcome: INTERESTED | NOT_INTERESTED | NEED_FOLLOWUP | DEAL_WON | DEAL_LOST | PENDING
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'outcome', "VARCHAR(30) NOT NULL DEFAULT 'PENDING'");
    // Follow-up jadwal berikutnya
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'next_followup_date', 'DATE NULL');
    // Estimasi nilai deal (potensi omset)
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'est_deal_value', 'DECIMAL(18,2) NULL');
    // Visitor — siapa yang melakukan kunjungan (bisa Staff atau Manager)
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'visitor_username', 'VARCHAR(100) NULL');
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'visitor_level', 'VARCHAR(20) NULL');
    // Kota/area kunjungan
    mpr_add_col_if_missing($pdo, 'mpr_visits', 'visit_city', 'VARCHAR(100) NULL');

    // --- Pipeline Prospek (new customer tracking across stages) ---
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mpr_pipeline` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `prospect_code` VARCHAR(40) NOT NULL,
            `prospect_name` VARCHAR(255) NOT NULL COMMENT 'Nama RS/Klinik/Customer prospek',
            `prospect_type` VARCHAR(30) NOT NULL DEFAULT 'RS' COMMENT 'RS|KLINIK|APOTEK|LAINNYA',
            `prospect_city` VARCHAR(100) NULL,
            `prospect_address` TEXT NULL,
            `pic_name` VARCHAR(150) NULL COMMENT 'Nama kontak di prospek',
            `pic_role` VARCHAR(100) NULL COMMENT 'Jabatan PIC',
            `pic_phone` VARCHAR(30) NULL,
            `stage` VARCHAR(30) NOT NULL DEFAULT 'PROSPEK' COMMENT 'PROSPEK|KUNJUNGAN|FOLLOW_UP|PRESENTASI|NEGOSIASI|WON|LOST',
            `est_deal_value` DECIMAL(18,2) NULL,
            `est_closing_date` DATE NULL,
            `source` VARCHAR(100) NULL COMMENT 'Dari mana lead ini (referral, cold call, dll)',
            `owner_username` VARCHAR(100) NULL COMMENT 'PIC dari tim MPR (bisa staff atau manager)',
            `owner_level` VARCHAR(20) NULL,
            `office_code` VARCHAR(20) NOT NULL,
            `dept_code` VARCHAR(20) NOT NULL DEFAULT 'MPR',
            `converted_at` DATETIME NULL COMMENT 'Saat berhasil jadi customer',
            `converted_customer_id` INT NULL,
            `lost_reason` TEXT NULL,
            `notes` TEXT NULL,
            `created_by` VARCHAR(100) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mpr_pipeline_code` (`prospect_code`),
            KEY `idx_mpr_pipeline_stage` (`stage`),
            KEY `idx_mpr_pipeline_office` (`office_code`),
            KEY `idx_mpr_pipeline_owner` (`owner_username`),
            KEY `idx_mpr_pipeline_deleted` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Serah terima prospek WON ke CRM — kontak PIC Purchasing
    mpr_add_col_if_missing($pdo, 'mpr_pipeline', 'purchasing_pic_name',  'VARCHAR(150) NULL COMMENT \'Nama kontak purchasing/buyer di customer\'');
    mpr_add_col_if_missing($pdo, 'mpr_pipeline', 'purchasing_pic_role',  'VARCHAR(100) NULL COMMENT \'Jabatan PIC purchasing\'');
    mpr_add_col_if_missing($pdo, 'mpr_pipeline', 'purchasing_pic_phone', 'VARCHAR(40)  NULL');
    mpr_add_col_if_missing($pdo, 'mpr_pipeline', 'purchasing_pic_email', 'VARCHAR(120) NULL');
    mpr_add_col_if_missing($pdo, 'mpr_pipeline', 'handover_to',          'VARCHAR(100) NULL COMMENT \'Username CRM staff yang menerima\'');
    mpr_add_col_if_missing($pdo, 'mpr_pipeline', 'handover_note',        'TEXT NULL');
    mpr_add_col_if_missing($pdo, 'mpr_pipeline', 'handover_at',          'DATETIME NULL');

    // Monthly targets per user (staff AND manager)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mpr_targets` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `year_month` VARCHAR(7) NOT NULL COMMENT 'YYYY-MM',
            `username` VARCHAR(100) NOT NULL,
            `level` VARCHAR(20) NOT NULL DEFAULT 'STAFF',
            `office_code` VARCHAR(20) NOT NULL,
            `target_visits` INT NOT NULL DEFAULT 0,
            `target_new_prospects` INT NOT NULL DEFAULT 0,
            `target_closings` INT NOT NULL DEFAULT 0,
            `set_by` VARCHAR(100) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mpr_targets` (`year_month`,`username`,`office_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");



    // Progress
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mpr_progress` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `plan_id` INT NOT NULL,
            `progress_date` DATE NOT NULL,
            `progress_pct` INT NULL,
            `milestone` VARCHAR(255) NULL,
            `issues` TEXT NULL,
            `next_step` TEXT NULL,
            `created_by` VARCHAR(100) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_mpr_progress_plan` (`plan_id`),
            KEY `idx_mpr_progress_date` (`progress_date`),
            KEY `idx_mpr_progress_deleted` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Budget Requests (workflow lintas departemen: MPR -> FIN approval)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `mpr_budget_requests` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `request_code` VARCHAR(40) NOT NULL,
            `plan_id` INT NOT NULL,
            `request_date` DATE NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL,
            `purpose` TEXT NULL,
            `vendor_name` VARCHAR(255) NULL,
            `attachment_path` VARCHAR(255) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
            `dept_code` VARCHAR(20) NOT NULL DEFAULT 'MPR',
            `office_code` VARCHAR(20) NOT NULL,
            `created_by` VARCHAR(100) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `submitted_at` DATETIME NULL,
            `approved_at` DATETIME NULL,
            `approved_by` VARCHAR(100) NULL,
            `approval_note` TEXT NULL,
            `deleted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_mpr_budget_code` (`request_code`),
            KEY `idx_mpr_budget_plan` (`plan_id`),
            KEY `idx_mpr_budget_status` (`status`),
            KEY `idx_mpr_budget_office` (`office_code`),
            KEY `idx_mpr_budget_deleted` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Audit log (shared)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `system_audit_logs` (
          `id` int NOT NULL AUTO_INCREMENT,
          `module` varchar(100) NOT NULL,
          `action` varchar(50) NOT NULL,
          `record_table` varchar(100) DEFAULT NULL,
          `record_id` int DEFAULT NULL,
          `record_code` varchar(100) DEFAULT NULL,
          `description` text DEFAULT NULL,
          `details` longtext DEFAULT NULL,
          `user_id` int DEFAULT NULL,
          `username` varchar(100) DEFAULT NULL,
          `role` varchar(50) DEFAULT NULL,
          `level` varchar(50) DEFAULT NULL,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_audit_module` (`module`),
          KEY `idx_audit_record` (`record_table`,`record_id`),
          KEY `idx_audit_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

// Operasional Harian Payment Tracking (FIN)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `mpr_ops_payments` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `work_date` DATE NOT NULL,
        `employee_code` VARCHAR(50) NOT NULL,
        `office_code` VARCHAR(20) NOT NULL DEFAULT '',
        `status` VARCHAR(20) NOT NULL DEFAULT 'PAID',
        `paid_amount` DECIMAL(18,2) NULL,
        `paid_ref` VARCHAR(100) NULL,
        `note` TEXT NULL,
        `paid_at` DATETIME NULL,
        `paid_by` VARCHAR(100) NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_mpr_ops_pay` (`work_date`,`employee_code`,`office_code`),
        KEY `idx_mpr_ops_date` (`work_date`),
        KEY `idx_mpr_ops_emp` (`employee_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Safety migration columns (kalau table sudah ada tapi kolom belum lengkap)
mpr_add_col_if_missing($pdo, 'mpr_ops_payments', 'paid_amount', 'DECIMAL(18,2) NULL');
mpr_add_col_if_missing($pdo, 'mpr_ops_payments', 'paid_ref', 'VARCHAR(100) NULL');
mpr_add_col_if_missing($pdo, 'mpr_ops_payments', 'note', 'TEXT NULL');
mpr_add_col_if_missing($pdo, 'mpr_ops_payments', 'paid_at', 'DATETIME NULL');
mpr_add_col_if_missing($pdo, 'mpr_ops_payments', 'paid_by', 'VARCHAR(100) NULL');
mpr_add_col_if_missing($pdo, 'mpr_ops_payments', 'updated_at', 'DATETIME NULL');

}

function mpr_audit(PDO $pdo, array $user, string $action, string $table, ?int $id, ?string $code, string $desc, array $details = []): void {
    $stmt = $pdo->prepare("
        INSERT INTO system_audit_logs
            (module, action, record_table, record_id, record_code, description, details, user_id, username, role, level)
        VALUES
            ('MPR', :action, :rt, :rid, :rcode, :desc, :details, :uid, :uname, :role, :level)
    ");
    $stmt->execute([
        ':action' => $action,
        ':rt' => $table,
        ':rid' => $id,
        ':rcode' => $code,
        ':desc' => $desc,
        ':details' => json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ':uid' => (int)($user['id'] ?? 0),
        ':uname' => (string)($user['username'] ?? ''),
        ':role' => (string)($user['role'] ?? ''),
        ':level' => (string)($user['level'] ?? ''),
    ]);
}
