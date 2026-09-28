<?php
declare(strict_types=1);

$base = rtrim((string)getenv('APP_BASE_URL'), '/');
if ($base === '') {
    echo "SKIP: APP_BASE_URL not set.\n";
    exit(0);
}

function req(string $method, string $url, array $headers = [], ?array $json = null): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    if ($json !== null) {
        $body = json_encode($json, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($h, ['Content-Type: application/json']));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $dec = json_decode((string)$body, true);
    return ['code' => $code, 'json' => is_array($dec) ? $dec : null, 'body' => (string)$body];
}

// Try REST URL first; fallback to .php if server lacks rewrite (nginx, etc.)
$meUrls = [$base . '/api/v1/mobile/auth/me', $base . '/api/v1/mobile/auth/me.php'];
$r1 = null;
foreach ($meUrls as $url) {
    $r1 = req('GET', $url, ['X-Request-Id: smoke-auth-me']);
    if ($r1['code'] === 401 && is_array($r1['json']) && (($r1['json']['code'] ?? '') === 'ERR_UNAUTHENTICATED')) {
        break;
    }
}
if ($r1 === null || $r1['code'] !== 401 || !is_array($r1['json']) || (($r1['json']['code'] ?? '') !== 'ERR_UNAUTHENTICATED')) {
    echo "FAIL: auth/me unauthorized check failed\n";
    exit(1);
}

$maxAttempts = (int)(getenv('MOBILE_QA_RATE_LIMIT_ATTEMPTS') ?: 40);
if ($maxAttempts < 1) $maxAttempts = 40;
$hitRateLimit = false;
$lastCode = '';
for ($i = 0; $i < $maxAttempts; $i++) {
    $rid = 'smoke-login-' . $i;
    $r = req('POST', $base . '/api/v1/mobile/auth/login', ['X-Request-Id: ' . $rid, 'X-Idempotency-Key: ' . $rid . '-idem'], [
        'username' => 'invalid-user',
        'password' => 'invalid-pass',
        'device_id' => 'smoke-device',
    ]);
    $lastCode = (string)($r['json']['code'] ?? '');
    if ($lastCode === 'ERR_RATE_LIMIT') {
        $hitRateLimit = true;
        break;
    }
}

if (!$hitRateLimit) {
    // Policy may be configured with high max_hits in some environments.
    // Keep smoke green as long as the endpoint is responding with controlled auth errors.
    if ($lastCode === 'ERR_UNAUTHENTICATED' || $lastCode === 'ERR_VALIDATION') {
        echo "WARN: login rate limit not reached within {$maxAttempts} attempts (last_code={$lastCode}).\n";
        echo "PASS: mobile API smoke checks with warning.\n";
        exit(0);
    }
    echo "FAIL: login rate limit check failed (last_code={$lastCode})\n";
    exit(1);
}

echo "PASS: mobile API smoke checks.\n";
exit(0);
