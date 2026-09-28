<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$docsPath = $root . '/docs/ERP_MENU_WORKFLOW_REFERENCE.md';
$navPath = $root . '/_shared/nav_config.php';
$outPath = $root . '/storage/logs/rbac_pages_inventory.json';

@mkdir(dirname($outPath), 0775, true);

$rows = [];
$seen = [];

$add = static function (string $source, string $url, string $fileHint = '') use (&$rows, &$seen, $root): void {
    $url = trim($url);
    if ($url === '' || $url[0] !== '/') {
        return;
    }
    $path = $root . $url;
    $exists = is_file($path);
    $key = $url . '|' . $fileHint;
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $rows[] = [
        'source' => $source,
        'url' => $url,
        'resolved_file' => $exists ? str_replace($root . '/', '', $path) : null,
        'exists' => $exists,
        'file_hint' => $fileHint !== '' ? $fileHint : null,
    ];
};

// 1) Parse nav_config.php
if (is_file($navPath)) {
    $cfg = require $navPath;
    if (is_array($cfg)) {
        foreach ($cfg as $variant => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                if (!empty($it['url']) && is_string($it['url'])) {
                    $url = str_replace('{base}', '', $it['url']);
                    $url = preg_replace('#//+#', '/', $url ?? '');
                    $add('nav_config:' . $variant, (string)$url, (string)($it['key'] ?? ''));
                }
            }
        }
    }
}

// 2) Parse doc URLs and file names
if (is_file($docsPath)) {
    $content = (string)file_get_contents($docsPath);
    if ($content !== '') {
        if (preg_match_all('#`(/[^`]+\.php)`#', $content, $m)) {
            foreach ($m[1] as $url) {
                $add('docs:url', (string)$url);
            }
        }
        if (preg_match_all('#\|\s*([A-Za-z0-9_/\-]+\.php)\s*\|#', $content, $m2)) {
            foreach ($m2[1] as $f) {
                $f = trim((string)$f);
                $cands = [
                    '/master/' . $f, '/sales/' . $f, '/purchases/' . $f, '/stock/' . $f, '/hrl/' . $f,
                    '/hrl_process/' . $f, '/hrl_reg_alkes/' . $f, '/absensi/' . $f, '/absensi/admin/' . $f,
                    '/kpi/' . $f, '/payroll/' . $f, '/mpr/' . $f, '/Fixed_Asset/' . $f, '/dashboards/' . $f,
                ];
                foreach ($cands as $u) {
                    if (is_file($root . $u)) {
                        $add('docs:file', $u, $f);
                        break;
                    }
                }
            }
        }
    }
}

usort($rows, static fn(array $a, array $b): int => strcmp((string)$a['url'], (string)$b['url']));
$missing = array_values(array_filter($rows, static fn(array $r): bool => empty($r['exists'])));

$payload = [
    'generated_at' => date(DateTimeInterface::ATOM),
    'app_root' => $root,
    'total' => count($rows),
    'missing_count' => count($missing),
    'items' => $rows,
    'missing' => $missing,
];

file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo json_encode(['ok' => true, 'out' => 'storage/logs/rbac_pages_inventory.json', 'total' => count($rows), 'missing' => count($missing)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
