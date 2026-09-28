<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$base = rtrim((string)getenv('APP_BASE_URL'), '/');
if ($base === '') {
    echo "SKIP: APP_BASE_URL not set.\n";
    exit(0);
}

/**
 * @return array{code:int,json:?array,body:string}
 */
function req(string $method, string $url, array $headers = [], ?array $json = null): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    // Allow self-signed cert on internal IP (10.10.60.20) — same as other smoke tools
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if ($json !== null) {
        $raw = json_encode($json, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $dec = json_decode($raw, true);
    return ['code' => $code, 'json' => is_array($dec) ? $dec : null, 'body' => $raw];
}

// Try REST URL first; fallback to .php if server lacks rewrite (nginx, etc.)
$meUrls = [$base . '/api/v1/mobile/auth/me', $base . '/api/v1/mobile/auth/me.php'];
$r1 = null;
foreach ($meUrls as $url) {
    $r1 = req('GET', $url, ['X-Request-Id: neg-auth-me']);
    if ($r1['code'] === 401 && (($r1['json']['code'] ?? '') === 'ERR_UNAUTHENTICATED')) {
        break;
    }
}
if ($r1 === null || $r1['code'] !== 401 || (($r1['json']['code'] ?? '') !== 'ERR_UNAUTHENTICATED')) {
    $lastUrl = $meUrls[array_key_last($meUrls)] ?? '';
    $code = $r1['code'] ?? 0;
    $body = (string)($r1['body'] ?? '');
    $got = ($r1['json']['code'] ?? '') ?: '(no code)';
    echo "FAIL: auth/me without token should return ERR_UNAUTHENTICATED 401\n";
    echo "  URL: {$lastUrl}\n";
    echo "  Got: HTTP {$code}, code={$got}\n";
    if ($body !== '') echo "  Body: " . str_replace("\n", ' ', substr($body, 0, 300)) . "\n";
    exit(1);
}

$refreshUrls = [$base . '/api/v1/mobile/auth/refresh', $base . '/api/v1/mobile/auth/refresh.php'];
$r2 = null;
foreach ($refreshUrls as $url) {
    $r2 = req('POST', $url, ['X-Request-Id: neg-auth-refresh', 'X-Idempotency-Key: neg-auth-refresh-idem'], ['refresh_token' => 'invalid-refresh-token', 'device_id' => 'qa-neg']);
    $code = (string)($r2['json']['code'] ?? '');
    if ($r2['code'] === 401 && ($code === 'ERR_UNAUTHENTICATED' || $code === 'ERR_TOKEN_EXPIRED')) {
        break;
    }
}
$code = (string)($r2['json']['code'] ?? '');
if ($r2['code'] !== 401 || ($code !== 'ERR_UNAUTHENTICATED' && $code !== 'ERR_TOKEN_EXPIRED')) {
    echo "FAIL: auth/refresh invalid token expected 401 ERR_UNAUTHENTICATED/ERR_TOKEN_EXPIRED\n";
    exit(1);
}

echo "PASS: negative auth tests.\n";
exit(0);
