<?php
/**
 * WQS Karantina Barang - FAST/STABLE
 * Path live: /web/ERP_RMI_SOFULL/stock/wqs_quarantine.php
 *
 * Perbaikan:
 * - Tidak menjalankan ALTER TABLE berulang yang membuat halaman lambat.
 * - Lengkapi kolom tabel secara aman tanpa error duplicate column.
 * - Tidak memakai AFTER agar tidak error jika kolom referensi belum ada.
 */

require_once __DIR__ . '/../master/auth.php';
if (!function_exists('rmi_icon') && is_file(__DIR__ . '/../_shared/rmi_icons.php')) {
    require_once __DIR__ . '/../_shared/rmi_icons.php';
}

if (function_exists('require_login')) {
    require_login();
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo && function_exists('db_pdo')) {
    $pdo = db_pdo();
}
if (!$pdo && function_exists('pdo')) {
    $pdo = pdo();
}

if (!function_exists('h')) {
    function h($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('u')) {
    function u(string $path): string {
        $bp = $GLOBALS['BASE_PROJECT'] ?? (defined('BASE_PROJECT') ? (string)BASE_PROJECT : '');
        return rtrim($bp, '/') . '/' . ltrim($path, '/');
    }
}

function wqs_q_user(): string {
    if (function_exists('auth_user')) {
        $u = auth_user();
        if (is_array($u)) {
            return (string)($u['username'] ?? $u['user_name'] ?? $u['name'] ?? 'system');
        }
    }
    return (string)($_SESSION['username'] ?? ($_SESSION['user']['username'] ?? 'system'));
}

function wqs_q_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    try {
        // NOTE: placeholder (?) TIDAK valid di SHOW TABLES LIKE (MySQL 1064).
        // Interpolasi aman karena $table sudah divalidasi regex di atas.
        $rows = $pdo->query("SHOW TABLES LIKE '{$table}'")->fetchAll(PDO::FETCH_NUM) ?: [];
        return count($rows) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function wqs_q_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    $cols = [];
    try {
        $st = $pdo->query("SHOW COLUMNS FROM `$table`");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[strtolower((string)$r['Field'])] = true;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $cache[$table] = $cols;
}

function wqs_q_col_exists(PDO $pdo, string $table, string $col): bool {
    $cols = wqs_q_columns($pdo, $table);
    return isset($cols[strtolower($col)]);
}

function wqs_q_add_missing_cols(PDO $pdo): void {
    /*
      Prinsip:
      - CREATE TABLE lengkap jika belum ada.
      - Jika tabel sudah ada versi lama, tambah hanya kolom yang belum ada.
      - Tidak pakai AFTER agar tidak error Unknown column.
      - Duplicate column diabaikan agar tidak mengganggu halaman.
    */
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wqs_stock_quarantine (
            id INT AUTO_INCREMENT PRIMARY KEY,
            return_id INT NULL,
            return_item_id INT NULL,
            do_id INT NULL,
            product_id INT NULL,
            sku VARCHAR(100) NULL,
            product_name VARCHAR(255) NULL,
            qty DECIMAL(18,2) NOT NULL DEFAULT 0,
            unit VARCHAR(50) NULL,
            office_code VARCHAR(50) NULL,
            condition_status VARCHAR(30) NOT NULL DEFAULT 'UNKNOWN',
            quarantine_status VARCHAR(30) NOT NULL DEFAULT 'OPEN',
            reason VARCHAR(255) NULL,
            note TEXT NULL,
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_by VARCHAR(100) NULL,
            processed_at DATETIME NULL,
            decision_note TEXT NULL,
            INDEX idx_office_code (office_code),
            INDEX idx_status (quarantine_status),
            INDEX idx_product_id (product_id),
            INDEX idx_return_id (return_id),
            INDEX idx_do_id (do_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $needed = [
        'return_id'           => "INT NULL",
        'return_item_id'      => "INT NULL",
        'do_id'               => "INT NULL",
        'product_id'          => "INT NULL",
        'sku'                 => "VARCHAR(100) NULL",
        'product_name'        => "VARCHAR(255) NULL",
        'qty'                 => "DECIMAL(18,2) NOT NULL DEFAULT 0",
        'unit'                => "VARCHAR(50) NULL",
        'office_code'         => "VARCHAR(50) NULL",
        'condition_status'    => "VARCHAR(30) NOT NULL DEFAULT 'UNKNOWN'",
        'quarantine_status'   => "VARCHAR(30) NOT NULL DEFAULT 'OPEN'",
        'reason'              => "VARCHAR(255) NULL",
        'note'                => "TEXT NULL",
        'created_by'          => "VARCHAR(100) NULL",
        'created_at'          => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        'processed_by'        => "VARCHAR(100) NULL",
        'processed_at'        => "DATETIME NULL",
        'decision_note'       => "TEXT NULL",
    ];

    $cols = wqs_q_columns($pdo, 'wqs_stock_quarantine');

    foreach ($needed as $col => $ddl) {
        if (isset($cols[strtolower($col)])) continue;

        try {
            $pdo->exec("ALTER TABLE wqs_stock_quarantine ADD COLUMN `$col` $ddl");
            $cols[strtolower($col)] = true;
        } catch (Throwable $e) {
            $m = strtolower($e->getMessage());
            if (strpos($m, 'duplicate column') !== false || strpos($m, '1060') !== false || strpos($m, 'already exists') !== false) {
                $cols[strtolower($col)] = true;
                continue;
            }
            throw $e;
        }
    }

    // Index dibuat aman satu per satu. Kalau sudah ada, abaikan.
    $indexes = [
        'idx_office_code' => 'office_code',
        'idx_status' => 'quarantine_status',
        'idx_product_id' => 'product_id',
        'idx_return_id' => 'return_id',
        'idx_do_id' => 'do_id',
    ];
    foreach ($indexes as $idx => $col) {
        if (!isset($cols[strtolower($col)])) continue;
        try {
            $pdo->exec("ALTER TABLE wqs_stock_quarantine ADD INDEX `$idx` (`$col`)");
        } catch (Throwable $e) {
            // Duplicate index atau index sudah ada, abaikan.
        }
    }

    // Normalisasi isi kosong.
    try {
        $pdo->exec("UPDATE wqs_stock_quarantine SET quarantine_status='OPEN' WHERE quarantine_status IS NULL OR quarantine_status=''");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("UPDATE wqs_stock_quarantine SET condition_status='UNKNOWN' WHERE condition_status IS NULL OR condition_status=''");
    } catch (Throwable $e) {}
}

function wqs_q_first_col(PDO $pdo, string $table, array $candidates): ?string {
    foreach ($candidates as $c) {
        if (wqs_q_col_exists($pdo, $table, $c)) return $c;
    }
    return null;
}

function wqs_q_add_stock(PDO $pdo, array $qrow, string $note): void {
    if (!wqs_q_table_exists($pdo, 'wqs_stock_by_office')) {
        throw new RuntimeException('Tabel wqs_stock_by_office tidak ditemukan.');
    }

    $qtyCol = wqs_q_first_col($pdo, 'wqs_stock_by_office', ['qty', 'stock_qty', 'onhand_qty']);
    if (!$qtyCol) {
        throw new RuntimeException('Kolom qty / stock_qty / onhand_qty di wqs_stock_by_office tidak ditemukan.');
    }

    $office = trim((string)($qrow['office_code'] ?? ''));
    $pid = (int)($qrow['product_id'] ?? 0);
    $sku = trim((string)($qrow['sku'] ?? ''));
    $qty = (float)($qrow['qty'] ?? 0);

    if ($office === '') throw new RuntimeException('Office karantina kosong.');
    if ($qty <= 0) throw new RuntimeException('Qty karantina tidak valid.');

    $hasProduct = wqs_q_col_exists($pdo, 'wqs_stock_by_office', 'product_id');
    $hasSku = wqs_q_col_exists($pdo, 'wqs_stock_by_office', 'sku');
    $hasOffice = wqs_q_col_exists($pdo, 'wqs_stock_by_office', 'office_code');
    $hasUpdated = wqs_q_col_exists($pdo, 'wqs_stock_by_office', 'updated_at');
    $hasCreated = wqs_q_col_exists($pdo, 'wqs_stock_by_office', 'created_at');

    if (!$hasOffice) throw new RuntimeException('Kolom office_code di wqs_stock_by_office tidak ditemukan.');

    if ($hasProduct && $pid > 0) {
        $where = "office_code=? AND product_id=?";
        $params = [$office, $pid];
    } elseif ($hasSku && $sku !== '') {
        $where = "office_code=? AND sku=?";
        $params = [$office, $sku];
    } else {
        throw new RuntimeException('Product ID atau SKU kosong. Tidak bisa release ke stok.');
    }

    $st = $pdo->prepare("SELECT id FROM wqs_stock_by_office WHERE $where LIMIT 1");
    $st->execute($params);
    $stockId = $st->fetchColumn();

    if ($stockId) {
        $sql = "UPDATE wqs_stock_by_office SET `$qtyCol` = COALESCE(`$qtyCol`,0) + ?";
        $vals = [$qty];
        if ($hasUpdated) $sql .= ", updated_at = NOW()";
        $sql .= " WHERE id = ?";
        $vals[] = $stockId;
        $pdo->prepare($sql)->execute($vals);
    } else {
        $data = ['office_code' => $office, $qtyCol => $qty];
        if ($hasProduct && $pid > 0) $data['product_id'] = $pid;
        if ($hasSku && $sku !== '') $data['sku'] = $sku;
        if ($hasCreated) $data['created_at'] = date('Y-m-d H:i:s');
        if ($hasUpdated) $data['updated_at'] = date('Y-m-d H:i:s');

        $cols = '`' . implode('`,`', array_keys($data)) . '`';
        $ph = implode(',', array_fill(0, count($data), '?'));
        $pdo->prepare("INSERT INTO wqs_stock_by_office ($cols) VALUES ($ph)")->execute(array_values($data));
    }
}

$msg = '';
$err = '';

try {
    if (!$pdo) throw new RuntimeException('Koneksi database tidak ditemukan.');
    wqs_q_add_missing_cols($pdo);
} catch (Throwable $e) {
    $err = 'Gagal memastikan tabel karantina: ' . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo && !$err) {
    $id = (int)($_POST['id'] ?? 0);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $decisionNote = trim((string)($_POST['decision_note'] ?? ''));

    try {
        if ($id <= 0) throw new RuntimeException('ID karantina tidak valid.');

        $st = $pdo->prepare("SELECT * FROM wqs_stock_quarantine WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Data karantina tidak ditemukan.');

        $curStatus = strtoupper((string)($row['quarantine_status'] ?? 'OPEN'));
        if (!in_array($curStatus, ['OPEN', 'HOLD'], true)) {
            throw new RuntimeException('Data karantina sudah diproses. Status: ' . $curStatus);
        }

        $pdo->beginTransaction();

        if ($action === 'release') {
            wqs_q_add_stock($pdo, $row, $decisionNote);
            $newStatus = 'RELEASED';
            $msg = 'Barang berhasil RELEASE ke stok.';
        } elseif ($action === 'scrap') {
            $newStatus = 'SCRAPPED';
            $msg = 'Barang berhasil ditandai SCRAPPED.';
        } elseif ($action === 'return_supplier') {
            $newStatus = 'RETURN_SUPPLIER';
            $msg = 'Barang berhasil ditandai RETURN SUPPLIER.';
        } elseif ($action === 'hold') {
            $newStatus = 'HOLD';
            $msg = 'Barang berhasil ditahan / HOLD.';
        } else {
            throw new RuntimeException('Aksi tidak dikenal.');
        }

        $up = $pdo->prepare("
            UPDATE wqs_stock_quarantine
            SET quarantine_status = ?,
                processed_by = ?,
                processed_at = NOW(),
                decision_note = ?
            WHERE id = ?
        ");
        $up->execute([$newStatus, wqs_q_user(), $decisionNote, $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
    }
}

$statusFilter = strtoupper(trim((string)($_GET['status'] ?? 'OPEN')));
$officeFilter = strtoupper(trim((string)($_GET['office'] ?? '')));
$qSearch = trim((string)($_GET['q'] ?? ''));

$counts = ['OPEN'=>0, 'HOLD'=>0, 'RELEASED'=>0, 'SCRAPPED'=>0, 'RETURN_SUPPLIER'=>0, 'TOTAL'=>0];
$offices = [];
$rows = [];

if ($pdo && !$err) {
    try {
        foreach ($counts as $s => $_) {
            if ($s === 'TOTAL') {
                $counts[$s] = (int)$pdo->query("SELECT COUNT(*) FROM wqs_stock_quarantine")->fetchColumn();
            } else {
                $st = $pdo->prepare("SELECT COUNT(*) FROM wqs_stock_quarantine WHERE UPPER(COALESCE(quarantine_status,''))=?");
                $st->execute([$s]);
                $counts[$s] = (int)$st->fetchColumn();
            }
        }

        $offices = $pdo->query("
            SELECT DISTINCT office_code
            FROM wqs_stock_quarantine
            WHERE office_code IS NOT NULL AND office_code <> ''
            ORDER BY office_code
        ")->fetchAll(PDO::FETCH_COLUMN);

        $where = [];
        $params = [];

        if ($statusFilter !== '' && $statusFilter !== 'ALL') {
            $where[] = "UPPER(COALESCE(quarantine_status,'')) = ?";
            $params[] = $statusFilter;
        }
        if ($officeFilter !== '') {
            $where[] = "UPPER(COALESCE(office_code,'')) = ?";
            $params[] = $officeFilter;
        }
        if ($qSearch !== '') {
            $where[] = "(COALESCE(sku,'') LIKE ? OR COALESCE(product_name,'') LIKE ? OR COALESCE(reason,'') LIKE ? OR COALESCE(note,'') LIKE ?)";
            $like = '%' . $qSearch . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $st = $pdo->prepare("
            SELECT *
            FROM wqs_stock_quarantine
            $whereSql
            ORDER BY id DESC
            LIMIT 300
        ");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>WQS - Karantina Barang</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#0b1220;--card:#182438;--line:rgba(255,255,255,.12);--txt:#e8ecf4;--muted:#94a3b8;--green:#16a34a;--red:#ef4444;--yellow:#f59e0b}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#07111f,#17273c);color:var(--txt);font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
a{color:#60a5fa;text-decoration:none}.top{background:rgba(3,7,18,.85);border-bottom:1px solid var(--line);padding:20px 24px;display:flex;justify-content:space-between;gap:12px;align-items:center}
h1{margin:0;font-size:24px}.sub{color:var(--muted);font-size:13px;margin-top:4px}.wrap{max-width:1360px;margin:auto;padding:24px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:1px solid var(--line);background:rgba(255,255,255,.07);color:var(--txt);padding:9px 12px;border-radius:10px;font-weight:800;cursor:pointer}
.btn.green{background:rgba(22,163,74,.18);color:#86efac}.btn.red{background:rgba(239,68,68,.18);color:#fecaca}.btn.yellow{background:rgba(245,158,11,.18);color:#fde68a}.btn.sm{font-size:12px;padding:6px 8px}
.card{background:rgba(24,36,56,.88);border:1px solid var(--line);border-radius:16px;margin-bottom:14px;padding:16px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.kpi{background:#101827;border:1px solid var(--line);border-top:3px solid var(--c,#64748b);border-radius:14px;padding:14px}.kpi .n{font-size:28px;font-weight:900}.kpi .l{font-size:12px;color:var(--muted);font-weight:900}
.msg{padding:12px 14px;border-radius:12px;margin-bottom:12px}.ok{background:rgba(22,163,74,.16);border:1px solid rgba(22,163,74,.4);color:#bbf7d0}.err{background:rgba(239,68,68,.16);border:1px solid rgba(239,68,68,.4);color:#fecaca}
.filter{display:flex;flex-wrap:wrap;gap:10px;align-items:end}label{display:block;color:var(--muted);font-size:12px;font-weight:900;margin-bottom:4px}input,select,textarea{background:#111827;color:var(--txt);border:1px solid var(--line);border-radius:9px;padding:9px 10px;min-height:38px}textarea{width:100%;min-height:48px}
table{width:100%;border-collapse:collapse}th{background:#0b1220;color:#cbd5e1;text-align:left;font-size:12px;padding:10px}td{padding:11px 10px;border-bottom:1px solid rgba(255,255,255,.08);vertical-align:top}.muted{color:var(--muted);font-size:12px}.badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:900;background:rgba(148,163,184,.18)}
@media(max-width:900px){.top{display:block}.table-wrap{overflow:auto}.filter>div{width:100%}input,select{width:100%}}
</style>
</head>
<body>
<div class="top">
  <div>
    <h1><?= rmi_icon('warn') ?> WQS - Karantina Barang</h1>
    <div class="sub">Barang retur rusak/expired/unknown ditahan di sini sebelum diputuskan.</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn sm" href="<?= h(u('/dashboards/warehouse/wqs_dashboard.php')) ?>"><?= rmi_icon('chart') ?> Dashboard WQS</a>
    <a class="btn sm" href="<?= h(u('/stock/wqs_stock.php')) ?>"><?= rmi_icon('box') ?> Lihat Stok</a>
    <a class="btn sm" href="<?= h(u('/sales/sales_do.php')) ?>"><?= rmi_icon('receipt') ?> Sales DO</a>
  </div>
</div>

<div class="wrap">
  <?php if ($msg): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="msg err"><?= h($err) ?></div><?php endif; ?>

  <div class="grid" style="margin-bottom:14px">
    <div class="kpi" style="--c:#f59e0b"><div class="n"><?= number_format($counts['OPEN']) ?></div><div class="l">OPEN</div></div>
    <div class="kpi" style="--c:#3b82f6"><div class="n"><?= number_format($counts['HOLD']) ?></div><div class="l">HOLD</div></div>
    <div class="kpi" style="--c:#16a34a"><div class="n"><?= number_format($counts['RELEASED']) ?></div><div class="l">RELEASED</div></div>
    <div class="kpi" style="--c:#ef4444"><div class="n"><?= number_format($counts['SCRAPPED']) ?></div><div class="l">SCRAPPED</div></div>
    <div class="kpi" style="--c:#a855f7"><div class="n"><?= number_format($counts['RETURN_SUPPLIER']) ?></div><div class="l">RETURN SUPPLIER</div></div>
    <div class="kpi"><div class="n"><?= number_format($counts['TOTAL']) ?></div><div class="l">TOTAL</div></div>
  </div>

  <div class="card">
    <form method="get" class="filter">
      <div>
        <label>Status</label>
        <select name="status">
          <?php foreach (['OPEN'=>'OPEN','HOLD'=>'HOLD','RELEASED'=>'RELEASED','SCRAPPED'=>'SCRAPPED','RETURN_SUPPLIER'=>'RETURN SUPPLIER','ALL'=>'SEMUA'] as $v=>$l): ?>
            <option value="<?= h($v) ?>" <?= $statusFilter===$v?'selected':'' ?>><?= h($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Office</label>
        <select name="office">
          <option value="">Semua office</option>
          <?php foreach ($offices as $of): ?>
            <option value="<?= h(strtoupper($of)) ?>" <?= $officeFilter===strtoupper($of)?'selected':'' ?>><?= h(strtoupper($of)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Cari SKU / Produk / alasan</label>
        <input type="text" name="q" value="<?= h($qSearch) ?>" placeholder="Cari...">
      </div>
      <div>
        <button class="btn yellow" type="submit">Filter</button>
        <a class="btn" href="<?= h(u('/stock/wqs_quarantine.php')) ?>">Reset</a>
      </div>
    </form>
  </div>

  <div class="card table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Produk</th>
          <th>Qty</th>
          <th>Office</th>
          <th>Kondisi</th>
          <th>Status</th>
          <th>Referensi</th>
          <th>Alasan / Catatan</th>
          <th>Dibuat</th>
          <th style="width:270px">Aksi</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="10" class="muted">Belum ada data karantina untuk filter ini.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        $st = strtoupper((string)($r['quarantine_status'] ?? 'OPEN'));
        $open = in_array($st, ['OPEN','HOLD'], true);
      ?>
        <tr>
          <td>#<?= (int)$r['id'] ?></td>
          <td><b><?= h($r['product_name'] ?: ('Product ID '.$r['product_id'])) ?></b><br><span class="muted">SKU: <?= h($r['sku'] ?: '-') ?></span></td>
          <td><b><?= number_format((float)$r['qty'],2,',','.') ?></b> <?= h($r['unit'] ?? '') ?></td>
          <td><?= h($r['office_code'] ?: '-') ?></td>
          <td><?= h($r['condition_status'] ?: '-') ?></td>
          <td><span class="badge"><?= h(str_replace('_',' ', $st)) ?></span></td>
          <td><span class="muted">Return:</span> <?= h($r['return_id'] ?: '-') ?><br><span class="muted">DO:</span> <?= h($r['do_id'] ?: '-') ?></td>
          <td><?= nl2br(h($r['reason'] ?: '-')) ?><?php if (!empty($r['note'])): ?><br><span class="muted"><?= nl2br(h($r['note'])) ?></span><?php endif; ?><?php if (!empty($r['decision_note'])): ?><hr><span class="muted">Keputusan:</span><br><?= nl2br(h($r['decision_note'])) ?><?php endif; ?></td>
          <td><?= h($r['created_at'] ?? '') ?><br><span class="muted"><?= h($r['created_by'] ?? '') ?></span></td>
          <td>
            <?php if ($open): ?>
              <form method="post" onsubmit="return confirm('Release ke stok jual?');">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="release">
                <textarea name="decision_note" placeholder="Catatan release..."></textarea>
                <button class="btn green sm" type="submit"><?= rmi_icon('check') ?> Release ke Stok</button>
              </form>
              <form method="post" onsubmit="return confirm('Tandai scrap/rusak?');">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="scrap">
                <textarea name="decision_note" placeholder="Catatan scrap..."></textarea>
                <button class="btn red sm" type="submit"><?= rmi_icon('cross') ?> Scrap</button>
              </form>
              <form method="post" onsubmit="return confirm('Return supplier?');">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="return_supplier">
                <textarea name="decision_note" placeholder="Catatan return supplier..."></textarea>
                <button class="btn yellow sm" type="submit">↩ Return Supplier</button>
              </form>
              <?php if ($st !== 'HOLD'): ?>
              <form method="post">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="action" value="hold">
                <textarea name="decision_note" placeholder="Catatan hold..."></textarea>
                <button class="btn sm" type="submit"><?= rmi_icon('warn') ?> Hold</button>
              </form>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted">Sudah diproses.</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
