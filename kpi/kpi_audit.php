<?php
if (!function_exists('rmi_icon')) { require_once __DIR__ . '/../_shared/rmi_icons.php'; }
// Login/session is enforced by _kpi_bootstrap.php.
require_once __DIR__ . '/_kpi_bootstrap.php';
$pdo = kpi_require_pdo();

require_once __DIR__ . '/../_shared/rbac.php';
if (!function_exists('rbac_require')) {
    http_response_code(500);
    exit('RBAC unavailable');
}

// Audit log is a cross-module KPI control page, not DO Audit only.
// Keep backward compatibility if the new permission has not yet been registered.
try {
    rbac_require($pdo, 'KPI.AUDIT_LOG');
} catch (Throwable $e) {
    rbac_require($pdo, 'KPI.DO_AUDIT');
}

if (!kpi_table_exists($pdo, 'kpi_audit_log')) {
    kpi_header('KPI Audit Log');
    kpi_nav('audit');
    echo "<div class='card' style='border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.08);padding:14px 16px'>
        <span class='badge danger'>MISSING</span>
        Tabel <code>kpi_audit_log</code> belum ada.
        Jalankan SQL: <code>kpi/kpi_enterprise_tables.sql</code>
    </div>";
    kpi_footer();
    exit;
}

$module = trim((string)($_GET['m'] ?? ''));
$action = strtoupper(trim((string)($_GET['a'] ?? '')));
$actor  = trim((string)($_GET['u'] ?? ''));
$dateFrom = trim((string)($_GET['from'] ?? ''));
$dateTo   = trim((string)($_GET['to'] ?? ''));

$isValidDate = static function (string $value): bool {
    if ($value === '') return true;
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt instanceof DateTime && $dt->format('Y-m-d') === $value;
};
if (!$isValidDate($dateFrom) || !$isValidDate($dateTo)) {
    http_response_code(400);
    exit('Format tanggal filter tidak valid. Gunakan YYYY-MM-DD.');
}
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    http_response_code(400);
    exit('Tanggal awal tidak boleh lebih besar dari tanggal akhir.');
}

$where = [];
$params = [];

// Partial search is more useful for operational audit investigation.
if ($module !== '') {
    $where[] = 'module LIKE :module';
    $params[':module'] = '%' . $module . '%';
}
if ($action !== '') {
    $where[] = 'UPPER(action) LIKE :action';
    $params[':action'] = '%' . $action . '%';
}
if ($actor !== '') {
    $where[] = 'actor LIKE :actor';
    $params[':actor'] = '%' . $actor . '%';
}
if ($dateFrom !== '') {
    $where[] = 'created_at >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'created_at < :date_to_next';
    $params[':date_to_next'] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

// Export must run before any HTML output so response headers remain valid.
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportSql = 'SELECT id, created_at, module, action, ref_code, actor, detail '
        . 'FROM kpi_audit_log' . $whereSql . ' ORDER BY id DESC LIMIT 5000';
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute($params);
    $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);
    kpi_csv_download('kpi_audit_' . date('Ymd_His') . '.csv', $exportRows);
    exit;
}

$listSql = 'SELECT id, created_at, module, action, ref_code, actor, detail '
    . 'FROM kpi_audit_log' . $whereSql . ' ORDER BY id DESC LIMIT 500';
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Count matching records independently from the 500-row preview.
$countSql = 'SELECT COUNT(*) FROM kpi_audit_log' . $whereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalMatching = (int)$countStmt->fetchColumn();

// Group stats over the same filter, independent from preview limit.
$statsSql = 'SELECT UPPER(action) AS action_name, COUNT(*) AS total '
    . 'FROM kpi_audit_log' . $whereSql . ' GROUP BY UPPER(action) ORDER BY total DESC LIMIT 8';
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($params);
$actionStats = $statsStmt->fetchAll(PDO::FETCH_ASSOC);

$actionColors = [
    'CREATE'            => ['#bbf7d0', 'rgba(34,197,94,.18)'],
    'UPDATE'            => ['#bfdbfe', 'rgba(59,130,246,.18)'],
    'DELETE'            => ['#fecaca', 'rgba(239,68,68,.18)'],
    'IMPORT'            => ['#e9d5ff', 'rgba(139,92,246,.18)'],
    'BULK'              => ['#fde68a', 'rgba(245,158,11,.18)'],
    'SNAPSHOT'          => ['#a5f3fc', 'rgba(6,182,212,.18)'],
    'LOCK'              => ['#fca5a5', 'rgba(239,68,68,.25)'],
    'UNLOCK'            => ['#86efac', 'rgba(34,197,94,.25)'],
    'POLICY_SAVE'       => ['#fde68a', 'rgba(245,158,11,.18)'],
    'SYNC_SYSTEM_START' => ['#c4b5fd', 'rgba(139,92,246,.16)'],
    'SYNC_SYSTEM'       => ['#93c5fd', 'rgba(59,130,246,.16)'],
    'SYNC_AUDIT_START'  => ['#c4b5fd', 'rgba(139,92,246,.16)'],
    'SYNC_AUDIT'        => ['#67e8f9', 'rgba(6,182,212,.16)'],
    'APPROVE'           => ['#86efac', 'rgba(34,197,94,.22)'],
    'REJECT'            => ['#fda4af', 'rgba(244,63,94,.22)'],
];

kpi_header('KPI Audit Log');
kpi_nav('audit');
?>

<div class="card">
  <div class="kpi-header-row">
    <div>
      <h3 class="kpi-section-title"><?=rmi_icon('search')?> Filter Audit Log</h3>
      <div class="muted" style="font-size:12px;margin-top:4px">
        Audit bersifat read-only. Sumber berasal dari aksi create/update/delete/import/sync/final/lock di seluruh modul KPI.
      </div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <a class="btn" href="kpi_audit.php?export=csv&m=<?= urlencode($module) ?>&a=<?= urlencode($action) ?>&u=<?= urlencode($actor) ?>&from=<?= urlencode($dateFrom) ?>&to=<?= urlencode($dateTo) ?>"><?=rmi_icon('inbox')?> Export CSV</a>
      <?php if ($module !== '' || $action !== '' || $actor !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
        <a class="btn secondary" href="kpi_audit.php"><?=rmi_icon('x')?> Reset Filter</a>
      <?php endif; ?>
    </div>
  </div>

  <form method="get" class="kpi-form-row">
    <div class="kpi-field">
      <label>Module</label>
      <input name="m" value="<?= h($module) ?>" placeholder="kpi_office, kpi_employee…">
    </div>
    <div class="kpi-field">
      <label>Action</label>
      <input name="a" value="<?= h($action) ?>" placeholder="SYNC, CREATE, LOCK…">
    </div>
    <div class="kpi-field">
      <label>Actor</label>
      <input name="u" value="<?= h($actor) ?>" placeholder="username">
    </div>
    <div class="kpi-field">
      <label>Dari tanggal</label>
      <input type="date" name="from" value="<?= h($dateFrom) ?>">
    </div>
    <div class="kpi-field">
      <label>Sampai tanggal</label>
      <input type="date" name="to" value="<?= h($dateTo) ?>">
    </div>
    <div class="kpi-field" style="justify-content:flex-end">
      <button class="btn ok" type="submit">Filter</button>
    </div>
  </form>
</div>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px">
  <div style="background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:8px 16px;font-size:12px;color:#94a3b8">
    Menampilkan <b style="color:#f1f5f9"><?= count($rows) ?></b> dari <b style="color:#f1f5f9"><?= $totalMatching ?></b> record cocok
  </div>
  <?php foreach ($actionStats as $stat):
      $actName = strtoupper((string)($stat['action_name'] ?? ''));
      [$color, $bg] = $actionColors[$actName] ?? ['#e2e8f0', 'rgba(255,255,255,.08)'];
  ?>
    <div style="background:<?= h($bg) ?>;border:1px solid <?= h($color) ?>33;border-radius:10px;padding:8px 14px;font-size:12px;color:<?= h($color) ?>">
      <?= h($actName) ?> <b><?= (int)($stat['total'] ?? 0) ?></b>
    </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h3 class="kpi-section-title"><?=rmi_icon('clipboard')?> Audit Log<?= ($module !== '' || $action !== '' || $actor !== '' || $dateFrom !== '' || $dateTo !== '') ? ' <span class="badge" style="font-size:10px;margin-left:6px">Filtered</span>' : '' ?></h3>

  <?php if (!$rows): ?>
    <div style="text-align:center;padding:28px;color:#64748b;font-size:13px">Tidak ada log yang cocok dengan filter.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>#ID</th>
            <th>Waktu</th>
            <th>Module</th>
            <th>Action</th>
            <th>Ref</th>
            <th>Actor</th>
            <th>Detail</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row):
              $act = strtoupper((string)($row['action'] ?? ''));
              [$aColor, $aBg] = $actionColors[$act] ?? ['#e2e8f0', 'rgba(255,255,255,.06)'];
              $detail = (string)($row['detail'] ?? '');
          ?>
            <tr>
              <td style="color:#64748b;font-size:11px"><?= h((string)($row['id'] ?? '')) ?></td>
              <td style="white-space:nowrap;font-size:11px;color:#94a3b8"><?= h((string)($row['created_at'] ?? '')) ?></td>
              <td><code style="font-size:11px;color:#93c5fd;background:rgba(59,130,246,.12);padding:2px 7px;border-radius:5px"><?= h((string)($row['module'] ?? '')) ?></code></td>
              <td><span style="background:<?= h($aBg) ?>;color:<?= h($aColor) ?>;font-size:10px;font-weight:700;padding:2px 9px;border-radius:8px;white-space:nowrap"><?= h($act) ?></span></td>
              <td style="font-size:11px;color:#94a3b8;font-family:monospace"><?= h((string)(($row['ref_code'] ?? '') !== '' ? $row['ref_code'] : '—')) ?></td>
              <td><span style="background:rgba(139,92,246,.12);color:#c4b5fd;font-size:11px;font-weight:600;padding:2px 8px;border-radius:6px"><?= h((string)($row['actor'] ?? 'SYSTEM')) ?></span></td>
              <td class="muted" style="max-width:420px;white-space:normal;word-break:break-word" title="<?= h($detail) ?>"><?= h($detail !== '' ? $detail : '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php kpi_footer();