<?php
declare(strict_types=1);

require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_login();

require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/../purchases/_purchases_lib.php';

if (function_exists('require_any_permission')) {
    require_any_permission([
        'WQS.PR_VIEW','WQS.VIEW','WQS.PR_CREATE','WQS.PR_EDIT',
        'PURCHASES.PO_VIEW','PURCHASES.PO_CREATE','PURCHASES.PO_EDIT'
    ]);
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','WQS','BRANCH','MANAGER','STAFF']);
}

$pdo = p_pdo();
p_ensure_schema($pdo);
p_ensure_col($pdo, 'wqs_pr', 'submitted_at', "`submitted_at` DATETIME NULL AFTER `status`");

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID PR tidak valid.');
}

$user = function_exists('auth_user') ? auth_user() : [];
$userDept = strtoupper(trim((string)($user['department'] ?? $user['level'] ?? '')));
$userRole = strtoupper(trim((string)($user['role'] ?? '')));
$userOffice = strtoupper(trim((string)($user['office_code'] ?? '')));
if ($userDept === '' && function_exists('auth_dept')) $userDept = strtoupper(trim((string)auth_dept()));
if ($userOffice === '' && function_exists('auth_office_code')) $userOffice = strtoupper(trim((string)auth_office_code()));

$isBranch = ($userDept === 'BRANCH' && !in_array($userRole, ['SYS','ADMIN','SUPERADMIN'], true));
$officeScope = ($isBranch && $userOffice !== '') ? $userOffice : null;

$pr = null;
$items = [];
$activePo = null;
$activePoSql = null;
$activePoParams = [];

try {
    $st = $pdo->prepare("
        SELECT pr.*, o.office_name
        FROM wqs_pr pr
        LEFT JOIN master_office o ON o.office_code = pr.office_code
        WHERE pr.id = ? AND pr.deleted_at IS NULL
        LIMIT 1
    ");
    $st->execute([$id]);
    $pr = $st->fetch(PDO::FETCH_ASSOC);

    if (!$pr) {
        http_response_code(404);
        exit('PR tidak ditemukan.');
    }

    if ($officeScope !== null && strtoupper(trim((string)($pr['office_code'] ?? ''))) !== $officeScope) {
        http_response_code(403);
        exit('Akses ditolak: PR bukan milik kantor Anda.');
    }

    $sti = $pdo->prepare("
        SELECT *
        FROM wqs_pr_items
        WHERE pr_id = ? AND deleted_at IS NULL
        ORDER BY line_no, id
    ");
    $sti->execute([$id]);
    $items = $sti->fetchAll(PDO::FETCH_ASSOC);

    if (p_has_table($pdo, 'purchases_po')) {
        $poCols = p_cols($pdo, 'purchases_po');
        $conds = [];
        $params = [];

        if (in_array('pr_id', $poCols, true)) {
            $conds[] = 'po.pr_id = ?';
            $params[] = $id;
        }
        if (in_array('source_pr_id', $poCols, true)) {
            $conds[] = 'po.source_pr_id = ?';
            $params[] = $id;
        }
        if (in_array('wqs_pr_id', $poCols, true)) {
            $conds[] = 'po.wqs_pr_id = ?';
            $params[] = $id;
        }

        $prCode = trim((string)($pr['pr_code'] ?? ''));
        foreach (['pr_code','source_pr_code','wqs_pr_code'] as $c) {
            if ($prCode !== '' && in_array($c, $poCols, true)) {
                $conds[] = "UPPER(TRIM(COALESCE(po.`{$c}`,''))) = UPPER(?)";
                $params[] = $prCode;
            }
        }
        if ($prCode !== '' && in_array('note', $poCols, true)) {
            $conds[] = "INSTR(UPPER(COALESCE(po.note,'')), UPPER(?)) > 0";
            $params[] = $prCode;
        }

        if ($conds) {
            $poCodeSelect = in_array('po_code', $poCols, true) ? 'po.po_code' : "'' AS po_code";
            $sql = "
                SELECT po.*, {$poCodeSelect}
                FROM purchases_po po
                WHERE (" . implode(' OR ', $conds) . ")
                  AND po.deleted_at IS NULL
                  AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')
                ORDER BY po.id DESC
                LIMIT 1
            ";
            $activePoSql = $sql;
            $activePoParams = $params;
            $sp = $pdo->prepare($activePoSql);
            $sp->execute($activePoParams);
            $activePo = $sp->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Gagal memuat detail PR: ' . rmi_h($e->getMessage()));
}

$status = strtoupper(trim((string)($pr['status'] ?? 'DRAFT')));
if ($activePo) $status = 'PO_CREATED';

$canEdit = function_exists('can') ? can('WQS.PR_EDIT') : p_can_create_pr();
$isOrphanPoCreated = ($status === 'PO_CREATED' && !$activePo);

// Recovery KHUSUS status stale/orphan: PR masih PO_CREATED tetapi tidak ada PO aktif.
// Tidak otomatis mengubah data. User berwenang harus menekan tombol recovery,
// lalu server melakukan verifikasi ulang di dalam transaction sebelum UPDATE.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recover_orphan_po_created'])) {
    require_post();
    verify_csrf();

    if (!$canEdit) {
        http_response_code(403);
        exit('Akses ditolak: Anda tidak memiliki hak edit PR.');
    }
    if (!$isOrphanPoCreated) {
        rmi_redirect('wqs_pr_view.php?id=' . $id);
    }

    try {
        $pdo->beginTransaction();

        // Kunci PR agar status tidak berubah bersamaan dengan proses lain.
        $lock = $pdo->prepare("SELECT id,pr_code,status,note FROM wqs_pr WHERE id=? AND deleted_at IS NULL FOR UPDATE");
        $lock->execute([$id]);
        $lockedPr = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$lockedPr) throw new RuntimeException('PR tidak ditemukan.');
        if (strtoupper(trim((string)($lockedPr['status'] ?? ''))) !== 'PO_CREATED') {
            throw new RuntimeException('Status PR sudah berubah. Refresh halaman sebelum melanjutkan.');
        }

        // Fail closed: bila query verifikasi PO tidak tersedia, recovery DITOLAK.
        if (!$activePoSql) {
            throw new RuntimeException('Relasi PO tidak dapat diverifikasi. Recovery dibatalkan untuk menjaga integritas data.');
        }
        $chk = $pdo->prepare($activePoSql);
        $chk->execute($activePoParams);
        $poNow = $chk->fetch(PDO::FETCH_ASSOC);
        if ($poNow) {
            throw new RuntimeException('PO aktif ditemukan. PR tetap PO_CREATED dan harus mengikuti proses Cancel PO.');
        }

        $reason = trim((string)($_POST['recovery_reason'] ?? ''));
        if ($reason === '') throw new RuntimeException('Alasan recovery wajib diisi.');
        $oldNote = trim((string)($lockedPr['note'] ?? ''));
        $tag = '[RECOVERY PO_CREATED TANPA PO AKTIF] ' . $reason;
        $newNote = $oldNote === '' ? $tag : $oldNote . "\n" . $tag;

        $up = $pdo->prepare("UPDATE wqs_pr SET status='REVISION_WQS', note=? WHERE id=? AND status='PO_CREATED' AND deleted_at IS NULL");
        $up->execute([$newNote,$id]);
        if ($up->rowCount() !== 1) throw new RuntimeException('Recovery gagal karena data berubah oleh proses lain.');

        $pdo->commit();

        if (function_exists('p_audit')) {
            p_audit($pdo,'PR',(string)($lockedPr['pr_code'] ?? ''),'RECOVER_ORPHAN_PO_CREATED',[
                'id'=>$id,'from'=>'PO_CREATED','to'=>'REVISION_WQS','reason'=>$reason
            ]);
        }
        rmi_redirect('wqs_pr_view.php?id=' . $id . '&recovered=1');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $recoveryError = $e->getMessage();
    }
}

// Setelah recovery sukses halaman reload dan status berasal dari database terbaru.
if (!empty($_GET['recovered'])) {
    $recoverySuccess = 'PR berhasil dipulihkan ke REVISION_WQS karena tidak ditemukan PO aktif. PR sekarang dapat direvisi atau dihapus dari daftar WQS PR sesuai hak akses.';
}

$group = strtoupper(trim((string)($pr['business_group'] ?? '')));
if (!in_array($group, ['BMHP','UNIT_ACC'], true)) {
    $cat = strtoupper(trim((string)($pr['category'] ?? 'BMHP')));
    $group = in_array($cat, ['UNIT_ACC','ALKES','AKSESORIS'], true) ? 'UNIT_ACC' : 'BMHP';
}

$editable = $canEdit && !$activePo && in_array($status, ['DRAFT','REVISION_WQS'], true);

require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = '<style>
body{background:#020617;color:#e5e7eb}
.rmi-container{max-width:1200px;margin:20px auto 32px;padding:0 14px}
.cardx{background:rgba(15,23,42,.97);border:1px solid #263449;border-radius:14px;margin-bottom:18px;overflow:hidden}
.headx{padding:15px 18px;border-bottom:1px solid #263449;background:#07101f;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap}
.bodyx{padding:18px}
.title{font-weight:700;font-size:17px}.muted{color:#94a3b8;font-size:12px}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.info{background:#0b1220;border:1px solid #243247;border-radius:10px;padding:12px}
.info .k{font-size:11px;color:#94a3b8;text-transform:uppercase}.info .v{font-size:13px;font-weight:600;margin-top:4px;word-break:break-word}
.badgex{display:inline-block;border-radius:999px;padding:5px 11px;font-size:11px;font-weight:700}
.DRAFT{background:#374151}.SUBMITTED{background:#2563eb}.REVISION_WQS{background:#f59e0b;color:#111827}.PO_CREATED{background:#16a34a}
.table{color:#e5e7eb}.table thead th{background:#020617;color:#e5e7eb;border-color:#263449;font-size:11px}.table td{color:#e5e7eb;border-color:#263449;font-size:12px;vertical-align:middle}
@media(max-width:800px){.grid{grid-template-columns:1fr 1fr}} @media(max-width:520px){.grid{grid-template-columns:1fr}}
</style>';

rmi_header('Detail Purchase Request', 'stock', [
    'subtitle' => 'Detail PR WQS — read/detail view.',
    'breadcrumbs' => [
        ['label'=>'Stock (WQS)','url'=>$baseProject . '/stock/index.php'],
        ['label'=>'WQS PR','url'=>'wqs_pr.php'],
        'Detail PR'
    ],
    'actions' => [
        ['label'=>'Kembali ke PR','url'=>'wqs_pr.php','class'=>'btn btn-sm btn-outline-light'],
        ['label'=>'Print','url'=>'wqs_pr_print.php?id='.$id,'class'=>'btn btn-sm btn-outline-light','target'=>'_blank'],
    ],
    'extra_head'=>$extraHead
]);
?>

<div class="rmi-container">
    <?php if (!empty($recoverySuccess)): ?>
        <div class="alert alert-success"><?= rmi_h($recoverySuccess) ?></div>
    <?php endif; ?>
    <?php if (!empty($recoveryError)): ?>
        <div class="alert alert-danger">Recovery ditolak: <?= rmi_h($recoveryError) ?></div>
    <?php endif; ?>
    <?php if ($isOrphanPoCreated && $canEdit): ?>
        <div class="alert alert-warning">
            <div class="fw-semibold mb-1">Status tidak sinkron: PO_CREATED tetapi PO aktif tidak ditemukan.</div>
            <div class="muted mb-2">Recovery tidak berjalan otomatis. Sistem akan memeriksa ulang PO aktif saat tombol ditekan. Jika PO aktif ditemukan, recovery diblokir.</div>
            <form method="post" class="d-flex gap-2 flex-wrap align-items-end" onsubmit="return confirm('Pulihkan PR ini ke REVISION_WQS? Sistem akan memeriksa ulang bahwa tidak ada PO aktif.');">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <input type="hidden" name="recover_orphan_po_created" value="1">
                <div style="min-width:320px;flex:1">
                    <label class="form-label">Alasan recovery</label>
                    <input class="form-control form-control-sm" name="recovery_reason" required maxlength="250" placeholder="Contoh: PR salah / PO terkait sudah dibatalkan atau tidak terbentuk">
                </div>
                <button class="btn btn-warning btn-sm">Pulihkan ke REVISION_WQS</button>
            </form>
        </div>
    <?php endif; ?>
    <div class="cardx">
        <div class="headx">
            <div>
                <div class="title"><?= rmi_h(strtoupper((string)$pr['pr_code'])) ?></div>
                <div class="muted">Purchase Request WQS</div>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <span class="badgex <?= rmi_h($status) ?>"><?= rmi_h($status) ?></span>
                <?php if ($editable): ?>
                    <a class="btn btn-sm btn-primary" href="wqs_pr.php?edit_pr=<?= $id ?>">Edit / Revisi</a>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-light" target="_blank" href="wqs_pr_print.php?id=<?= $id ?>">Print</a>
            </div>
        </div>
        <div class="bodyx">
            <div class="grid">
                <div class="info"><div class="k">PR Code</div><div class="v"><?= rmi_h(strtoupper((string)$pr['pr_code'])) ?></div></div>
                <div class="info"><div class="k">Tanggal PR</div><div class="v"><?= rmi_h((string)($pr['pr_date'] ?? '-')) ?></div></div>
                <div class="info"><div class="k">Office</div><div class="v"><?= rmi_h(strtoupper((string)($pr['office_name'] ?? $pr['office_code'] ?? '-'))) ?></div></div>
                <div class="info"><div class="k">Kelompok</div><div class="v"><?= rmi_h($group) ?></div></div>
                <div class="info"><div class="k">Status</div><div class="v"><?= rmi_h($status) ?></div></div>
                <div class="info"><div class="k">Submit ke PQP</div><div class="v"><?= !empty($pr['submitted_at']) ? rmi_h(date('d-m-Y H:i', strtotime((string)$pr['submitted_at']))) : '-' ?></div></div>
                <div class="info"><div class="k">Dibuat Oleh</div><div class="v"><?= rmi_h((string)($pr['created_by'] ?? '-')) ?></div></div>
                <div class="info"><div class="k">Jumlah Item</div><div class="v"><?= count($items) ?></div></div>
                <div class="info"><div class="k">PO Aktif</div><div class="v"><?= $activePo ? rmi_h(strtoupper((string)($activePo['po_code'] ?? 'ADA'))) : '-' ?></div></div>
            </div>

            <div class="info mt-3">
                <div class="k">Catatan</div>
                <div class="v"><?= nl2br(rmi_h((string)($pr['note'] ?? '-'))) ?></div>
            </div>
        </div>
    </div>

    <div class="cardx">
        <div class="headx">
            <div>
                <div class="title">Detail Barang</div>
                <div class="muted">Snapshot item yang tersimpan pada PR.</div>
            </div>
        </div>
        <div class="bodyx p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width:55px">No</th>
                            <th>SKU</th>
                            <th>Nama Produk</th>
                            <th>Business Group</th>
                            <th>Kategori</th>
                            <th class="text-end">Qty</th>
                            <th>Unit</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$items): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">Tidak ada item PR.</td></tr>
                    <?php else: foreach ($items as $i => $it): ?>
                        <tr>
                            <td><?= (int)($it['line_no'] ?? ($i+1)) ?></td>
                            <td><strong><?= rmi_h(strtoupper((string)($it['sku'] ?? '-'))) ?></strong></td>
                            <td><?= rmi_h(strtoupper((string)($it['products_name'] ?? '-'))) ?></td>
                            <td><?= rmi_h(strtoupper((string)($it['business_group'] ?? '-'))) ?></td>
                            <td><?= rmi_h(strtoupper((string)($it['category'] ?? '-'))) ?></td>
                            <td class="text-end"><?= rmi_h(rtrim(rtrim(number_format((float)($it['qty'] ?? 0),2,'.',','),'0'),'.')) ?></td>
                            <td><?= rmi_h(strtoupper((string)($it['unit'] ?? '-'))) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php rmi_footer(); ?>
