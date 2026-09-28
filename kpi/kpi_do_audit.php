<?php
// require_login(); // login enforced via _kpi_bootstrap.php
// DO Audit Viewer - CRM -> WQS -> SCM -> ACT -> FIN
require_once __DIR__ . '/_kpi_bootstrap.php';
require_once __DIR__ . '/_kpi_metrics.php';

if (function_exists('require_any_permission')) {
    require_any_permission(['KPI.VIEW', 'SALES.AUDIT']);
}

$pdo = kpi_require_pdo();
kpi_ensure_sales_do_audit($pdo);

if (!kpi_table_exists($pdo, 'sales_do_audit')) {
    kpi_header('DO Audit');
    kpi_nav('do_audit');
    echo "<div class='card'><span class='badge danger'>MISSING</span> Tabel <b>sales_do_audit</b> tidak ditemukan.</div>";
    kpi_footer();
    exit;
}

/**
 * Resolve departemen asli actor dari master user secara defensif.
 * Tidak mengubah data audit lama; hanya memperbaiki tampilan/export.
 */
function kpi_do_audit_actor_dept_map(PDO $pdo, array $actorNames): array {
    $actorNames = array_values(array_unique(array_filter(array_map(
        static fn($v) => trim((string)$v),
        $actorNames
    ), static fn($v) => $v !== '')));
    if (!$actorNames) return [];

    $userTable = null;
    foreach (['master_user','master_users','users','user'] as $candidate) {
        if (kpi_table_exists($pdo, $candidate)) { $userTable = $candidate; break; }
    }
    if (!$userTable) return [];

    $cols = kpi_table_columns($pdo, $userTable);
    $identityCols = [];
    foreach (['user_code','username','user_name','name','full_name','employee_code','email'] as $c) {
        if (isset($cols[$c])) $identityCols[] = $c;
    }
    if (!$identityCols) return [];

    $deptDirectCol = kpi_pick_col($cols, ['department_code','dept_code','department','dept','departement_code','departements_code']);
    $deptIdCol = kpi_pick_col($cols, ['department_id','dept_id','departement_id','departements_id']);

    $deptTable = $deptPk = $deptCode = null;
    if (!$deptDirectCol && $deptIdCol) {
        foreach (['master_departements','master_departments','departements','departments'] as $candidate) {
            if (!kpi_table_exists($pdo, $candidate)) continue;
            $dcols = kpi_table_columns($pdo, $candidate);
            $pk = kpi_pick_col($dcols, ['id','department_id','dept_id']);
            $code = kpi_pick_col($dcols, ['departements_code','department_code','dept_code','code','name','department_name','departements_name']);
            if ($pk && $code) { $deptTable=$candidate; $deptPk=$pk; $deptCode=$code; break; }
        }
    }
    if (!$deptDirectCol && !($deptTable && $deptIdCol && $deptPk && $deptCode)) return [];

    $ph = implode(',', array_fill(0, count($actorNames), '?'));
    $identityWhere = []; $params = [];
    foreach ($identityCols as $col) {
        $identityWhere[] = "LOWER(TRIM(u.`{$col}`)) IN ({$ph})";
        foreach ($actorNames as $name) $params[] = strtolower($name);
    }
    $selectIdentity = implode(', ', array_map(static fn($c) => "u.`{$c}`", $identityCols));
    if ($deptDirectCol) { $deptExpr="u.`{$deptDirectCol}`"; $join=''; }
    else { $deptExpr="dp.`{$deptCode}`"; $join=" LEFT JOIN `{$deptTable}` dp ON dp.`{$deptPk}` = u.`{$deptIdCol}` "; }

    $sql = "SELECT {$selectIdentity}, {$deptExpr} AS resolved_dept FROM `{$userTable}` u {$join} WHERE " . implode(' OR ', $identityWhere);
    try {
        $st=$pdo->prepare($sql); $st->execute($params); $map=[];
        while ($row=$st->fetch(PDO::FETCH_ASSOC)) {
            $resolved=strtoupper(trim((string)($row['resolved_dept'] ?? '')));
            if ($resolved==='') continue;
            foreach ($identityCols as $col) {
                $key=strtolower(trim((string)($row[$col] ?? '')));
                if ($key!=='') $map[$key]=$resolved;
            }
        }
        return $map;
    } catch (Throwable $e) { return []; }
}

function kpi_do_audit_transition_meta(string $from, string $to, string $dept): array {
    $from = strtolower(trim($from));
    $to   = strtolower(trim($to));
    $dept = strtoupper(trim($dept));

    $normal = [
        'new>crm_to_wqs'              => ['CRM', 'CRM kirim ke WQS'],
        'draft>crm_to_wqs'            => ['CRM', 'CRM kirim ke WQS'],
        'crm_to_wqs>wqs_processing'   => ['WQS', 'WQS mulai proses'],
        'wqs_processing>ready_scm'    => ['WQS', 'WQS selesai, serah ke SCM'],
        'ready_scm>on_delivery'       => ['SCM', 'SCM mulai pengiriman'],
        'on_delivery>delivered'       => ['SCM', 'Barang diterima customer'],
        'delivered>wait_payment'      => ['ACT', 'ACT selesai, serah ke FIN'],
        'wait_payment>paid'           => ['FIN', 'Pembayaran selesai'],
        'wait_payment>fin_done'       => ['FIN', 'Pembayaran selesai'],
    ];

    $key = $from . '>' . $to;
    if (isset($normal[$key])) {
        return [
            'type' => 'NORMAL',
            'process_dept' => $normal[$key][0],
            'label' => $normal[$key][1],
        ];
    }

    $joined = $from . ' ' . $to;
    if (str_contains($joined, 'revision') || str_contains($joined, 'revisi')) {
        return ['type'=>'REVISION', 'process_dept'=>$dept ?: 'CRM', 'label'=>'Alur revisi DO'];
    }
    if (str_contains($joined, 'return') || str_contains($joined, 'retur')) {
        return ['type'=>'RETURN', 'process_dept'=>$dept ?: 'SCM', 'label'=>'Alur retur DO'];
    }
    if (str_contains($joined, 'cancel') || str_contains($joined, 'void')) {
        return ['type'=>'CANCEL', 'process_dept'=>$dept, 'label'=>'Pembatalan DO'];
    }

    return ['type'=>'OTHER', 'process_dept'=>$dept, 'label'=>'Transisi lain'];
}

$monthYm = trim((string)($_GET['month'] ?? '')) ?: date('Y-m');
[$d1, $d2] = kpi_month_range($monthYm);

$dept   = strtoupper(trim((string)($_GET['dept'] ?? '')));
$actor  = trim((string)($_GET['actor'] ?? ''));
$doId   = trim((string)($_GET['do_id'] ?? ''));
$office = strtoupper(trim((string)($_GET['office'] ?? '')));
$doCode = trim((string)($_GET['do_code'] ?? ''));
$type   = strtoupper(trim((string)($_GET['type'] ?? '')));

$hasSalesDo = kpi_table_exists($pdo, 'sales_do');
$sdCols = $hasSalesDo ? kpi_table_columns($pdo, 'sales_do') : [];
$sdIdCol = $hasSalesDo ? (kpi_pick_col($sdCols, ['id']) ?: 'id') : null;
$sdCodeCol = $hasSalesDo ? kpi_pick_col($sdCols, ['do_code','code']) : null;
$sdOfficeCol = $hasSalesDo ? kpi_pick_col($sdCols, ['office_code','office']) : null;

$where = ["a.created_at BETWEEN :d1 AND :d2"];
$params = [':d1'=>$d1.' 00:00:00', ':d2'=>$d2.' 23:59:59'];

if ($actor !== '') {
    $where[] = 'a.actor_name LIKE :actor';
    $params[':actor'] = '%'.$actor.'%';
}
if ($doId !== '' && ctype_digit($doId)) {
    $where[] = 'a.do_id = :do_id';
    $params[':do_id'] = (int)$doId;
}
if ($office !== '' && $hasSalesDo && $sdOfficeCol) {
    $where[] = "UPPER(COALESCE(d.{$sdOfficeCol},'')) = :office";
    $params[':office'] = $office;
}
if ($doCode !== '' && $hasSalesDo && $sdCodeCol) {
    $where[] = "d.{$sdCodeCol} LIKE :do_code";
    $params[':do_code'] = '%'.$doCode.'%';
}

$select = [
    'a.id', 'a.do_id', 'a.status_from', 'a.status_to',
    'a.actor_dept', 'a.actor_name', 'a.note', 'a.created_at'
];
$join = '';
if ($hasSalesDo && $sdIdCol) {
    $join = " LEFT JOIN sales_do d ON d.{$sdIdCol} = a.do_id ";
    $select[] = $sdCodeCol ? "d.{$sdCodeCol} AS do_code" : "NULL AS do_code";
    $select[] = $sdOfficeCol ? "d.{$sdOfficeCol} AS office_code" : "NULL AS office_code";
} else {
    $select[] = 'NULL AS do_code';
    $select[] = 'NULL AS office_code';
}

$sqlBase = "SELECT ".implode(', ', $select)." FROM sales_do_audit a {$join} WHERE ".implode(' AND ', $where);

$st = $pdo->prepare($sqlBase." ORDER BY a.created_at DESC, a.id DESC LIMIT 5000");
$st->execute($params);
$allRows = $st->fetchAll(PDO::FETCH_ASSOC);

$actorDeptMap = kpi_do_audit_actor_dept_map(
    $pdo,
    array_map(static fn($r) => (string)($r['actor_name'] ?? ''), $allRows)
);

$rows = [];
foreach ($allRows as $r) {
    $meta = kpi_do_audit_transition_meta(
        (string)($r['status_from'] ?? ''),
        (string)($r['status_to'] ?? ''),
        (string)($r['actor_dept'] ?? '')
    );
    $r['_type'] = $meta['type'];
    $r['_process_dept'] = $meta['process_dept'];
    $r['_label'] = $meta['label'];

    $actorKey = strtolower(trim((string)($r['actor_name'] ?? '')));
    $resolvedActorDept = $actorKey !== '' ? (string)($actorDeptMap[$actorKey] ?? '') : '';
    $r['_actor_dept'] = $resolvedActorDept !== ''
        ? $resolvedActorDept
        : strtoupper(trim((string)($r['actor_dept'] ?? '')));
    $r['_actor_dept_source'] = $resolvedActorDept !== '' ? 'MASTER_USER' : 'AUDIT_FALLBACK';

    if ($dept !== '' && strtoupper((string)$r['_process_dept']) !== $dept) continue;
    if ($type !== '' && $r['_type'] !== $type) continue;
    $rows[] = $r;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportRows = [];
    foreach ($rows as $r) {
        $exportRows[] = [
            'id' => $r['id'] ?? '',
            'created_at' => $r['created_at'] ?? '',
            'do_id' => $r['do_id'] ?? '',
            'do_code' => $r['do_code'] ?? '',
            'office_code' => $r['office_code'] ?? '',
            'status_from' => $r['status_from'] ?? '',
            'status_to' => $r['status_to'] ?? '',
            'event_type' => $r['_type'] ?? '',
            'process_dept' => $r['_process_dept'] ?? '',
            'actor_dept' => $r['_actor_dept'] ?? '',
            'actor_dept_source' => $r['_actor_dept_source'] ?? '',
            'actor_name' => $r['actor_name'] ?? '',
            'note' => $r['note'] ?? '',
        ];
    }
    kpi_csv_download('sales_do_audit_'.$monthYm.'.csv', $exportRows);
}

kpi_header('DO Audit');
kpi_nav('do_audit');

$queryArgs = [
    'month'=>$monthYm, 'dept'=>$dept, 'actor'=>$actor, 'do_id'=>$doId,
    'office'=>$office, 'do_code'=>$doCode, 'type'=>$type,
];
$exportUrl = 'kpi_do_audit.php?'.http_build_query(array_merge($queryArgs, ['export'=>'csv']));
?>

<div class="card">
  <div class="kpi-header-row">
    <div>
      <h2>DO Audit Log</h2>
      <div class="kpi-subtitle">
        Alur normal: <b>CRM → WQS → SCM → ACT → FIN</b><br>
        Data: <code>sales_do_audit</code> • Range: <b><?= h($d1) ?></b> s/d <b><?= h($d2) ?></b>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <a class="btn secondary" href="<?= h($exportUrl) ?>">Export CSV</a>
      <a class="btn secondary" href="kpi_do_audit.php">Reset</a>
    </div>
  </div>
</div>

<div class="card">
  <h3 class="kpi-section-title">Filter</h3>
  <form method="get" class="kpi-form-row">
    <div class="kpi-field"><label>Bulan</label><input type="month" name="month" value="<?= h($monthYm) ?>"></div>
    <div class="kpi-field"><label>Process Dept</label>
      <select name="dept">
        <option value="">Semua Dept</option>
        <?php foreach (['CRM','WQS','SCM','ACT','FIN'] as $opt): ?>
          <option value="<?= h($opt) ?>" <?= $dept===$opt?'selected':'' ?>><?= h($opt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="kpi-field"><label>Jenis Event</label>
      <select name="type">
        <option value="">Semua Event</option>
        <?php foreach (['NORMAL','REVISION','RETURN','CANCEL','OTHER'] as $opt): ?>
          <option value="<?= h($opt) ?>" <?= $type===$opt?'selected':'' ?>><?= h($opt) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="kpi-field"><label>Office</label><input name="office" value="<?= h($office) ?>" placeholder="BGR/BKS/TGR/SMG..."></div>
    <div class="kpi-field"><label>DO Code</label><input name="do_code" value="<?= h($doCode) ?>" placeholder="BMHP-BKS-..."></div>
    <div class="kpi-field"><label>Actor</label><input name="actor" value="<?= h($actor) ?>" placeholder="username / nama"></div>
    <div class="kpi-field"><label>DO ID</label><input name="do_id" value="<?= h($doId) ?>" placeholder="123"></div>
    <div class="kpi-field" style="justify-content:flex-end"><button class="btn secondary" type="submit">Apply</button></div>
  </form>
</div>

<?php
$counts = ['NORMAL'=>0,'REVISION'=>0,'RETURN'=>0,'CANCEL'=>0,'OTHER'=>0];
foreach ($rows as $r) $counts[$r['_type']] = ($counts[$r['_type']] ?? 0) + 1;
?>
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
  <span class="pill">Rows: <?= h((string)count($rows)) ?></span>
  <?php foreach ($counts as $k=>$v): ?><span class="pill"><?= h($k) ?>: <?= h((string)$v) ?></span><?php endforeach; ?>
</div>

<div class="card">
  <h3 class="kpi-section-title">Log (maksimum 5000)</h3>
  <div class="table-wrap">
    <table>
      <thead><tr>
        <th>ID</th><th>Waktu</th><th>Office</th><th>DO</th><th>From</th><th>To</th>
        <th>Event</th><th>Process Dept</th><th>Actor Dept</th><th>Actor</th><th>Note</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="11" class="muted">Tidak ada data sesuai filter.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= h((string)($r['id'] ?? '')) ?></td>
          <td style="white-space:nowrap"><?= h((string)($r['created_at'] ?? '')) ?></td>
          <td><span class="pill"><?= h((string)($r['office_code'] ?? '')) ?></span></td>
          <td>
            <b><?= h((string)($r['do_code'] ?? '')) ?></b>
            <div class="muted">ID <?= h((string)($r['do_id'] ?? '')) ?></div>
          </td>
          <td><?= h((string)($r['status_from'] ?? '')) ?></td>
          <td><?= h((string)($r['status_to'] ?? '')) ?></td>
          <td><span class="pill" title="<?= h((string)$r['_label']) ?>"><?= h((string)$r['_type']) ?></span></td>
          <td><span class="pill"><?= h((string)$r['_process_dept']) ?></span></td>
          <td title="<?= h((string)($r['_actor_dept_source'] ?? '')) ?>"><?= h((string)($r['_actor_dept'] ?? '')) ?></td>
          <td><?= h((string)($r['actor_name'] ?? '')) ?></td>
          <td style="max-width:320px;white-space:normal" title="<?= h((string)($r['note'] ?? '')) ?>"><?= h((string)($r['note'] ?? '')) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h3 class="kpi-section-title">Ketentuan Audit</h3>
  <div class="muted">
    KPI SLA hanya membaca transisi normal. Event revisi, retur, pembatalan, dan transisi lain tetap dicatat untuk audit,
    tetapi tidak dianggap sebagai penyelesaian tahap normal. Kolom <b>Process Dept</b> adalah pemilik proses SLA,
    sedangkan <b>Actor Dept</b> diambil dari master user. Bila akun lama tidak ditemukan, sistem memakai nilai audit sebagai fallback tanpa mengubah data lama.
  </div>
</div>

<?php kpi_footer(); ?>
