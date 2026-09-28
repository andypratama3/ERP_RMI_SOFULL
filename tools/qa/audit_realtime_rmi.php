<?php
/**
 * audit_realtime_rmi.php — Read-only audit real data & anomalies.
 * Output: JSON + markdown ke storage/logs atau --output-dir.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/_shared/env.php';
if (function_exists('rmi_env_load')) {
    rmi_env_load();
}
require_once $root . '/_shared/db.php';

$outputDir = $root . '/storage/logs';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (is_string($arg) && str_starts_with($arg, '--output-dir=')) {
        $outputDir = rtrim(substr($arg, 13), '/');
        break;
    }
}
@mkdir($outputDir, 0775, true);

$pdo = null;
try {
    $pdo = rmi_db_pdo();
} catch (Throwable $e) {
    $result = ['ok' => false, 'error' => 'db_unavailable', 'generated_at' => date('c')];
    file_put_contents($outputDir . '/audit_realtime_last.json', json_encode($result, JSON_PRETTY_PRINT));
    fwrite(STDERR, "DB unavailable\n");
    exit(1);
}

$thirtyDays = date('Y-m-d', strtotime('-30 days'));
$sevenDays = date('Y-m-d', strtotime('-7 days'));
$twentyFourHours = date('Y-m-d H:i:s', strtotime('-24 hours'));

$anomalies = [];
$aggregates = [];

// (a) Dokumen tanpa office_code
$tablesOffice = ['wqs_pr', 'purchases_po', 'sales_do', 'wqs_incoming', 'purchases_invoice_ap'];
foreach ($tablesOffice as $t) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $st->execute([$t]);
        if ((int)$st->fetchColumn() === 0) continue;
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$t}` WHERE created_at >= ? AND (office_code IS NULL OR TRIM(COALESCE(office_code,'')) = '')");
        $st->execute([$thirtyDays]);
        $cnt = (int)$st->fetchColumn();
        $aggregates["{$t}_missing_office_30d"] = $cnt;
        if ($cnt > 0) {
            $anomalies[] = ['code' => 'MISSING_OFFICE', 'table' => $t, 'count' => $cnt];
        }
    } catch (Throwable $e) {
        $aggregates["{$t}_error"] = $e->getMessage();
    }
}

// (b) GR posted tanpa media minimum — skip if no rule/column
// (c) DO flow_status invalid
$validFlowStatus = ['DRAFT', 'crm_to_wqs', 'sent_wqs', 'wqs_processing', 'ready_scm', 'wqs_done', 'on_delivery', 'delivered', 'scm_done', 'wait_payment', 'act_done', 'paid', 'fin_done', 'closed', 'cancelled'];
try {
    $st = $pdo->prepare("SELECT flow_status, COUNT(*) as cnt FROM sales_do WHERE do_date >= ? GROUP BY flow_status");
    $st->execute([$thirtyDays]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $fs = trim((string)($r['flow_status'] ?? ''));
        if ($fs !== '' && !in_array($fs, $validFlowStatus, true)) {
            $anomalies[] = ['code' => 'INVALID_FLOW_STATUS', 'flow_status' => $fs, 'count' => (int)$r['cnt']];
        }
    }
    $aggregates['sales_do_by_flow_status_30d'] = $rows;
} catch (Throwable $e) {
    $aggregates['sales_do_error'] = $e->getMessage();
}

// (d) Orphan link PR→PO
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_po po LEFT JOIN wqs_pr pr ON pr.id = po.pr_id WHERE po.deleted_at IS NULL AND po.created_at >= ? AND pr.id IS NULL");
    $st->execute([$thirtyDays]);
    $orphan = (int)($st->fetchColumn() ?: 0);
    $aggregates['po_orphan_pr_30d'] = $orphan;
    if ($orphan > 0) {
        $anomalies[] = ['code' => 'ORPHAN_PO_PR', 'count' => $orphan];
    }
} catch (Throwable $e) {
    $aggregates['orphan_error'] = $e->getMessage();
}

// (e) Negative stock
try {
    $st = $pdo->query("SELECT COUNT(*) FROM wqs_stock_by_office WHERE stock_qty < 0");
    $neg = (int)($st->fetchColumn() ?: 0);
    $aggregates['negative_stock_count'] = $neg;
    if ($neg > 0) {
        $anomalies[] = ['code' => 'NEGATIVE_STOCK', 'count' => $neg];
    }
} catch (Throwable $e) {
    $aggregates['negative_stock_error'] = $e->getMessage();
}

// (f) Recent activity 24h & 7d
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE created_at >= ?");
    $st->execute([$twentyFourHours]);
    $aggregates['sales_do_24h'] = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM sales_do WHERE created_at >= ?");
    $st->execute([$sevenDays]);
    $aggregates['sales_do_7d'] = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases_po WHERE deleted_at IS NULL AND created_at >= ?");
    $st->execute([$twentyFourHours]);
    $aggregates['purchases_po_24h'] = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $aggregates['recent_activity_error'] = $e->getMessage();
}

// (g) /Volumes path leakage in artifacts
$volumesLeak = false;
$logsDir = $root . '/storage/logs';
$scanFiles = glob($logsDir . '/*.json') ?: [];
foreach (array_slice($scanFiles, 0, 50) as $fp) {
    $raw = @file_get_contents($fp);
    if ($raw !== false && str_contains($raw, '/Volumes/')) {
        $volumesLeak = true;
        $anomalies[] = ['code' => 'VOLUMES_PATH_LEAK', 'file' => basename($fp), 'message' => 'CRITICAL: /Volumes/ in artifact'];
        break;
    }
}
$aggregates['volumes_leak_scan'] = $volumesLeak ? 'FAIL' : 'OK';

// (h) Stock docs missing depo_code (where applicable)
$depoTables = ['wqs_incoming' => 'received_date', 'wqs_stock_transfer' => 'created_at', 'wqs_stock_adjustment' => 'created_at'];
foreach ($depoTables as $tbl => $dateCol) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = 'depo_code'");
        $st->execute([$tbl]);
        if ((int)$st->fetchColumn() === 0) continue;
        $st = $pdo->prepare("SELECT COUNT(*) FROM `{$tbl}` WHERE {$dateCol} >= ? AND (depo_code IS NULL OR TRIM(COALESCE(depo_code,'')) = '')");
        $st->execute([$thirtyDays]);
        $cnt = (int)$st->fetchColumn();
        $aggregates["{$tbl}_missing_depo_30d"] = $cnt;
        if ($cnt > 0) {
            $anomalies[] = ['code' => 'MISSING_DEPO', 'table' => $tbl, 'count' => $cnt];
        }
    } catch (Throwable $e) {
        $aggregates["{$tbl}_depo_error"] = 'skip';
    }
}

$result = [
    'ok' => empty($anomalies),
    'generated_at' => date('c'),
    'aggregates' => $aggregates,
    'anomalies' => $anomalies,
];

$jsonPath = $outputDir . '/audit_realtime_last.json';
$jsonContent = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($jsonPath, $jsonContent);
file_put_contents($outputDir . '/audit_realtime_rmi.json', $jsonContent);

$md = "# Audit Realtime RMI\n\n";
$md .= "**Generated:** " . date('c') . "\n\n";
$md .= "## Aggregates\n\n";
foreach ($aggregates as $k => $v) {
    if (is_array($v)) {
        $md .= "- **{$k}:** " . json_encode($v, JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        $md .= "- **{$k}:** {$v}\n";
    }
}
$md .= "\n## Anomalies\n\n";
if (empty($anomalies)) {
    $md .= "Tidak ada.\n";
} else {
    foreach ($anomalies as $a) {
        $md .= "- **{$a['code']}** " . json_encode($a, JSON_UNESCAPED_SLASHES) . "\n";
    }
}

file_put_contents($outputDir . '/audit_realtime_last.md', $md);

echo "OK: audit_realtime written to {$jsonPath}\n";
exit(empty($anomalies) ? 0 : 1);
