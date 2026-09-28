<?php
/**
 * Stock Opname Report Detail
 * Laporan lengkap setelah tim WQS melakukan stok opname
 */
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/_wqs_bootstrap.php';
require_any_permission(['STOCK.CREATE', 'WQS.INCOMING_VIEW', 'WQS.INCOMING_CREATE', 'WQS.INCOMING_EDIT', 'WQS.PICKING_VIEW', 'WQS.PICKING_CREATE', 'WQS.PICKING_EDIT']);

$pdo = wqs_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!function_exists('h')) { function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); } }

// Ensure migrations 111, 128 (pakai query+fetchAll agar tidak ada unbuffered cursor)
foreach (['111_wqs_stock_opname_per_kantor_depo', '128_wqs_stock_opname_evidence'] as $mig) {
    $f = __DIR__ . '/../sql/migrations/' . $mig . '.sql';
    if (is_file($f)) {
        foreach (array_filter(array_map('trim', explode(';', file_get_contents($f)))) as $stmt) {
            if ($stmt !== '' && stripos($stmt, '--') !== 0) {
                try {
                    $st = $pdo->query($stmt);
                    if ($st instanceof \PDOStatement) {
                        $st->fetchAll();
                    }
                } catch (Throwable $e) {}
            }
        }
    }
}

$id = (int)($_GET['id'] ?? 0);
$export = $_GET['export'] ?? '';

$opname = null;
$items = [];
$attachments = [];
$summary = ['total_item' => 0, 'total_match' => 0, 'total_diff' => 0, 'total_plus' => 0, 'total_minus' => 0, 'sum_delta' => 0];

if ($id > 0) {
    $st = $pdo->prepare("SELECT * FROM wqs_stock_opname WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $opname = $st->fetch(PDO::FETCH_ASSOC);
    if ($opname) {
        $st = $pdo->prepare("SELECT * FROM wqs_stock_opname_items WHERE opname_id=? ORDER BY delta_qty DESC, sku");
        $st->execute([$id]);
        $items = $st->fetchAll(PDO::FETCH_ASSOC);
        try {
            $st = $pdo->prepare("SELECT * FROM wqs_stock_opname_attachments WHERE opname_id=? ORDER BY uploaded_at");
            $st->execute([$id]);
            $attachments = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}

        $doCrossRef = [];
        $incCrossRef = [];
        $trfCrossRefMinus = [];
        $trfCrossRefPlus = [];
        $opOffice = strtoupper(trim((string)($opname['office_code'] ?? '')));
        $opDate = (string)($opname['opname_date'] ?? date('Y-m-d'));
        $dateFrom = date('Y-m-d', strtotime($opDate . ' -14 days'));
        $minusPids = [];
        $plusPids = [];
        foreach ($items as $it) {
            $d = (float)($it['delta_qty'] ?? 0);
            if ($d < 0) $minusPids[(int)$it['product_id']] = true;
            elseif ($d > 0) $plusPids[(int)$it['product_id']] = true;
        }
        if (!empty($minusPids) && $opOffice !== '') {
            try {
                $pidList = array_keys($minusPids);
                $ph = implode(',', array_fill(0, count($pidList), '?'));
                $st = $pdo->prepare("
                    SELECT d.id, d.do_code, d.do_date, d.wqs_stock_before, d.wqs_stock_after, i.product_id, i.qty
                    FROM sales_do d
                    JOIN sales_do_items i ON i.do_id = d.id
                    WHERE UPPER(TRIM(COALESCE(d.office_code,''))) = ?
                    AND d.status IN ('ready_scm','on_delivery','delivered','wait_payment','paid')
                    AND i.product_id IN ($ph)
                    AND (
                        (d.wqs_ready_at IS NOT NULL AND d.wqs_ready_at >= ? AND d.wqs_ready_at < DATE_ADD(?, INTERVAL 1 DAY))
                        OR (d.wqs_ready_at IS NULL AND d.do_date >= ? AND d.do_date <= ?)
                    )
                    ORDER BY d.wqs_ready_at DESC, d.do_date DESC
                ");
                $params = array_merge([$opOffice], $pidList, [$dateFrom, $opDate, $dateFrom, $opDate]);
                $st->execute($params);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $pid = (int)$row['product_id'];
                    if (count($doCrossRef[$pid] ?? []) >= 5) continue;
                    $hasFoto = !empty(trim((string)($row['wqs_stock_before'] ?? ''))) && !empty(trim((string)($row['wqs_stock_after'] ?? '')));
                    if (!isset($doCrossRef[$pid])) $doCrossRef[$pid] = [];
                    $doCrossRef[$pid][] = [
                        'do_code' => $row['do_code'] ?? '',
                        'do_id' => (int)$row['id'],
                        'do_date' => $row['do_date'] ?? '',
                        'qty' => (float)($row['qty'] ?? 0),
                        'has_foto' => $hasFoto,
                    ];
                }
            } catch (Throwable $e) {}
        }
        if (!empty($plusPids) && $opOffice !== '') {
            try {
                $hasIncCols = false;
                $chk = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wqs_incoming' AND column_name='wqs_stock_before'");
                if ($chk && (int)$chk->fetchColumn() > 0) $hasIncCols = true;
                if ($hasIncCols) {
                    $pidList = array_keys($plusPids);
                    $ph = implode(',', array_fill(0, count($pidList), '?'));
                    $st = $pdo->prepare("
                        SELECT inc.id, inc.incoming_code, inc.received_date, inc.wqs_stock_before, inc.wqs_stock_after, ii.product_id, ii.qty
                        FROM wqs_incoming inc
                        JOIN wqs_incoming_items ii ON ii.incoming_id = inc.id
                        WHERE UPPER(TRIM(COALESCE(inc.office_code,''))) = ?
                        AND ii.product_id IN ($ph)
                        AND inc.received_date >= ? AND inc.received_date <= ?
                        ORDER BY inc.received_date DESC, inc.id DESC
                    ");
                    $st->execute(array_merge([$opOffice], $pidList, [$dateFrom, $opDate]));
                    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $pid = (int)$row['product_id'];
                        if (count($incCrossRef[$pid] ?? []) >= 5) continue;
                        $hasFoto = !empty(trim((string)($row['wqs_stock_before'] ?? ''))) && !empty(trim((string)($row['wqs_stock_after'] ?? '')));
                        if (!isset($incCrossRef[$pid])) $incCrossRef[$pid] = [];
                        $incCrossRef[$pid][] = [
                            'incoming_code' => $row['incoming_code'] ?? '',
                            'inc_id' => (int)$row['id'],
                            'received_date' => $row['received_date'] ?? '',
                            'qty' => (float)($row['qty'] ?? 0),
                            'has_foto' => $hasFoto,
                        ];
                    }
                }
            } catch (Throwable $e) {}
        }
        try {
            $hasTrf = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='wqs_stock_transfer'")->fetch();
            if ($hasTrf && !empty($minusPids) && $opOffice !== '') {
                $pidList = array_keys($minusPids);
                $ph = implode(',', array_fill(0, count($pidList), '?'));
                $st = $pdo->prepare("
                    SELECT t.id, t.transfer_code, t.transfer_date, t.from_office, t.wqs_stock_before, t.wqs_stock_after, t.foto_fisik_keluar, i.product_id, i.qty
                    FROM wqs_stock_transfer t
                    JOIN wqs_stock_transfer_items i ON i.transfer_id = t.id
                    WHERE UPPER(TRIM(t.from_office)) = ? AND t.status IN ('SENT','RECEIVED')
                    AND i.product_id IN ($ph)
                    AND t.transfer_date >= ? AND t.transfer_date <= ?
                    ORDER BY t.transfer_date DESC
                ");
                $st->execute(array_merge([$opOffice], $pidList, [$dateFrom, $opDate]));
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $pid = (int)$row['product_id'];
                    if (count($trfCrossRefMinus[$pid] ?? []) >= 3) continue;
                    $hasFoto = !empty(trim((string)($row['wqs_stock_before'] ?? ''))) && !empty(trim((string)($row['wqs_stock_after'] ?? ''))) && !empty(trim((string)($row['foto_fisik_keluar'] ?? '')));
                    if (!isset($trfCrossRefMinus[$pid])) $trfCrossRefMinus[$pid] = [];
                    $trfCrossRefMinus[$pid][] = [
                        'transfer_code' => $row['transfer_code'] ?? '',
                        'transfer_id' => (int)$row['id'],
                        'transfer_date' => $row['transfer_date'] ?? '',
                        'qty' => (float)($row['qty'] ?? 0),
                        'has_foto' => $hasFoto,
                    ];
                }
            }
            if ($hasTrf && !empty($plusPids) && $opOffice !== '') {
                $pidList = array_keys($plusPids);
                $ph = implode(',', array_fill(0, count($pidList), '?'));
                $st = $pdo->prepare("
                    SELECT t.id, t.transfer_code, t.transfer_date, t.to_office, t.wqs_stock_before_penerima, t.wqs_stock_after_penerima, t.foto_fisik_masuk, i.product_id, i.qty
                    FROM wqs_stock_transfer t
                    JOIN wqs_stock_transfer_items i ON i.transfer_id = t.id
                    WHERE UPPER(TRIM(t.to_office)) = ? AND t.status = 'RECEIVED'
                    AND i.product_id IN ($ph)
                    AND t.transfer_date >= ? AND t.transfer_date <= ?
                    ORDER BY t.transfer_date DESC
                ");
                $st->execute(array_merge([$opOffice], $pidList, [$dateFrom, $opDate]));
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $pid = (int)$row['product_id'];
                    if (count($trfCrossRefPlus[$pid] ?? []) >= 3) continue;
                    $hasFoto = !empty(trim((string)($row['wqs_stock_before_penerima'] ?? ''))) && !empty(trim((string)($row['wqs_stock_after_penerima'] ?? ''))) && !empty(trim((string)($row['foto_fisik_masuk'] ?? '')));
                    if (!isset($trfCrossRefPlus[$pid])) $trfCrossRefPlus[$pid] = [];
                    $trfCrossRefPlus[$pid][] = [
                        'transfer_code' => $row['transfer_code'] ?? '',
                        'transfer_id' => (int)$row['id'],
                        'transfer_date' => $row['transfer_date'] ?? '',
                        'qty' => (float)($row['qty'] ?? 0),
                        'has_foto' => $hasFoto,
                    ];
                }
            }
        } catch (Throwable $e) {}

        foreach ($items as $it) {
            $summary['total_item']++;
            $d = (float)($it['delta_qty'] ?? 0);
            if ($d == 0) $summary['total_match']++;
            else {
                $summary['total_diff']++;
                if ($d > 0) $summary['total_plus']++;
                else $summary['total_minus']++;
                $summary['sum_delta'] += $d;
            }
        }
    }
} else {
    $filterStatus = $_GET['status'] ?? '';
    $filterOffice = trim($_GET['office'] ?? '');
    $filterDepo = trim($_GET['depo'] ?? '');
    $filterFrom = $_GET['from'] ?? date('Y-m-01');
    $filterTo = $_GET['to'] ?? date('Y-m-d');
    $where = ["o.opname_date BETWEEN ? AND ?"];
    $params = [$filterFrom, $filterTo];
    if ($filterStatus !== '') { $where[] = "o.status=?"; $params[] = $filterStatus; }
    if ($filterOffice !== '') { $where[] = "UPPER(COALESCE(o.office_code,'')) = UPPER(?)"; $params[] = $filterOffice; }
    $opCols = function_exists('table_cols') ? (table_cols($pdo, 'wqs_stock_opname') ?: []) : [];
    if ($filterDepo !== '' && in_array('depo_name', $opCols, true)) { $where[] = "UPPER(COALESCE(o.depo_name,'')) = UPPER(?)"; $params[] = $filterDepo; }
    $st = $pdo->prepare("SELECT o.*, (SELECT COUNT(*) FROM wqs_stock_opname_items i WHERE i.opname_id=o.id) AS item_count,
            (SELECT COUNT(*) FROM wqs_stock_opname_items i WHERE i.opname_id=o.id AND i.delta_qty IS NOT NULL AND i.delta_qty != 0) AS diff_count
            FROM wqs_stock_opname o WHERE " . implode(' AND ', $where) . " ORDER BY o.opname_date DESC, o.id DESC");
    $st->execute($params);
    $list = $st->fetchAll(PDO::FETCH_ASSOC);
}

$bp = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : (string)($GLOBALS['BASE_PROJECT'] ?? '');

if ($id > 0 && !$opname) {
    rmi_redirect('wqs_stock_opname_report.php');
}

if ($export === 'csv' && $opname && $id > 0) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="opname_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $opname['opname_code']) . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $opname['office_code'] ?? '') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Opname', $opname['opname_code'], 'Kantor', $opname['office_code'] ?? '', 'Depo', $opname['depo_name'] ?? '', 'Tanggal', $opname['opname_date'] ?? '']);
    fputcsv($out, ['SKU', 'Produk', 'Unit', 'Qty Sistem', 'Qty Fisik', 'Selisih', 'Keterangan']);
    foreach ($items as $it) {
        fputcsv($out, [
            $it['sku'] ?? '',
            $it['product_name'] ?? '',
            $it['unit'] ?? '',
            $it['qty_system'] ?? 0,
            $it['qty_fisik'] ?? '',
            $it['delta_qty'] ?? 0,
            $it['note'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

rmi_header('Report Stock Opname', [
    'active' => 'stock',
    'subtitle' => 'Laporan detail stok opname — Terjadwal setiap awal minggu',
    'breadcrumbs' => [
        ['label' => 'Stock (WQS)', 'url' => $bp . '/stock/wqs_stock.php'],
        ['label' => 'Stock Opname', 'url' => $bp . '/stock/wqs_stock_opname.php'],
        ['label' => 'Report', 'url' => $bp . '/stock/wqs_stock_opname_report.php'],
    ],
]);
?>

<?php if ($opname && $id > 0): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4>Report Opname: <?= h($opname['opname_code']) ?></h4>
    <div class="text-muted small"><?= h($opname['opname_date']) ?> · <?= h($opname['status']) ?> · Kantor: <?= h($opname['office_code'] ?? '-') ?> · Depo: <?= h($opname['depo_name'] ?? '-') ?></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light btn-sm" href="wqs_stock_opname_report.php?id=<?= $id ?>&export=csv">Export CSV</a>
    <?php if (in_array($opname['status'], ['DRAFT', 'PENDING_VERIFY'])): ?>
    <a class="btn btn-outline-light btn-sm" href="wqs_stock_opname.php?id=<?= $id ?>"><?= $opname['status'] === 'PENDING_VERIFY' ? 'Verify' : 'Edit' ?></a>
    <?php endif; ?>
    <a class="btn btn-outline-light btn-sm" href="wqs_stock_opname.php">← Daftar</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-2">
    <div class="card bg-dark border">
      <div class="card-body py-2">
        <div class="small text-muted">Total Item</div>
        <div class="h4 mb-0"><?= $summary['total_item'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card bg-dark border">
      <div class="card-body py-2">
        <div class="small text-muted">Match (0 selisih)</div>
        <div class="h4 mb-0 text-success"><?= $summary['total_match'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card bg-dark border">
      <div class="card-body py-2">
        <div class="small text-muted">Ada Selisih</div>
        <div class="h4 mb-0 text-warning"><?= $summary['total_diff'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card bg-dark border">
      <div class="card-body py-2">
        <div class="small text-muted">Fisik > Sistem</div>
        <div class="h4 mb-0 text-success">+<?= $summary['total_plus'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card bg-dark border">
      <div class="card-body py-2">
        <div class="small text-muted">Fisik < Sistem</div>
        <div class="h4 mb-0 text-danger"><?= $summary['total_minus'] ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-2">
    <div class="card bg-dark border">
      <div class="card-body py-2">
        <div class="small text-muted">Total Delta</div>
        <div class="h4 mb-0 <?= $summary['sum_delta'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= $summary['sum_delta'] >= 0 ? '+' : '' ?><?= number_format($summary['sum_delta'], 2) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-2">
  <div class="card-body py-2">
    <strong>Info:</strong> Dibuat <?= h($opname['created_at']) ?> oleh <?= h($opname['created_by'] ?? '-') ?>
    <?php if (!empty($opname['submitted_at'])): ?> · Submit <?= h($opname['submitted_at']) ?> oleh <?= h($opname['submitted_by'] ?? '-') ?><?php endif; ?>
    <?php if (!empty($opname['applied_at'])): ?> · Applied <?= h($opname['applied_at']) ?> oleh <?= h($opname['applied_by'] ?? '-') ?><?php endif; ?>
    <?php if (!empty($opname['verified_at'])): ?> · Diverifikasi <?= h($opname['verified_at']) ?> oleh <?= h($opname['verified_by'] ?? '-') ?><?php endif; ?>
    <?php if (!empty($opname['note'])): ?> · <?= h($opname['note']) ?><?php endif; ?>
  </div>
</div>

<?php if (!empty($opname['signature_path'])): ?>
<div class="card mb-2">
  <div class="card-body py-2">
    <strong>Tanda tangan verifikator:</strong>
    <img src="<?= h($bp . $opname['signature_path']) ?>" alt="Paraf" style="max-height:80px; border:1px solid #495057; border-radius:4px;">
  </div>
</div>
<?php endif; ?>

<?php if (!empty($attachments)): ?>
<div class="card mb-2">
  <div class="card-body py-2">
    <strong>Foto fisik:</strong>
    <?php foreach ($attachments as $a): ?>
    <a href="<?= h($bp . $a['file_path']) ?>" target="_blank" class="me-2 text-info"><?= h($a['original_filename'] ?? basename($a['file_path'])) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card mb-2">
  <div class="card-body py-2">
    <strong>Cross-reference DO/Incoming/Transfer:</strong> Selisih minus → DO & Transfer keluar. Selisih plus → Incoming & Transfer masuk. Status foto = bukti kartu stok + foto fisik produk.
  </div>
</div>

<div class="table-responsive">
  <table class="table table-dark table-striped table-sm">
    <thead>
      <tr>
        <th>No</th>
        <th>SKU</th>
        <th>Produk</th>
        <th>Unit</th>
        <th>Qty Sistem</th>
        <th>Qty Fisik</th>
        <th>Selisih</th>
        <th>DO/Incoming Terkait (foto)</th>
        <th>Keterangan</th>
      </tr>
    </thead>
    <tbody>
      <?php $no = 1; foreach ($items as $it): ?>
      <?php $d = (float)($it['delta_qty'] ?? 0); $pid = (int)($it['product_id'] ?? 0); $dos = $doCrossRef[$pid] ?? []; $incs = $incCrossRef[$pid] ?? []; $trfMinus = $trfCrossRefMinus[$pid] ?? []; $trfPlus = $trfCrossRefPlus[$pid] ?? []; ?>
      <tr class="<?= $d != 0 ? 'table-warning' : '' ?>">
        <td><?= $no++ ?></td>
        <td><b><?= h($it['sku']) ?></b></td>
        <td><?= h($it['product_name']) ?></td>
        <td><?= h($it['unit'] ?? '-') ?></td>
        <td><?= number_format((float)($it['qty_system'] ?? 0), 2) ?></td>
        <td><?= $it['qty_fisik'] !== null ? number_format((float)$it['qty_fisik'], 2) : '-' ?></td>
        <td><span class="<?= $d > 0 ? 'text-success fw-bold' : ($d < 0 ? 'text-danger fw-bold' : '') ?>"><?= $d != 0 ? ($d > 0 ? '+' : '') . number_format($d, 2) : '-' ?></span></td>
        <td class="small">
          <?php if ($d < 0): ?>
            <?php foreach ($dos as $do): ?>
            <div>DO <a href="<?= h($bp) ?>/stock/wqs_do_tasks.php" target="_blank"><?= h($do['do_code']) ?></a> · <?= number_format($do['qty'], 0) ?> pcs · <span class="<?= $do['has_foto'] ? 'text-success' : 'text-warning' ?>"><?= $do['has_foto'] ? '✓' : '✗' ?></span></div>
            <?php endforeach; ?>
            <?php foreach ($trfMinus as $tr): ?>
            <div>TRF <a href="<?= h($bp) ?>/stock/wqs_stock_transfer.php?view=<?= $tr['transfer_id'] ?>" target="_blank"><?= h($tr['transfer_code']) ?></a> · <?= number_format($tr['qty'], 0) ?> pcs · <span class="<?= $tr['has_foto'] ? 'text-success' : 'text-warning' ?>"><?= $tr['has_foto'] ? '✓' : '✗' ?></span></div>
            <?php endforeach; ?>
            <?php if (empty($dos) && empty($trfMinus)): ?><span class="text-muted">Tidak ada DO/Transfer terkait</span><?php endif; ?>
          <?php elseif ($d > 0): ?>
            <?php foreach ($incs as $inc): ?>
            <div>INC <a href="<?= h($bp) ?>/stock/wqs_incoming_view.php?code=<?= urlencode($inc['incoming_code']) ?>" target="_blank"><?= h($inc['incoming_code']) ?></a> · <?= number_format($inc['qty'], 0) ?> pcs · <span class="<?= $inc['has_foto'] ? 'text-success' : 'text-warning' ?>"><?= $inc['has_foto'] ? '✓' : '✗' ?></span></div>
            <?php endforeach; ?>
            <?php foreach ($trfPlus as $tr): ?>
            <div>TRF <a href="<?= h($bp) ?>/stock/wqs_stock_transfer.php?view=<?= $tr['transfer_id'] ?>" target="_blank"><?= h($tr['transfer_code']) ?></a> · <?= number_format($tr['qty'], 0) ?> pcs · <span class="<?= $tr['has_foto'] ? 'text-success' : 'text-warning' ?>"><?= $tr['has_foto'] ? '✓' : '✗' ?></span></div>
            <?php endforeach; ?>
            <?php if (empty($incs) && empty($trfPlus)): ?><span class="text-muted">Tidak ada Incoming/Transfer terkait</span><?php endif; ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td><?= h($it['note'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div>
    <h4>Report Stock Opname</h4>
    <div class="text-muted small">Pilih opname untuk melihat report detail.</div>
  </div>
  <a class="btn btn-outline-light btn-sm" href="<?= h($bp) ?>/stock/wqs_stock_opname.php">← Opname</a>
</div>

<form method="get" class="mb-3 row g-2 align-items-end">
  <div class="col-md-2">
    <label class="form-label small">Status</label>
    <select name="status" class="form-select form-select-sm">
      <option value="">Semua</option>
      <option value="DRAFT" <?= ($_GET['status'] ?? '')==='DRAFT'?'selected':'' ?>>Draft</option>
      <option value="APPLIED" <?= ($_GET['status'] ?? '')==='APPLIED'?'selected':'' ?>>Applied</option>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label small">Kantor</label>
    <select name="office" class="form-select form-select-sm">
      <option value="">Semua</option>
      <?php
      $offices = [];
      try { $offices = $pdo->query("SELECT DISTINCT office_code FROM wqs_stock_opname WHERE office_code IS NOT NULL AND office_code != '' ORDER BY office_code")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
      foreach ($offices as $oc): ?>
      <option value="<?= h($oc) ?>" <?= ($_GET['office'] ?? '')===$oc?'selected':'' ?>><?= h($oc) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label small">Depo</label>
    <select name="depo" class="form-select form-select-sm">
      <option value="">Semua</option>
      <?php
      $depos = [];
      try { $depos = $pdo->query("SELECT DISTINCT depo_name FROM wqs_stock_opname WHERE depo_name IS NOT NULL AND depo_name != '' ORDER BY depo_name")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
      foreach ($depos as $d): ?>
      <option value="<?= h($d) ?>" <?= ($_GET['depo'] ?? '')===$d?'selected':'' ?>><?= h($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label small">Dari</label>
    <input type="date" name="from" class="form-control form-control-sm" value="<?= h($_GET['from'] ?? date('Y-m-01')) ?>">
  </div>
  <div class="col-md-2">
    <label class="form-label small">Sampai</label>
    <input type="date" name="to" class="form-control form-control-sm" value="<?= h($_GET['to'] ?? date('Y-m-d')) ?>">
  </div>
  <div class="col-md-2">
    <button type="submit" class="btn btn-outline-light btn-sm">Filter</button>
  </div>
</form>

<div class="table-responsive">
  <table class="table table-dark table-striped">
    <thead>
      <tr>
        <th>Kode</th>
        <th>Tanggal</th>
        <th>Kantor</th>
        <th>Depo</th>
        <th>Status</th>
        <th>Item</th>
        <th>Selisih</th>
        <th>Dibuat</th>
        <th>Aksi</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($list ?? [] as $o): ?>
      <tr>
        <td><b><?= h($o['opname_code']) ?></b></td>
        <td><?= h($o['opname_date']) ?></td>
        <td><?= h($o['office_code'] ?? '-') ?></td>
        <td><?= h($o['depo_name'] ?? '-') ?></td>
        <td><span class="badge <?= $o['status']==='APPLIED'?'bg-success':'bg-warning' ?>"><?= h($o['status']) ?></span></td>
        <td><?= (int)($o['item_count'] ?? 0) ?></td>
        <td><?= (int)($o['diff_count'] ?? 0) ?></td>
        <td><?= h($o['created_at'] ?? '') ?></td>
        <td>
          <a class="btn btn-sm btn-outline-light" href="wqs_stock_opname_report.php?id=<?= $o['id'] ?>">Report Detail</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if (empty($list ?? [])): ?><div class="text-muted">Tidak ada data opname untuk periode ini.</div><?php endif; ?>
<?php endif; ?>

<?php rmi_footer(); ?>
