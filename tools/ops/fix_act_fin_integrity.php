<?php
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_access_helpers.php';
if (PHP_SAPI !== 'cli') {
    tools_require_access('ops/fix_act_fin_integrity.php');
    require_login();
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

/**
 * One-time/backfill fixer for ACT/FIN flow integrity in sales_do.
 * Ensures timestamp order:
 *   scm_delivered_at <= act_ready_fin_at <= fin_paid_at (for paid/fin_done statuses).
 */

$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$rows = $pdo->query("
    SELECT id, status, do_date, created_at, last_updated_at, scm_delivered_at, act_ready_fin_at, fin_paid_at
    FROM sales_do
    WHERE LOWER(TRIM(COALESCE(status,''))) IN ('wait_payment','paid','closed','fin_done','paid_done')
")->fetchAll(PDO::FETCH_ASSOC);

$updated = 0;

$pdo->beginTransaction();
try {
    $up = $pdo->prepare("
        UPDATE sales_do
        SET scm_delivered_at = ?,
            act_ready_fin_at = ?,
            fin_paid_at = ?
        WHERE id = ?
    ");

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $status = strtolower(trim((string)$r['status']));

        $base = (string)($r['last_updated_at'] ?? '');
        if ($base === '') {
            $base = (string)($r['created_at'] ?? '');
        }
        if ($base === '') {
            $d = trim((string)($r['do_date'] ?? ''));
            $base = $d !== '' ? ($d . ' 09:00:00') : date('Y-m-d H:i:s');
        }
        $baseTs = strtotime($base) ?: time();

        $scm = trim((string)($r['scm_delivered_at'] ?? ''));
        $act = trim((string)($r['act_ready_fin_at'] ?? ''));
        $fin = trim((string)($r['fin_paid_at'] ?? ''));

        $scmTs = $scm !== '' ? (strtotime($scm) ?: $baseTs) : $baseTs;
        $actTs = $act !== '' ? (strtotime($act) ?: ($scmTs + 300)) : ($scmTs + 300);
        if ($actTs < $scmTs) {
            $actTs = $scmTs + 300;
        }

        $needsFin = in_array($status, ['paid', 'closed', 'fin_done', 'paid_done'], true);
        $finTs = null;
        if ($needsFin) {
            $finTs = $fin !== '' ? (strtotime($fin) ?: ($actTs + 300)) : ($actTs + 300);
            if ($finTs < $actTs) {
                $finTs = $actTs + 300;
            }
        } else {
            $finTs = $fin !== '' ? (strtotime($fin) ?: null) : null;
        }

        $newScm = date('Y-m-d H:i:s', $scmTs);
        $newAct = date('Y-m-d H:i:s', $actTs);
        $newFin = $finTs !== null ? date('Y-m-d H:i:s', $finTs) : null;

        $oldScm = $scm !== '' ? $scm : null;
        $oldAct = $act !== '' ? $act : null;
        $oldFin = $fin !== '' ? $fin : null;

        if ($oldScm !== $newScm || $oldAct !== $newAct || $oldFin !== $newFin) {
            $up->execute([$newScm, $newAct, $newFin, $id]);
            $updated++;
        }
    }

    $pdo->commit();
    echo json_encode([
        'ok' => true,
        'updated_rows' => $updated,
        'scanned_rows' => count($rows),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}
