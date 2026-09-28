<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';

require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../tools_ui_helpers.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_access_helpers.php';
require_once __DIR__ . '/_lib/rfc_dashboard_lib.php';

tools_require_access('rfc/rfc_dashboard.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

if (!function_exists('h')) {
    function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

$requestId = 'rfc-dash-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 8);

// Guard check: clean-room must pass.
$guardOut = [];
$guardCode = 1;
$guardCmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ts_root() . '/tools/dev/verify_clean_room.php');
@exec($guardCmd . ' 2>&1', $guardOut, $guardCode);
if ((int)$guardCode !== 0) {
    http_response_code(500);
    rfcdash_append_audit('RFC_DASHBOARD_VIEWED', $requestId, ['result' => 'FAIL', 'error' => 'CLEAN_ROOM_GUARD_FAIL']);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>RFC Dashboard</title></head><body>';
    echo '<h1>ATTENTION</h1><p>Clean-room guard failed. Stop execution.</p><pre>' . h(rfcdash_mask(implode(' | ', array_slice($guardOut, -2)))) . '</pre>';
    echo '</body></html>';
    exit;
}

$msg = (string)($_GET['msg'] ?? '');
$msgLevel = (string)($_GET['lvl'] ?? 'info');
$envHint = strtolower(trim((string)($_GET['env_hint'] ?? 'staging')));
if (!in_array($envHint, ['staging', 'production'], true)) $envHint = 'staging';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $action = strtoupper(trim((string)($_POST['action'] ?? '')));
    $envPost = strtolower(trim((string)($_POST['env_hint'] ?? 'staging')));
    if (!in_array($envPost, ['staging', 'production'], true)) $envPost = 'staging';
    $resultMsg = 'Action unknown.';
    $resultLvl = 'warning';

    if ($action === 'REFRESH_INDEX') {
        $cmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ts_root() . '/tools/rfc/rfc_index_scan.php') . ' --write-last';
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $ok = ((int)$code === 0);
        $resultMsg = $ok ? 'RFC index refreshed.' : 'RFC index refresh failed.';
        $resultLvl = $ok ? 'success' : 'danger';
        rfcdash_append_audit('RFC_INDEX_REFRESHED', $requestId, [
            'env_hint' => $envPost,
            'ok' => $ok,
            'output_tail_masked' => rfcdash_mask(implode(' | ', array_slice($out, -2))),
        ]);
        ts_append_run_history('rfc_index_refreshed', $ok ? 'OK' : 'FAIL', [
            'actor_username' => tools_current_actor_username(),
            'source' => 'tools/rfc/rfc_dashboard.php',
            'request_id' => $requestId,
        ]);
    } elseif ($action === 'RUN_LINT_QUICK') {
        $cmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ts_root() . '/tools/rfc/rfc_quality_lint.php')
            . ' --env=' . escapeshellarg($envPost) . ' --mode=quick --write-last';
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        $ok = ((int)$code === 0);
        $resultMsg = $ok ? 'RFC lint quick completed.' : 'RFC lint quick completed with FAIL.';
        $resultLvl = $ok ? 'success' : 'danger';
        rfcdash_append_audit('RFC_LINT_TRIGGERED', $requestId, [
            'mode' => 'quick',
            'env_hint' => $envPost,
            'ok' => $ok,
            'output_tail_masked' => rfcdash_mask(implode(' | ', array_slice($out, -2))),
        ]);
        ts_append_run_history('rfc_lint_quick', $ok ? 'OK' : 'FAIL', [
            'actor_username' => tools_current_actor_username(),
            'source' => 'tools/rfc/rfc_dashboard.php',
            'request_id' => $requestId,
        ]);
    } elseif ($action === 'RUN_LINT_FULL') {
        $confirm = trim((string)($_POST['confirm_text'] ?? ''));
        if ($envPost === 'production' && $confirm !== 'RUN_RFC_LINT_FULL') {
            $resultMsg = 'Confirm text invalid for production full lint.';
            $resultLvl = 'danger';
        } else {
            $cmd = escapeshellarg((string)(PHP_BINARY ?: 'php')) . ' ' . escapeshellarg(ts_root() . '/tools/rfc/rfc_quality_lint.php')
                . ' --env=' . escapeshellarg($envPost) . ' --mode=full --strict --write-last';
            $out = [];
            $code = 1;
            @exec($cmd . ' 2>&1', $out, $code);
            $ok = ((int)$code === 0);
            $resultMsg = $ok ? 'RFC lint full strict completed.' : 'RFC lint full strict returned FAIL.';
            $resultLvl = $ok ? 'success' : 'danger';
            rfcdash_append_audit('RFC_LINT_TRIGGERED', $requestId, [
                'mode' => 'full',
                'strict' => true,
                'env_hint' => $envPost,
                'ok' => $ok,
                'output_tail_masked' => rfcdash_mask(implode(' | ', array_slice($out, -2))),
            ]);
            ts_append_run_history('rfc_lint_full', $ok ? 'OK' : 'FAIL', [
                'actor_username' => tools_current_actor_username(),
                'source' => 'tools/rfc/rfc_dashboard.php',
                'request_id' => $requestId,
            ]);
        }
    }

    rmi_redirect('rfc_dashboard.php?msg=' . urlencode($resultMsg) . '&lvl=' . urlencode($resultLvl) . '&env_hint=' . urlencode($envPost));
}

rfcdash_append_audit('RFC_DASHBOARD_VIEWED', $requestId, [
    'env_hint' => $envHint,
    'query' => [
        'status' => (string)($_GET['status'] ?? 'ALL'),
        'type' => (string)($_GET['type'] ?? 'ALL'),
        'lint' => (string)($_GET['lint'] ?? 'ALL'),
        'search' => (string)($_GET['search'] ?? ''),
    ],
]);

$sources = rfcdash_load_sources();
$rows = rfcdash_merge_rows((array)$sources['index']['data'], (array)$sources['lint']['data'], (array)$sources['usage']['data']);
$state = rfcdash_build_state($sources, $rows);
rfcdash_write_state($state, $sources);

$filters = [
    'status' => (string)($_GET['status'] ?? 'ALL'),
    'type' => (string)($_GET['type'] ?? 'ALL'),
    'lint' => (string)($_GET['lint'] ?? 'ALL'),
    'search' => (string)($_GET['search'] ?? ''),
];
$filtered = rfcdash_filter_rows($rows, $filters);
$limit = (int)($_GET['limit'] ?? 50);
if ($limit <= 0) $limit = 50;
if ($limit > 200) $limit = 200;
$filtered = array_slice($filtered, 0, $limit);

$summary = (array)($state['summary'] ?? []);
$fresh = (array)($state['freshness'] ?? []);
$issues = (array)($state['top_issues'] ?? []);
$sourceCommands = [];
if (!$sources['index']['ok']) $sourceCommands[] = 'php tools/rfc/rfc_index_scan.php --write-last';
if (!$sources['lint']['ok']) $sourceCommands[] = 'php tools/rfc/rfc_quality_lint.php --env=staging --mode=quick --write-last';
if (!$sources['usage']['ok']) $sourceCommands[] = 'php tools/rfc/collect_rfc_usage.php --env=staging --run-id=last --write-last';

$types = ['ALL'];
foreach ($rows as $r) {
    $t = strtoupper(trim((string)($r['type'] ?? '')));
    if ($t !== '' && !in_array($t, $types, true)) $types[] = $t;
}
sort($types, SORT_STRING);

$baseProject = rmi_layout_base_project();
rmi_header('RFC Dashboard', [
    'active' => 'tools',
    'subtitle' => 'Read-only RFC dashboard dengan lint badges dan usage links.',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'RFC Dashboard'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= h($msgLevel) ?> py-2 mb-0"><?= h($msg) ?></div></div><?php endif; ?>
  <?php if ($sourceCommands !== []): ?>
    <div class="col-12">
      <div class="alert alert-warning py-2 mb-0">
        <?= tools_badge('ATTENTION', 'STATE_MISSING') ?>
        <?php if (!$sources['index']['ok']): ?><span class="ms-1">INDEX_MISSING</span><?php endif; ?>
        <?php if (!$sources['lint']['ok']): ?><span class="ms-1">LINT_MISSING</span><?php endif; ?>
        <?php if (!$sources['usage']['ok']): ?><span class="ms-1">USAGE_MISSING</span><?php endif; ?>
        <div class="small mt-1">Run commands:</div>
        <?php foreach ($sourceCommands as $cmd): ?><div class="small"><code><?= h($cmd) ?></code></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="col-md-3">
    <div class="rmi-card p-3 h-100">
      <div class="small rmi-muted">Total RFC</div>
      <div class="h4 mb-0"><?= (int)($summary['total'] ?? 0) ?></div>
      <div class="small mt-1">Overall: <?= tools_badge((string)($state['overall_level'] ?? 'UNKNOWN')) ?></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="rmi-card p-3 h-100">
      <div class="small rmi-muted">By Status</div>
      <?php foreach ((array)($summary['by_status'] ?? []) as $k => $v): ?>
        <div class="small"><?= h((string)$k) ?>: <b><?= (int)$v ?></b></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-md-3">
    <div class="rmi-card p-3 h-100">
      <div class="small rmi-muted">Lint Summary</div>
      <div class="small">PASS: <b><?= (int)($summary['lint']['pass'] ?? 0) ?></b></div>
      <div class="small">WARN: <b><?= (int)($summary['lint']['warn'] ?? 0) ?></b></div>
      <div class="small">FAIL: <b><?= (int)($summary['lint']['fail'] ?? 0) ?></b></div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="rmi-card p-3 h-100">
      <div class="small rmi-muted">Last Run RFC</div>
      <div class="small">Primary: <b><?= h((string)($summary['used_last_run']['primary'] ?? 'null')) ?></b></div>
      <div class="small">Related: <b><?= (int)($summary['used_last_run']['related_count'] ?? 0) ?></b></div>
      <div class="small mt-1">Freshness idx/lint/usage (s): <b><?= h((string)($fresh['rfc_index_age_s'] ?? 'null')) ?>/<?= h((string)($fresh['rfc_lint_age_s'] ?? 'null')) ?>/<?= h((string)($fresh['rfc_usage_age_s'] ?? 'null')) ?></b></div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Filters</div>
      <form method="get" class="d-flex gap-2 flex-wrap">
        <select name="status" class="form-select form-select-sm" style="max-width:180px">
          <?php foreach (['ALL','DRAFT','IN_REVIEW','APPROVED','IMPLEMENTED','CLOSED','REJECTED'] as $opt): ?>
            <option value="<?= h($opt) ?>" <?= strtoupper((string)$filters['status']) === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="type" class="form-select form-select-sm" style="max-width:240px">
          <?php foreach ($types as $opt): ?>
            <option value="<?= h($opt) ?>" <?= strtoupper((string)$filters['type']) === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="lint" class="form-select form-select-sm" style="max-width:140px">
          <?php foreach (['ALL','PASS','WARN','FAIL'] as $opt): ?>
            <option value="<?= h($opt) ?>" <?= strtoupper((string)$filters['lint']) === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
        <input name="search" class="form-control form-control-sm" style="max-width:260px" value="<?= h((string)$filters['search']) ?>" placeholder="search id/title/type">
        <select name="env_hint" class="form-select form-select-sm" style="max-width:150px">
          <?php foreach (['staging','production'] as $opt): ?>
            <option value="<?= h($opt) ?>" <?= $envHint === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-rmi" type="submit">Apply</button>
      </form>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-semibold">RFC Table (max <?= (int)$limit ?>)</div>
        <div class="d-flex gap-2 flex-wrap">
          <form method="post" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
            <input type="hidden" name="action" value="REFRESH_INDEX">
            <input type="hidden" name="env_hint" value="<?= h($envHint) ?>">
            <button class="btn btn-sm btn-outline-light" type="submit">Refresh RFC Index</button>
          </form>
          <form method="post" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
            <input type="hidden" name="action" value="RUN_LINT_QUICK">
            <input type="hidden" name="env_hint" value="<?= h($envHint) ?>">
            <button class="btn btn-sm btn-outline-light" type="submit">Run RFC Quality Lint (Quick)</button>
          </form>
          <form method="post" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="csrf_token" value="<?= h((string)csrf_token()) ?>">
            <input type="hidden" name="action" value="RUN_LINT_FULL">
            <input type="hidden" name="env_hint" value="<?= h($envHint) ?>">
            <?php if ($envHint === 'production'): ?><input class="form-control form-control-sm" name="confirm_text" placeholder="RUN_RFC_LINT_FULL" required><?php endif; ?>
            <button class="btn btn-sm btn-rmi" type="submit">Run RFC Quality Lint (Full)</button>
          </form>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th>RFC ID</th><th>Title</th><th>Type</th><th>Target Env</th><th>Status</th><th>Approvals</th><th>Lint</th><th>Schedule</th><th>Used Last Run</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($filtered === []): ?>
              <tr><td colspan="10">No data for selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($filtered as $r): ?>
              <?php
                $status = strtoupper((string)($r['status'] ?? 'UNKNOWN'));
                $lintRes = strtoupper((string)($r['lint_result'] ?? 'UNKNOWN'));
                $comp = count((array)($r['approvals_completed'] ?? []));
                $miss = (array)($r['approvals_missing'] ?? []);
                $req = $comp + count($miss);
              ?>
              <tr>
                <td><a href="rfc_view.php?rfc=<?= urlencode((string)$r['id']) ?>"><?= h((string)$r['id']) ?></a></td>
                <td><?= h((string)$r['title']) ?></td>
                <td><?= h((string)$r['type']) ?></td>
                <td><?= h((string)$r['target_env']) ?></td>
                <td><?= tools_badge($status === 'APPROVED' ? 'HEALTHY' : ($status === 'REJECTED' ? 'CRITICAL' : 'ATTENTION'), $status) ?></td>
                <td class="small"><?= (int)$comp ?>/<?= (int)$req ?><?php if ($miss !== []): ?><div class="text-warning">missing: <?= h(implode(',', $miss)) ?></div><?php endif; ?></td>
                <td><?= tools_badge($lintRes === 'PASS' ? 'HEALTHY' : ($lintRes === 'FAIL' ? 'CRITICAL' : ($lintRes === 'WARN' ? 'ATTENTION' : 'UNKNOWN')), $lintRes) ?><div class="small"><?= h((string)$r['coverage_percent']) ?>%</div></td>
                <td class="small"><?= h((string)($r['schedule'] !== '' ? $r['schedule'] : '-')) ?></td>
                <td><?= tools_badge((string)($r['used_last_run'] === 'PRIMARY' ? 'HEALTHY' : ($r['used_last_run'] === 'RELATED' ? 'ATTENTION' : 'UNKNOWN')), (string)$r['used_last_run']) ?></td>
                <td>
                  <a class="btn btn-sm btn-outline-light" href="rfc_view.php?rfc=<?= urlencode((string)$r['id']) ?>">View</a>
                  <a class="btn btn-sm btn-outline-light" href="rfc_download.php?rfc=<?= urlencode((string)$r['id']) ?>">Download</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Problems (Deploy Blockers)</div>
      <?php if ($issues === []): ?>
        <div class="small"><?= tools_badge('HEALTHY', 'No critical issues detected') ?></div>
      <?php else: ?>
        <ul class="small mb-0">
          <?php foreach ($issues as $i): ?>
            <li><?= tools_badge((string)$i['severity'], (string)$i['severity']) ?> <a href="rfc_view.php?rfc=<?= urlencode((string)$i['rfc_id']) ?>"><?= h((string)$i['rfc_id']) ?></a> - <?= h((string)$i['code']) ?> - <?= h((string)$i['message']) ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>

