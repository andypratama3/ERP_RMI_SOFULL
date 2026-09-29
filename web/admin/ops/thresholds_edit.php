<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/../../../_shared/rbac.php';
require_once __DIR__ . '/../../../_shared/erp_audit.php';
require_once __DIR__ . '/../../../tools/ops/_lib/ops_thresholds_lib.php';

require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.VIEW', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$activePath = ops_thresholds_active_path();
$baselinePath = ops_thresholds_baseline_path();
$actor = (string)($_SESSION['username'] ?? 'SYSTEM');
$requestId = 'ops-policy-web-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);
$msg = '';
$errors = [];

$currentRaw = '';
if (is_file($activePath)) $currentRaw = (string)@file_get_contents($activePath);
if (trim($currentRaw) === '' && is_file($baselinePath)) $currentRaw = (string)@file_get_contents($baselinePath);
if (trim($currentRaw) === '') $currentRaw = ops_thresholds_dump_yaml(ops_thresholds_defaults());

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'dry_run');
    $yamlInput = (string)($_POST['policy_yaml'] ?? '');
    if (trim($yamlInput) === '') {
        $errors[] = 'empty_yaml';
    } else {
        try {
            $policy = ops_thresholds_parse_yaml($yamlInput);
            if (!is_array($policy)) {
                $errors[] = 'schema_not_map';
            } else {
                $valErr = validate_ops_thresholds_struct($policy);
                if ($valErr !== []) {
                    $errors = array_merge($errors, $valErr);
                } elseif ($action === 'apply') {
                    $policy['updated_at'] = date(DateTimeInterface::ATOM);
                    $policy['updated_by'] = $actor;
                    $fingerprint = ops_thresholds_fingerprint($policy);
                    $prevPath = ops_root() . '/storage/state/ops_thresholds_previous_' . date('Ymd_His') . '.yaml';
                    if (is_file($activePath)) {
                        $rawOld = (string)@file_get_contents($activePath);
                        if ($rawOld !== '') @file_put_contents($prevPath, $rawOld);
                    }
                    if (@file_put_contents($activePath, ops_thresholds_dump_yaml($policy)) === false) {
                        $errors[] = 'write_active_failed';
                    } else {
                        $evt = [
                            'ts' => date(DateTimeInterface::ATOM),
                            'actor_username' => $actor,
                            'action' => 'OPS_POLICY_APPLIED',
                            'policy_id' => (string)($policy['policy_id'] ?? ''),
                            'fingerprint' => $fingerprint,
                            'source' => 'web',
                            'request_id' => $requestId,
                        ];
                        ops_thresholds_append_audit_log($evt);
                        if (function_exists('auth_pdo') && function_exists('audit_event')) {
                            $pdo = auth_pdo();
                            if ($pdo instanceof PDO) {
                                audit_event($pdo, 'OPS_POLICY_APPLIED', 'OPS', 'ops_policy', (string)($policy['policy_id'] ?? ''), 'Ops policy applied from web', [
                                    'policy_id' => (string)($policy['policy_id'] ?? ''),
                                    'fingerprint' => $fingerprint,
                                    'request_id' => $requestId,
                                    'actor_username' => $actor,
                                    'source' => 'web',
                                ]);
                            }
                        }
                        $msg = 'Policy applied successfully.';
                        $currentRaw = ops_thresholds_dump_yaml($policy);
                    }
                } else {
                    $msg = 'Dry-run validation passed.';
                    $currentRaw = $yamlInput;
                }
            }
        } catch (Throwable $e) {
            $errors[] = ops_mask($e->getMessage());
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Ops Thresholds Edit</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 16px; color:#111; }
    textarea { width:100%; min-height:440px; font-family: monospace; }
    .ok { color:#065f46; } .err { color:#991b1b; }
  </style>
</head>
<body>
  <h1>Edit Ops Thresholds Policy</h1>
  <p><a href="thresholds_view.php">Back to View</a></p>
  <?php if ($msg !== ''): ?><p class="ok"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
  <?php if ($errors !== []): ?>
    <div class="err">
      <b>Validation Errors:</b>
      <ul>
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars(ops_mask((string)$e), ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <textarea name="policy_yaml"><?= htmlspecialchars($currentRaw, ENT_QUOTES, 'UTF-8') ?></textarea>
    <p>
      <button type="submit" name="action" value="dry_run">Dry-Run Validate</button>
      <button type="submit" name="action" value="apply">Apply Policy</button>
    </p>
  </form>
</body>
</html>

