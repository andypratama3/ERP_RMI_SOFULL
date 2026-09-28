<?php
// --- WEB guard (admin-only) ---
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../master/auth.php';
    if (function_exists('require_login')) {
        require_login();
    } elseif (function_exists('auth_require_login')) {
        auth_require_login();
    } else {
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
        if (empty($_SESSION['user']) && empty($_SESSION['username'])) {
            http_response_code(401);
            die('Unauthorized');
        }
    }

    // Only SUPERADMIN/ADMIN can run tools
    $role  = strtoupper(trim((string)($_SESSION['role'] ?? '')));
    $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
    $user  = strtolower(trim((string)($_SESSION['username'] ?? ($_SESSION['user'] ?? ''))));
    $isAdmin = in_array($role, ['ADMIN','SUPERADMIN'], true)
        || in_array($level, ['ADMIN','SUPERADMIN'], true)
        || in_array($user, ['admin','superadmin'], true);

    if (!$isAdmin) {
        http_response_code(403);
        die('Forbidden');
    }
}
// --- /WEB guard ---

/**
 * Enforce guarded helper function h() to avoid fatal redeclare + PHP 8.1 null deprecation.
 *
 * What it does:
 * - Scans PHP files and replaces any *unguarded* `function h(` definition with a guarded version:
 *
 *     if (!function_exists('h')) {
 *         function h($v): string {
 *             return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
 *         }
 *     }
 *
 * Usage:
 *   php tools/enforce_h_guard.php [--apply] [--only=master,sales,stock,...]
 *
 * Notes:
 * - Pattern matching (not a full parser) but uses brace counting to replace function body safely.
 * - Skips files that already contain a `function_exists('h')` guard.
 */

$root = realpath(__DIR__ . '/..');
if (!$root) {
    fwrite(STDERR, "Cannot resolve project root\n");
    exit(1);
}

$args = $argv;
array_shift($args);
$apply = in_array('--apply', $args, true);
$iUnderstand = in_array('--i-understand', $args, true);
$checkMode = in_array('--check', $args, true) || !$apply;
$withBackup = in_array('--backup', $args, true);
$only = null;
foreach ($args as $a) {
    if (strpos($a, '--only=') === 0) {
        $only = array_values(array_filter(array_map('trim', explode(',', substr($a, 7)))));
    }
}

$allowedRoots = [
    'master', 'sales', 'stock', 'tools', 'dashboards', 'api', 'hrl', 'hrl_process', 'hrl_reg_alkes',
    'kpi', 'mpr', 'payroll', 'purchases', 'absensi', 'Fixed_Asset', 'rbac', '_shared', 'root'
];
$appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));
if ($apply && in_array($appEnv, ['prod', 'production'], true) && !$iUnderstand) {
    fwrite(STDERR, "Production safeguard: add --i-understand for --apply mode.\n");
    exit(2);
}

function is_allowed_module(string $rel, ?array $only): bool {
    if ($only === null) return true;
    $mod = module_from_rel($rel);
    return in_array($mod, $only, true);
}

function module_from_rel(string $rel): string {
    $rel = ltrim($rel, '/');
    $parts = explode('/', $rel);
    if (count($parts) === 1) return 'root';
    return $parts[0];
}

function iter_php_files(string $root, array $allowedRoots): array {
    $files = [];
    foreach ($allowedRoots as $dir) {
        $path = ($dir === 'root') ? $root : $root . '/' . $dir;
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile()) continue;
            $fn = $f->getFilename();
            if (substr($fn, -4) !== '.php') continue;
            // Skip vendor-like
            $full = $f->getPathname();
            if (strpos($full, '/vendor/') !== false || strpos($full, '/node_modules/') !== false) continue;
            $files[] = $full;
        }
    }
    sort($files);
    return $files;
}

function has_guard_for_h(string $src, int $pos): bool {
    // Look back 400 chars for a function_exists('h') style guard
    $start = max(0, $pos - 400);
    $chunk = substr($src, $start, $pos - $start);
    return (bool)preg_match('/function_exists\s*\(\s*[\"\']h[\"\']\s*\)/i', $chunk);
}

function find_matching_brace(string $src, int $openPos): int {
    $len = strlen($src);
    $depth = 0;
    for ($i = $openPos; $i < $len; $i++) {
        $ch = $src[$i];
        if ($ch === '{') $depth++;
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) return $i;
        }
    }
    return -1;
}

function replace_unguarded_h(string $src, bool &$changed, array &$reasons): string {
    $changed = false;
    $out = $src;
    $offset = 0;

    while (true) {
        $pos = strpos($out, 'function h', $offset);
        if ($pos === false) break;

        // Ensure it's a function declaration (not a call/word)
        $before = ($pos > 0) ? $out[$pos - 1] : '';
        if ($before !== '' && preg_match('/[a-zA-Z0-9_]/', $before)) {
            $offset = $pos + 9;
            continue;
        }

        // Must be `function h(`
        $after = substr($out, $pos, 30);
        if (!preg_match('/^function\s+h\s*\(/', $after)) {
            $offset = $pos + 9;
            continue;
        }

        if (has_guard_for_h($out, $pos)) {
            $reasons[] = 'skip_guarded';
            $offset = $pos + 9;
            continue;
        }

        // Find opening brace for this function
        $bracePos = strpos($out, '{', $pos);
        if ($bracePos === false) {
            $reasons[] = 'skip_no_brace';
            $offset = $pos + 9;
            continue;
        }

        $endBrace = find_matching_brace($out, $bracePos);
        if ($endBrace < 0) {
            $reasons[] = 'skip_unmatched_brace';
            $offset = $pos + 9;
            continue;
        }

        // Find indentation of function line
        $lineStart = strrpos(substr($out, 0, $pos), "\n");
        $lineStart = ($lineStart === false) ? 0 : $lineStart + 1;
        $indent = '';
        for ($i = $lineStart; $i < strlen($out); $i++) {
            $c = $out[$i];
            if ($c === ' ' || $c === "\t") $indent .= $c;
            else break;
        }

        $replacement = $indent . "if (!function_exists('h')) {\n";
        $replacement .= $indent . "    function h(\$v): string {\n";
        $replacement .= $indent . "        return htmlspecialchars((string)(\$v ?? ''), ENT_QUOTES, 'UTF-8');\n";
        $replacement .= $indent . "    }\n";
        $replacement .= $indent . "}\n";

        // Replace entire function block (from lineStart? or from pos?)
        // We'll replace from $pos to $endBrace inclusive to avoid touching other code.
        $out = substr($out, 0, $pos) . $replacement . substr($out, $endBrace + 1);

        $changed = true;
        // Move offset past replacement to avoid infinite loop
        $offset = $pos + strlen($replacement);
    }

    return $out;
}

$files = iter_php_files($root, $allowedRoots);
$patched = 0;
$skipped = 0;
$errors = 0;
$filesTouched = [];
$backupRoot = $root . '/storage/backups/patch_backups/h_guard_' . date('Ymd_His');

function backup_before_write_h(string $full, string $root, string $backupRoot): void {
    $rel = ltrim(str_replace($root, '', $full), '/');
    $dest = $backupRoot . '/' . $rel;
    if (!is_dir(dirname($dest))) {
        @mkdir(dirname($dest), 0775, true);
    }
    @copy($full, $dest);
}

foreach ($files as $full) {
    $rel = str_replace($root . '/', '', $full);
    if (!is_allowed_module($rel, $only)) continue;

    $src = @file_get_contents($full);
    if ($src === false) {
        $errors++;
        fwrite(STDERR, "[ERR] Cannot read: {$rel}\n");
        continue;
    }

    if (strpos($src, 'function h') === false) {
        continue; // not counted
    }

    $reasons = [];
    $changed = false;
    $new = replace_unguarded_h($src, $changed, $reasons);

    if (!$changed) {
        $skipped++;
        continue;
    }

    $patched++;
    echo ($apply ? "[APPLY]" : "[PREVIEW]") . " h() guard -> {$rel}\n";

    if ($apply) {
        if ($withBackup) {
            backup_before_write_h($full, $root, $backupRoot);
        }
        $ok = @file_put_contents($full, $new);
        if ($ok === false) {
            $errors++;
            fwrite(STDERR, "[ERR] Cannot write: {$rel}\n");
        } else {
            $filesTouched[] = $rel;
        }
    }
}

echo "\n--- Summary ---\n";
echo "Patched: {$patched}\n";
echo "Skipped (already ok/guarded): {$skipped}\n";
echo "Errors: {$errors}\n";
echo "Mode: " . ($checkMode ? 'CHECK/PREVIEW' : 'APPLY') . "\n";
if ($withBackup && $apply) echo "Backup dir: {$backupRoot}\n";

echo "\nNext: re-run Enterprise Audit to confirm h() unguarded dropped.\n";
@file_put_contents(
    $root . '/storage/logs/security_hardening_last.json',
    json_encode([
        'tool' => 'enforce_h_guard.php',
        'mode' => $checkMode ? 'check' : 'apply',
        'with_backup' => $withBackup,
        'files_touched' => count($filesTouched),
        'skipped' => $skipped,
        'failed' => $errors,
        'touched_files' => $filesTouched,
        'generated_at' => date(DateTimeInterface::ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

