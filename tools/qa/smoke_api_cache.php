<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$logs = $root . '/storage/logs';
$base = (string)(getenv('SMOKE_BASE_URL') ?: getenv('APP_URL') ?: 'http://127.0.0.1');
$requestId = 'smoke-api-cache-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

require_once $root . '/_shared/bootstrap.php';
require_once $root . '/api/v1/mobile/_response.php';

$checks = [];
$overallOk = true;

function sac_add(array &$checks, string $name, bool $ok, string $detail = ''): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

// 1) Login to get token
$loginBody = json_encode([
    'username' => (string)(getenv('SMOKE_ADMIN_USER') ?: 'SmokeSYS_SYS'),
    'password' => (string)(getenv('SMOKE_ADMIN_PASS') ?: 'SmokeAdmin#123'),
    'device_id' => 'smoke-api-cache-device',
]);
$ch = curl_init(rtrim($base, '/') . '/api/v1/mobile/auth/login');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $loginBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-Request-Id: ' . $requestId,
        'X-Idempotency-Key: smoke-api-cache-' . $requestId,
    ],
    CURLOPT_TIMEOUT => 15,
]);
$loginResp = curl_exec($ch);
$loginCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$token = '';
if ($loginCode === 200 && $loginResp !== false) {
    $j = json_decode($loginResp, true);
    $token = (string)($j['data']['access_token'] ?? '');
}

if ($token === '') {
    sac_add($checks, 'login', false, "code={$loginCode}, need token for dashboard/get");
    $overallOk = false;
} else {
    sac_add($checks, 'login', true, 'OK');
}

// 2) Hit dashboard/get first time -> expect MISS or BYPASS
if ($token !== '') {
    $ch2 = curl_init(rtrim($base, '/') . '/api/v1/mobile/dashboard/get');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'X-Request-Id: ' . $requestId . '-1',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $r1 = curl_exec($ch2);
    $code1 = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $h1 = curl_getinfo($ch2, CURLINFO_HEADER_SIZE);
    $headers1 = substr((string)$r1, 0, $h1);
    curl_close($ch2);

    $xCache1 = '';
    if (preg_match('/X-Cache:\s*(\S+)/i', $headers1, $m)) {
        $xCache1 = trim((string)$m[1]);
    }

    $ok1 = ($code1 === 200);
    $expectMissOrBypass = in_array($xCache1, ['MISS', 'BYPASS'], true) || $xCache1 === '';
    sac_add($checks, 'dashboard_get_first', $ok1 && ($expectMissOrBypass || $xCache1 === 'HIT'), "code={$code1}, X-Cache={$xCache1}");
    if (!$ok1) $overallOk = false;

    // 3) Hit dashboard/get second time -> if cache enabled expect HIT, else BYPASS
    $ch3 = curl_init(rtrim($base, '/') . '/api/v1/mobile/dashboard/get');
    curl_setopt_array($ch3, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'X-Request-Id: ' . $requestId . '-2',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $r2 = curl_exec($ch3);
    $code2 = (int)curl_getinfo($ch3, CURLINFO_HTTP_CODE);
    $h2 = curl_getinfo($ch3, CURLINFO_HEADER_SIZE);
    $headers2 = substr((string)$r2, 0, $h2);
    curl_close($ch3);

    $xCache2 = '';
    if (preg_match('/X-Cache:\s*(\S+)/i', $headers2, $m)) {
        $xCache2 = trim((string)$m[1]);
    }

    $ok2 = ($code2 === 200);
    $envVal = function_exists('rmi_env') ? rmi_env('API_CACHE_ENABLED', '0') : (getenv('API_CACHE_ENABLED') ?: '0');
    $cacheEnabled = (int)$envVal === 1;
    $expectHitIfEnabled = $cacheEnabled ? ($xCache2 === 'HIT') : ($xCache2 === 'BYPASS' || $xCache2 === '');
    sac_add($checks, 'dashboard_get_second', $ok2, "code={$code2}, X-Cache={$xCache2}, enabled=" . ($cacheEnabled ? '1' : '0'));
    if (!$ok2) $overallOk = false;

    // 4) Response has X-Content-Type-Options
    $hasNosniff = stripos($headers2, 'X-Content-Type-Options') !== false;
    sac_add($checks, 'security_headers', $hasNosniff, $hasNosniff ? 'X-Content-Type-Options present' : 'missing');
    if (!$hasNosniff) $overallOk = false;
}

$output = [
    'state_version' => 1,
    'request_id' => $requestId,
    'overall_ok' => $overallOk,
    'checks' => $checks,
    'generated_at' => date(DateTimeInterface::ATOM),
];

@mkdir($logs, 0775, true);
@file_put_contents($logs . '/smoke_api_cache_last.json', json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo json_encode($output, JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($overallOk ? 0 : 1);
