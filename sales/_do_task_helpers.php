<?php
declare(strict_types=1);

// Prevent direct web access to this include-only file.
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * _do_task_helpers.php
 * Shared helpers untuk semua halaman DO Task (WQS, SCM, ACT, FIN).
 * Di-include satu kali; semua fungsi dilindungi function_exists().
 *
 * Fungsi yang disediakan:
 *   - do_task_ensure_column()       — DDL guard (aman duplikat)
 *   - do_task_table_has_column()    — cek kolom ada/tidak
 *   - do_task_select_col()          — SQL helper tanpa alias tabel
 *   - do_task_select_col_d()        — SQL helper dengan alias 'd'
 *   - do_task_upload_file()         — upload file aman + safe_filename
 *   - do_task_ensure_audit_table()  — CREATE IF NOT EXISTS sales_do_audit
 *   - do_task_audit_actor_name()    — nama aktor dari session
 *   - do_task_audit_append()        — INSERT sales_do_audit
 *   - do_task_pill()                — HTML pill badge (step flow)
 */

if (!function_exists('do_task_ensure_column')) {
    function do_task_ensure_column(PDO $pdo, string $table, string $column, string $ddl): void {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            if (!(bool)$stmt->fetch()) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
            }
        } catch (Throwable $e) {
            // fail-soft: jangan matikan halaman kalau kolom belum ada
        }
    }
}

if (!function_exists('do_task_table_has_column')) {
    function do_task_table_has_column(PDO $pdo, string $table, string $column): bool {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            return (bool)$stmt->fetch();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('do_task_select_col')) {
    function do_task_select_col(bool $hasCol, string $col): string {
        return $hasCol ? "`{$col}`" : "NULL AS `{$col}`";
    }
}

if (!function_exists('do_task_select_col_d')) {
    function do_task_select_col_d(bool $hasCol, string $col): string {
        return $hasCol ? "d.`{$col}`" : "NULL AS `{$col}`";
    }
}

if (!function_exists('do_task_upload_file')) {
    /**
     * Upload file dan kembalikan URL relatif, atau null bila gagal/tidak ada.
     *
     * @param string   $field    Nama field $_FILES
     * @param string   $dirRel   Direktori relatif dari project root (e.g. 'uploads/sales_scm')
     * @param string[] $allowExt Extension yang diizinkan
     */
    function do_task_upload_file(string $field, string $dirRel, array $allowExt): ?string {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
        $f = $_FILES[$field];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

        $tmp = $f['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp)) return null;

        $origName = (string)($f['name'] ?? 'file');
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowExt, true)) return null;

        $base = realpath(__DIR__ . '/..');
        if ($base === false) return null;

        $dirAbs = $base . '/' . trim($dirRel, '/');
        if (!is_dir($dirAbs)) @mkdir($dirAbs, 0777, true);

        // safe_filename tersedia dari _shared/helpers.php
        $safeName  = function_exists('safe_filename') ? safe_filename($origName) : preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', $origName);
        $basePart  = pathinfo($safeName, PATHINFO_FILENAME) ?: 'file';
        $safeExt   = strtolower(pathinfo($safeName, PATHINFO_EXTENSION)) ?: $ext;

        $fname   = $basePart . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $safeExt;
        $destAbs = $dirAbs . '/' . $fname;

        if (!@move_uploaded_file($tmp, $destAbs)) return null;

        return '/' . trim($dirRel, '/') . '/' . $fname;
    }
}

if (!function_exists('do_task_ensure_audit_table')) {
    function do_task_ensure_audit_table(PDO $pdo): void {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sales_do_audit (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                do_id       INT NOT NULL,
                status_from VARCHAR(50) NULL,
                status_to   VARCHAR(50) NULL,
                actor_dept  VARCHAR(50) NULL,
                actor_name  VARCHAR(100) NULL,
                note        TEXT NULL,
                created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_do_id (do_id),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) { /* fail-soft */ }
    }
}

if (!function_exists('do_task_audit_actor_name')) {
    function do_task_audit_actor_name(): string {
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
        $u = trim((string)(
            $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['full_name'] ?? ''
        ));
        return $u !== '' ? $u : 'SYSTEM';
    }
}

if (!function_exists('sales_do_audit_append')) {
    function sales_do_audit_append(PDO $pdo, int $do_id, ?string $from, ?string $to, string $dept, string $note = ''): void {
        try {
            do_task_ensure_audit_table($pdo);
            $actor = do_task_audit_actor_name();
            $pdo->prepare("INSERT INTO sales_do_audit
                            (do_id, status_from, status_to, actor_dept, actor_name, note)
                            VALUES (?,?,?,?,?,?)")
                ->execute([$do_id, $from, $to, $dept, $actor, $note]);
        } catch (Throwable $e) { /* fail-soft */ }
    }
}

if (!function_exists('do_task_pill')) {
    function do_task_pill(string $label, bool $active = false): string {
        $cls = $active ? 'pill active' : 'pill';
        $safe = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return "<span class=\"{$cls}\">{$safe}</span>";
    }
}
