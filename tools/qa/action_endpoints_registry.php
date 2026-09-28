<?php
/**
 * action_endpoints_registry.php — Discover mutation-like PHP endpoints (heuristic).
 *
 * Output: storage/logs/action_endpoints_registry_last.json
 *
 * Usage: php tools/qa/action_endpoints_registry.php [--write-last]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);

$skipDirs = ['vendor', 'node_modules', '.git', 'storage/logs', 'storage/backups', 'storage/cache'];
$extOk = ['php'];

$mutationPattern = '/\$_POST\b|\$_REQUEST\s*\[\s*[\'"]action[\'"]|approve|post_pay|create_pay|void|delete|submit|apply|reverse|\$_POST\s*\[\s*[\'"](save|delete|approve)/i';

$registry = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iter as $f) {
    if (!$f->isFile()) {
        continue;
    }
    $full = str_replace('\\', '/', $f->getPathname());
    foreach ($skipDirs as $sd) {
        if (str_contains($full, '/' . $sd . '/')) {
            continue 2;
        }
    }
    $ext = strtolower((string)pathinfo($full, PATHINFO_EXTENSION));
    if (!in_array($ext, $extOk, true)) {
        continue;
    }
    if (str_starts_with($full, $root . '/tools/qa/') && str_contains($full, '_test')) {
        continue;
    }
    $src = (string)@file_get_contents($full);
    if ($src === '' || !preg_match($mutationPattern, $src)) {
        continue;
    }
    $rel = ltrim(str_replace($root . '/', '', $full), '/');
    $risk = 'STOCK';
    if (preg_match('/payment|pay_|ap_|gl_reversal|reversal|cash|invoice_ap|purchases_payment/i', $rel . $src)) {
        $risk = 'CASH_OUT';
    }
    if (str_starts_with($rel, 'tools/') || str_contains($rel, '/tools/')) {
        $risk = 'TOOLS';
    }
    if (str_contains($rel, 'rbac') || str_contains($rel, 'master_system_login')) {
        $risk = 'RBAC';
    }
    $perm = '(infer from bootstrap / RBAC_ALL_MODULES_V1.md)';
    $hasFin = (bool)preg_match('/auth_require_fin_central|rbac_guard_require_fin_central|fin_central/i', $src);
    $hasCsrf = (bool)preg_match('/verify_csrf|rbac_guard_require_csrf_post/i', $src);

    $registry[] = [
        'file' => $rel,
        'method' => (preg_match('/REQUEST_METHOD.*POST/', $src) ? 'POST' : 'MIXED'),
        'action' => '(heuristic — inspect isset($_POST[...]) / cases)',
        'permission_code' => $perm,
        'module' => explode('/', $rel, 2)[0] ?? '',
        'risk' => $risk,
        'has_fin_central_guard_hint' => $hasFin,
        'has_csrf_hint' => $hasCsrf,
    ];
}

usort($registry, static fn ($a, $b) => strcmp((string)$a['file'], (string)$b['file']));

$payload = [
    'run_at' => date(DateTimeInterface::ATOM),
    'count' => count($registry),
    'entries' => $registry,
];

if ($writeLast) {
    @mkdir($root . '/storage/logs', 0775, true);
    @file_put_contents($root . '/storage/logs/action_endpoints_registry_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode(['ok' => true, 'count' => count($registry)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
