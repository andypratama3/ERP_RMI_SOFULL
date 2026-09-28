<?php
/**
 * tools/enforce_login_guards.php
 *
 * Patch-in `require_login()` into PHP pages that look unguarded (pattern-based).
 *
 * Why this exists:
 * - Your Enterprise Audit is file-based (pattern matching). Even if a guard exists in an include,
 *   individual pages may still be flagged. This tool inserts a small, explicit guard line per page.
 *
 * Safety:
 * - Default mode is PREVIEW (no write).
 * - Use --apply to write changes.
 *
 * Usage:
 *   php tools/enforce_login_guards.php
 *   php tools/enforce_login_guards.php --apply
 *   php tools/enforce_login_guards.php --apply --only=stock,sales,master,dashboards,tools,root
 */

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../master/auth.php';
    if (function_exists('require_login')) {
        require_login();
    }
    if (function_exists('require_role')) {
        require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
    }
    http_response_code(405);
    exit('CLI only');
}

function print_usage(): void
{
    $msg = <<<TXT
Usage:
  php tools/enforce_login_guards.php [--apply] [--only=mod1,mod2,...]

Notes:
  - Run from the PROJECT ROOT (same level as master/, _shared/, tools/).
  - master/login.php + master/logout.php + master/auth.php are excluded.

Examples:
  php tools/enforce_login_guards.php
  php tools/enforce_login_guards.php --apply --only=stock,sales

TXT;
    fwrite(STDERR, $msg);
}

function parse_args(array $argv): array
{
    $args = [
        'apply' => false,
        'check' => false,
        'backup' => false,
        'i_understand' => false,
        'only' => null,
        'help' => false,
        'verbose' => false,
    ];

    foreach ($argv as $i => $a) {
        if ($i === 0) continue;
        if ($a === '--apply') $args['apply'] = true;
        elseif ($a === '--check') $args['check'] = true;
        elseif ($a === '--backup') $args['backup'] = true;
        elseif ($a === '--i-understand') $args['i_understand'] = true;
        elseif ($a === '--help' || $a === '-h') $args['help'] = true;
        elseif ($a === '--verbose') $args['verbose'] = true;
        elseif (str_starts_with($a, '--only=')) {
            $args['only'] = substr($a, 7);
        }
    }

    return $args;
}

function norm_slash(string $path): string
{
    return str_replace('\\', '/', $path);
}

function rel_path(string $root, string $path): string
{
    $root = rtrim(norm_slash($root), '/');
    $path = norm_slash($path);
    if (str_starts_with($path, $root . '/')) {
        return substr($path, strlen($root) + 1);
    }
    return $path;
}

function module_from_rel(string $rel): string
{
    $rel = norm_slash($rel);
    $parts = explode('/', $rel);
    return $parts[0] ?? 'root';
}

function depth_from_rel(string $rel): int
{
    $rel = norm_slash($rel);
    $dir = dirname($rel);
    if ($dir === '.' || $dir === '') return 0;
    $segments = array_values(array_filter(explode('/', $dir), fn($s) => $s !== ''));
    return count($segments);
}

function root_expr(int $depth): string
{
    if ($depth <= 0) return '__DIR__';
    return 'dirname(__DIR__, ' . $depth . ')';
}

function should_skip(string $rel): bool
{
    $r = strtolower(norm_slash($rel));

    // Skip common vendor/build dirs
    $skipPrefixes = [
        'vendor/', 'node_modules/', '.git/', '.idea/', '.vscode/',
        'storage/', 'cache/', 'tmp/', 'logs/',
    ];
    foreach ($skipPrefixes as $p) {
        if (str_starts_with($r, $p)) return true;
    }

    // Skip tools themselves
    if (str_starts_with($r, 'tools/')) return true;

    // Skip master auth + login/logout
    if (preg_match('#^master/(auth|login|logout)\.php$#', $r)) return true;

    return false;
}

function has_likely_guard(string $content): bool
{
    if (preg_match('/\brequire_login\s*\(/', $content)) return true;
    // already requiring master/auth in some form
    if (preg_match('#\b(require|include)(_once)?\s*\(?.*master\s*/\s*auth\.php#i', $content)) return true;
    if (preg_match('#\b(require|include)(_once)?\s+.*master\s*/\s*auth\.php#i', $content)) return true;
    return false;
}

function build_guard_snippet(int $depth, string $module): string
{
    $root = root_expr($depth);

    $lines = [];
    $lines[] = "// --- auto-injected login guard (tools/enforce_login_guards.php) ---";
    $lines[] = "// Only enforce when this file is executed directly (safe for include/helper files).";
    $lines[] = "if (PHP_SAPI !== 'cli') {";
    $lines[] = "  \$__sf = (string)(\$_SERVER['SCRIPT_FILENAME'] ?? '');";
    $lines[] = "  \$__direct = (\$__sf === '' || realpath(__FILE__) === realpath(\$__sf));";
    $lines[] = "  if (\$__direct) {";
    $lines[] = "    require_once {$root} . '/master/auth.php';";
    $lines[] = "    require_login();";
    $lines[] = "  }";
    $lines[] = "}";

    // Optional: tighten some admin-ish modules
    $moduleLower = strtolower($module);
    $rbacModules = ['rbac', 'tools'];
    if (in_array($moduleLower, $rbacModules, true)) {
        $lines[] = "// require_role('ADMIN'); // uncomment if you want to hard-lock this page to ADMIN";
    }

    $lines[] = "// -------------------------------------------------------------";

    return "\n" . implode("\n", $lines) . "\n\n";
}

function insert_after_php_open(string $content, string $snippet): ?string
{
    if (!preg_match('/<\?php/i', $content)) return null;

    // Keep optional declare(strict_types=1); directly after <?php
    $pattern = '/(<\?php\s*(?:declare\s*\(.*?\);\s*)?)/is';
    return preg_replace($pattern, '$1' . $snippet, $content, 1);
}

function find_php_files(string $root): array
{
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $out = [];
    foreach ($rii as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) continue;
        if (strtolower($file->getExtension()) !== 'php') continue;
        $out[] = $file->getPathname();
    }

    sort($out);
    return $out;
}

// --------------------------- main ---------------------------

$args = parse_args($argv);
if ($args['help']) {
    print_usage();
    exit(0);
}

$root = getcwd();
$appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));
if ($args['apply'] && in_array($appEnv, ['prod', 'production'], true) && !$args['i_understand']) {
    fwrite(STDERR, "Production safeguard: add --i-understand for --apply mode.\n");
    exit(2);
}
$only = null;
if (is_string($args['only']) && trim($args['only']) !== '') {
    $only = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $args['only'])))));
}

$phpFiles = find_php_files($root);
$total = 0;
$patched = 0;
$skipped = 0;
$errors = 0;
$filesTouched = [];
$backupRoot = $root . '/storage/backups/patch_backups/login_guards_' . date('Ymd_His');

function backup_before_write_guard(string $full, string $root, string $backupRoot): void
{
    $rel = ltrim(str_replace($root, '', $full), '/');
    $dest = $backupRoot . '/' . $rel;
    if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0775, true);
    @copy($full, $dest);
}

foreach ($phpFiles as $path) {
    $rel = rel_path($root, $path);
    if (should_skip($rel)) {
        $skipped++;
        continue;
    }

    $module = module_from_rel($rel);
    if (is_array($only) && !in_array(strtolower($module), $only, true)) {
        continue;
    }

    $total++;
    $content = @file_get_contents($path);
    if ($content === false) {
        fwrite(STDERR, "[WARN] Cannot read: {$rel}\n");
        continue;
    }

    if (has_likely_guard($content)) {
        if ($args['verbose']) {
            echo "[OK] {$rel} (already guarded)\n";
        }
        continue;
    }

    $depth = depth_from_rel($rel);
    $snippet = build_guard_snippet($depth, $module);
    $newContent = insert_after_php_open($content, $snippet);
    if ($newContent === null || $newContent === $content) {
        if ($args['verbose']) {
            echo "[SKIP] {$rel} (no <?php or unchanged)\n";
        }
        continue;
    }

    $patched++;

    if ($args['apply']) {
        if ($args['backup']) {
            backup_before_write_guard($path, $root, $backupRoot);
        }
        $ok = @file_put_contents($path, $newContent);
        if ($ok === false) {
            fwrite(STDERR, "[ERR] Failed to write: {$rel}\n");
            $errors++;
        } else {
            echo "[PATCHED] {$rel}\n";
            $filesTouched[] = $rel;
        }
    } else {
        echo "[PREVIEW] would patch: {$rel}\n";
    }
}

echo "\n--- Summary ---\n";
echo "Root          : {$root}\n";
echo "Files scanned  : {$total}\n";
echo "Skipped        : {$skipped}\n";
echo "Patched        : {$patched}\n";
echo "Mode           : " . ($args['apply'] ? 'APPLY' : 'PREVIEW') . "\n";
if ($args['backup'] && $args['apply']) {
    echo "Backup dir     : {$backupRoot}\n";
}

if (!$args['apply']) {
    echo "\nTip: run again with --apply to write changes.\n";
}
@file_put_contents(
    $root . '/storage/logs/security_hardening_last.json',
    json_encode([
        'tool' => 'enforce_login_guards.php',
        'mode' => $args['apply'] ? 'apply' : 'check',
        'with_backup' => (bool)$args['backup'],
        'files_touched' => count($filesTouched),
        'skipped' => $skipped,
        'failed' => $errors,
        'touched_files' => $filesTouched,
        'generated_at' => date(DateTimeInterface::ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);
