<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';
require_once __DIR__ . '/../tools_access_helpers.php';

tools_require_access('release/release_final_checklist.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

// ── Build logic inline (tidak pakai CLI subprocess) ───────────────────────────
function rfc_read(string $path): array {
    if (!is_file($path)) return [];
    $d = @json_decode((string)@file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function rfc_build_checklist(): array {
    $root = defined('APP_ROOT') ? APP_ROOT : (realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));

    $threshold  = rfc_read($root . '/tools/release/release_thresholds_v1.json');
    $freshMins  = (int)($threshold['freshness_minutes'] ?? 180);
    $rid        = 'checklist-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);

    // Read state artifacts — fail-soft (missing = degraded, not crashed)
    $doctor    = rfc_read($root . '/storage/logs/doctor_last.json');
    $triage    = rfc_read($root . '/storage/logs/erp_hardening_triage_web.last.json');
    $smoke     = rfc_read($root . '/storage/logs/smoke_tools_regression_last.json');
    $contract  = rfc_read($root . '/storage/logs/contract_check.last.json');
    $sla       = rfc_read($root . '/storage/logs/sla_monitor_last.json');
    $preflight = rfc_read($root . '/storage/logs/preflight_check.last.json');
    $cutover   = rfc_read($root . '/storage/logs/cutover_checks.last.json');
    $smoke2    = rfc_read($root . '/storage/logs/smoke_http_last.json');
    $dualGate  = rfc_read($root . '/storage/logs/final_gate_dual_last.json');
    $govLint   = rfc_read($root . '/storage/logs/module_governance_lint_last.json');
    $heartbeat = rfc_read($root . '/storage/logs/monitoring_heartbeat_last.json');

    $now = time();
    $fresh = static function (array $state) use ($now, $freshMins): bool {
        $ts = (string)($state['generated_at'] ?? $state['ts'] ?? $state['run_at'] ?? '');
        if ($ts === '') return false;
        $epoch = @strtotime($ts);
        return $epoch !== false && (($now - $epoch) <= ($freshMins * 60));
    };

    // Validate base URL
    $baseUrlValid = false;
    if (function_exists('tools_validate_base_url')) {
        $baseUrlValid = (bool)((tools_validate_base_url())['valid'] ?? false);
    } else {
        $baseUrlValid = (bool)($smoke['tools_base_url_valid'] ?? true);
    }

    $doctorCritical = (int)($doctor['overall']['critical_fail_count'] ?? 0);
    $triageP0       = (int)($triage['summary']['p0'] ?? 0);
    $smokeFail      = (int)($smoke['summary']['fail'] ?? $smoke2['fail_count'] ?? 0);
    $contractOk     = (bool)($contract['ok'] ?? $cutover['overall_ok'] ?? false);
    $slaOk          = !((bool)($sla['sla']['breach'] ?? false));
    $cutoverOk      = (bool)($cutover['overall_ok'] ?? false);
    $smoke2Ok       = (bool)($smoke2['ok'] ?? (($smoke2['fail_count'] ?? 1) === 0));
    $dualGateOk     = (bool)($dualGate['ok'] ?? false);
    $govLintOk      = (bool)($govLint['ok'] ?? false);
    $heartbeatOk    = (bool)($heartbeat['ok'] ?? true);
    $inProgress     = strtoupper((string)($doctor['status'] ?? '')) === 'IN_PROGRESS';

    $prefOk = (bool)($preflight['ok'] ?? false);

    $items = [
        // Core
        ['item'=>'Preflight OK',                     'pass'=>$prefOk,             'source'=>'preflight_check.last.json',              'category'=>'core'],
        ['item'=>'Smoke HTTP fail==0',                'pass'=>$smoke2Ok,           'source'=>'smoke_http_last.json',                   'category'=>'core'],
        ['item'=>'Contract check OK',                 'pass'=>$contractOk,         'source'=>'contract_check.last.json',               'category'=>'core'],
        ['item'=>'Cutover gate overall_ok',           'pass'=>$cutoverOk,          'source'=>'cutover_checks.last.json',               'category'=>'core'],
        // Security
        ['item'=>'Hardening triage P0==0',            'pass'=>$triageP0===0,       'source'=>'erp_hardening_triage_web.last.json',     'category'=>'security'],
        ['item'=>'Tools base URL valid',              'pass'=>$baseUrlValid,       'source'=>'TOOLS_BASE_URL env',                     'category'=>'security'],
        // RBAC & Governance
        ['item'=>'Governance lint OK',                'pass'=>$govLintOk,          'source'=>'module_governance_lint_last.json',       'category'=>'governance'],
        ['item'=>'Dual gate (internal+public) OK',    'pass'=>$dualGateOk,         'source'=>'final_gate_dual_last.json',              'category'=>'dual_gate'],
        // Monitoring
        ['item'=>'Monitoring heartbeat OK',           'pass'=>$heartbeatOk,        'source'=>'monitoring_heartbeat_last.json',         'category'=>'monitoring'],
        ['item'=>'SLA monitor OK',                    'pass'=>$slaOk,              'source'=>'sla_monitor_last.json',                  'category'=>'monitoring'],
        // Freshness (non-blocking warning only)
        ['item'=>'Artifacts fresh (<'.$freshMins.'m)','pass'=>$fresh($doctor)||$fresh($cutover)||$fresh($smoke2), 'source'=>'freshness check', 'category'=>'freshness'],
    ];

    $rows = [];
    foreach ($items as $it) {
        $status = $inProgress ? 'IN_PROGRESS' : ($it['pass'] ? 'DONE' : 'BLOCKED');
        $rows[] = [
            'item'     => $it['item'],
            'status'   => $status,
            'category' => $it['category'],
            'pass'     => $it['pass'],
            'source'   => $it['source'],
            'ts'       => date(DateTimeInterface::ATOM),
        ];
    }

    $noGo = ($doctorCritical > 0) || ($triageP0 > 0) || ($smokeFail > 0) || !$contractOk || !$baseUrlValid;
    $go   = !$noGo && $slaOk && $cutoverOk;

    $doneCount    = count(array_filter($rows, fn($r) => $r['status'] === 'DONE'));
    $blockedCount = count(array_filter($rows, fn($r) => $r['status'] === 'BLOCKED'));

    $payload = [
        'state_version' => 1,
        'request_id'    => $rid,
        'generated_at'  => date(DateTimeInterface::ATOM),
        'signature'     => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: ''),
        'rows'          => $rows,
        'overall' => [
            'go'                  => $go,
            'decision'            => $go ? 'GO' : 'NO-GO',
            'no_go'               => $noGo,
            'done_count'          => $doneCount,
            'blocked_count'       => $blockedCount,
            'total_items'         => count($rows),
            'critical_fail_count' => $doctorCritical,
            'triage_p0'           => $triageP0,
            'smoke_fail'          => $smokeFail,
            'contract_ok'         => $contractOk,
            'cutover_ok'          => $cutoverOk,
            'dual_gate_ok'        => $dualGateOk,
            'tools_base_url_valid'=> $baseUrlValid,
            'sla_ok'              => $slaOk,
        ],
    ];

    // Write artifacts
    $logsDir = $root . '/storage/logs';
    @mkdir($logsDir, 0775, true);
    if (function_exists('tools_json_write_atomic')) {
        tools_json_write_atomic($logsDir . '/release_final_checklist_last.json', $payload);
    } else {
        @file_put_contents($logsDir . '/release_final_checklist_last.json', json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
    @file_put_contents($logsDir . '/release_final_checklist_history.jsonl', json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

    return $payload;
}

// ── Handle POST — build inline ─────────────────────────────────────────────
$msg = ''; $msgType = 'info'; $buildResult = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    try {
        $buildResult = rfc_build_checklist();
        $go = (bool)($buildResult['overall']['go'] ?? false);
        $msg = $go
            ? '✅ GO — Checklist berhasil dibangun. Keputusan: <strong>GO</strong>.'
            : '⚠️ NO-GO — Checklist berhasil dibangun. Keputusan: <strong>NO-GO</strong>. Periksa BLOCKED item.';
        $msgType = $go ? 'success' : 'warning';
    } catch (Throwable $e) {
        $msg = 'Build gagal: ' . h($e->getMessage());
        $msgType = 'danger';
    }
}

$root = defined('APP_ROOT') ? APP_ROOT : (realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
$state = rfc_read($root . '/storage/logs/release_final_checklist_last.json');

$baseProject = rmi_layout_base_project();
rmi_header('Release Final Checklist', [
    'active'      => 'tools',
    'subtitle'    => 'GO / NO-GO berbasis state real — DONE / IN_PROGRESS / BLOCKED',
    'breadcrumbs' => [
        ['label'=>'Tools','url'=>$baseProject.'/tools/index.php'],
        'Release Final Checklist',
    ],
]);
?>
<style>
.rfc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-bottom:16px}
.rfc-kc{background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:10px 14px;text-align:center;border-top:3px solid var(--rc)}
.rfc-kc-val{font-size:22px;font-weight:800;color:#fff}
.rfc-kc-lbl{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.3px}
.rfc-item{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:13px}
.rfc-item:last-child{border-bottom:none}
.rfc-badge{padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;white-space:nowrap}
.rfc-badge.DONE{background:rgba(34,197,94,.15);color:#4ade80}
.rfc-badge.BLOCKED{background:rgba(239,68,68,.15);color:#f87171}
.rfc-badge.IN_PROGRESS{background:rgba(245,158,11,.15);color:#fbbf24}
.rfc-decision{font-size:32px;font-weight:900;padding:12px 24px;border-radius:12px;display:inline-block;margin-bottom:8px}
.rfc-decision.GO{background:rgba(34,197,94,.15);color:#4ade80;border:2px solid rgba(34,197,94,.3)}
.rfc-decision.NOGO{background:rgba(239,68,68,.12);color:#f87171;border:2px solid rgba(239,68,68,.3)}
.rfc-cat{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.3px;padding:2px 6px;background:rgba(255,255,255,.04);border-radius:4px}
</style>

<div class="row g-3">
  <?php if ($msg !== ''): ?>
  <div class="col-12">
    <div class="alert alert-<?= h($msgType) ?> alert-dismissible fade show py-2">
      <?= $msg ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Actions -->
  <div class="col-12">
    <div class="rmi-card p-3 d-flex gap-2 align-items-center flex-wrap">
      <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
        <button class="btn btn-primary btn-sm" type="submit">🔄 Build Checklist</button>
      </form>
      <?php if (!empty($state)): ?>
        <a class="btn btn-outline-light btn-sm" href="export_checklist.php?fmt=json">📤 Export JSON</a>
        <a class="btn btn-outline-light btn-sm" href="export_checklist.php?fmt=csv">📤 Export CSV</a>
      <?php endif; ?>
      <span class="ms-auto" style="font-size:11px;color:#475569">
        <?= !empty($state['generated_at']) ? 'Terakhir build: '.h(substr((string)$state['generated_at'],0,19)) : 'Belum ada build.' ?>
      </span>
    </div>
  </div>

  <?php if (!empty($state['overall'])): ?>
  <?php $overall = $state['overall']; $isGo = (bool)($overall['go'] ?? false); ?>

  <!-- Decision -->
  <div class="col-12">
    <div class="rmi-card p-3 text-center">
      <div class="rfc-decision <?= $isGo ? 'GO' : 'NOGO' ?>">
        <?= $isGo ? '✅ GO' : '🚫 NO-GO' ?>
      </div>
      <div style="font-size:13px;color:#94a3b8">
        <?= (int)($overall['done_count'] ?? 0) ?> DONE &nbsp;·&nbsp;
        <?= (int)($overall['blocked_count'] ?? 0) ?> BLOCKED &nbsp;·&nbsp;
        <?= (int)($overall['total_items'] ?? 0) ?> total
      </div>
    </div>
  </div>

  <!-- KPI Summary -->
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="rfc-grid">
        <div class="rfc-kc" style="--rc:#22c55e">
          <div class="rfc-kc-val"><?= (int)($overall['done_count'] ?? 0) ?></div>
          <div class="rfc-kc-lbl">✅ Done</div>
        </div>
        <div class="rfc-kc" style="--rc:#ef4444">
          <div class="rfc-kc-val"><?= (int)($overall['blocked_count'] ?? 0) ?></div>
          <div class="rfc-kc-lbl">🚫 Blocked</div>
        </div>
        <div class="rfc-kc" style="--rc:<?= (bool)($overall['cutover_ok'] ?? false) ? '#22c55e' : '#ef4444' ?>">
          <div class="rfc-kc-val"><?= (bool)($overall['cutover_ok'] ?? false) ? '✅' : '❌' ?></div>
          <div class="rfc-kc-lbl">Cutover</div>
        </div>
        <div class="rfc-kc" style="--rc:<?= (bool)($overall['dual_gate_ok'] ?? false) ? '#22c55e' : '#ef4444' ?>">
          <div class="rfc-kc-val"><?= (bool)($overall['dual_gate_ok'] ?? false) ? '✅' : '❌' ?></div>
          <div class="rfc-kc-lbl">Dual Gate</div>
        </div>
        <div class="rfc-kc" style="--rc:<?= (bool)($overall['contract_ok'] ?? false) ? '#22c55e' : '#ef4444' ?>">
          <div class="rfc-kc-val"><?= (bool)($overall['contract_ok'] ?? false) ? '✅' : '❌' ?></div>
          <div class="rfc-kc-lbl">Contract</div>
        </div>
        <div class="rfc-kc" style="--rc:<?= (int)($overall['smoke_fail'] ?? 0) === 0 ? '#22c55e' : '#ef4444' ?>">
          <div class="rfc-kc-val"><?= (int)($overall['smoke_fail'] ?? 0) ?></div>
          <div class="rfc-kc-lbl">Smoke Fail</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Checklist rows -->
  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-3">📋 Checklist Items</div>
      <?php foreach ((array)($state['rows'] ?? []) as $row):
        $st = (string)($row['status'] ?? 'BLOCKED');
      ?>
      <div class="rfc-item">
        <span class="rfc-badge <?= h($st) ?>"><?= h($st) ?></span>
        <span class="flex-fill"><?= h((string)($row['item'] ?? '')) ?></span>
        <span class="rfc-cat"><?= h((string)($row['category'] ?? '')) ?></span>
        <span style="font-size:11px;color:#475569" title="<?= h((string)($row['source'] ?? '')) ?>">
          <?= h(mb_strimwidth((string)($row['source'] ?? ''), 0, 35, '…')) ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php else: ?>
  <!-- No state yet -->
  <div class="col-12">
    <div class="rmi-card p-3" style="text-align:center;padding:40px!important">
      <div style="font-size:40px;margin-bottom:12px">📋</div>
      <div class="fw-semibold" style="color:#e2e8f0;margin-bottom:6px">Belum ada checklist</div>
      <div style="font-size:13px;color:#64748b">Klik <strong>Build Checklist</strong> untuk membuat checklist GO/NO-GO berdasarkan state artifact terkini.</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Raw state (collapsible) -->
  <?php if (!empty($state)): ?>
  <div class="col-12">
    <details>
      <summary style="cursor:pointer;font-size:12px;color:#64748b;padding:8px 12px;background:rgba(255,255,255,.03);border-radius:8px;border:1px solid rgba(255,255,255,.06)">
        📄 Raw JSON State
      </summary>
      <div class="rmi-card p-3 mt-2">
        <pre class="small mb-0" style="white-space:pre-wrap;font-size:11px;color:#94a3b8;max-height:400px;overflow-y:auto"><?= h(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
      </div>
    </details>
  </div>
  <?php endif; ?>
</div>

<?php rmi_footer(); ?>
