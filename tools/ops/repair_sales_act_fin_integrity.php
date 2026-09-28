<?php
/**
 * repair_sales_act_fin_integrity.php — Minimal-risk repair for ACT/FIN flow violations.
 * Default: DRY-RUN. Apply requires: --apply --i-understand=REPAIR_SALES_ACT_FIN_INTEGRITY
 * Output: storage/logs/repair_sales_act_fin_integrity_last.json
 * Rule: Clear fin_paid_at (and related) when inconsistent; do NOT force status to PAID.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
require_once $root . '/tools/tools_state_lib.php';
require_once $root . '/tools/tools_ui_helpers.php';
require_once $root . '/_shared/db.php';

$args = $_SERVER['argv'] ?? [];
$apply = false;
$iUnderstand = '';
foreach ($args as $a) {
    if (is_string($a) && str_starts_with($a, '--i-understand=')) {
        $iUnderstand = trim(substr($a, 15));
    }
}
$apply = in_array('--apply', $args, true) && $iUnderstand === 'REPAIR_SALES_ACT_FIN_INTEGRITY';
$writeLast = in_array('--write-last', $args, true);

$requestId = date('Ymd_His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
$logs = ts_storage_logs_dir();

function has_col(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $st->execute([$table, $col]);
    return (int)$st->fetchColumn() > 0;
}

/**
 * Candidates: paid/closed/fin_done/paid_done with fin_paid_at set but inconsistent
 * (fin_paid_at < act_ready_fin_at or act_ready_fin_at IS NULL).
 * Minimal-risk: clear fin_paid_at (rollback paid marker).
 */
$candidates = [];
$applied = [];

try {
    $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $hasFinPaidAt = has_col($pdo, 'sales_do', 'fin_paid_at');
    if (!$hasFinPaidAt) {
        $payload = [
            'state_version' => 1,
            'run_at' => date(DateTimeInterface::ATOM),
            'request_id' => $requestId,
            'overall_ok' => true,
            'candidates' => [],
            'applied_count' => 0,
            'message' => 'fin_paid_at column not present',
        ];
        if ($writeLast) {
            ts_write_json($logs . '/repair_sales_act_fin_integrity_last.json', $payload);
        }
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $sql = "
        SELECT id, do_code, status, scm_delivered_at, act_ready_fin_at, fin_paid_at
        FROM sales_do
        WHERE LOWER(TRIM(COALESCE(status,''))) IN ('paid','closed','fin_done','paid_done')
          AND fin_paid_at IS NOT NULL
          AND (act_ready_fin_at IS NULL OR fin_paid_at < act_ready_fin_at)
    ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $r) {
        $candidates[] = [
            'id' => (int)$r['id'],
            'do_code' => (string)($r['do_code'] ?? ''),
            'status' => (string)($r['status'] ?? ''),
            'fin_paid_at' => $r['fin_paid_at'] ?? null,
            'act_ready_fin_at' => $r['act_ready_fin_at'] ?? null,
        ];
    }

    if ($apply && count($candidates) > 0) {
        $pdo->beginTransaction();
        try {
            foreach ($candidates as $c) {
                $id = (int)$c['id'];
                $doCode = $c['do_code'];
                $before = ['fin_paid_at' => $c['fin_paid_at']];
                $set = ['fin_paid_at' => null];
                if (has_col($pdo, 'sales_do', 'fin_paid_date')) {
                    $set['fin_paid_date'] = null;
                }
                $cols = implode(', ', array_map(static fn($k) => "`$k`=?", array_keys($set)));
                $st = $pdo->prepare("UPDATE sales_do SET $cols WHERE id=?");
                $st->execute([...array_values($set), $id]);
                if ($st->rowCount() > 0) {
                    $applied[] = [
                        'id' => $id,
                        'do_code' => $doCode,
                        'before' => $before,
                        'after' => array_combine(array_keys($set), array_fill(0, count($set), null)),
                    ];
                    if (!function_exists('audit_event') && is_file($root . '/_shared/erp_audit.php')) {
                        require_once $root . '/_shared/erp_audit.php';
                    }
                    if (function_exists('audit_event')) {
                        try {
                            audit_event($pdo, 'OPS_DATA_REPAIR', 'SALES_DO', 'repair_act_fin', (string)$id, 'Cleared inconsistent fin_paid_at', [
                                'before' => $before,
                                'after' => array_combine(array_keys($set), array_fill(0, count($set), null)),
                                'request_id' => $requestId,
                            ]);
                        } catch (Throwable $auditEx) {
                            // non-fatal
                        }
                    }
                    $maqPath = $root . '/storage/logs/manual_action_queue_last.json';
                    if (is_file($maqPath)) {
                        $maq = json_decode((string)file_get_contents($maqPath), true) ?: [];
                        $rows = (array)($maq['rows'] ?? $maq['items'] ?? []);
                        $rows[] = [
                            'id' => 'repair_act_fin_' . $id . '_' . $requestId,
                            'created_at' => date(DateTimeInterface::ATOM),
                            'severity' => 'P2',
                            'title' => "Verify Sales/DO integrity repair: $doCode (paid flags cleared).",
                            'owner' => 'OPS',
                            'suggested_action' => 'Verify DO ' . $doCode . ' status and re-apply payment if valid.',
                            'status' => 'OPEN',
                            'pic' => '',
                        ];
                        $maq['rows'] = $rows;
                        $maq['generated_at'] = date(DateTimeInterface::ATOM);
                        @file_put_contents($maqPath, json_encode($maq, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'request_id' => $requestId,
        'overall_ok' => true,
        'dry_run' => !$apply,
        'candidates' => $candidates,
        'applied_count' => count($applied),
        'applied' => $applied,
    ];
    if ($writeLast) {
        ts_write_json($logs . '/repair_sales_act_fin_integrity_last.json', $payload);
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $payload = [
        'state_version' => 1,
        'run_at' => date(DateTimeInterface::ATOM),
        'request_id' => $requestId,
        'overall_ok' => false,
        'error' => tools_mask_sensitive($e->getMessage()),
        'candidates' => $candidates,
        'applied_count' => count($applied),
    ];
    if ($writeLast) {
        ts_write_json($logs . '/repair_sales_act_fin_integrity_last.json', $payload);
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
