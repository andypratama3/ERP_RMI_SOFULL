<?php
/**
 * rbac_action_matrix.php — POST / action layer from dual RBAC matrix snapshots.
 *
 * Requires rbac_matrix_http_dual.php (or compatible snapshots) first.
 * Reads:
 *   storage/logs/rbac_matrix_http_check_internal_last.json
 *   storage/logs/rbac_matrix_http_check_public_last.json
 *
 * Writes:
 *   storage/logs/rbac_action_matrix_last.internal.json
 *   storage/logs/rbac_action_matrix_last.public.json
 *   storage/logs/rbac_action_matrix_last.summary.md
 *   storage/logs/rbac_action_matrix_last.json (compat: internal POST view)
 *
 * Usage: php tools/qa/rbac_action_matrix.php [--strict] [--write-last] [--skip-public-check]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);
require_once $root . '/tools/_shared/tools_bootstrap.php';
require_once $root . '/tools/tools_state_lib.php';

$args = $_SERVER['argv'] ?? [];
$strict = in_array('--strict', $args, true);
$writeLast = in_array('--write-last', $args, true);
$skipPublicCheck = in_array('--skip-public-check', $args, true)
    || trim((string)(getenv('RBAC_ACTION_MATRIX_SKIP_PUBLIC') ?: '')) === '1';

$logsDir = $root . '/storage/logs';
$pathInt = $logsDir . '/rbac_matrix_http_check_internal_last.json';
$pathPub = $logsDir . '/rbac_matrix_http_check_public_last.json';

if (!is_file($pathInt)) {
    fwrite(STDERR, "FAIL: Missing {$pathInt} — run: php tools/qa/rbac_matrix_http_dual.php --write-last\n");
    exit($strict ? 2 : 1);
}

$dataInt = json_decode((string)file_get_contents($pathInt), true);
if (!is_array($dataInt)) {
    fwrite(STDERR, "FAIL: Invalid internal matrix JSON\n");
    exit($strict ? 2 : 1);
}

$dataPub = null;
if (!$skipPublicCheck && !is_file($pathPub)) {
    fwrite(STDERR, "WARN: Missing {$pathPub} — treating as public layer skipped (use rbac_matrix_http_dual without --skip-public for full dual).\n");
    $skipPublicCheck = true;
}
if (!$skipPublicCheck) {
    $dataPub = json_decode((string)file_get_contents($pathPub), true);
    if (!is_array($dataPub)) {
        fwrite(STDERR, "FAIL: Invalid public matrix JSON\n");
        exit($strict ? 2 : 1);
    }
}

$postOnly = static function (array $payload): array {
    $out = $payload;
    $out['results'] = array_values(array_filter(
        (array)($payload['results'] ?? []),
        static fn ($r) => (($r['method'] ?? '') === 'POST')
    ));

    return $out;
};

$countMismatch = static function (array $payload): int {
    $n = 0;
    foreach ((array)($payload['results'] ?? []) as $ep) {
        if (($ep['method'] ?? '') !== 'POST') {
            continue;
        }
        if (!(bool)($ep['guest_test']['ok'] ?? true)) {
            $n++;
        }
        foreach ((array)($ep['role_tests'] ?? []) as $t) {
            if (!($t['ok'] ?? true)) {
                $n++;
            }
        }
    }

    return $n;
};

$postInt = $postOnly($dataInt);
$postPub = $dataPub !== null ? $postOnly($dataPub) : null;

$mismatchInt = $countMismatch($dataInt);
$mismatchPub = $dataPub !== null ? $countMismatch($dataPub) : 0;
$okInt = $mismatchInt === 0;
$okPub = $skipPublicCheck ? true : ($mismatchPub === 0);
$overallOk = $okInt && $okPub;

$failLines = [];
$collect = static function (string $layer, array $payload) use (&$failLines): void {
    foreach ((array)($payload['results'] ?? []) as $ep) {
        if (($ep['method'] ?? '') !== 'POST') {
            continue;
        }
        $path = (string)($ep['path'] ?? '');
        $action = (string)($ep['action'] ?? '');
        $id = (string)($ep['id'] ?? '');
        foreach ((array)($ep['role_tests'] ?? []) as $user => $t) {
            if ($t['ok'] ?? true) {
                continue;
            }
            $failLines[] = [
                'layer' => $layer,
                'actor' => $user,
                'endpoint' => $path,
                'method' => 'POST',
                'action' => $action,
                'expected' => (string)($t['expected'] ?? ''),
                'actual' => (string)($t['actual'] ?? ''),
                'location' => (string)($t['location'] ?? ''),
                'category' => (string)($t['mismatch_category'] ?? 'OTHER'),
            ];
        }
    }
};
$collect('INTERNAL', $dataInt);
if ($dataPub !== null) {
    $collect('PUBLIC', $dataPub);
}

$md = "# RBAC Action Matrix — Summary\n\n";
$md .= '**Generated:** ' . date('Y-m-d H:i:s T') . "\n\n";
$md .= "| Layer | POST mismatches | OK |\n|-------|-----------------|----|\n";
$md .= '| INTERNAL | ' . $mismatchInt . ' | ' . ($okInt ? 'yes' : '**NO**') . " |\n";
$md .= '| PUBLIC | ' . ($skipPublicCheck ? 'n/a' : (string)$mismatchPub) . ' | ' . ($okPub ? 'yes' : '**NO**') . " |\n\n";
$md .= "## Failures (actor × endpoint × action)\n\n";
if ($failLines === []) {
    $md .= "_None._\n";
} else {
    foreach ($failLines as $fl) {
        $md .= '- **' . $fl['layer'] . '** `' . $fl['actor'] . '` → `' . $fl['endpoint'] . '` action=`' . $fl['action'] . '` expected **' . $fl['expected'] . '** got **' . $fl['actual'] . '** [' . $fl['category'] . "]\n";
    }
}

if ($writeLast) {
    @mkdir($logsDir, 0775, true);
    @file_put_contents($logsDir . '/rbac_action_matrix_last.internal.json', json_encode($postInt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($postPub !== null) {
        @file_put_contents($logsDir . '/rbac_action_matrix_last.public.json', json_encode($postPub, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    @file_put_contents($logsDir . '/rbac_action_matrix_last.summary.md', $md);
    $finCrit = false;
    foreach ((array)($postInt['results'] ?? []) as $r) {
        if (empty($r['cash_out'])) {
            continue;
        }
        foreach ((array)($r['role_tests'] ?? []) as $t) {
            if ((int)($t['expected'] ?? 0) === 403 && (int)($t['actual'] ?? 0) === 200) {
                $finCrit = true;
                break 2;
            }
        }
    }
    $actionPayload = [
        'ok' => $overallOk,
        'run_at' => date(DateTimeInterface::ATOM),
        'post_mismatch_internal' => $mismatchInt,
        'post_mismatch_public' => $skipPublicCheck ? null : $mismatchPub,
        'fin_special_critical' => $finCrit,
        'results' => $postInt['results'] ?? [],
    ];
    @file_put_contents($logsDir . '/rbac_action_matrix_last.json', json_encode($actionPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    @file_put_contents($logsDir . '/rbac_action_smoke_last.json', json_encode($actionPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

echo json_encode([
    'ok' => $overallOk,
    'post_mismatch_internal' => $mismatchInt,
    'post_mismatch_public' => $skipPublicCheck ? null : $mismatchPub,
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($overallOk ? 0 : ($strict ? 2 : 1));
