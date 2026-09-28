<?php
/**
 * Audit: semua kode permission yang dipakai page_registry vs katalog config/rbac_permissions.php.
 * Jalankan: php tools/rbac/audit_registry_catalog_gap.php (dari root proyek / NAS).
 */
declare(strict_types=1);

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$regPath = $root . '/config/page_registry.php';
$permPath = $root . '/config/rbac_permissions.php';

if (!is_file($regPath) || !is_file($permPath)) {
    fwrite(STDERR, "Missing config files.\n");
    exit(1);
}

/** @var array<string, list<array<string, mixed>>> $registry */
$registry = require $regPath;
/** @var list<array{0:string}> $permRows */
$permRows = require $permPath;

$catalog = [];
foreach ($permRows as $row) {
    if (isset($row[0]) && is_string($row[0])) {
        $catalog[$row[0]] = true;
    }
}

$used = [];

$collectStrings = static function (mixed $v) use (&$collectStrings, &$used): void {
    if (is_string($v) && $v !== '' && preg_match('/^[A-Z][A-Z0-9_]*\.[A-Z][A-Z0-9_]*$/', $v)) {
        $used[$v] = true;
    } elseif (is_array($v)) {
        foreach ($v as $item) {
            $collectStrings($item);
        }
    }
};

foreach ($registry as $section => $rows) {
    if (!is_array($rows)) {
        continue;
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (isset($row['perms']) && is_array($row['perms'])) {
            $collectStrings($row['perms']);
        }
        if (isset($row['route_any']) && is_array($row['route_any'])) {
            $collectStrings($row['route_any']);
        }
    }
}

$missing = [];
foreach (array_keys($used) as $code) {
    if (!isset($catalog[$code])) {
        $missing[] = $code;
    }
}
sort($missing);

echo "Registry permission codes (unique): " . count($used) . "\n";
echo "Catalog rows: " . count($permRows) . "\n";
if ($missing === []) {
    echo "OK: no gap — all registry codes exist in rbac_permissions.php\n";
    exit(0);
}

echo "GAP: " . count($missing) . " code(s) in page_registry but not in catalog:\n";
foreach ($missing as $m) {
    echo "  - {$m}\n";
}
exit(1);
