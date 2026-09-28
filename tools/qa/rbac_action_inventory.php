<?php
/**
 * rbac_action_inventory.php — Scan menu workflow doc + PHP files for POST actions.
 *
 * Output:
 *   storage/logs/rbac_action_inventory_last.json
 *   storage/logs/rbac_action_inventory_last.md
 *
 * Usage: php tools/qa/rbac_action_inventory.php [--write-last]
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
chdir($root);
require_once $root . '/tools/_shared/app_root_guard.php';
if (function_exists('tools_assert_expected_app_root')) {
    try {
        tools_assert_expected_app_root();
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(2);
    }
}

$args = $_SERVER['argv'] ?? [];
$writeLast = in_array('--write-last', $args, true);

$docPath = $root . '/docs/ERP_MENU_WORKFLOW_REFERENCE.md';
$doc = is_file($docPath) ? (string)file_get_contents($docPath) : '';

$paths = [];
if ($doc !== '') {
    if (preg_match_all('`/([a-zA-Z0-9_\-/]+\.php)`', $doc, $m)) {
        foreach ($m[1] as $rel) {
            $paths['/' . ltrim($rel, '/')] = true;
        }
    }
    if (preg_match_all('/\|\s*[^|\n]+\|\s*`?([a-z][a-z0-9_]*\.php)`?\s*\|/i', $doc, $m2)) {
        foreach ($m2[1] as $bn) {
            $candidates = ['master/' . $bn, $bn, 'sales/' . $bn, 'purchases/' . $bn, 'stock/' . $bn];
            foreach ($candidates as $c) {
                if (is_file($root . '/' . $c)) {
                    $paths['/' . $c] = true;
                    break;
                }
            }
        }
    }
}
$pathList = array_keys($paths);
sort($pathList);

$mutationHints = '/(approve|post|pay|void|delete|reject|submit|save|create|update|apply|cancel|export|reverse|lock|unlock)/i';

$inventory = [];

foreach ($pathList as $urlPath) {
    $file = $root . $urlPath;
    if (!is_file($file) || !is_readable($file)) {
        continue;
    }
    $src = (string)file_get_contents($file);
    $hasPost = (bool)preg_match('/\$_SERVER\s*\[\s*[\'"]REQUEST_METHOD[\'"]\s*\]\s*===?\s*[\'"]POST[\'"]/', $src)
        || (bool)preg_match('/\bREQUEST_METHOD\b[^;]*POST/', $src);
    if (!$hasPost && !preg_match('/\$_POST\s*\[/', $src) && !preg_match('/isset\s*\(\s*\$_POST/', $src)) {
        continue;
    }

    $actions = [];
    if (preg_match_all('/isset\s*\(\s*\$_POST\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]\s*\)/', $src, $am)) {
        foreach ($am[1] as $a) {
            $actions[$a] = true;
        }
    }
    if (preg_match_all('/\$_POST\[\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*\]/', $src, $am2)) {
        foreach ($am2[1] as $a) {
            if (!in_array(strtolower($a), ['csrf_token', 'submit', '_method'], true)) {
                $actions[$a] = true;
            }
        }
    }
    if (preg_match_all("/case\\s+['\"]([a-zA-Z0-9_]+)['\"]\\s*:/", $src, $am3)) {
        foreach ($am3[1] as $a) {
            $actions[$a] = true;
        }
    }

    $actionList = array_keys($actions);
    sort($actionList);

    foreach ($actionList as $act) {
        $mut = (bool)preg_match($mutationHints, $act);
        $policy = [];
        if ($mut && (stripos($file, 'payment') !== false || stripos($act, 'pay') !== false)) {
            $policy[] = 'FIN_CENTRAL_APPROVER (MgrFIN_BGR + SYS)';
        }
        $inventory[] = [
            'file' => $urlPath,
            'action' => $act,
            'mutation_inferred' => $mut,
            'required_permission' => '(see RBAC_ALL_MODULES_V1.md / page bootstrap)',
            'special_policies' => $policy,
        ];
    }

    if ($actionList === [] && $hasPost) {
        $inventory[] = [
            'file' => $urlPath,
            'action' => '(POST handler — no named action detected)',
            'mutation_inferred' => true,
            'required_permission' => '(see page bootstrap)',
            'special_policies' => [],
        ];
    }
}

$logsDir = $root . '/storage/logs';
if ($writeLast) {
    @mkdir($logsDir, 0775, true);
    file_put_contents($logsDir . '/rbac_action_inventory_last.json', json_encode([
        'run_at' => date(DateTimeInterface::ATOM),
        'source_doc' => 'docs/ERP_MENU_WORKFLOW_REFERENCE.md',
        'files_scanned' => count($pathList),
        'entries' => $inventory,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $md = "# RBAC Action Inventory\n\n";
    $md .= '**Generated:** ' . date('Y-m-d H:i:s T') . "\n\n";
    $byModule = [];
    foreach ($inventory as $row) {
        $parts = explode('/', trim((string)$row['file'], '/'));
        $mod = $parts[0] ?? 'root';
        $byModule[$mod][] = $row;
    }
    ksort($byModule);
    foreach ($byModule as $mod => $rows) {
        $md .= "## {$mod}\n\n";
        $md .= "| File | Action | Mutation? | Policies |\n";
        $md .= "|------|--------|-----------|----------|\n";
        foreach ($rows as $r) {
            $pol = implode(', ', (array)($r['special_policies'] ?? []));
            $mut = ($r['mutation_inferred'] ?? false) ? 'yes' : 'no';
            $md .= '| `' . $r['file'] . '` | `' . $r['action'] . '` | ' . $mut . ' | ' . ($pol !== '' ? $pol : '—') . " |\n";
        }
        $md .= "\n";
    }
    file_put_contents($logsDir . '/rbac_action_inventory_last.md', $md);
}

echo json_encode(['ok' => true, 'entries' => count($inventory)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
