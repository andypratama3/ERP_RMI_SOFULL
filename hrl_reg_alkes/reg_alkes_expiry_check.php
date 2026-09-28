<?php
/**
 * reg_alkes_expiry_check.php
 * CLI/Cron: read-only scan, output JSON to storage/logs/reg_alkes_expiry_last.json
 * Web: optional UI (stub). NOTIFY_ENABLED=0 default.
 */
declare(strict_types=1);

$isCli = (php_sapi_name() === 'cli');

if ($isCli) {
    require_once __DIR__ . '/../_shared/bootstrap.php';
    require_once __DIR__ . '/../_shared/db.php';
} else {
    require_once __DIR__ . '/../_shared/assets.php';
    require_once __DIR__ . '/../master/auth.php';
    require_login();
    if (function_exists('require_any_permission')) {
        require_any_permission(['HRL.REG_ALKES_VIEW', 'HRL.VIEW', 'PQP.VIEW']);
    }
}

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : (function_exists('db_pdo') ? db_pdo() : null);
$requestId = 'req-' . date('YmdHis') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

$result = [
    'phase' => '03',
    'generated_at' => date('c'),
    'ok' => true,
    'counts' => ['total' => 0, 'expiring_30d' => 0, 'expiring_90d' => 0, 'expired' => 0],
    'top_expiring' => [],
    'request_id' => $requestId,
];

if (!$pdo) {
    $result['ok'] = false;
    $result['error'] = 'DB unavailable';
} else {
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases");
        $result['counts']['total'] = (int)$st->fetchColumn();

        // Expiry monitoring MUST use the NIE validity date, never revision_deadline.
        // revision_deadline belongs only to Stage 11 workflow/SLA.
        $cols = [];
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM hrl_reg_alkes_cases");
            while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[] = (string)($row['Field'] ?? '');
            }
        } catch (Throwable $ignore) {}

        $hasExpiry = in_array('nie_expiry_date', $cols, true);
        $hasIssue  = in_array('nie_issue_date', $cols, true);

        if ($hasExpiry && $hasIssue) {
            // Backward compatible: legacy screens stored "berlaku sampai" in nie_issue_date.
            // Prefer the canonical field; only use legacy issue-date value when it is today/future.
            $expiryExpr = "COALESCE(NULLIF(nie_expiry_date,'0000-00-00'), CASE WHEN nie_issue_date >= CURDATE() THEN nie_issue_date ELSE NULL END)";
            $sourceExpr = "CASE WHEN nie_expiry_date IS NOT NULL AND nie_expiry_date <> '0000-00-00' THEN 'nie_expiry_date' ELSE 'legacy_nie_issue_date' END";
        } elseif ($hasExpiry) {
            $expiryExpr = "NULLIF(nie_expiry_date,'0000-00-00')";
            $sourceExpr = "'nie_expiry_date'";
        } elseif ($hasIssue) {
            // Legacy schema fallback. This keeps old data usable until reg_alkes_case.php
            // adds/migrates the canonical nie_expiry_date column.
            $expiryExpr = "CASE WHEN nie_issue_date >= CURDATE() THEN nie_issue_date ELSE nie_issue_date END";
            $sourceExpr = "'legacy_nie_issue_date'";
        } else {
            throw new RuntimeException('NIE expiry date column is unavailable');
        }

        $baseWhere = "nie_no IS NOT NULL AND TRIM(nie_no) <> '' AND ({$expiryExpr}) IS NOT NULL";

        $st = $pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases
                           WHERE {$baseWhere}
                             AND ({$expiryExpr}) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
        $result['counts']['expiring_30d'] = (int)$st->fetchColumn();

        // 90-day bucket is 31..90 days so it does not double-count the 30-day bucket.
        $st = $pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases
                           WHERE {$baseWhere}
                             AND ({$expiryExpr}) > DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                             AND ({$expiryExpr}) <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)");
        $result['counts']['expiring_90d'] = (int)$st->fetchColumn();

        $st = $pdo->query("SELECT COUNT(*) FROM hrl_reg_alkes_cases
                           WHERE {$baseWhere}
                             AND ({$expiryExpr}) < CURDATE()");
        $result['counts']['expired'] = (int)$st->fetchColumn();

        // Show only licenses that actually need attention in the next 90 days.
        $st = $pdo->query("SELECT id, case_code, product_name, nie_no,
                                  ({$expiryExpr}) AS nie_expiry_date,
                                  {$sourceExpr} AS expiry_date_source
                           FROM hrl_reg_alkes_cases
                           WHERE {$baseWhere}
                             AND ({$expiryExpr}) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
                           ORDER BY ({$expiryExpr}) ASC, id ASC
                           LIMIT 15");
        $result['top_expiring'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $result['expiry_logic'] = [
            'canonical_field' => $hasExpiry ? 'nie_expiry_date' : null,
            'legacy_fallback' => $hasIssue ? 'nie_issue_date' : null,
            'revision_deadline_used' => false,
            'expiring_30d_bucket' => 'today..30 days',
            'expiring_90d_bucket' => '31..90 days',
        ];
    } catch (Throwable $e) {
        $result['ok'] = false;
        $result['error'] = 'Scan failed';
    }
}

$logDir = (function_exists('ts_storage_logs_dir') ? ts_storage_logs_dir() : (__DIR__ . '/../storage/logs'));
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$outPath = $logDir . '/reg_alkes_expiry_last.json';
@file_put_contents($outPath, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

if ($isCli) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit($result['ok'] ? 0 : 1);
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('Reg Alkes Expiry Check', ['active' => 'hrl_reg_alkes', 'breadcrumbs' => [['label' => 'HRL Reg Alkes', 'url' => 'index.php'], ['label' => 'Expiry Check', 'url' => '']]]);
?>
<div class="alert alert-info">Read-only scan. Output: [APP_ROOT]/storage/logs/reg_alkes_expiry_last.json</div>
<div class="card mb-3"><div class="card-body">
  <h6>Counts</h6>
  <p class="mb-0">Total: <?= (int)($result['counts']['total'] ?? 0) ?> | Expiring 30d: <?= (int)($result['counts']['expiring_30d'] ?? 0) ?> | Expiring 90d: <?= (int)($result['counts']['expiring_90d'] ?? 0) ?> | Expired: <?= (int)($result['counts']['expired'] ?? 0) ?></p>
</div></div>
<div class="card"><div class="card-body">
  <h6>Top 15 Expiring</h6>
  <pre class="small"><?= htmlspecialchars(json_encode($result['top_expiring'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
</div></div>
<?php rmi_footer(); ?>
