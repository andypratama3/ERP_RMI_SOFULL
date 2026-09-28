<?php
/**
 * Shared audit helper untuk Master modules (vendors, products, customers).
 * Menulis ke system_audit_logs — sama seperti master_manufactures.
 */
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('master_audit_ensure_table')) {
    function master_audit_ensure_table(PDO $pdo): void {
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
              `ip` varchar(45) DEFAULT NULL,
              `user_agent` varchar(255) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_module_action` (`module`,`action`),
              KEY `idx_record` (`record_table`,`record_id`),
              KEY `idx_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}

if (!function_exists('master_audit')) {
    /**
     * @param string $module e.g. 'master_vendors', 'master_products', 'master_customers'
     * @param string $record_table e.g. 'master_vendors'
     */
    function master_audit(PDO $pdo, string $module, string $record_table, string $action, ?int $record_id, ?string $record_code, string $description, array $details = []): void {
        master_audit_ensure_table($pdo);
        $u = [
            'user_id'   => $_SESSION['user_id'] ?? null,
            'username'  => $_SESSION['username'] ?? ($_SESSION['user']['username'] ?? ''),
            'role'      => $_SESSION['role'] ?? '',
            'level'     => $_SESSION['level'] ?? '',
        ];
        if (function_exists('auth_username') && $u['username'] === '') {
            $u['username'] = auth_username();
        }
        $stmt = $pdo->prepare("
            INSERT INTO system_audit_logs
            (module, action, record_table, record_id, record_code, description, details,
             user_id, username, role, level, ip, user_agent, created_at)
            VALUES
            (:module, :action, :rt, :rid, :rcode, :descr, :details,
             :uid, :uname, :role, :level, :ip, :ua, NOW())
        ");
        $stmt->execute([
            ':module'  => $module,
            ':action'  => $action,
            ':rt'      => $record_table,
            ':rid'     => $record_id,
            ':rcode'   => $record_code,
            ':descr'   => $description,
            ':details' => !empty($details) ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ':uid'     => $u['user_id'],
            ':uname'   => substr((string)$u['username'], 0, 100),
            ':role'    => substr((string)$u['role'], 0, 50),
            ':level'   => substr((string)$u['level'], 0, 50),
            ':ip'      => substr(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]), 0, 45),
            ':ua'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250),
        ]);
    }
}
