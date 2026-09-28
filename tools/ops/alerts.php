<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/_lib/alerting_lib.php';
require_once __DIR__ . '/_lib/alert_policy_lib.php';

tools_require_access('ops/alerts.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$env = strtolower((string)(getenv('APP_ENV') ?: 'staging'));
if (!in_array($env, ['staging', 'production'], true)) $env = 'staging';
$policyRes = load_alert_policy($env);
$policy = (array)$policyRes['policy'];
$msg = '';
$msgType = 'info';
$policyPanel = [
    'source' => (string)($policyRes['source'] ?? 'DOCS'),
    'fingerprint' => (string)($policyRes['policy_fingerprint'] ?? ''),
    'policy_id' => (string)($policyRes['policy_id'] ?? ''),
    'updated_at' => (string)($policyRes['policy_updated_at'] ?? ''),
    'updated_by' => (string)($policyRes['policy_updated_by'] ?? ''),
    'path_masked' => (string)($policyRes['path_masked'] ?? ''),
    'ok' => (bool)($policyRes['ok'] ?? false),
    'errors' => (array)($policyRes['errors'] ?? []),
];
$applyLast = ts_read_json(ts_root() . '/storage/logs/pipeline/alert_policy_apply_last.json');
$validateLast = ts_read_json(ts_root() . '/storage/logs/pipeline/alert_policy_validate_last.json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $action = strtoupper(trim((string)($_POST['action'] ?? '')));
    $ruleKey = trim((string)($_POST['rule_key'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $actor = tools_current_actor_username();
    $res = ['ok' => false, 'error' => 'ERR_ACTION_UNKNOWN'];
    if ($action === 'ACKNOWLEDGE') {
        $res = al_apply_workflow_action($ruleKey, 'ACKNOWLEDGE', $actor, $env, $policy, $note);
    } elseif ($action === 'IN_PROGRESS') {
        $res = al_apply_workflow_action($ruleKey, 'IN_PROGRESS', $actor, $env, $policy, $note);
    } elseif ($action === 'RESOLVE_MANUAL') {
        $res = al_apply_workflow_action($ruleKey, 'RESOLVE_MANUAL', $actor, $env, $policy, $note);
    } elseif ($action === 'ASSIGN_OWNER') {
        $res = al_apply_workflow_action($ruleKey, 'ASSIGN_OWNER', $actor, $env, $policy, $note, [
            'primary' => trim((string)($_POST['owner_primary'] ?? '')),
            'secondary' => trim((string)($_POST['owner_secondary'] ?? '')),
        ]);
    } elseif ($action === 'VALIDATE_POLICY') {
        $path = trim((string)($_POST['validate_path'] ?? 'storage/state/alerting_policy_current.yaml'));
        $cmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ts_root() . '/tools/ops/validate_alerting_policy.php')
            . ' --path=' . escapeshellarg($path) . ' --strict --write-last';
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $msg = ((int)$code === 0 ? 'Policy validate PASS.' : 'Policy validate FAIL.') . ' ' . h(al_mask(implode(' | ', array_slice($out, -2))));
        $msgType = ((int)$code === 0) ? 'success' : 'danger';
    } elseif ($action === 'APPLY_DOCS_POLICY') {
        $confirm = trim((string)($_POST['confirm_apply'] ?? ''));
        $rfcInput = strtoupper(trim((string)($_POST['rfc_id'] ?? '')));
        if ($confirm !== 'APPLY_ALERT_POLICY') {
            $msg = 'Confirm text invalid.';
            $msgType = 'danger';
        } else {
            $cmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ts_root() . '/tools/ops/update_alerting_policy.php')
                . ' --env=' . escapeshellarg($env)
                . ' --from=' . escapeshellarg('docs/governance/ALERTING_POLICY.yaml')
                . ' --apply --i-understand --confirm=' . escapeshellarg('APPLY_ALERT_POLICY')
                . ' --actor=' . escapeshellarg($actor)
                . ' --write-last';
            if ($env === 'production') {
                $cmd .= ' --rfc=' . escapeshellarg($rfcInput);
            }
            $out = [];
            $code = 1;
            @exec($cmd . ' 2>&1', $out, $code);
            $msg = ((int)$code === 0 ? 'Apply policy PASS.' : 'Apply policy FAIL.') . ' ' . h(al_mask(implode(' | ', array_slice($out, -2))));
            $msgType = ((int)$code === 0) ? 'success' : 'danger';
        }
    }
    if (in_array($action, ['VALIDATE_POLICY', 'APPLY_DOCS_POLICY'], true)) {
        // handled above
    } elseif (!empty($res['ok'])) {
        $msg = 'Workflow updated.';
        $msgType = 'success';
    } else {
        $msg = 'Action failed: ' . h(al_mask((string)($res['error'] ?? 'ERR_UNKNOWN')));
        $msgType = 'danger';
        al_append_audit([
            'actor_username' => $actor,
            'request_id' => 'alerts-ui-' . date('YmdHis'),
            'action' => 'ALERT_STATUS_SET',
            'env' => $env,
            'rule_key' => $ruleKey,
            'result' => 'FAIL',
            'note_masked' => $note,
        ]);
    }
}

$workflowWrap = al_load_workflow_state($env);
$wf = (array)$workflowWrap['data'];
$rules = (array)($wf['rules'] ?? []);
$alertsLast = ts_read_json(al_alerts_last_path());
$activeRules = array_map('strval', (array)($alertsLast['active_rules'] ?? []));
$allowOwnerOverride = (bool)($policy['workflow']['allow_owner_override'] ?? false);

$baseProject = rmi_layout_base_project();
rmi_header('Ops Alerts Workflow', [
    'active' => 'tools',
    'subtitle' => 'Workflow & SLA management untuk alert owner enterprise.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Ops Alerts'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgType) ?> py-2 mb-0"><?= $msg ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Alert Policy Status</div>
      <div class="small mb-1">
        Source: <b><?= h($policyPanel['source']) ?></b>
        · Policy ID: <b><?= h((string)$policyPanel['policy_id']) ?></b>
        · Fingerprint: <code><?= h((string)$policyPanel['fingerprint']) ?></code>
      </div>
      <div class="small mb-2">
        Updated: <b><?= h(tools_fmt_ts((string)$policyPanel['updated_at'])) ?></b> by <b><?= h((string)$policyPanel['updated_by']) ?></b>
        · Path: <code><?= h((string)$policyPanel['path_masked']) ?></code>
      </div>
      <div class="small mb-2">
        Last validate: <b><?= h(tools_fmt_ts((string)($validateLast['generated_at'] ?? ''))) ?></b>
        · Last apply: <b><?= h(tools_fmt_ts((string)($applyLast['generated_at'] ?? ''))) ?></b>
        · Apply status: <b><?= h((string)($applyLast['overall_ok'] ?? '')) ?></b>
      </div>
      <?php if (!$policyPanel['ok']): ?>
        <div class="alert alert-warning py-2 mb-2"><?= tools_badge('ATTENTION', 'POLICY INVALID') ?> <?= h(implode('; ', array_map('strval', (array)$policyPanel['errors']))) ?></div>
      <?php endif; ?>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post" class="d-flex gap-2 align-items-center">
          <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
          <input type="hidden" name="action" value="VALIDATE_POLICY">
          <select name="validate_path" class="form-select form-select-sm">
            <option value="storage/state/alerting_policy_current.yaml">Validate Active Policy</option>
            <option value="docs/governance/ALERTING_POLICY.yaml">Validate Docs Policy</option>
          </select>
          <button class="btn btn-sm btn-outline-light" type="submit">Validate Policy</button>
        </form>
        <form method="post" class="d-flex gap-2 align-items-center">
          <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
          <input type="hidden" name="action" value="APPLY_DOCS_POLICY">
          <?php if ($env === 'production'): ?>
            <input class="form-control form-control-sm" name="rfc_id" placeholder="RFC-YYYY-####" required>
          <?php endif; ?>
          <input class="form-control form-control-sm" name="confirm_apply" placeholder="APPLY_ALERT_POLICY" required>
          <button class="btn btn-sm btn-rmi" type="submit">Apply Docs Policy to Active</button>
        </form>
      </div>
    </div>
  </div>
  <?php if (!empty($workflowWrap['corrupt'])): ?>
    <div class="col-12"><div class="alert alert-warning py-2 mb-0"><?= tools_badge('ATTENTION', 'WORKFLOW_STATE_CORRUPT') ?> state workflow corrupt; engine akan rebuild safe default.</div></div>
  <?php endif; ?>
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-semibold">Workflow & SLA</div>
        <a class="btn btn-sm btn-outline-light" href="../ops/alert_engine.php" onclick="return false;">Run via CLI: <code>php tools/ops/alert_engine.php --env=<?= h($env) ?></code></a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th>Rule</th><th>Severity</th><th>Status</th><th>Owner</th><th>Opened</th><th>Ack Due</th><th>Resolve Due</th><th>Breach</th><th>Last Seen</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rules): ?>
              <tr><td colspan="10">No workflow state yet. Run alert engine first.</td></tr>
            <?php endif; ?>
            <?php foreach ($rules as $ruleKey => $row): $row = (array)$row; $st = strtoupper((string)($row['status'] ?? 'OPEN')); $sev = strtoupper((string)($row['severity'] ?? 'ATTENTION')); $isActive = in_array((string)$ruleKey, $activeRules, true); ?>
              <tr>
                <td><code><?= h((string)$ruleKey) ?></code> <?= $isActive ? tools_badge('ATTENTION', 'ACTIVE') : tools_badge('OK', 'NORMAL') ?></td>
                <td><?= tools_badge($sev === 'CRITICAL' ? 'CRITICAL' : 'ATTENTION', $sev) ?></td>
                <td><?= tools_badge($st === 'RESOLVED' ? 'HEALTHY' : 'ATTENTION', $st) ?></td>
                <td class="small"><?= h((string)($row['owners']['primary'] ?? 'TBD')) ?> / <?= h((string)($row['owners']['secondary'] ?? 'TBD')) ?></td>
                <td class="small"><?= h(tools_fmt_ts((string)($row['opened_at'] ?? ''))) ?></td>
                <td class="small"><?= h(tools_fmt_ts((string)($row['sla']['ack_due_at'] ?? ''))) ?></td>
                <td class="small"><?= h(tools_fmt_ts((string)($row['sla']['resolve_due_at'] ?? ''))) ?></td>
                <td><?= !empty($row['sla']['breached']) ? tools_badge('CRITICAL', 'SLA BREACH') : tools_badge('HEALTHY', 'OK') ?></td>
                <td class="small"><?= h(tools_fmt_ts((string)($row['last_seen_at'] ?? ''))) ?></td>
                <td>
                  <div class="d-flex gap-1 flex-wrap">
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
                      <input type="hidden" name="action" value="ACKNOWLEDGE">
                      <input type="hidden" name="rule_key" value="<?= h((string)$ruleKey) ?>">
                      <input class="form-control form-control-sm mb-1" name="note" placeholder="Ack note min 10 chars" required>
                      <button class="btn btn-sm btn-outline-light" type="submit">ACK</button>
                    </form>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
                      <input type="hidden" name="action" value="IN_PROGRESS">
                      <input type="hidden" name="rule_key" value="<?= h((string)$ruleKey) ?>">
                      <button class="btn btn-sm btn-outline-light" type="submit">IN PROGRESS</button>
                    </form>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
                      <input type="hidden" name="action" value="RESOLVE_MANUAL">
                      <input type="hidden" name="rule_key" value="<?= h((string)$ruleKey) ?>">
                      <input class="form-control form-control-sm mb-1" name="note" placeholder="Resolve note min 10 chars" required>
                      <button class="btn btn-sm btn-outline-light" type="submit">RESOLVE</button>
                    </form>
                    <?php if ($allowOwnerOverride): ?>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
                        <input type="hidden" name="action" value="ASSIGN_OWNER">
                        <input type="hidden" name="rule_key" value="<?= h((string)$ruleKey) ?>">
                        <input class="form-control form-control-sm mb-1" name="owner_primary" placeholder="Primary">
                        <input class="form-control form-control-sm mb-1" name="owner_secondary" placeholder="Secondary">
                        <input class="form-control form-control-sm mb-1" name="note" placeholder="Override note min 10 chars" required>
                        <button class="btn btn-sm btn-outline-light" type="submit">ASSIGN</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>

