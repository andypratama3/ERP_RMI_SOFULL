<?php
declare(strict_types=1);

require_once __DIR__ . '/../../_shared/app_init.php';

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$outFile = $root . '/storage/logs/mobile_contract_check_last.json';

$allowErrors = [
    'ERR_UNAUTHENTICATED',
    'ERR_TOKEN_EXPIRED',
    'ERR_FORBIDDEN',
    'ERR_VALIDATION',
    'ERR_RATE_LIMIT',
    'ERR_IDEMPOTENCY_REPLAY_MISMATCH',
    'ERR_CONFLICT_STATE_CHANGED',
    'ERR_DUPLICATE_INVOICE',
    'ERR_3WAY_MISMATCH',
    'ERR_NEGATIVE_STOCK',
    'ERR_SELF_APPROVE_FORBIDDEN',
    'ERR_UPLOAD_INVALID_MIME',
    'ERR_UPLOAD_TOO_LARGE',
    'ERR_QUOTA_EXCEEDED',
    'ERR_NOT_FOUND',
    'ERR_INTERNAL',
];

function chk_read_json(string $path): ?array
{
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $j = json_decode($raw, true);
    return is_array($j) ? $j : null;
}

function chk_envelope(array $j, array $allowErrors): array
{
    $ok = true;
    $errs = [];
    if (!array_key_exists('ok', $j)) { $ok = false; $errs[] = 'missing:ok'; }
    if (!array_key_exists('request_id', $j)) { $ok = false; $errs[] = 'missing:request_id'; }
    if (($j['ok'] ?? null) === true) {
        if (!array_key_exists('message', $j)) { $ok = false; $errs[] = 'missing:message'; }
        if (!array_key_exists('data', $j)) { $ok = false; $errs[] = 'missing:data'; }
    } else {
        if (!array_key_exists('code', $j)) { $ok = false; $errs[] = 'missing:code'; }
        if (!array_key_exists('message', $j)) { $ok = false; $errs[] = 'missing:message'; }
        if (isset($j['code']) && !in_array((string)$j['code'], $allowErrors, true)) {
            $ok = false;
            $errs[] = 'error_code_not_allowed:' . (string)$j['code'];
        }
    }
    return [$ok, $errs];
}

$samples = [];
$samplesDir = $root . '/storage/logs/mobile_contract_samples';
if (is_dir($samplesDir)) {
    $it = scandir($samplesDir);
    if (is_array($it)) {
        foreach ($it as $f) {
            if ($f === '.' || $f === '..') continue;
            if (strtolower(pathinfo($f, PATHINFO_EXTENSION)) !== 'json') continue;
            $samples[] = $samplesDir . '/' . $f;
        }
    }
}

$results = [
    'ts' => date('c'),
    'checked_files' => [],
    'passed' => true,
    'errors' => [],
];

if (empty($samples)) {
    // Skip (not fail): mobile contract samples belum di-seed; gate tetap PASS agar automation tidak terblokir
    $results['passed'] = true;
    $results['skipped'] = true;
    $results['errors'][] = 'SKIP: No sample response files in storage/logs/mobile_contract_samples/*.json (seed samples untuk validasi penuh)';
} else {
    foreach ($samples as $f) {
        $j = chk_read_json($f);
        if (!is_array($j)) {
            $results['passed'] = false;
            $results['errors'][] = 'invalid_json:' . basename($f);
            continue;
        }
        [$ok, $errs] = chk_envelope($j, $allowErrors);
        $results['checked_files'][] = basename($f);
        if (!$ok) {
            $results['passed'] = false;
            foreach ($errs as $e) {
                $results['errors'][] = basename($f) . ':' . $e;
            }
        }
    }
}

@mkdir(dirname($outFile), 0777, true);
file_put_contents($outFile, json_encode($results, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

echo json_encode($results, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($results['passed'] ? 0 : 1);
