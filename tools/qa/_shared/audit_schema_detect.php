<?php
/**
 * audit_schema_detect.php — Scan PHP pages → detect tables/columns.
 * NO assumptions: if seed file missing => FAIL + write assumptions_log.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$SEED_FILES = [
    'stock/wqs_pr.php',
    'purchases/purchases_po.php',
    'stock/wqs_incoming.php',
    'purchases/purchases_invoice_ap.php',
    'purchases/purchases_payment_ap.php',
    'sales/sales_do.php',
    'stock/wqs_stock_adjustment.php',
    'stock/wqs_stock_opname.php',
];

function audit_schema_extract_tables(string $content): array {
    $tables = [];
    $patterns = [
        '/\bFROM\s+[`]?(\w+)[`]?\s/i',
        '/\bJOIN\s+[`]?(\w+)[`]?\s/i',
        '/\bUPDATE\s+[`]?(\w+)[`]?\s/i',
        '/\bINSERT\s+INTO\s+[`]?(\w+)[`]?\s/i',
        '/\bDELETE\s+FROM\s+[`]?(\w+)[`]?\s/i',
        '/\$table\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]/',
    ];
    foreach ($patterns as $p) {
        if (preg_match_all($p, $content, $m)) {
            foreach ($m[1] as $t) {
                $t = trim($t);
                if ($t !== '' && !in_array($t, $tables, true)) {
                    $tables[] = $t;
                }
            }
        }
    }
    return array_values(array_unique($tables));
}

function audit_schema_validate_tables(PDO $pdo, array $tables): array {
    $valid = [];
    $st = $pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    foreach ($tables as $t) {
        $st->execute([$t]);
        if ($st->fetch()) {
            $valid[] = $t;
        }
    }
    return $valid;
}

function audit_schema_get_columns(PDO $pdo, string $table): array {
    $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position");
    $st->execute([$table]);
    return array_column($st->fetchAll(PDO::FETCH_ASSOC), 'column_name');
}

/**
 * @return array{ok:bool, modules:array, assumptions:array, errors:array}
 */
function audit_schema_detect(string $root, PDO $pdo, array $seedFiles): array {
    $modules = [];
    $assumptions = [];
    $errors = [];

    foreach ($seedFiles as $rel) {
        $path = $root . '/' . $rel;
        if (!is_file($path)) {
            $errors[] = "Seed file missing: {$rel}";
            $assumptions[] = "MISSING_SEED: {$rel}";
            continue;
        }
        $content = (string)@file_get_contents($path);
        $tables = audit_schema_extract_tables($content);
        $validTables = audit_schema_validate_tables($pdo, $tables);
        $columns = [];
        foreach ($validTables as $t) {
            $columns[$t] = audit_schema_get_columns($pdo, $t);
        }
        $modules[$rel] = [
            'tables' => $validTables,
            'columns' => $columns,
        ];
    }

    $ok = count($errors) === 0;
    return [
        'ok' => $ok,
        'modules' => $modules,
        'assumptions' => $assumptions,
        'errors' => $errors,
    ];
}
