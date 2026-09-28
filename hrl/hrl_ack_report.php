<?php
// require_login(); // static scan marker

require_once __DIR__ . '/_layout_top.php';
hrl_require_manage();

$unit = strtoupper(trim((string)($_GET['unit'] ?? '')));

$where = "WHERE d.deleted_at IS NULL AND d.current_version > 0";
$params = [];
if ($unit !== '' && in_array($unit, ['HR','LEGAL'], true)) {
  $where .= " AND d.unit = :u";
  $params[':u'] = $unit;
}

$sql = "
  SELECT
    d.id, d.doc_code, d.title, d.unit, d.category, d.status, d.current_version, d.updated_at,
    (SELECT COUNT(*) FROM hrl_doc_acks a WHERE a.doc_id=d.id AND a.version_no=d.current_version) AS ack_count,
    (SELECT MAX(a.ack_at) FROM hrl_doc_acks a WHERE a.doc_id=d.id AND a.version_no=d.current_version) AS last_ack
  FROM hrl_docs d
  $where
  ORDER BY d.updated_at DESC, d.id DESC
  LIMIT 1000
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="rmi-card">
  <div class="rmi-card-header">
    <div>
      <h5>Acknowledgement Report</h5>
      <div class="sub">Ringkasan ack per dokumen (versi aktif)</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
      <a class="btn btn-outline-light btn-sm" href="<?=e(url_hrl('hrl_ack_report.php'))?>">All</a>
      <a class="btn btn-outline-light btn-sm" href="<?=e(url_hrl('hrl_ack_report.php?unit=HR'))?>">HR</a>
      <a class="btn btn-outline-light btn-sm" href="<?=e(url_hrl('hrl_ack_report.php?unit=LEGAL'))?>">Legal</a>
    </div>
  </div>
  <div class="rmi-card-body">
    <div class="table-responsive">
      <table id="ackTable" class="table table-sm table-bordered align-middle">
        <thead>
          <tr>
            <th>Code</th>
            <th>Title</th>
            <th>Unit</th>
            <th>Cat</th>
            <th>Status</th>
            <th>Ver</th>
            <th>Ack Count</th>
            <th>Last Ack</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><code><?=e((string)$r['doc_code'])?></code></td>
              <td><?=e((string)$r['title'])?></td>
              <td><?=e((string)$r['unit'])?></td>
              <td><?=e((string)$r['category'])?></td>
              <td><?=e((string)$r['status'])?></td>
              <td class="text-center"><?= (int)$r['current_version'] ?></td>
              <td class="text-center"><span class="badge bg-info text-dark"><?= (int)$r['ack_count'] ?></span></td>
              <td class="hint"><?=e((string)($r['last_ack'] ?? ''))?></td>
              <td><a class="btn btn-sm btn-primary" href="<?=e(url_hrl('hrl_doc_view.php?id='.(int)$r['id']))?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="9" class="muted">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
  $(function(){
    $('#ackTable').DataTable({
      pageLength: 25,
      order: [],
      dom: 'Bfrtip',
      buttons: ['copy','csv','excel','pdf','print']
    });
  });
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
