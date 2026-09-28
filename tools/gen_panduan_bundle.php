<?php
declare(strict_types=1);
/**
 * Generate: panduan_*.php wrappers, stub panduan_*.md, config/page_registry_panduan_generated.php
 *
 * Run from project root: php tools/gen_panduan_bundle.php
 */
$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

$mapPath = $root . '/docs/help_sop_map.json';
$raw = json_decode((string)file_get_contents($mapPath), true);
if (!is_array($raw)) {
    fwrite(STDERR, "Invalid JSON: $mapPath\n");
    exit(1);
}

/** @var list<string> $paths */
$paths = [];
foreach ($raw['map'] ?? [] as $e) {
    if (!empty($e['path']) && is_string($e['path']) && str_ends_with($e['path'], '.php')) {
        $paths[] = trim($e['path'], '/');
    }
}
$paths = array_values(array_unique($paths));
sort($paths, SORT_STRING);

/** @var array<string, array<string,mixed>> $titles */
$titles = [];
foreach ($raw['map'] ?? [] as $e) {
    if (!empty($e['path']) && !empty($e['title'])) {
        $titles[trim((string)$e['path'], '/')] = (string)$e['title'];
    }
}

$registry = require $root . '/config/page_registry.php';
if (!function_exists('_p')) {
    fwrite(STDERR, "_p() missing\n");
    exit(1);
}

/**
 * @param array<string, list<array<string,mixed>>> $reg
 */
function panduan_find_row(array $reg, string $url): ?array
{
    foreach ($reg as $pages) {
        if (!is_array($pages)) {
            continue;
        }
        foreach ($pages as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['url'] ?? '') === $url) {
                return $row;
            }
        }
    }
    return null;
}

/**
 * @return list<string>
 */
function panduan_route_any_codes(?array $row): array
{
    $codes = [];
    if ($row !== null) {
        $routeAny = $row['route_any'] ?? null;
        if (is_array($routeAny) && $routeAny !== []) {
            foreach ($routeAny as $c) {
                $c = strtoupper(trim((string)$c));
                if ($c !== '') {
                    $codes[] = $c;
                }
            }
        } else {
            $perms = $row['perms'] ?? [];
            if (is_array($perms)) {
                foreach (['access', 'view'] as $k) {
                    $v = $perms[$k] ?? null;
                    if ($v !== null && $v !== '') {
                        $codes[] = strtoupper(trim((string)$v));
                    }
                }
            }
        }
    }
    $codes[] = 'DOCS.PANDUAN_VIEW';
    $codes[] = 'DOCS.VIEW';
    return array_values(array_unique(array_filter($codes)));
}

function panduan_export_php_array(array $codes): string
{
    $out = '[';
    foreach ($codes as $i => $c) {
        $out .= ($i > 0 ? ', ' : '') . var_export($c, true);
    }
    return $out . ']';
}

$registryRows = [];
$nPhp = 0;
$nMd = 0;

foreach ($paths as $erpRel) {
    $stem = substr($erpRel, 0, -4);
    $dir = dirname($stem);
    $base = basename($stem);
    $panduanPhp = ($dir === '.' ? '' : $dir . '/') . 'panduan_' . $base . '.php';
    // Satu halaman kanonik — hindari panduan_index / panduan_dashboard_center / panduan.php ganda di registry
    if ($erpRel === 'dashboards/index.php' || $erpRel === 'dashboards/dashboard_center.php') {
        $panduanPhp = 'dashboards/panduan.php';
    }
    $panduanMd = 'docs/panduan/' . ($dir === '.' ? '' : $dir . '/') . 'panduan_' . $base . '.md';

    $d = dirname($erpRel);
    $upN = ($d === '.' || $d === '') ? 1 : (substr_count($d, '/') + 1);
    $up = str_repeat('../', $upN);

    $phpContent = <<<PHP
<?php
declare(strict_types=1);
/**
 * Panduan halaman — di-generate tools/gen_panduan_bundle.php
 * Halaman terkait: /{$erpRel}
 */
require_once __DIR__ . '/{$up}_shared/rmi_panduan_helper.php';
rmi_panduan_run_page(__FILE__);

PHP;

    $fullPhp = $root . '/' . $panduanPhp;
    $dirFs = dirname($fullPhp);
    if (!is_dir($dirFs)) {
        mkdir($dirFs, 0755, true);
    }
    // Timpa hanya stub generator; file kustom (tanpa rmi_panduan_run_page(__FILE__)) tidak ditimpa
    $existing = is_file($fullPhp) ? (string) file_get_contents($fullPhp) : '';
    $isStub = ($existing === '') || str_contains($existing, 'rmi_panduan_run_page(__FILE__)');
    if ($isStub) {
        file_put_contents($fullPhp, $phpContent);
        $nPhp++;
    }

    $fullMd = $root . '/' . $panduanMd;
    $mdDir = dirname($fullMd);
    if (!is_dir($mdDir)) {
        mkdir($mdDir, 0755, true);
    }
    if (!is_file($fullMd)) {
        $title = $titles[$erpRel] ?? $base;
        file_put_contents($fullMd, "# Panduan: {$title}\n\nHalaman ERP: `{$erpRel}`\n\n*(Isi panduan operasional — SYS)*\n");
        $nMd++;
    }

    $parentRow = panduan_find_row($registry, $erpRel);
    $ra = panduan_route_any_codes($parentRow);
    if ($parentRow !== null && isset($parentRow['perms']) && is_array($parentRow['perms'])) {
        $perms = $parentRow['perms'];
    } else {
        $perms = _p(['access' => 'DOCS.VIEW', 'view' => 'DOCS.VIEW']);
    }
    // Hanya satu baris registry untuk dashboards/panduan.php (dashboard_center map ke file yang sama)
    if ($erpRel === 'dashboards/dashboard_center.php') {
        continue;
    }

    $label = 'Panduan — ' . ($titles[$erpRel] ?? str_replace('_', ' ', $base));
    $registryRows[] = [
        'label' => $label,
        'url' => $panduanPhp,
        'route_any' => $ra,
        'perms' => $perms,
    ];
}

// Sort rows by url for stable diff
usort($registryRows, static fn ($a, $b) => strcmp((string)$a['url'], (string)$b['url']));

$genPath = $root . '/config/page_registry_panduan_generated.php';
$buf = "<?php\ndeclare(strict_types=1);\n/**\n * Auto-generated — jangan edit manual. Regenerate: php tools/gen_panduan_bundle.php\n */\nreturn [\n";
foreach ($registryRows as $row) {
    $buf .= "    ['label'=>" . var_export((string)$row['label'], true)
        . ",'url'=>" . var_export((string)$row['url'], true)
        . ",'route_any'=>" . panduan_export_php_array($row['route_any'])
        . ",'perms'=>_p(" . var_export($row['perms'], true) . ")],\n";
}
$buf .= "];\n";
file_put_contents($genPath, $buf);

echo "OK: {$nPhp} PHP wrappers, {$nMd} new MD stubs, registry rows: " . count($registryRows) . " → {$genPath}\n";
