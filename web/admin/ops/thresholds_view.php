<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/../../../_shared/rbac.php';
require_once __DIR__ . '/../../../tools/ops/_lib/ops_thresholds_lib.php';

require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.VIEW', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$activePath = ops_thresholds_active_path();
$baselinePath = ops_thresholds_baseline_path();
$read = ops_thresholds_read_file($activePath);
if (!$read['ok']) {
    $read = ops_thresholds_read_file($baselinePath);
}

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $raw = (string)($read['raw'] ?? '');
    if ($raw === '') {
        $raw = ops_thresholds_dump_yaml(ops_thresholds_defaults());
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="ops_thresholds_current.yaml"');
    echo $raw;
    exit;
}

$status = $read['ok'] ? 'OK' : 'ATTENTION';
$policy = mask_sensitive_in_policy_output((array)($read['policy'] ?? []));
$fingerprint = (string)($read['fingerprint'] ?? '');
$updatedAt = (string)($policy['updated_at'] ?? '');
$updatedBy = (string)($policy['updated_by'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Ops Thresholds View</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 16px; color:#111; }
    pre { border:1px solid #ddd; padding:10px; background:#fafafa; overflow:auto; }
    .badge { display:inline-block; padding:4px 8px; border-radius:12px; font-weight:700; }
    .ok { background:#d1fae5; } .att { background:#fef3c7; }
  </style>
</head>
<body>
  <h1>Ops Thresholds Policy</h1>
  <p>
    <span class="badge <?= $status === 'OK' ? 'ok' : 'att' ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
    &nbsp;source: <?= htmlspecialchars($read['path_masked'] ?? ops_mask($baselinePath), ENT_QUOTES, 'UTF-8') ?>
  </p>
  <p>
    policy_id: <b><?= htmlspecialchars((string)($policy['policy_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></b><br>
    fingerprint: <code><?= htmlspecialchars($fingerprint, ENT_QUOTES, 'UTF-8') ?></code><br>
    updated_at/by: <?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?> / <?= htmlspecialchars($updatedBy, ENT_QUOTES, 'UTF-8') ?>
  </p>
  <p>
    <a href="thresholds_edit.php">Edit Policy</a> |
    <a href="?download=1">Download YAML</a>
  </p>
  <?php if (!$read['ok']): ?>
    <h3>Validation Errors</h3>
    <ul>
      <?php foreach ((array)($read['errors'] ?? []) as $err): ?>
        <li><?= htmlspecialchars(ops_mask((string)$err), ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <h3>Policy (masked)</h3>
  <pre><?= htmlspecialchars(ops_thresholds_dump_yaml($policy ?: ops_thresholds_defaults()), ENT_QUOTES, 'UTF-8') ?></pre>
</body>
</html>

