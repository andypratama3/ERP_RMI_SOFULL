<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/tools_ui_helpers.php';
require_once __DIR__ . '/_shared/tools_bootstrap.php';
require_once __DIR__ . '/../_shared/db.php';

function s_log(string $msg): void { echo $msg . PHP_EOL; }
function s_now(): string { return gmdate('c'); }

function s_load_env(string $root): array {
    $out = [];
    $envFile = $root . '/.env';
    if (!is_file($envFile)) return $out;
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $out[trim($k)] = trim($v, "\"'");
    }
    return $out;
}

function s_db_pdo(string $root): ?PDO {
    if (function_exists('rmi_db_pdo')) {
        try {
            $pdo = rmi_db_pdo();
            if ($pdo instanceof PDO) {
                return $pdo;
            }
        } catch (Throwable $e) {
            // Fall back to local resolver below.
        }
    }

    $env = s_load_env($root);
    $defaultPort = (string)((int)(getenv('DB_PORT_DEFAULT') ?: (is_dir('/Applications/MAMP') ? 8889 : 3306)));
    $defaultPass = (string)(getenv('DB_PASS_DEFAULT') ?: (is_dir('/Applications/MAMP') ? 'root' : ''));
    $cfg = function_exists('rmi_db_config') ? rmi_db_config() : [];
    // Keep fallback aligned with shared DB resolver to avoid false-negative smoke failures.
    $host = (string)($env['ERP_DB_HOST'] ?? $env['DB_HOST'] ?? ($cfg['host'] ?? tools_get_env_db('HOST') ?: 'localhost'));
    $port = (string)($env['ERP_DB_PORT'] ?? $env['DB_PORT'] ?? ($cfg['port'] ?? tools_get_env_db('PORT') ?: $defaultPort));
    $name = (string)($env['ERP_DB_NAME'] ?? $env['DB_DATABASE'] ?? $env['DB_NAME'] ?? ($cfg['name'] ?? tools_get_env_db('NAME') ?: 'ERP_RMI_SOFULL'));
    $user = (string)($env['ERP_DB_USER'] ?? $env['DB_USERNAME'] ?? $env['DB_USER'] ?? ($cfg['user'] ?? tools_get_env_db('USER') ?: 'root'));
    $pass = (string)($env['ERP_DB_PASS'] ?? $env['DB_PASSWORD'] ?? ($cfg['pass'] ?? tools_get_env_db('PASS') ?: $defaultPass));
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function s_seed_user(PDO $pdo, string $username, string $password, string $role, string $level, string $department): void {
    if (function_exists('rmi_tools_seed_username_allowed') && !rmi_tools_seed_username_allowed($username)) {
        return;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $st = $pdo->prepare("SELECT id FROM master_system_login WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $id = (int)($st->fetchColumn() ?: 0);
    if ($id > 0) {
        $up = $pdo->prepare("UPDATE master_system_login SET password_hash=?, role=?, level=?, department=?, status='ACTIVE', deleted_at=NULL, deleted_by=NULL, delete_reason=NULL, updated_at=NOW() WHERE id=?");
        $up->execute([$hash, $role, $level, $department, $id]);
    } else {
        $ins = $pdo->prepare("INSERT INTO master_system_login(username,password_hash,full_name,role,level,department,status,created_at,updated_at) VALUES(?,?,?,?,?,?,'ACTIVE',NOW(),NOW())");
        $ins->execute([$username, $hash, $username, $role, $level, $department]);
        $id = (int)$pdo->lastInsertId();
    }
    // Bersihkan throttle agar login smoke tidak terblokir dari run sebelumnya
    try {
        $pdo->prepare("DELETE FROM auth_login_attempts WHERE username=?")->execute([$username]);
    } catch (Throwable $e) { /* tabel mungkin belum ada */ }
}

/** Browser-like UA — many edge proxies (Cloudflare, etc.) block default curl; internal IP usually unaffected. */
function s_req_default_user_agent(): string {
    return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 ERP-Smoke/1.0';
}

function s_req(string $method, string $url, string $cookieFile, array $post = [], array $headers = [], int $timeoutSec = 40, ?string $userAgent = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // allow self-signed/Cloudflare Origin cert
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, ($userAgent !== null && $userAgent !== '') ? $userAgent : s_req_default_user_agent());
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeoutSec));
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $browserish = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
        'Cache-Control: no-cache',
    ];
    $hdr = $headers !== [] ? array_merge($browserish, $headers) : $browserish;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if ($resp === false) $resp = '';
    $raw = (string)$resp;
    $header = substr($raw, 0, $hsize);
    $body = substr($raw, $hsize);
    return ['code' => $code, 'header' => $header, 'body' => $body, 'error' => $err];
}

function s_has_crash(string $body): bool {
    foreach (['Fatal error', 'Unhandled Exception', 'Uncaught', 'Parse error', 'SQLSTATE'] as $k) {
        if (stripos($body, $k) !== false) return true;
    }
    // Detect real PHP warnings/notices while avoiding false positives
    // from normal UI text labels that contain "Warning:".
    if (preg_match('/\\bWarning:\\s+.*\\s+in\\s+.*\\s+on line\\s+\\d+/i', $body)) return true;
    if (preg_match('/\\bNotice:\\s+.*\\s+in\\s+.*\\s+on line\\s+\\d+/i', $body)) return true;
    return false;
}

function s_csrf_token(string $html): string {
    if (preg_match('/name="csrf_token"\s+value="([^"]+)"/i', $html, $m)) return (string)$m[1];
    if (preg_match('/name="_csrf"\s+value="([^"]+)"/i', $html, $m)) return (string)$m[1];
    if (preg_match('/var\s+token\s*=\s*"([^"]+)"/i', $html, $m)) return (string)$m[1];
    return '';
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$args = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);
$skipPublic = trim((string)(getenv('SMOKE_SKIP_PUBLIC') ?: '')) === '1';

require_once __DIR__ . '/smoke_http_layer.php';

$baseInternal = (string)(getenv('SMOKE_BASE_URL') ?: getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('APP_URL') ?: '');
if ($baseInternal === '' && function_exists('rmi_tools_base_url')) {
    $baseInternal = rmi_tools_base_url('internal');
}
if ($baseInternal === '') {
    $baseInternal = 'https://localhost/ERP_RMI_SOFULL';
}
if (function_exists('normalize_base_url')) {
    try {
        $baseInternal = normalize_base_url($baseInternal);
    } catch (Throwable $e) {
        $baseInternal = rtrim(preg_replace('#(https?://)/+#', '$1', trim($baseInternal)), '/');
    }
} else {
    $baseInternal = rtrim(preg_replace('#(https?://)/+#', '$1', trim($baseInternal)), '/');
}

$basePublic = trim((string)(getenv('TOOLS_BASE_URL_PUBLIC') ?: getenv('APP_PUBLIC_URL') ?: ''));
if ($basePublic === '' && function_exists('rmi_tools_base_url')) {
    $basePublic = rmi_tools_base_url('public');
}
if ($basePublic === '' || stripos($basePublic, 'localhost') !== false) {
    $basePublic = 'https://erp.rizqullahmediska.com/ERP_RMI_SOFULL';
}
if (function_exists('normalize_base_url')) {
    try {
        $basePublic = normalize_base_url($basePublic);
    } catch (Throwable $e) {
        $basePublic = rtrim(preg_replace('#(https?://)/+#', '$1', trim($basePublic)), '/');
    }
}

$adminUser = (string)(getenv('SMOKE_ADMIN_USER') ?: 'SmokeSYS_SYS');
$adminPass = (string)(getenv('SMOKE_ADMIN_PASS') ?: 'SmokeAdmin#123');
$staffUser = (string)(getenv('SMOKE_STAFF_USER') ?: 'SmokeBRANCH_SYS');
$staffPass = (string)(getenv('SMOKE_STAFF_PASS') ?: 'SmokeStaff#123');

$results = [];
$failed = 0;
$passed = 0;
$started = microtime(true);

$add = function (string $category, string $name, bool $ok, string $detail = '', ?int $httpCode = null) use (&$results, &$failed, &$passed): void {
    $row = ['category' => $category, 'name' => $name, 'ok' => $ok, 'detail' => $detail, 'layer' => 'global'];
    if ($httpCode !== null) {
        $row['http_code'] = $httpCode;
    }
    if (!$ok) {
        $row['mismatch_category'] = ($httpCode === 301) ? 'URL_JOIN_BUG' : 'OTHER';
    }
    $results[] = $row;
    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }
};

@mkdir($root . '/storage/logs', 0775, true);
$preflightWritable = is_dir($root . '/storage/logs') && is_writable($root . '/storage/logs');
$add('preflight', 'storage_logs_writable', $preflightWritable, $preflightWritable ? 'ok' : 'not writable');

$pdo = s_db_pdo($root);
$add('preflight', 'db_connect', $pdo instanceof PDO, $pdo instanceof PDO ? 'ok' : 'db connect failed');
if ($pdo instanceof PDO) {
    try {
        s_seed_user($pdo, $adminUser, $adminPass, 'sys', 'sys', 'SYS');
        s_seed_user($pdo, $staffUser, $staffPass, 'staff', 'staff', 'BRANCH');
        $add('preflight', 'seed_smoke_users', true, 'ok');
    } catch (Throwable $e) {
        $add('preflight', 'seed_smoke_users', false, 'failed');
    }
}

$layerInternal = smoke_http_run_layer($root, $baseInternal, 'internal', $adminUser, $adminPass, $staffUser, $staffPass);
foreach ($layerInternal['results'] as $row) {
    $results[] = $row;
    if (!empty($row['ok'])) {
        $passed++;
    } else {
        $failed++;
    }
}

$layerPublic = null;
$publicSkippedEdge = false;
if (!$skipPublic) {
    $requirePublic = trim((string)(getenv('SMOKE_REQUIRE_PUBLIC') ?: '')) === '1';
    // Default: run full public suite (acceptance: public fail=0). Opt-in skip when WAF blocks CLI: SMOKE_AUTO_SKIP_PUBLIC_EDGE=1
    $autoSkipPublicEdge = trim((string)(getenv('SMOKE_AUTO_SKIP_PUBLIC_EDGE') ?: '')) === '1';
    $probeCookie = $root . '/storage/logs/.smoke_public_probe_cookie.txt';
    @unlink($probeCookie);
    $probePath = '/master/login.php';
    $probeUrl = function_exists('url_join') ? url_join($basePublic, $probePath) : rtrim($basePublic, '/') . $probePath;
    $probeResp = s_req('GET', $probeUrl, $probeCookie, [], [], 25);
    @unlink($probeCookie);
    $publicLoginReachable = in_array($probeResp['code'], [200, 302], true) && !s_has_crash($probeResp['body']);
    if (!$publicLoginReachable && $autoSkipPublicEdge && !$requirePublic) {
        $publicSkippedEdge = true;
        $bodyL = strtolower(substr($probeResp['body'], 0, 8000));
        $looksLikeEdge = str_contains($bodyL, 'cloudflare')
            || str_contains($bodyL, 'cf-ray')
            || str_contains($bodyL, 'attention required')
            || str_contains($bodyL, 'just a moment');
        $layerPublic = [
            'results' => [[
                'category' => 'preflight',
                'name' => 'public_layer_skipped_edge_block',
                'ok' => true,
                'detail' => 'probe GET /master/login.php code=' . $probeResp['code']
                    . ($looksLikeEdge ? ' (body suggests CDN/WAF)' : '')
                    . ' — skipped full public suite; internal layer is authoritative. '
                    . 'Fix: whitelist NAS/outbound IP on Cloudflare, or set SMOKE_REQUIRE_PUBLIC=1 to fail this run until public is reachable.',
                'layer' => 'public',
                'http_code' => $probeResp['code'],
                'mismatch_category' => 'PUBLIC_EDGE_SKIPPED',
            ]],
            'failed' => 0,
            'passed' => 1,
            'base_url' => $basePublic,
            'elapsed_ms' => 0,
            'skipped' => true,
            'skipped_reason' => 'EDGE_BLOCK_OR_UNREACHABLE',
            'probe_code' => $probeResp['code'],
        ];
        foreach ($layerPublic['results'] as $row) {
            $results[] = $row;
            $passed++;
        }
    } else {
        $layerPublic = smoke_http_run_layer($root, $basePublic, 'public', $adminUser, $adminPass, $staffUser, $staffPass);
        foreach ($layerPublic['results'] as $row) {
            $results[] = $row;
            if (!empty($row['ok'])) {
                $passed++;
            } else {
                $failed++;
            }
        }
    }
}

$elapsed = (int)round((microtime(true) - $started) * 1000);
$summary = [
    'state_version' => 2,
    'ok' => $failed === 0,
    'fail_count' => $failed,
    'pass_count' => $passed,
    'generated_at' => s_now(),
    'base_url' => $baseInternal,
    'base_url_internal' => $layerInternal['base_url'],
    'base_url_public' => $layerPublic ? $layerPublic['base_url'] : null,
    'layers' => [
        'internal' => [
            'base_url' => $layerInternal['base_url'],
            'fail' => $layerInternal['failed'],
            'pass' => $layerInternal['passed'],
            'elapsed_ms' => $layerInternal['elapsed_ms'],
        ],
        'public' => $layerPublic ? [
            'base_url' => $layerPublic['base_url'],
            'fail' => $layerPublic['failed'],
            'pass' => $layerPublic['passed'],
            'elapsed_ms' => $layerPublic['elapsed_ms'],
            'skipped' => (bool)($layerPublic['skipped'] ?? false),
            'skipped_reason' => $layerPublic['skipped_reason'] ?? null,
            'probe_code' => $layerPublic['probe_code'] ?? null,
        ] : null,
    ],
    'total' => count($results),
    'pass' => $passed,
    'fail' => $failed,
    'elapsed_ms' => $elapsed,
    'checks' => $results,
    'results' => $results,
    'summary' => ['pass' => $passed, 'fail' => $failed, 'total' => count($results)],
];
if ($layerPublic && empty($layerPublic['skipped']) && (int)($layerPublic['failed'] ?? 0) > 0) {
    $summary['public_layer_note'] = 'Public failures often = WAF/CDN blocking CLI (403 on login). Run: php tools/qa/smoke_public_probe.php --write-last. Interim: SMOKE_AUTO_SKIP_PUBLIC_EDGE=1';
}

$logsDir = $root . '/storage/logs';
$outFile = $logsDir . '/smoke_http_last.json';
@file_put_contents($outFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$intOnly = ['state_version' => $summary['state_version'], 'ok' => $layerInternal['failed'] === 0, 'base_url' => $layerInternal['base_url'], 'results' => array_values(array_filter($results, static fn ($r) => ($r['layer'] ?? '') === 'internal' || ($r['layer'] ?? '') === 'global'))];
@file_put_contents($logsDir . '/smoke_http_last.internal.json', json_encode($intOnly, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
if ($layerPublic) {
    $pubOnly = ['state_version' => $summary['state_version'], 'ok' => $layerPublic['failed'] === 0, 'base_url' => $layerPublic['base_url'], 'results' => array_values(array_filter($results, static fn ($r) => ($r['layer'] ?? '') === 'public'))];
    @file_put_contents($logsDir . '/smoke_http_last.public.json', json_encode($pubOnly, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
$mdFile = $logsDir . '/smoke_http_last.md';
$md = [];
$md[] = '# Smoke HTTP Last Result';
$md[] = '';
$md[] = '- Generated: ' . $summary['generated_at'];
$md[] = '- Internal: ' . $summary['base_url_internal'];
$md[] = '- Public: ' . (string)($summary['base_url_public'] ?? 'skipped');
$md[] = '- Total: ' . $summary['total'];
$md[] = '- Pass: ' . $summary['pass'];
$md[] = '- Fail: ' . $summary['fail'];
$md[] = '- Elapsed (ms): ' . $summary['elapsed_ms'];
$md[] = '';
$md[] = '## Checks';
foreach ($results as $row) {
    $mc = isset($row['mismatch_category']) ? ' [' . $row['mismatch_category'] . ']' : '';
    $md[] = '- [' . (!empty($row['ok']) ? 'PASS' : 'FAIL') . '] ' . ($row['layer'] ?? '') . ' / ' . $row['category'] . ' :: ' . $row['name'] . ' (' . $row['detail'] . ')' . $mc;
}
@file_put_contents($mdFile, implode("\n", $md) . "\n");
echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
$exitCode = $failed > 0 ? ($strict ? 2 : 1) : 0;
exit($exitCode);

