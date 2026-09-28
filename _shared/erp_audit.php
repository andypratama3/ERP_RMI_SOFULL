<?php



// RMI_GUARD_DIRECT_ACCESS
if (basename(__FILE__) === basename($_SERVER["SCRIPT_FILENAME"] ?? "")) {
    $auth = __DIR__ . "/../master/auth.php";
    if (is_file($auth)) { require_once $auth; }
    if (function_exists("require_login")) { require_login(); }
    http_response_code(403);
    exit("Forbidden");
}

// _shared/erp_audit.php
// Universal audit log helper (dipakai Payroll sekarang, bisa dipakai modul lain nanti)
// Versi ini auto-migrate kolom supaya tidak error saat ada instalasi lama.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function erp_request_id(): string {
    $rid = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? $_SERVER['RMI_REQUEST_ID'] ?? '');
    if ($rid !== '') return $rid;
    try {
        return bin2hex(random_bytes(12));
    } catch (Throwable $e) {
        return substr(sha1((string)microtime(true) . ':' . mt_rand()), 0, 24);
    }
}

function audit_event(
    PDO $pdo,
    string $action,
    string $module,
    string $entityType = '',
    string $entityId = '',
    string $description = '',
    array $meta = []
): void {
    $entityKey = trim($entityType) !== '' ? ($entityType . ($entityId !== '' ? '#' . $entityId : '')) : ($entityId !== '' ? $entityId : null);
    $meta = array_merge([
        'request_id' => erp_request_id(),
        'actor_username' => function_exists('current_actor_username') ? current_actor_username() : ((string)($_SESSION['username'] ?? 'SYSTEM')),
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ], $meta);
    if ($description !== '') {
        $meta['description'] = $description;
    }
    erp_audit($pdo, $module, $entityKey, $action, $meta);
}

function erp_audit_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    try {
        // NOTE: placeholder (?) TIDAK valid di SHOW TABLES LIKE (MySQL 1064).
        // Interpolasi aman karena $table sudah divalidasi regex di atas.
        $rows = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchAll(PDO::FETCH_NUM) ?: [];
        return count($rows) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function erp_audit_col_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $st->execute([$col]);
        return (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function erp_audit_add_col(PDO $pdo, string $table, string $ddl): void {
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    } catch (Throwable $e) {
        // ignore
    }
}

function erp_audit_add_index(PDO $pdo, string $table, string $name, string $cols): void {
    try {
        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` ({$cols})");
    } catch (Throwable $e) {
        // ignore (mungkin sudah ada)
    }
}

function erp_audit_ensure(PDO $pdo): void {
    // Create minimal table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS erp_audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        module VARCHAR(50) NOT NULL,
        entity_key VARCHAR(120) NULL,
        action VARCHAR(50) NOT NULL,
        user_id INT NULL,
        username VARCHAR(120) NULL,
        role VARCHAR(60) NULL,
        level VARCHAR(60) NULL,
        ip_address VARCHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        payload_json LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_module (module),
        KEY idx_action (action),
        KEY idx_entity (entity_key),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Auto-migrate: add missing columns for older installs
    $table = 'erp_audit_log';
    if (!erp_audit_table_exists($pdo, $table)) return;

    $cols = [
        'module'      => "module VARCHAR(50) NOT NULL",
        'entity_key'  => "entity_key VARCHAR(120) NULL",
        'action'      => "action VARCHAR(50) NOT NULL",
        'user_id'     => "user_id INT NULL",
        'username'    => "username VARCHAR(120) NULL",
        'role'        => "role VARCHAR(60) NULL",
        'level'       => "level VARCHAR(60) NULL",
        'ip_address'  => "ip_address VARCHAR(64) NULL",
        'user_agent'  => "user_agent VARCHAR(255) NULL",
        'payload_json'=> "payload_json LONGTEXT NULL",
        'created_at'  => "created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ];

    foreach ($cols as $col => $ddl) {
        if (!erp_audit_col_exists($pdo, $table, $col)) {
            erp_audit_add_col($pdo, $table, "`{$col}` {$ddl}");
        }
    }

    // Ensure basic indexes (ignore errors if already exists)
    erp_audit_add_index($pdo, $table, 'idx_module', '`module`');
    erp_audit_add_index($pdo, $table, 'idx_action', '`action`');
    erp_audit_add_index($pdo, $table, 'idx_entity', '`entity_key`');
    erp_audit_add_index($pdo, $table, 'idx_created', '`created_at`');

    // Composite indexes for fast filtering (enterprise)
    erp_audit_add_index($pdo, $table, 'idx_module_created', '`module`,`created_at`');
    erp_audit_add_index($pdo, $table, 'idx_module_action_created', '`module`,`action`,`created_at`');
    erp_audit_add_index($pdo, $table, 'idx_entity_created', '`entity_key`,`created_at`');
}

function erp_audit(PDO $pdo, string $module, ?string $entityKey, string $action, $payload = null): void {
    try {
        $userId   = $_SESSION['user_id'] ?? null;
        $username = function_exists('current_actor_username') ? current_actor_username() : ($_SESSION['username'] ?? 'SYSTEM');
        $role     = $_SESSION['role'] ?? null;
        $level    = $_SESSION['level'] ?? null;
        $ua       = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // IP asli: utamakan X-Forwarded-For (di balik proxy/Cloudflare)
        $rawIp = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $ipParts = explode(',', $rawIp);
        $ip = trim((string)($ipParts[0] ?? ''));

        $json = null;
        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) $json = null;
        }

        // 1) Tulis ke erp_audit_log (legacy — tetap dipertahankan)
        $stmt = $pdo->prepare("INSERT INTO erp_audit_log
            (module, entity_key, action, user_id, username, role, level, ip_address, user_agent, payload_json, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$module, $entityKey, $action, $userId, $username, $role, $level, $ip, $ua, $json]);

        // 2) Dual-write ke system_audit_logs agar tampil di Audit Log UI
        if (function_exists('master_audit_ensure_table')) master_audit_ensure_table($pdo);
        $descr = strtoupper((string)$module) . ' — ' . strtoupper($action)
               . ($entityKey ? ' [' . $entityKey . ']' : '');
        $pdo->prepare("
            INSERT INTO system_audit_logs
                (module, action, record_table, record_code, description, details,
                 user_id, username, role, level, ip, user_agent, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([
            strtolower($module),
            strtoupper($action),
            null,
            $entityKey,
            $descr,
            $json,
            $userId,
            $username,
            strtoupper((string)$role),
            strtoupper((string)$level),
            substr($ip, 0, 45),
            substr((string)$ua, 0, 255),
        ]);
    } catch (Throwable $e) {
        // audit tidak boleh bikin sistem error
    }
}

function erp_audit_excerpt(?string $json, int $limit = 120): string {
    if (!$json) return '';
    $s = trim($json);
    if (mb_strlen($s) <= $limit) return $s;
    return mb_substr($s, 0, $limit) . '…';
}
