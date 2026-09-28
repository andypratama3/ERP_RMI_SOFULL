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
 * tools/enforce_upload_safety.php
 *
 * Patch-in upload filename sanitization (safe_filename) for files that handle uploads.
 *
 * What it does (pattern-based):
 * - For PHP files containing `$_FILES`, insert an include to `_shared/upload_safety.php`
 * - Then sanitize the `$_FILES` filename field in-place (`rmi_sanitize_uploads($_FILES)`).
 *
 * This is designed to be a low-risk retrofit:
 * - It does NOT rewrite your upload logic.
 * - Existing code that uses `$_FILES['x']['name']` will receive a sanitized basename.
 *
 * Usage:
 *   php tools/enforce_upload_safety.php                 # preview
 *   php tools/enforce_upload_safety.php --apply         # write changes
 *   php tools/enforce_upload_safety.php --only=stock,sales
 */

function uso_usage(): void
{
    echo "Upload Safety Enforcer\n";
    echo "Usage:\n";
    echo "  php tools/enforce_upload_safety.php [--apply] [--only=mod1,mod2]\n\n";
    echo "Examples:\n";
    echo "  php tools/enforce_upload_safety.php\n";
    echo "  php tools/enforce_upload_safety.php --apply\n";
    echo "  php tools/enforce_upload_safety.php --apply --only=stock,sales\n";
}

function uso_parse_args(array $argv): array
{
    $args = [
        'apply' => false,
        'check' => false,
        'backup' => false,
        'i_understand' => false,
        'only' => null,
    ];

    foreach ($argv as $i => $arg) {
        if ($i === 0) continue;
        if ($arg === '--help' || $arg === '-h') {
            $args['help'] = true;
            continue;
        }
        if ($arg === '--apply') {
            $args['apply'] = true;
            continue;
        }
        if ($arg === '--i-understand') {
            $args['i_understand'] = true;
            continue;
        }
        if ($arg === '--check') {
            $args['check'] = true;
            continue;
        }
        if ($arg === '--backup') {
            $args['backup'] = true;
            continue;
        }
        if (str_starts_with($arg, '--only=')) {
            $args['only'] = substr($arg, strlen('--only='));
            continue;
        }
    }

    return $args;
}

function uso_normalize(string $path): string
{
    return str_replace('\\', '/', $path);
}

function uso_relpath(string $root, string $path): string
{
    $root = rtrim(uso_normalize($root), '/');
    $path = uso_normalize($path);
    if (str_starts_with($path, $root . '/')) {
        return substr($path, strlen($root) + 1);
    }
    return ltrim($path, '/');
}

function uso_is_excluded(string $rel): bool
{
    $relLower = strtolower($rel);
    $excludedPrefixes = [
        'vendor/', 'node_modules/', '.git/', '.idea/', '.vscode/',
        'storage/', 'tmp/', 'temp/', 'logs/',
    ];
    foreach ($excludedPrefixes as $p) {
        if (str_starts_with($relLower, $p)) return true;
    }
    return false;
}

function uso_module_name(string $rel): string
{
    $rel = uso_normalize($rel);
    if (str_contains($rel, '/')) {
        return explode('/', $rel, 2)[0];
    }
    return 'root';
}

function uso_depth(string $rel): int
{
    $rel = uso_normalize($rel);
    $dir = dirname($rel);
    if ($dir === '.' || $dir === '') return 0;
    $parts = array_values(array_filter(explode('/', $dir), fn($p) => $p !== ''));
    return count($parts);
}

function uso_root_expr(int $depth): string
{
    if ($depth <= 0) return '__DIR__';
    return 'dirname(__DIR__, ' . $depth . ')';
}

function uso_has_upload(string $content): bool
{
    // A simple heuristic: if it references $_FILES, treat as upload-handling.
    return str_contains($content, '$_FILES');
}

function uso_already_patched(string $content): bool
{
    // If it already references our helper or safe_filename, skip.
    if (str_contains($content, 'rmi_sanitize_uploads(')) return true;
    if (preg_match('/\bsafe_filename\s*\(/', $content)) return true;
    if (str_contains($content, "upload_safety.php")) return true;
    return false;
}

function uso_insert_after_php_open(string $content, string $snippet): ?string
{
    if (!str_contains($content, '<?php')) return null;

    // Insert right after <?php and optional declare(...);
    $pattern = '/(<\?php\s*(?:declare\s*\(.*?\);\s*)?)/s';
    if (!preg_match($pattern, $content)) {
        return null;
    }

    return preg_replace($pattern, "$1\n" . $snippet . "\n", $content, 1);
}

function uso_snippet(int $depth): string
{
    $rootExpr = uso_root_expr($depth);

    return "// --- upload safety (auto-enforced) ---\n"
        . "require_once {$rootExpr} . '/_shared/upload_safety.php';\n"
        . "if (!empty(\$_FILES)) {\n"
        . "    // enforce safe_filename() for all uploaded names\n"
        . "    rmi_sanitize_uploads(\$_FILES);\n"
        . "}\n"
        . "// --- /upload safety ---";
}

function uso_find_php_files(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        if (strtolower($file->getExtension()) !== 'php') continue;
        $out[] = $file->getPathname();
    }
    sort($out);
    return $out;
}

// ---- main ----

$args = uso_parse_args($argv);
if (!empty($args['help'])) {
    uso_usage();
    exit(0);
}

$root = getcwd();
$apply = (bool)$args['apply'];
$appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));
if ($apply && in_array($appEnv, ['prod', 'production'], true) && !$args['i_understand']) {
    fwrite(STDERR, "Production safeguard: add --i-understand for --apply mode.\n");
    exit(2);
}
$only = null;
if (is_string($args['only']) && trim($args['only']) !== '') {
    $only = array_values(array_filter(array_map('trim', explode(',', $args['only']))));
}

$phpFiles = uso_find_php_files($root);
$changed = 0;
$skipped = 0;
$targets = 0;
$errors = 0;
$filesTouched = [];
$backupRoot = $root . '/storage/backups/patch_backups/upload_safety_' . date('Ymd_His');

function uso_backup_before_write(string $path, string $root, string $backupRoot): void
{
    $rel = uso_relpath($root, $path);
    $dest = $backupRoot . '/' . $rel;
    if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0775, true);
    @copy($path, $dest);
}

foreach ($phpFiles as $path) {
    $rel = uso_relpath($root, $path);
    if (uso_is_excluded($rel)) {
        $skipped++;
        continue;
    }

    $module = uso_module_name($rel);
    if (is_array($only) && !in_array($module, $only, true)) {
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false) {
        continue;
    }

    if (!uso_has_upload($content)) {
        continue;
    }
    $targets++;

    if (uso_already_patched($content)) {
        continue;
    }

    $depth = uso_depth($rel);
    $snippet = uso_snippet($depth);
    $newContent = uso_insert_after_php_open($content, $snippet);
    if ($newContent === null) {
        continue;
    }

    $changed++;
    echo "[PATCH] $rel\n";

    if ($apply) {
        if (!empty($args['backup'])) {
            uso_backup_before_write($path, $root, $backupRoot);
        }
        file_put_contents($path, $newContent);
        $filesTouched[] = $rel;
    }
}

echo "\nDone. Candidates with uploads: $targets\n";
echo $apply ? "Patched files: $changed\n" : "Would patch files: $changed (run with --apply)\n";
echo "Excluded/ignored: $skipped\n";
if (!empty($args['backup']) && $apply) {
    echo "Backup dir: $backupRoot\n";
}
@file_put_contents(
    $root . '/storage/logs/security_hardening_last.json',
    json_encode([
        'tool' => 'enforce_upload_safety.php',
        'mode' => $apply ? 'apply' : 'check',
        'with_backup' => (bool)($args['backup'] ?? false),
        'files_touched' => count($filesTouched),
        'skipped' => $skipped,
        'failed' => $errors,
        'touched_files' => $filesTouched,
        'generated_at' => date(DateTimeInterface::ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

