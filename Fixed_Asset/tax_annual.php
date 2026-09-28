<?php
require_once __DIR__ . '/_inc/layout.php';
require_once __DIR__ . '/_inc/fa_helpers.php';

fa_preflight_or_die($pdo);
rbac_require(['FIXED_ASSET.TAX_ANNUAL', 'FIXED_ASSET.TAX_ANNUAL_VIEW']);
require_login();


// schema-robust: beberapa versi fa_assets punya nama kolom berbeda
$colAcqDate = fa_pick_col($pdo, 'fa_assets', ['acq_date','purchase_date','acquired_date','acquisition_date','buy_date','tgl_perolehan','date_acq','acq_dt']);
$colAcqCost = fa_pick_col($pdo, 'fa_assets', ['acq_cost','acq_value','purchase_cost','cost','nilai_perolehan','harga_perolehan','acq_price']);
$colSalvage = fa_pick_col($pdo, 'fa_assets', ['salvage_value','residual_value','nilai_residu']);
$colTaxGroup = fa_pick_col($pdo, 'fa_assets', ['tax_group_code','tax_group','group_code']);
$colDepMethod = fa_pick_col($pdo, 'fa_assets', ['dep_method','depr_method','method']);

function fa_fmt_money($v): string {
    return number_format((float)$v, 0, ',', '.');
}

$year = $_GET['year'] ?? date('Y');
$office = $_GET['office_code'] ?? 'ALL';
$exportMode = (string)($_GET['export'] ?? '');
$exportDetail = ($exportMode === 'detail' || $exportMode === 'csv');
$exportSummary = ($exportMode === 'summary');
$checkMissing = (($_GET['check'] ?? '') === 'missing');

if (!preg_match('/^\d{4}$/', (string)$year)) $year = date('Y');
$year = (string)$year;
$yearLike = $year . '-%';

// Detect columns (support different schemas)
$COL_OFFICE = fa_pick_col($pdo, 'fa_assets', ['office_code','office','office_cd','office_name','office_id']);
$COL_DEPT   = fa_pick_col($pdo, 'fa_assets', ['dept_code','department_code','departement_code','dept','department','departement_id','dept_id']);

// Office dropdown: prefer master_office, fallback to assets distinct
$offices = [];
$officeNames = [];
if (fa_table_exists($pdo, 'master_office')) {
    $MO_CODE = fa_pick_col($pdo, 'master_office', ['office_code','code']);
    $MO_NAME = fa_pick_col($pdo, 'master_office', ['office_name','name','office']);
    if ($MO_CODE) {
        $sqlMO = "SELECT `$MO_CODE` AS code" . ($MO_NAME ? ", `$MO_NAME` AS name" : ", `$MO_CODE` AS name") . " FROM master_office WHERE `$MO_CODE` IS NOT NULL AND `$MO_CODE`<>'' ORDER BY `$MO_CODE`";
        $rowsMO = $pdo->query($sqlMO)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rowsMO as $r) {
            $c = (string)($r['code'] ?? '');
            if ($c === '') continue;
            $offices[] = $c;
            $officeNames[$c] = (string)($r['name'] ?? $c);
        }
    }
}
if (!$offices && $COL_OFFICE) {
    $sqlO = "SELECT DISTINCT `$COL_OFFICE` AS code FROM fa_assets WHERE deleted_at IS NULL AND `$COL_OFFICE` IS NOT NULL AND `$COL_OFFICE`<>'' ORDER BY `$COL_OFFICE`";
    $tmp = $pdo->query($sqlO)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tmp as $c) {
        $c = (string)$c;
        if ($c === '') continue;
        $offices[] = $c;
        $officeNames[$c] = $c;
    }
}

// Department dropdown (optional): show only if assets has dept column
$dept = trim((string)($_GET['dept_code'] ?? 'ALL'));
$depts = [];
$deptNames = [];
if ($COL_DEPT) {
    if (fa_table_exists($pdo, 'master_departements')) {
        $MD_CODE = fa_pick_col($pdo, 'master_departements', ['dept_code','departement_code','code']);
        $MD_NAME = fa_pick_col($pdo, 'master_departements', ['dept_name','departement_name','name']);
        if ($MD_CODE) {
            $sqlMD = "SELECT `$MD_CODE` AS code" . ($MD_NAME ? ", `$MD_NAME` AS name" : ", `$MD_CODE` AS name") . " FROM master_departements WHERE `$MD_CODE` IS NOT NULL AND `$MD_CODE`<>'' ORDER BY `$MD_CODE`";
            $rowsMD = $pdo->query($sqlMD)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rowsMD as $r) {
                $c = (string)($r['code'] ?? '');
                if ($c === '') continue;
                $depts[] = $c;
                $deptNames[$c] = (string)($r['name'] ?? $c);
            }
        }
    }
    if (!$depts) {
        $sqlD = "SELECT DISTINCT `$COL_DEPT` AS code FROM fa_assets WHERE deleted_at IS NULL AND `$COL_DEPT` IS NOT NULL AND `$COL_DEPT`<>'' ORDER BY `$COL_DEPT`";
        $tmp = $pdo->query($sqlD)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tmp as $c) {
            $c = (string)$c;
            if ($c === '') continue;
            $depts[] = $c;
            $deptNames[$c] = $c;
        }
    }
}


$params = [':yearLike' => $yearLike];

// Office filtering uses detected column; if office stored as id and master_office exists, join will resolve to office_code.
$OFFICE_JOIN = "";
$OFFICE_FILTER_COL = "";
$SEL_OFFICE = "a.office_code AS office_code"; // fallback

if ($COL_OFFICE) {
    // if assets store office_id, try to join master_office to get office_code
    if (preg_match('/_id$/', $COL_OFFICE) && fa_table_exists($pdo, 'master_office')) {
        $MO_ID   = fa_pick_col($pdo, 'master_office', ['id','office_id']);
        $MO_CODE = fa_pick_col($pdo, 'master_office', ['office_code','code']);
        if ($MO_ID && $MO_CODE) {
            $OFFICE_JOIN = "LEFT JOIN master_office mo ON mo.`$MO_ID` = a.`$COL_OFFICE`";
            $OFFICE_FILTER_COL = "mo.`$MO_CODE`";
            $SEL_OFFICE = "mo.`$MO_CODE` AS office_code";
        }
    }
    if ($OFFICE_FILTER_COL === "") {
        $OFFICE_FILTER_COL = "a.`$COL_OFFICE`";
        $SEL_OFFICE = "a.`$COL_OFFICE` AS office_code";
    }
}

$officeSql = '';
if ($office !== 'ALL' && $OFFICE_FILTER_COL !== '') {
    $officeSql = " AND $OFFICE_FILTER_COL = :office_code ";
    $params[':office_code'] = $office;
}

// Dept filter (only if assets has dept col)
$deptSql = '';
if ($COL_DEPT && $dept !== 'ALL') {
    $deptSql = " AND a.`$COL_DEPT` = :dept_code ";
    $params[':dept_code'] = $dept;
}
// --- schema-robust column picking (support old installations) ---
$COL_ACQ_DATE = fa_pick_col($pdo, 'fa_assets', ['acq_date','acquired_date','acquisition_date','purchase_date','buy_date','tgl_perolehan','tanggal_perolehan']);
$COL_ACQ_COST = fa_pick_col($pdo, 'fa_assets', ['acq_cost','acq_value','acq_cost_value','purchase_cost','cost','nilai_perolehan','harga_perolehan']);
$COL_SALVAGE  = fa_pick_col($pdo, 'fa_assets', ['salvage_value','residual_value','nilai_residu']);
$COL_TAXGROUP = fa_pick_col($pdo, 'fa_assets', ['tax_group_code','tax_group','tax_group_id']);
$COL_METHOD   = fa_pick_col($pdo, 'fa_assets', ['dep_method','depr_method','method']);
$COL_LIFE     = fa_pick_col($pdo, 'fa_assets', ['useful_life_months','life_months','useful_life','useful_months','umur_manfaat_bulan','umr_manfaat_bln']);

$SEL_ACQ_DATE = $COL_ACQ_DATE ? "a.`{$COL_ACQ_DATE}` AS acq_date" : "NULL AS acq_date";
$SEL_ACQ_COST = $COL_ACQ_COST ? "a.`{$COL_ACQ_COST}` AS acq_cost" : "0 AS acq_cost";
$SEL_SALVAGE  = $COL_SALVAGE  ? "a.`{$COL_SALVAGE}` AS salvage_value" : "0 AS salvage_value";
$SEL_TAXGROUP = $COL_TAXGROUP ? "a.`{$COL_TAXGROUP}` AS tax_group_code" : "'' AS tax_group_code";
$SEL_METHOD   = $COL_METHOD   ? "a.`{$COL_METHOD}` AS dep_method" : "'SL' AS dep_method";
$SEL_LIFE     = $COL_LIFE     ? "a.`{$COL_LIFE}` AS useful_life_months" : "NULL AS useful_life_months";


$COST_EXPR = $COL_ACQ_COST ? "COALESCE(a.`{$COL_ACQ_COST}`,0)" : "0";

$WHERE_ACQ_DATE = $COL_ACQ_DATE ? "AND COALESCE(a.`{$COL_ACQ_DATE}`, '1900-01-01') <= STR_TO_DATE(CONCAT(:yearEnd,'-12-31'), '%Y-%m-%d')" : "";

$sql = "
SELECT
  a.id,
  {$SEL_OFFICE},
  a.asset_code,
  a.asset_name,
  a.category,
  {$SEL_ACQ_DATE},
  {$SEL_ACQ_COST},
  {$SEL_SALVAGE},
  {$SEL_TAXGROUP},
  {$SEL_METHOD},
  {$SEL_LIFE},
  COALESCE(agg.dep_year,0) AS dep_year,
  COALESCE(lmin.opening_book, {$COST_EXPR}) AS opening_book,
  COALESCE(lmax.closing_book, {$COST_EXPR}) AS closing_book,
  COALESCE(lmax.accum_after,0) AS accum_after,
  agg.min_p AS first_period,
  agg.max_p AS last_period
FROM fa_assets a
{$OFFICE_JOIN}
LEFT JOIN (
  SELECT asset_id,
         SUM(dep_amount) AS dep_year,
         MIN(period_ym) AS min_p,
         MAX(period_ym) AS max_p
  FROM fa_dep_lines
  WHERE period_ym LIKE :yearLike
  GROUP BY asset_id
) agg ON agg.asset_id = a.id
LEFT JOIN fa_dep_lines lmin ON lmin.asset_id = a.id AND lmin.period_ym = agg.min_p
LEFT JOIN fa_dep_lines lmax ON lmax.asset_id = a.id AND lmax.period_ym = agg.max_p
WHERE a.deleted_at IS NULL
  {$WHERE_ACQ_DATE}
  $officeSql
  $deptSql
ORDER BY office_code, tax_group_code, a.asset_code
";
$params[':yearEnd'] = $year;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) $rows = [];

// Missing months check (based on dep runs)
$missingMonths = [];
if ($checkMissing) {
    if (fa_table_exists($pdo, "fa_dep_runs")) {
    $runStmt = $pdo->prepare("SELECT DISTINCT period_ym FROM fa_dep_runs WHERE period_ym LIKE ? ORDER BY period_ym");
    $runStmt->execute([$yearLike]);
    $present = $runStmt->fetchAll(PDO::FETCH_COLUMN);
} else {
    $present = [];
}
$presentSet = array_fill_keys($present ?: [], true);

    for ($m=1; $m<=12; $m++) {
        $ym = sprintf('%s-%02d', $year, $m);
        if (!isset($presentSet[$ym])) $missingMonths[] = $ym;
    }
}

if ($exportDetail || $exportSummary) {
    header('Content-Type: text/csv; charset=utf-8');
    $fnameMode = $exportSummary ? 'summary' : 'detail';
    header('Content-Disposition: attachment; filename="fixed_asset_tax_'.$fnameMode.'_'.$year.'_'.($office==='ALL'?'ALL':$office).'.csv"');

    $out = fopen('php://output', 'w');

    if ($exportSummary) {
        // Summary per office (ACT-friendly)
        $agg = [];
        foreach ($rows as $r) {
            $oc = (string)$r['office_code'];
            if (!isset($agg[$oc])) {
                $agg[$oc] = [
                    'office_code' => $oc,
                    'asset_count' => 0,
                    'total_acq_cost' => 0.0,
                    'dep_year' => 0.0,
                    'accum_end' => 0.0,
                    'nbv_end' => 0.0,
                ];
            }
            $agg[$oc]['asset_count'] += 1;
            $agg[$oc]['total_acq_cost'] += (float)($r['acq_cost'] ?? 0);
            $agg[$oc]['dep_year'] += (float)($r['dep_year'] ?? 0);
            $agg[$oc]['accum_end'] += (float)($r['accum_after'] ?? 0);
            $agg[$oc]['nbv_end'] += (float)($r['closing_book'] ?? 0);
        }

        fputcsv($out, ['office_code','tax_year','asset_count','total_acq_cost','dep_year','accum_end','nbv_end']);

        ksort($agg);
        foreach ($agg as $row) {
            fputcsv($out, [
                $row['office_code'],
                $year,
                $row['asset_count'],
                round($row['total_acq_cost'], 2),
                round($row['dep_year'], 2),
                round($row['accum_end'], 2),
                round($row['nbv_end'], 2),
            ]);
        }
        fclose($out);
        exit;
    }

    // Detail per asset (ACT-friendly order)
    fputcsv($out, [
        'office_code','tax_year','asset_code','asset_name','category',
        'tax_group_code','dep_method','useful_life_months',
        'acq_date','acq_cost',
        'opening_book','dep_year','accum_end','nbv_end',
        'first_period','last_period'
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            (string)$r['office_code'],
            $year,
            (string)$r['asset_code'],
            (string)$r['asset_name'],
            (string)$r['category'],
            (string)$r['tax_group_code'],
            (string)$r['dep_method'],
            $r['useful_life_months'],
            $r['acq_date'],
            (float)($r['acq_cost'] ?? 0),
            (float)($r['opening_book'] ?? 0),
            (float)($r['dep_year'] ?? 0),
            (float)($r['accum_after'] ?? 0),
            (float)($r['closing_book'] ?? 0),
            $r['first_period'],
            $r['last_period'],
        ]);
    }
    fclose($out);
    exit;
}

fa_header('Laporan Pajak Tahunan - Fixed Asset');

$totalCost = 0.0;
$totalDep = 0.0;
$totalAccum = 0.0;
$totalNbv = 0.0;
?>
<div class="d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4 class="text-white mb-0">Laporan Penyusutan Tahunan (Fiskal/Support)</h4>
    <div class="subtle">Sumber: fa_assets + fa_dep_lines/fa_dep_runs • Tahun <?= h($year) ?></div>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-light" href="?year=<?= h($year) ?>&office_code=<?= h($office) ?>&dept_code=<?= h($dept) ?>&check=missing">Cek Missing Months</a>
    <a class="btn btn-light" href="?year=<?= h($year) ?>&office_code=<?= h($office) ?>&dept_code=<?= h($dept) ?>&export=detail">Export Detail CSV</a>
    <a class="btn btn-outline-secondary" href="?year=<?= h($year) ?>&office_code=<?= h($office) ?>&dept_code=<?= h($dept) ?>&export=summary">Export Summary CSV</a>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2" method="get">
      <div class="col-md-2">
        <label class="form-label">Tahun</label>
        <input type="text" name="year" class="form-control" value="<?= h($year) ?>" placeholder="2025">
      </div>
      <div class="col-md-3">
        <label class="form-label">Office</label>
        <select name="office_code" class="form-select">
          <option value="ALL" <?= $office==='ALL'?'selected':''; ?>>ALL</option>
          <?php foreach ($offices as $oc): $label = $oc; if (isset($officeNames[$oc]) && $officeNames[$oc] !== '' && $officeNames[$oc] !== $oc) $label = $oc . ' - ' . $officeNames[$oc]; ?>
            <option value="<?= h($oc) ?>" <?= $office===$oc?'selected':''; ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($COL_DEPT): ?>
      <div class="col-md-3">
        <label class="form-label">Department</label>
        <select name="dept_code" class="form-select">
          <option value="ALL" <?= $dept==='ALL'?'selected':''; ?>>ALL</option>
          <?php foreach ($depts as $dc): $dlabel = $dc; if (isset($deptNames[$dc]) && $deptNames[$dc] !== '' && $deptNames[$dc] !== $dc) $dlabel = $dc . ' - ' . $deptNames[$dc]; ?>
            <option value="<?= h($dc) ?>" <?= $dept===$dc?'selected':''; ?>><?= h($dlabel) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-7 d-flex align-items-end gap-2">
 align-items-end gap-2">
        <button class="btn btn-primary" type="submit">Tampilkan</button>
        <a class="btn btn-outline-secondary" href="<?= h($BASE_FA) ?>/depreciation.php">Ke Depresiasi</a>
      </div>
    </form>
  </div>
</div>

<?php if ($checkMissing): ?>
  <div class="card mb-3">
    <div class="card-body">
      <h6 class="mb-2">Cek Missing Months (<?= h($year) ?>)</h6>
      <?php if (!$missingMonths): ?>
        <div class="alert alert-success mb-0">Aman ✅ Semua bulan sudah ada depreciation run.</div>
      <?php else: ?>
        <div class="alert alert-warning">
          Bulan yang belum ada depreciation run:
          <div class="mt-2 d-flex flex-wrap gap-2">
            <?php foreach ($missingMonths as $ym): ?>
              <a class="badge text-bg-warning text-decoration-none" href="<?= h($BASE_FA) ?>/depreciation.php?period_ym=<?= h($ym) ?>"><?= h($ym) ?></a>
            <?php endforeach; ?>
          </div>
          <div class="small mt-2">Klik bulan untuk langsung buka halaman Depresiasi periode tersebut.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th>Office</th>
            <th>Asset Code</th>
            <th>Asset Name</th>
            <th>Group</th>
            <th>Acq Date</th>
            <th class="text-end">Acq Cost</th>
            <th class="text-end">Dep <?= h($year) ?></th>
            <th class="text-end">Accum End</th>
            <th class="text-end">NBV End</th>
            <th>First</th>
            <th>Last</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $totalCost += (float)$r['acq_cost'];
            $totalDep += (float)$r['dep_year'];
            $totalAccum += (float)$r['accum_after'];
            $totalNbv += (float)$r['closing_book'];
          ?>
            <tr>
              <td><?= h($r['office_code']) ?></td>
              <td><?= h($r['asset_code']) ?></td>
              <td><?= h($r['asset_name']) ?></td>
              <td><?= h($r['tax_group_code']) ?></td>
              <td><?= h($r['acq_date']) ?></td>
              <td class="text-end"><?= fa_fmt_money($r['acq_cost']) ?></td>
              <td class="text-end"><?= fa_fmt_money($r['dep_year']) ?></td>
              <td class="text-end"><?= fa_fmt_money($r['accum_after']) ?></td>
              <td class="text-end"><?= fa_fmt_money($r['closing_book']) ?></td>
              <td><?= h($r['first_period'] ?? '') ?></td>
              <td><?= h($r['last_period'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="5" class="text-end">TOTAL</th>
            <th class="text-end"><?= fa_fmt_money($totalCost) ?></th>
            <th class="text-end"><?= fa_fmt_money($totalDep) ?></th>
            <th class="text-end"><?= fa_fmt_money($totalAccum) ?></th>
            <th class="text-end"><?= fa_fmt_money($totalNbv) ?></th>
            <th colspan="2"></th>
          </tr>
        </tfoot>
      </table>
    </div>
    <div class="small text-muted mt-2">
      Catatan: Ini laporan “support” penyusutan tahunan dari sistem. Pastikan tax_group_code & masa manfaat fiskal sudah sesuai kebijakan ACT.
    </div>
  </div>
</div>

<?php fa_footer(); ?>
