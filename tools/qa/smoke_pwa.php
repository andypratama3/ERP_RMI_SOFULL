<?php
declare(strict_types=1);

/**
 * PWA Smoke — verifikasi file & manifest untuk install di Android.
 * Spec: docs/governance/21_PWA_MOBILE_UX_SPEC.md
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$writeLast = !in_array('--no-write-last', $_SERVER['argv'] ?? [], true);

$results = [];
$passed = 0;
$failed = 0;

$add = static function (string $category, string $name, bool $ok, $detail = '') use (&$results, &$passed, &$failed): void {
    $results[] = ['category' => $category, 'name' => $name, 'ok' => $ok, 'detail' => (string)$detail];
    $ok ? $passed++ : $failed++;
};

// ─── File existence ───
$pwaFiles = [
    'public/manifest.json' => 'Manifest PWA utama',
    'public/sw.js' => 'Service Worker',
    'public/offline.html' => 'Offline fallback',
    'public/icon-192.png' => 'Ikon 192 (PNG)',
    'public/icon-512.png' => 'Ikon 512 (PNG)',
    'public/icon-192.svg' => 'Ikon 192 (SVG)',
    'sales/scm-tracker-manifest.json' => 'Manifest SCM Tracker',
];
foreach ($pwaFiles as $rel => $desc) {
    $path = $root . '/' . $rel;
    $exists = is_file($path);
    $add('pwa_files', $rel, $exists, $exists ? 'ok' : 'missing');
}

// ─── Manifest validation ───
$manifestPath = $root . '/public/manifest.json';
if (is_file($manifestPath)) {
    $raw = @file_get_contents($manifestPath);
    $json = $raw !== false ? json_decode($raw, true) : null;
    $add('manifest', 'valid_json', is_array($json), is_array($json) ? 'ok' : (json_last_error_msg() ?: 'parse error'));
    if (is_array($json)) {
        $add('manifest', 'has_name', !empty($json['name']), $json['name'] ?? '');
        $add('manifest', 'has_short_name', !empty($json['short_name']), $json['short_name'] ?? '');
        $add('manifest', 'has_icons', !empty($json['icons']) && is_array($json['icons']), count($json['icons'] ?? []));
        $add('manifest', 'has_display', isset($json['display']), $json['display'] ?? '');
        $add('manifest', 'has_theme_color', isset($json['theme_color']), $json['theme_color'] ?? '');
    }
} else {
    $add('manifest', 'valid_json', false, 'file missing');
}

// ─── SCM manifest validation ───
$scmManifestPath = $root . '/sales/scm-tracker-manifest.json';
if (is_file($scmManifestPath)) {
    $raw = @file_get_contents($scmManifestPath);
    $json = $raw !== false ? json_decode($raw, true) : null;
    $add('scm_manifest', 'valid_json', is_array($json), is_array($json) ? 'ok' : (json_last_error_msg() ?: 'parse error'));
    if (is_array($json)) {
        $add('scm_manifest', 'has_start_url', !empty($json['start_url']), $json['start_url'] ?? '');
    }
} else {
    $add('scm_manifest', 'valid_json', false, 'file missing');
}

// ─── Layout has manifest link ───
$layoutPath = $root . '/_shared/rmi_layout.php';
if (is_file($layoutPath)) {
    $layout = @file_get_contents($layoutPath);
    $hasManifest = $layout !== false && (str_contains($layout, 'rel="manifest"') || str_contains($layout, "rel='manifest'"));
    $hasThemeColor = $layout !== false && (str_contains($layout, 'theme-color') || str_contains($layout, 'theme_color'));
    $hasSw = $layout !== false && (str_contains($layout, 'serviceWorker') || str_contains($layout, 'sw.js'));
    $add('layout', 'manifest_link', $hasManifest, $hasManifest ? 'ok' : 'missing');
    $add('layout', 'theme_color_meta', $hasThemeColor, $hasThemeColor ? 'ok' : 'missing');
    $add('layout', 'sw_registration', $hasSw, $hasSw ? 'ok' : 'missing');
} else {
    $add('layout', 'manifest_link', false, 'rmi_layout.php not found');
}

// ─── Optional HTTP checks ───
$baseUrl = trim((string)(getenv('TOOLS_BASE_URL_INTERNAL') ?: getenv('SMOKE_BASE_URL') ?: getenv('APP_URL') ?: ''));
if ($baseUrl !== '' && function_exists('curl_init')) {
    $baseUrl = rtrim(preg_replace('#(https?://)/+#', '$1', $baseUrl), '/');
    $urls = [
        $baseUrl . '/public/manifest.json' => 'manifest_http',
        $baseUrl . '/public/sw.js' => 'sw_http',
        $baseUrl . '/public/offline.html' => 'offline_http',
    ];
    foreach ($urls as $url => $name) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_NOBODY => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ok = ($code === 200 && $body !== false);
        $add('http', $name, $ok, "code={$code}");
    }
}

$summary = [
    'generated_at' => gmdate('c'),
    'total' => count($results),
    'pass' => $passed,
    'fail' => $failed,
    'checks' => $results,
];

if ($writeLast) {
    @mkdir($root . '/storage/logs', 0775, true);
    $outFile = $root . '/storage/logs/smoke_pwa_last.json';
    @file_put_contents($outFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed > 0 ? 1 : 0);
