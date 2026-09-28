<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$base = rtrim((string)getenv('APP_BASE_URL'), '/');
$user = (string)getenv('MOBILE_QA_USER');
$pass = (string)getenv('MOBILE_QA_PASS');
if ($base === '' || $user === '' || $pass === '') {
    echo "SKIP: APP_BASE_URL/MOBILE_QA_USER/MOBILE_QA_PASS belum diset.\n";
    exit(0);
}

/**
 * @return array{code:int,json:?array}
 */
function rq(string $method, string $url, array $headers = [], ?array $body = null): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    // Allow self-signed cert on internal IP (10.10.60.20)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if ($body !== null) {
        $raw = json_encode($body, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $json = json_decode((string)$raw, true);
    return ['code' => $code, 'json' => is_array($json) ? $json : null];
}

$rid = 'master-policy-' . date('YmdHis');
$login = rq(
    'POST',
    $base . '/api/v1/mobile/auth/login',
    ['X-Request-Id: ' . $rid, 'X-Idempotency-Key: ' . $rid . '-idem'],
    ['username' => $user, 'password' => $pass, 'device_id' => 'qa-cli']
);
if ($login['code'] !== 200 || !is_array($login['json']) || empty($login['json']['data']['access_token'])) {
    echo "FAIL: login gagal untuk smoke master policy.\n";
    exit(1);
}
$token = (string)$login['json']['data']['access_token'];

$checks = [
    '/api/v1/mobile/master/products',
    '/api/v1/mobile/master/vendors',
    '/api/v1/mobile/master/customers',
];
foreach ($checks as $ep) {
    $r = rq('GET', $base . $ep, ['X-Request-Id: ' . $rid . '-g', 'Authorization: Bearer ' . $token]);
    $code = (string)($r['json']['code'] ?? '');
    if ($r['code'] !== 403 || $code !== 'ERR_FORBIDDEN') {
        echo "FAIL: {$ep} expected 403 ERR_FORBIDDEN, got {$r['code']} {$code}\n";
        exit(1);
    }
}

echo "PASS: master/* policy returns 403.\n";
exit(0);
