<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

opsgov_require_admin();

$statePath = opsgov_root() . '/storage/logs/module_governance_tracker.json';
$defaults = [
    'state_version' => 1,
    'updated_at' => date(DateTimeInterface::ATOM),
    'modules' => [
        ['id' => 'tools', 'tier' => 1, 'name' => 'tools/', 'owner' => 'DevOps + Release Captain'],
        ['id' => 'dashboards_finance', 'tier' => 1, 'name' => 'dashboards/finance + dashboards/index.php', 'owner' => 'Finance Ops + QA'],
        ['id' => 'chat', 'tier' => 1, 'name' => 'chat + api/v1/chat + app/Services/Chat*', 'owner' => 'Platform + Security'],
        ['id' => 'kpi', 'tier' => 1, 'name' => 'kpi/', 'owner' => 'PMO KPI + Data Governance'],
        ['id' => 'purchases', 'tier' => 2, 'name' => 'purchases/', 'owner' => 'Procurement Ops + Finance Control'],
        ['id' => 'sales', 'tier' => 2, 'name' => 'sales/', 'owner' => 'Sales Ops'],
        ['id' => 'stock', 'tier' => 2, 'name' => 'stock/ + WQS', 'owner' => 'Warehouse Ops'],
        ['id' => 'rbac', 'tier' => 2, 'name' => 'rbac/', 'owner' => 'Security Governance'],
        ['id' => 'master', 'tier' => 3, 'name' => 'master/', 'owner' => 'Data Steward'],
        ['id' => 'hr_payroll', 'tier' => 3, 'name' => 'payroll + hrl + absensi', 'owner' => 'HR + Compliance'],
        ['id' => 'fixed_asset', 'tier' => 3, 'name' => 'Fixed_Asset/', 'owner' => 'Accounting Control'],
        ['id' => 'api_other', 'tier' => 3, 'name' => 'api/ internal lain', 'owner' => 'Platform/API Governance'],
    ],
    'status' => [],
];

$state = opsgov_read_json($statePath);
if (!$state) {
    $state = $defaults;
}
if (!isset($state['status']) || !is_array($state['status'])) {
    $state['status'] = [];
}
$pillars = ['stability', 'security', 'data_integrity', 'ops', 'release'];
$allowed = ['TODO', 'IN_PROGRESS', 'DONE', 'BLOCKED'];
$msg = '';
$msgType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $moduleId = trim((string)($_POST['module_id'] ?? ''));
    $pillar = trim((string)($_POST['pillar'] ?? ''));
    $newStatus = strtoupper(trim((string)($_POST['new_status'] ?? 'TODO')));
    $note = trim((string)($_POST['note'] ?? ''));

    if ($moduleId === '' || !in_array($pillar, $pillars, true) || !in_array($newStatus, $allowed, true)) {
        $msg = 'Input update tracker tidak valid.';
        $msgType = 'danger';
    } else {
        if (!isset($state['status'][$moduleId]) || !is_array($state['status'][$moduleId])) {
            $state['status'][$moduleId] = [];
        }
        $state['status'][$moduleId][$pillar] = [
            'status' => $newStatus,
            'note' => opsgov_mask($note),
            'updated_at' => date(DateTimeInterface::ATOM),
            'updated_by' => (string)($_SESSION['username'] ?? 'SYSTEM'),
        ];
        $state['updated_at'] = date(DateTimeInterface::ATOM);
        opsgov_safe_write_json($statePath, $state);
        ts_append_run_history('module_governance_tracker_update', 'OK', [
            'source' => 'tools/ops/module_governance_tracker.php',
            'module_id' => $moduleId,
            'pillar' => $pillar,
            'status' => $newStatus,
        ]);
        $msg = 'Tracker berhasil diperbarui.';
        $msgType = 'success';
    }
}

function mgt_status_badge(string $s): string
{
    $s = strtoupper(trim($s));
    return match ($s) {
        'DONE' => tools_badge('HEALTHY', 'DONE'),
        'IN_PROGRESS' => tools_badge('ATTENTION', 'IN PROGRESS'),
        'BLOCKED' => tools_badge('CRITICAL', 'BLOCKED'),
        default => tools_badge('UNKNOWN', 'TODO'),
    };
}

function mgt_summary(array $modules, array $status, int $tier): array
{
    $counts = ['TODO' => 0, 'IN_PROGRESS' => 0, 'DONE' => 0, 'BLOCKED' => 0];
    foreach ($modules as $m) {
        if ((int)($m['tier'] ?? 0) !== $tier) continue;
        $mid = (string)($m['id'] ?? '');
        foreach (['stability', 'security', 'data_integrity', 'ops', 'release'] as $p) {
            $s = strtoupper((string)($status[$mid][$p]['status'] ?? 'TODO'));
            if (!isset($counts[$s])) $s = 'TODO';
            $counts[$s]++;
        }
    }
    $total = array_sum($counts);
    $done = (int)($counts['DONE'] ?? 0);
    $progress = $total > 0 ? round(($done / $total) * 100, 1) : 0;
    return ['counts' => $counts, 'progress' => $progress, 'total' => $total];
}

$modules = (array)($state['modules'] ?? []);
$statusMap = (array)($state['status'] ?? []);
$sum1 = mgt_summary($modules, $statusMap, 1);
$sum2 = mgt_summary($modules, $statusMap, 2);
$sum3 = mgt_summary($modules, $statusMap, 3);

$baseProject = rmi_layout_base_project();
rmi_header('Module Governance Tracker', [
    'active' => 'tools',
    'subtitle' => 'Batch 1-3 execution tracker by module and pillar',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Module Governance Tracker'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= opsgov_h($msgType) ?> py-2 mb-0"><?= opsgov_h($msg) ?></div></div><?php endif; ?>
  <div class="col-12">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Batch Progress</div>
      <div class="small">Tier 1 progress: <b><?= opsgov_h((string)$sum1['progress']) ?>%</b> · DONE <?= (int)$sum1['counts']['DONE'] ?>/<?= (int)$sum1['total'] ?></div>
      <div class="small">Tier 2 progress: <b><?= opsgov_h((string)$sum2['progress']) ?>%</b> · DONE <?= (int)$sum2['counts']['DONE'] ?>/<?= (int)$sum2['total'] ?></div>
      <div class="small">Tier 3 progress: <b><?= opsgov_h((string)$sum3['progress']) ?>%</b> · DONE <?= (int)$sum3['counts']['DONE'] ?>/<?= (int)$sum3['total'] ?></div>
      <div class="small text-muted mt-1">Updated at: <?= opsgov_h(tools_fmt_ts((string)($state['updated_at'] ?? ''))) ?></div>
    </div>
  </div>

  <div class="col-12">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Execution Grid (Stability/Security/Data Integrity/Ops/Release)</div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle">
          <thead><tr><th>Module</th><th>Pillar</th><th>Status</th><th>Owner</th><th>Update</th></tr></thead>
          <tbody>
            <?php foreach ($modules as $m): $mid=(string)($m['id'] ?? ''); ?>
              <?php foreach ($pillars as $p): $row=(array)($statusMap[$mid][$p] ?? ['status'=>'TODO','note'=>'','updated_at'=>'']); ?>
                <tr>
                  <td>
                    <b><?= opsgov_h((string)($m['name'] ?? '-')) ?></b>
                    <div class="small text-muted">Tier <?= (int)($m['tier'] ?? 0) ?> · <?= opsgov_h($mid) ?></div>
                  </td>
                  <td><code><?= opsgov_h($p) ?></code></td>
                  <td>
                    <?= mgt_status_badge((string)($row['status'] ?? 'TODO')) ?>
                    <div class="small text-muted"><?= opsgov_h((string)($row['note'] ?? '')) ?></div>
                  </td>
                  <td class="small"><?= opsgov_h((string)($m['owner'] ?? '-')) ?></td>
                  <td>
                    <form method="post" class="d-flex gap-1 flex-wrap">
                      <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
                      <input type="hidden" name="module_id" value="<?= opsgov_h($mid) ?>">
                      <input type="hidden" name="pillar" value="<?= opsgov_h($p) ?>">
                      <select class="form-select form-select-sm" name="new_status" style="width:auto">
                        <?php foreach ($allowed as $opt): ?><option value="<?= opsgov_h($opt) ?>"><?= opsgov_h($opt) ?></option><?php endforeach; ?>
                      </select>
                      <input class="form-control form-control-sm" name="note" placeholder="note" style="min-width:160px">
                      <button class="btn btn-sm btn-rmi">Save</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
