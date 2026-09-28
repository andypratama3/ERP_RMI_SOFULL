<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/evidence_export.php';

opsgov_require_admin();
$root = opsgov_root();
$msg = ''; $type = 'info';
$verifyRefreshAt = '';
$packRoots = [
    $root . '/exports/evidence/*/*.zip',
    $root . '/storage/logs/evidence_pack/*/*.zip',
];

if (!function_exists('evidence_verify_zip_manifest')) {
    function evidence_verify_zip_manifest(string $zipPath): array
    {
        if (!class_exists('ZipArchive')) {
            return ['status' => 'WARN', 'ok' => 0, 'fail' => 0, 'note' => 'ZipArchive unavailable'];
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['status' => 'WARN', 'ok' => 0, 'fail' => 0, 'note' => 'ZIP cannot be opened'];
        }
        $manifestRaw = $zip->getFromName('manifest.json');
        if (!is_string($manifestRaw) || $manifestRaw === '') {
            $zip->close();
            return ['status' => 'WARN', 'ok' => 0, 'fail' => 0, 'note' => 'manifest.json missing'];
        }
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest)) {
            $zip->close();
            return ['status' => 'WARN', 'ok' => 0, 'fail' => 0, 'note' => 'manifest.json invalid'];
        }
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $okCount = 0;
        $failCount = 0;
        foreach ($files as $row) {
            if (!is_array($row)) continue;
            $name = (string)($row['file'] ?? '');
            $sha = strtolower((string)($row['sha256'] ?? ''));
            if ($name === '' || $sha === '') continue;
            $content = $zip->getFromName($name);
            if (!is_string($content) || $content === '') {
                $failCount++;
                continue;
            }
            $actual = strtolower(hash('sha256', $content));
            if (hash_equals($sha, $actual)) {
                $okCount++;
            } else {
                $failCount++;
            }
        }
        $zip->close();
        $status = $failCount > 0 ? 'WARN' : 'OK';
        return ['status' => $status, 'ok' => $okCount, 'fail' => $failCount, 'note' => $failCount > 0 ? 'Checksum mismatch detected' : 'Checksums verified'];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $action = (string)($_POST['action'] ?? 'generate');
    if ($action === 'reverify') {
        $verifyRefreshAt = date('Y-m-d H:i:s');
        ts_append_run_history('compliance_evidence_reverify', 'OK', [
            'source' => 'tools/compliance/evidence_index.php',
            'refreshed_at' => $verifyRefreshAt,
        ]);
        $msg = 'Re-verify dijalankan. Status checksum telah diperbarui.';
        $type = 'info';
    } else {
        $start = (string)($_POST['start_date'] ?? date('Y-m-01'));
        $end = (string)($_POST['end_date'] ?? date('Y-m-t'));
        $run = compliance_export_evidence($start, $end);
        $msg = $run['ok'] ? 'Evidence pack berhasil dibuat: ' . (string)($run['zip_masked'] ?? '-') : (string)($run['message'] ?? 'Export gagal');
        $type = $run['ok'] ? 'success' : 'danger';
    }
}

if (isset($_GET['download'])) {
    $f = (string)$_GET['download'];
    $packsNow = [];
    foreach ($packRoots as $g) {
        $packsNow = array_merge($packsNow, glob($g) ?: []);
    }
    $allowed = [];
    foreach ($packsNow as $p) {
        $rp = realpath($p);
        if ($rp && is_file($rp) && str_ends_with(strtolower($rp), '.zip')) $allowed[$rp] = true;
    }
    $real = realpath($f);
    if ($real && str_ends_with(strtolower($real), '.zip') && isset($allowed[$real])) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($real) . '"');
        header('Content-Length: ' . (string)filesize($real));
        readfile($real);
        exit;
    }
    $msg = 'File tidak valid.';
    $type = 'danger';
}

$packs = [];
foreach ($packRoots as $g) {
    $packs = array_merge($packs, glob($g) ?: []);
}
rsort($packs);
$quickFilter = strtolower(trim((string)($_GET['filter'] ?? 'all')));
if (!in_array($quickFilter, ['all', 'warn'], true)) {
    $quickFilter = 'all';
}
$verifyByPack = [];
$verifyOkTotal = 0;
$verifyWarnTotal = 0;
foreach ($packs as $p) {
    $verify = evidence_verify_zip_manifest($p);
    $verifyByPack[$p] = $verify;
    if (($verify['status'] ?? 'WARN') === 'OK') {
        $verifyOkTotal++;
    } else {
        $verifyWarnTotal++;
    }
}
$visiblePacks = array_values(array_filter($packs, static function (string $p) use ($quickFilter, $verifyByPack): bool {
    if ($quickFilter === 'warn') {
        return (string)(($verifyByPack[$p]['status'] ?? 'WARN')) === 'WARN';
    }
    return true;
}));

$baseProject = rmi_layout_base_project();
rmi_header('Compliance Evidence Pack', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Compliance Evidence Pack']]);
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card p-3">
      <?php if ($msg !== ''): ?><div class="alert alert-<?= opsgov_h($type) ?>"><?= opsgov_h($msg) ?></div><?php endif; ?>
      <div class="fw-semibold mb-2">Generate Evidence (One Click)</div>
      <form method="post" class="row g-2">
        <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
        <div class="col-6"><label class="form-label small">Start Date</label><input class="form-control form-control-sm" type="date" name="start_date" value="<?= opsgov_h(date('Y-m-01')) ?>"></div>
        <div class="col-6"><label class="form-label small">End Date</label><input class="form-control form-control-sm" type="date" name="end_date" value="<?= opsgov_h(date('Y-m-t')) ?>"></div>
        <div class="col-12"><button class="btn btn-sm btn-rmi">Generate ZIP</button></div>
      </form>
      <div class="small text-muted mt-2">Jika source log belum tersedia, exporter akan tetap jalan dan menandai data yang tersedia saja (graceful).</div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card p-3">
      <div class="fw-semibold mb-2">Evidence Packs</div>
      <div class="d-flex gap-2 align-items-center mb-2">
        <form method="post" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
          <input type="hidden" name="action" value="reverify">
          <button class="btn btn-sm btn-outline-light" type="submit">Re-verify all packs</button>
        </form>
        <a class="btn btn-sm <?= $quickFilter === 'all' ? 'btn-rmi' : 'btn-outline-light' ?>" href="?filter=all">Show All</a>
        <a class="btn btn-sm <?= $quickFilter === 'warn' ? 'btn-warning' : 'btn-outline-light' ?>" href="?filter=warn">Show Only WARN</a>
        <span class="small text-muted">OK: <?= opsgov_h((string)$verifyOkTotal) ?> • WARN: <?= opsgov_h((string)$verifyWarnTotal) ?></span>
        <?php if ($verifyRefreshAt !== ''): ?><span class="small text-muted">refreshed: <?= opsgov_h($verifyRefreshAt) ?></span><?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-dark-custom">
          <thead><tr><th>File</th><th>Size</th><th>Verify</th><th>Action</th></tr></thead>
          <tbody>
          <?php if (!$visiblePacks): ?><tr><td colspan="4" class="text-center muted"><?= $quickFilter === 'warn' ? 'Tidak ada pack WARN.' : 'Belum ada evidence pack.' ?></td></tr><?php endif; ?>
          <?php foreach ($visiblePacks as $p): ?>
            <?php $verify = (array)($verifyByPack[$p] ?? ['status' => 'WARN', 'ok' => 0, 'fail' => 0]); ?>
            <tr>
              <td><code><?= opsgov_h(opsgov_mask($p)) ?></code></td>
              <td><?= opsgov_h(number_format(((int)filesize($p))/1024, 1)) ?> KB</td>
              <td class="small">
                <?= tools_badge((string)$verify['status']) ?>
                <span class="text-muted">ok:<?= opsgov_h((string)$verify['ok']) ?> fail:<?= opsgov_h((string)$verify['fail']) ?></span>
              </td>
              <td><a class="btn btn-sm btn-outline-light" href="?download=<?= urlencode($p) ?>">Download</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
