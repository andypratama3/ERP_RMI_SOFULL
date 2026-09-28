<?php
/**
 * SYS-only web trigger for INSIGHT REAL (same outputs as CLI).
 * POST dengan CSRF. Menjalankan logika read-only + audit INSIGHT_REAL_RUN.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/insight_real_lib.php';

tools_require_access('qa/insight_real_web.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$logsDir = ts_storage_logs_dir();
$ran = false;
$error = '';
$paths = [
    'json' => $logsDir . '/insight_real_last.json',
    'md' => $logsDir . '/insight_real_last.md',
    'csv' => $logsDir . '/insight_real_do_backlog.csv',
    'qlog' => $logsDir . '/insight_real_query_log.md',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf($_POST['csrf_token'] ?? null);
    }
    $ran = true;
    try {
        $pdo = rmi_db_pdo();
        $actor = function_exists('auth_username') ? auth_username() : 'web';
        $requestId = bin2hex(random_bytes(8));
        $payload = irlib_run($pdo, [
            'logs_dir' => $logsDir,
            'actor' => $actor,
            'write_audit' => true,
            'request_id' => $requestId,
        ]);
        $qEntries = $payload['_query_log_entries'] ?? [];
        unset($payload['_query_log_entries']);
        $payload['sales_do']['o2c_mapping_reference'] = 'sales/sales_dashboard.php $STATUS_TO_STAGE';
        file_put_contents($paths['json'], json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

        $qlog = new InsightRealQueryLog();
        foreach ($qEntries as $e) {
            $qlog->log($e['sql'] ?? '', $e['params'] ?? [], (string)($e['note'] ?? ''));
        }
        $qlog->writeMarkdown($paths['qlog'], 'INSIGHT REAL — Query log');

        file_put_contents($paths['md'], ir_insight_real_render_md($payload, 'yes (web run)') . "\n");

        $fh = fopen($paths['csv'], 'wb');
        if ($fh !== false) {
            fputcsv($fh, ['doc_code', 'office', 'status', 'age_days']);
            foreach (($payload['sales_do']['backlog_stuck_gt3d'] ?? []) as $row) {
                fputcsv($fh, [
                    (string)($row['doc_code'] ?? ''),
                    (string)($row['office_code'] ?? ''),
                    (string)($row['status'] ?? ''),
                    (string)($row['age_days'] ?? ''),
                ]);
            }
            fclose($fh);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

header('Content-Type: text/html; charset=utf-8');
$csrf = function_exists('csrf_token') ? csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>INSIGHT REAL</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 720px; margin: 24px auto; padding: 0 16px; }
    code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
    .ok { color: #059669; }
    .err { color: #b91c1c; }
  </style>
</head>
<body>
  <h1>INSIGHT REAL</h1>
  <p>SYS-only. Menulis artefak di <code>storage/logs/</code> + audit <code>INSIGHT_REAL_RUN</code>.</p>
  <?php if ($error !== ''): ?>
    <p class="err">Error: <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
  <?php elseif ($ran): ?>
    <p class="ok">Selesai. File:</p>
    <ul>
      <?php foreach ($paths as $k => $p): ?>
        <li><strong><?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>:</strong> <code><?= htmlspecialchars($p, ENT_QUOTES, 'UTF-8') ?></code></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">Jalankan INSIGHT REAL</button>
  </form>
  <p><a href="../index.php">← Tools</a></p>
</body>
</html>
