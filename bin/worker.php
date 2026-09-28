<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../app/Workers/ChatRetentionWorker.php';
require_once __DIR__ . '/../app/Services/SalesTrackingService.php';

$pdo = rmi_db_pdo();
erp_audit_ensure($pdo);
echo "[worker] started at " . date('c') . PHP_EOL;

while (true) {
    $pdo->beginTransaction();
    $st = $pdo->query(
        "SELECT * FROM jobs
         WHERE status = 'PENDING' AND run_at <= NOW()
         ORDER BY id ASC
         LIMIT 1
         FOR UPDATE"
    );
    $job = $st->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        $pdo->commit();
        sleep(2);
        continue;
    }
    $pdo->prepare("UPDATE jobs SET status='RUNNING', attempts=attempts+1 WHERE id=?")->execute([(int)$job['id']]);
    $pdo->commit();

    $ok = true;
    $err = null;
    try {
        // Minimal worker behavior; actual handlers can be added by job_type.
        switch ((string)$job['job_type']) {
            case 'bank_recon_auto_match':
                // not yet implemented (reserved)
                break;
            case 'gl_posting_batch':
                $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
                if (!is_array($payload)) {
                    throw new RuntimeException('Invalid payload_json.');
                }
                $module = (string)($payload['module'] ?? '');
                $event = (string)($payload['event'] ?? '');
                $sourceRef = (string)($payload['source_ref'] ?? '');
                $amount = (float)($payload['amount'] ?? 0);
                $desc = (string)($payload['description'] ?? ('Queued posting ' . $sourceRef));
                $uid = (int)($payload['user_id'] ?? 0);
                if ($module === '' || $event === '' || $sourceRef === '' || $amount <= 0) {
                    throw new RuntimeException('Missing required payload fields for gl_posting_batch.');
                }
                $svc = new \App\Accounting\GLPostingService();
                $headerId = $svc->createJournalFromMapping($pdo, $module, $event, $sourceRef, $amount, $desc, $uid);
                erp_audit($pdo, 'GL_WORKER', 'JOB#' . (int)$job['id'], 'POSTED', [
                    'header_id' => $headerId,
                    'module' => $module,
                    'event' => $event,
                    'source_ref' => $sourceRef,
                ]);
                break;
            case 'tax_export':
                // reserved for next phase
                break;
            case 'maintenance_cleanup':
                // Cleanup stale rate-limit windows
                $pdo->exec("DELETE FROM api_rate_limits WHERE reset_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
                // Expire bypass tickets automatically
                try {
                    require_once __DIR__ . '/../master/schema_mfa.php';
                    schema_ensure_auth_mfa_bypass_tickets($pdo);
                    $pdo->exec("UPDATE auth_mfa_bypass_tickets
                               SET is_active=0, status='EXPIRED', updated_at=NOW()
                               WHERE is_active=1 AND expires_at <= NOW() AND status='APPROVED'");
                } catch (Throwable $e) {
                    // ignore if status column not present on older schema
                    $pdo->exec("UPDATE auth_mfa_bypass_tickets
                               SET is_active=0, updated_at=NOW()
                               WHERE is_active=1 AND expires_at <= NOW()");
                }
                erp_audit($pdo, 'WORKER', 'MAINTENANCE', 'CLEANUP', []);
                break;
            case 'chat_retention':
                $ret = \App\Workers\ChatRetentionWorker::run($pdo, 500);
                $ret['actor_username'] = 'SYSTEM';
                erp_audit($pdo, 'CHAT', 'RETENTION', 'CHAT_RETENTION_PURGE', $ret);
                break;
            case 'sales_tracking_sync':
                $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
                if (!is_array($payload)) {
                    $payload = [];
                }
                $limit = max(1, min(500, (int)($payload['limit'] ?? 50)));
                $allowTransition = !empty($payload['allow_status_transition']);
                $svc = new \App\Services\SalesTrackingService();
                $ret = $svc->syncActive($pdo, $limit, $allowTransition);
                erp_audit($pdo, 'SCM_TRACKING', 'JOB#' . (int)$job['id'], 'SYNC_ACTIVE', [
                    'result' => $ret,
                    'allow_status_transition' => $allowTransition ? 1 : 0,
                ]);
                break;
            default:
                break;
        }
    } catch (Throwable $e) {
        $ok = false;
        $err = $e->getMessage();
    }

    if ($ok) {
        $pdo->prepare("UPDATE jobs SET status='DONE', updated_at=NOW() WHERE id=?")->execute([(int)$job['id']]);
        echo "[worker] job #" . (int)$job['id'] . " done" . PHP_EOL;
    } else {
        $pdo->prepare("UPDATE jobs SET status='FAILED', last_error=?, updated_at=NOW() WHERE id=?")
            ->execute([$err, (int)$job['id']]);
        echo "[worker] job #" . (int)$job['id'] . " failed: " . $err . PHP_EOL;
    }
}
