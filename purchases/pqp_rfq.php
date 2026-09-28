<?php
/**
 * purchases/pqp_rfq.php
 * RFQ (Request for Quotation) — PQP buat RFQ, lihat comparison quotation dari manufacturers.
 */
declare(strict_types=1);

require_once __DIR__ . '/_purchases_bootstrap.php';
purchases_require_login();

require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['PQP.VIEW']);
} else {
    require_role(['ADMIN', 'SUPERADMIN', 'SYS', 'PQP', 'SCM', 'FIN', 'ACT', 'WQS', 'BRANCH', 'MANAGER', 'STAFF']);
}

require_once __DIR__ . '/_purchases_lib.php';
require_once __DIR__ . '/pqp_rfq_helper.php';
require_once __DIR__ . '/../_shared/rmi_layout.php';
$pdo = p_pdo();

// Ensure tables exist
try {
    $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pqp_rfq'");
    if (!$chk || !$chk->fetch()) {
        $sql = file_get_contents(__DIR__ . '/../sql/migrations/151_pqp_rfq_quotations.sql');
        if ($sql) $pdo->exec($sql);
    }
    $chk2 = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pqp_rfq_comments'");
    if (!$chk2 || !$chk2->fetch()) {
        $sql2 = file_get_contents(__DIR__ . '/../sql/migrations/152_pqp_rfq_extras.sql');
        if ($sql2) $pdo->exec($sql2);
    }
} catch (Throwable $e) {}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (function_exists('verify_csrf')) verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

$flash = p_flash_get();
$baseProject = rmi_layout_base_project();

// Create RFQ
if (isset($_POST['action']) && $_POST['action'] === 'create_rfq') {
    $title = trim((string)($_POST['title'] ?? ''));
    $product_description = trim((string)($_POST['product_description'] ?? ''));
    $product_sku = trim((string)($_POST['product_sku'] ?? ''));
    $quantity = (float)($_POST['quantity'] ?? 0);
    $unit = trim((string)($_POST['unit'] ?? 'unit'));
    $spec_notes = trim((string)($_POST['spec_notes'] ?? ''));
    $deadline = trim((string)($_POST['deadline'] ?? ''));
    $status = in_array($_POST['status'] ?? '', ['draft', 'open'], true) ? $_POST['status'] : 'draft';

    $err = [];
    if ($title === '') $err[] = 'Judul wajib.';
    if ($deadline === '') $err[] = 'Deadline wajib.';
    if (strtotime($deadline) === false) $err[] = 'Format deadline tidak valid.';

    if (empty($err)) {
        try {
            $y = (int)date('Y');
            $st = $pdo->query("SELECT MAX(id) FROM pqp_rfq WHERE YEAR(created_at)={$y}");
            $maxId = (int)($st->fetchColumn() ?: 0);
            if (!function_exists('doc_prefix_rfq')) require_once __DIR__ . '/../config/doc_numbering.php';
            $rfq_code = doc_prefix_rfq($y) . str_pad((string)($maxId + 1), 4, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO pqp_rfq (rfq_code, title, product_description, product_sku, quantity, unit, spec_notes, deadline, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$rfq_code, $title, $product_description ?: null, $product_sku ?: null, $quantity ?: null, $unit, $spec_notes ?: null, $deadline, $status, p_username()]);
            $newId = (int)$pdo->lastInsertId();
            pqp_rfq_audit($pdo, 'pqp_rfq', 'pqp_rfq', 'CREATE', $newId, $rfq_code,
                "RFQ dibuat: {$rfq_code} — {$title}",
                ['title' => $title, 'status' => $status, 'deadline' => $deadline]);
            p_flash_set('success', 'RFQ ' . $rfq_code . ' berhasil dibuat.');
        } catch (Throwable $e) {
            if (function_exists('rmi_log_module_error')) rmi_log_module_error('pqp_rfq', $e, ['action' => 'RFQ_CREATE', 'rfq_code' => $rfq_code ?? null]);
            p_flash_set('danger', 'Gagal: ' . $e->getMessage());
        }
    } else {
        p_flash_set('danger', implode(' ', $err));
    }
    rmi_redirect('pqp_rfq.php');
}

// Add comment (PQP)
if (isset($_POST['action']) && $_POST['action'] === 'add_comment' && isset($_POST['rfq_id'])) {
    $rfqId = (int)$_POST['rfq_id'];
    $body = trim((string)($_POST['body'] ?? ''));
    if ($rfqId > 0 && $body !== '') {
        $st = $pdo->prepare("SELECT id FROM pqp_rfq WHERE id=?");
        $st->execute([$rfqId]);
        $rfqRow = $st->fetch(PDO::FETCH_ASSOC);
        if ($rfqRow) {
            $pdo->prepare("INSERT INTO pqp_rfq_comments (rfq_id, author_type, author_id, author_name, body) VALUES (?, 'pqp', ?, ?, ?)")
                ->execute([$rfqId, $_SESSION['user_id'] ?? null, p_username(), $body]);
            pqp_rfq_audit($pdo, 'pqp_rfq', 'pqp_rfq', 'ADD_COMMENT', $rfqId,
                $rfqRow['rfq_code'] ?? null,
                "Komentar ditambahkan ke RFQ " . ($rfqRow['rfq_code'] ?? "#$rfqId"),
                ['body_preview' => mb_substr($body, 0, 80)]);
        }
    }
    rmi_redirect('pqp_rfq.php?id=' . $rfqId);
}

// Toggle status
if (isset($_POST['action']) && $_POST['action'] === 'toggle_status' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    $st = $pdo->prepare("SELECT rfq_code, status, title, deadline FROM pqp_rfq WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $newStatus = ($r['status'] ?? '') === 'open' ? 'closed' : 'open';
        $pdo->prepare("UPDATE pqp_rfq SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $id]);
        pqp_rfq_audit($pdo, 'pqp_rfq', 'pqp_rfq', 'TOGGLE_STATUS', $id, $r['rfq_code'] ?? '', "RFQ status: {$newStatus}", []);
        if ($newStatus === 'open') {
            $rfqRow = ['id' => $id, 'rfq_code' => $r['rfq_code'], 'title' => $r['title'] ?? '', 'deadline' => $r['deadline'] ?? ''];
            $st2 = $pdo->prepare("SELECT title FROM pqp_rfq WHERE id=?");
            $st2->execute([$id]);
            $r2 = $st2->fetch(PDO::FETCH_ASSOC);
            if ($r2) $rfqRow['title'] = $r2['title'];
            $n = pqp_rfq_notify_manufacturers_open($pdo, $rfqRow);
            if ($n > 0) p_flash_set('success', 'RFQ ' . ($r['rfq_code'] ?? '') . ' status diubah ke open. Notifikasi email terkirim ke ' . $n . ' manufacturer.');
            else p_flash_set('success', 'RFQ ' . ($r['rfq_code'] ?? '') . ' status diubah ke ' . $newStatus);
        } else {
            p_flash_set('success', 'RFQ ' . ($r['rfq_code'] ?? '') . ' status diubah ke ' . $newStatus);
        }
    }
    rmi_redirect('pqp_rfq.php' . (isset($_GET['id']) ? '?id=' . (int)$_GET['id'] : ''));
}

$rfqs = [];
$filterStatus = $_GET['status'] ?? '';
$filterDateFrom = trim((string)($_GET['date_from'] ?? ''));
$filterDateTo = trim((string)($_GET['date_to'] ?? ''));
try {
    $sql = "SELECT r.*, (SELECT COUNT(*) FROM pqp_rfq_quotations q WHERE q.rfq_id=r.id AND q.status='submitted') as quote_count FROM pqp_rfq r WHERE 1=1";
    $params = [];
    if (in_array($filterStatus, ['draft', 'open', 'closed', 'cancelled'], true)) {
        $sql .= " AND r.status=?";
        $params[] = $filterStatus;
    }
    if ($filterDateFrom !== '' && strtotime($filterDateFrom) !== false) {
        $sql .= " AND r.deadline >= ?";
        $params[] = $filterDateFrom . ' 00:00:00';
    }
    if ($filterDateTo !== '' && strtotime($filterDateTo) !== false) {
        $sql .= " AND r.deadline <= ?";
        $params[] = $filterDateTo . ' 23:59:59';
    }
    $sql .= " ORDER BY r.created_at DESC";
    $st = $params ? $pdo->prepare($sql) : $pdo->query($sql);
    if ($params) $st->execute($params);
    $rfqs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$viewId = (int)($_GET['id'] ?? 0);
$viewRfq = null;
$quotations = [];
if ($viewId > 0) {
    $st = $pdo->prepare("SELECT * FROM pqp_rfq WHERE id=?");
    $st->execute([$viewId]);
    $viewRfq = $st->fetch(PDO::FETCH_ASSOC);
    if ($viewRfq) {
        $st = $pdo->prepare("
            SELECT q.*, m.manufacture_name
            FROM pqp_rfq_quotations q
            LEFT JOIN master_manufactures m ON m.id=q.manufacture_id
            WHERE q.rfq_id=? AND q.status='submitted'
        ");
        $st->execute([$viewId]);
        $quotations = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('RFQ — Request for Quotation', [
    'active' => 'purchases_rfq',
    'breadcrumbs' => [
        ['label' => 'Purchases', 'url' => $baseProject . '/purchases/index.php'],
        'RFQ',
    ],
    'actions' => [
        ['label' => 'Manufacturer Portal', 'url' => $baseProject . '/manufacturer_portal/login.php', 'class' => 'btn btn-sm btn-outline-light', 'attrs' => 'target="_blank"'],
    ],
]);

$csrf = function_exists('csrf_token') ? csrf_token() : '';
?>
<?php if ($flash): ?>
<div class="alert alert-<?= rmi_h($flash['type']) ?>"><?= rmi_h($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($viewRfq): ?>
<div class="mb-4">
    <a href="pqp_rfq.php" class="btn btn-outline-secondary btn-sm">← Daftar RFQ</a>
</div>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><?= rmi_h($viewRfq['rfq_code']) ?> — <?= rmi_h($viewRfq['title']) ?></strong>
        <span class="badge bg-<?= ($viewRfq['status'] ?? '') === 'open' ? 'success' : 'secondary' ?>"><?= rmi_h($viewRfq['status']) ?></span>
    </div>
    <div class="card-body">
        <p><strong>Deskripsi:</strong> <?= nl2br(rmi_h($viewRfq['product_description'])) ?></p>
        <?php if ($viewRfq['product_sku']): ?><p><strong>SKU:</strong> <?= rmi_h($viewRfq['product_sku']) ?></p><?php endif; ?>
        <?php if ($viewRfq['quantity']): ?><p><strong>Qty:</strong> <?= rmi_h($viewRfq['quantity']) ?> <?= rmi_h($viewRfq['unit']) ?></p><?php endif; ?>
        <p><strong>Deadline:</strong> <?= rmi_h($viewRfq['deadline']) ?></p>
        <form method="post" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">
            <input type="hidden" name="action" value="toggle_status">
            <input type="hidden" name="id" value="<?= (int)$viewRfq['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary"><?= ($viewRfq['status'] ?? '') === 'open' ? 'Tutup RFQ' : 'Buka RFQ' ?></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <strong>Perbandingan Quotation (urut harga terendah)</strong>
        <?php if (!empty($quotations)): ?>
        <div>
            <a href="pqp_rfq_export.php?id=<?= (int)$viewRfq['id'] ?>&format=csv" class="btn btn-sm btn-light">Export CSV</a>
            <a href="pqp_rfq_export.php?id=<?= (int)$viewRfq['id'] ?>&format=xls" class="btn btn-sm btn-light">Export Excel</a>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($quotations)): ?>
        <p class="text-muted mb-0">Belum ada quotation dari manufacturer.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>#</th><th>Manufacturer</th><th>Harga</th><th>Currency</th><th>≈ USD</th><th>Lead Time</th><th>Payment Terms</th><th>Submit</th></tr></thead>
                <tbody>
                <?php foreach ($quotations as $i => $q): ?>
                <tr class="<?= $i === 0 ? 'table-success' : '' ?>">
                    <td><?= $i + 1 ?></td>
                    <td><?= rmi_h($q['manufacture_name'] ?? $q['manufacture_code']) ?></td>
                    <td><strong><?= number_format((float)$q['unit_price'], 2) ?></strong></td>
                    <td><?= rmi_h($q['currency']) ?></td>
                    <td class="text-muted"><?= number_format((float)($q['unit_price_usd'] ?? 0), 2) ?></td>
                    <td><?= $q['lead_time_days'] ? (int)$q['lead_time_days'] . ' hari' : '-' ?></td>
                    <td><?= rmi_h($q['payment_terms']) ?></td>
                    <td><?php if (!empty($q['file_rel'])): ?><a href="pqp_rfq_download.php?id=<?= (int)$q['id'] ?>" class="btn btn-sm btn-outline-secondary">Download</a><?php else: ?>-<?php endif; ?></td>
                    <td><?= rmi_h($q['submitted_at']) ?></td>
                </tr>
                <?php if (!empty($q['notes'])): ?>
                <tr><td colspan="9" class="small text-muted"><?= rmi_h($q['notes']) ?></td></tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$comments = [];
try {
    $stC = $pdo->prepare("SELECT * FROM pqp_rfq_comments WHERE rfq_id=? ORDER BY created_at ASC");
    $stC->execute([$viewId]);
    $comments = $stC->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<div class="card mb-4">
    <div class="card-header"><strong>Diskusi / Komentar</strong></div>
    <div class="card-body">
        <div class="mb-3">
            <?php foreach ($comments as $c): ?>
            <div class="border-bottom pb-2 mb-2">
                <small class="text-muted"><?= rmi_h($c['author_name']) ?> (<?= rmi_h($c['author_type']) ?>) — <?= rmi_h($c['created_at']) ?></small>
                <p class="mb-0"><?= nl2br(rmi_h($c['body'])) ?></p>
            </div>
            <?php endforeach; ?>
            <?php if (empty($comments)): ?><p class="text-muted small mb-0">Belum ada komentar.</p><?php endif; ?>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">
            <input type="hidden" name="action" value="add_comment">
            <input type="hidden" name="rfq_id" value="<?= (int)$viewRfq['id'] ?>">
            <textarea name="body" class="form-control mb-2" rows="2" placeholder="Tulis komentar untuk klarifikasi/nego..." required></textarea>
            <button type="submit" class="btn btn-sm btn-primary">Kirim</button>
        </form>
    </div>
</div>

<?php
$auditLogs = [];
try {
    $stA = $pdo->prepare("SELECT action, record_code, description, username, created_at FROM system_audit_logs WHERE module IN ('pqp_rfq','pqp_rfq_quotations') AND record_id=? ORDER BY created_at DESC LIMIT 20");
    $stA->execute([$viewId]);
    $auditLogs = $stA->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
if (!empty($auditLogs)):
?>
<div class="card mb-4">
    <div class="card-header"><strong>Audit Log</strong></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Deskripsi</th></tr></thead>
                <tbody>
                <?php foreach ($auditLogs as $a): ?>
                <tr><td><?= rmi_h($a['created_at']) ?></td><td><?= rmi_h($a['username']) ?></td><td><?= rmi_h($a['action']) ?></td><td><?= rmi_h($a['description']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>

<div class="card mb-4">
    <div class="card-header"><strong>Buat RFQ Baru</strong></div>
    <div class="card-body">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">
            <input type="hidden" name="action" value="create_rfq">
            <div class="row g-2">
                <div class="col-md-12"><label class="form-label">Judul *</label><input type="text" name="title" class="form-control" required placeholder="Contoh: Masker Bedah 3 Ply"></div>
                <div class="col-md-12"><label class="form-label">Deskripsi Produk / Spesifikasi</label><textarea name="product_description" class="form-control" rows="3" placeholder="Spesifikasi produk yang diminta"></textarea></div>
                <div class="col-md-3"><label class="form-label">SKU (opsional)</label><input type="text" name="product_sku" class="form-control"></div>
                <div class="col-md-2"><label class="form-label">Qty</label><input type="number" name="quantity" class="form-control" step="0.01" placeholder="0"></div>
                <div class="col-md-2"><label class="form-label">Unit</label><input type="text" name="unit" class="form-control" value="unit"></div>
                <div class="col-md-3"><label class="form-label">Deadline Submit *</label><input type="datetime-local" name="deadline" class="form-control" required></div>
                <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="draft">Draft</option><option value="open">Open</option></select></div>
                <div class="col-md-12"><label class="form-label">Catatan Spesifikasi</label><textarea name="spec_notes" class="form-control" rows="2"></textarea></div>
                <div class="col-md-12"><button type="submit" class="btn btn-primary">Buat RFQ</button></div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header"><strong>Daftar RFQ</strong></div>
    <div class="card-body">
        <form method="get" class="row g-2 mb-3 align-items-end">
            <div class="col-auto">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select form-select-sm" style="width:auto">
                    <option value="">Semua</option>
                    <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
                    <option value="open" <?= $filterStatus === 'open' ? 'selected' : '' ?>>Open</option>
                    <option value="closed" <?= $filterStatus === 'closed' ? 'selected' : '' ?>>Closed</option>
                    <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small">Deadline dari</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= rmi_h($filterDateFrom) ?>" style="width:auto">
            </div>
            <div class="col-auto">
                <label class="form-label small">sampai</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= rmi_h($filterDateTo) ?>" style="width:auto">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
                <a href="pqp_rfq.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>RFQ Code</th><th>Judul</th><th>Deadline</th><th>Status</th><th>Quotation</th><th>Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($rfqs as $r): ?>
                <tr>
                    <td><?= rmi_h($r['rfq_code']) ?></td>
                    <td><?= rmi_h($r['title']) ?></td>
                    <td><?= rmi_h($r['deadline']) ?></td>
                    <td><span class="badge bg-<?= ($r['status'] ?? '') === 'open' ? 'success' : 'secondary' ?>"><?= rmi_h($r['status']) ?></span></td>
                    <td><?= (int)($r['quote_count'] ?? 0) ?></td>
                    <td><a href="?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">Lihat</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (empty($rfqs)): ?>
        <p class="text-muted mb-0">Belum ada RFQ. Buat RFQ di form atas.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php rmi_footer(); ?>
