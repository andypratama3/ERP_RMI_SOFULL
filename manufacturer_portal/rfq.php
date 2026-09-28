<?php
/**
 * manufacturer_portal/rfq.php
 * RFQ — Manufacturer lihat RFQ, submit quotation.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_mportal_login();
require_once __DIR__ . '/../purchases/pqp_rfq_helper.php';

$pdo = rmi_db_pdo();
$user = mportal_user();
$mc = $user['manufacture_code'];
$mid = $user['manufacture_id'];
$base = mportal_base();

$pageTitle = mportal_t('rfq');
$flash = $_SESSION['mportal_flash'] ?? null;
unset($_SESSION['mportal_flash']);

// Ensure tables exist
try {
    $chk = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='pqp_rfq'");
    if (!$chk || !$chk->fetch()) {
        $sql = file_get_contents(__DIR__ . '/../sql/migrations/151_pqp_rfq_quotations.sql');
        if ($sql) $pdo->exec($sql);
    }
} catch (Throwable $e) {}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (function_exists('verify_csrf')) verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

// Add comment (manufacturer)
if (isset($_POST['action']) && $_POST['action'] === 'add_comment' && isset($_POST['rfq_id'])) {
    $rfqId = (int)$_POST['rfq_id'];
    $body = trim((string)($_POST['body'] ?? ''));
    if ($rfqId > 0 && $body !== '') {
        $st = $pdo->prepare("SELECT id FROM pqp_rfq WHERE id=? AND status='open'");
        $st->execute([$rfqId]);
        if ($st->fetch()) {
            $pdo->prepare("INSERT INTO pqp_rfq_comments (rfq_id, author_type, author_id, author_name, manufacture_code, body) VALUES (?, 'manufacturer', ?, ?, ?, ?)")
                ->execute([$rfqId, $user['id'], $user['full_name'] ?: $user['username'], $mc, $body]);
        }
    }
    rmi_redirect('rfq.php#form-' . $rfqId);
}

// Submit quotation
if (isset($_POST['action']) && $_POST['action'] === 'submit_quote') {
    $rfq_id = (int)($_POST['rfq_id'] ?? 0);
    $unit_price = (float)($_POST['unit_price'] ?? 0);
    $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
    $lead_time_days = (int)($_POST['lead_time_days'] ?? 0);
    $payment_terms = trim((string)($_POST['payment_terms'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    $err = [];
    if ($rfq_id <= 0) $err[] = 'RFQ invalid.';
    if ($unit_price <= 0) $err[] = 'Harga wajib > 0.';
    if (!in_array($currency, ['USD', 'CNY', 'IDR', 'EUR'], true)) $currency = 'USD';

    $file_rel = null;
    if (!empty($_FILES['quotation_file']['name']) && ($_FILES['quotation_file']['error'] ?? 0) === UPLOAD_ERR_OK) {
        $allowed = ['pdf', 'xls', 'xlsx', 'doc', 'docx'];
        $ext = strtolower(pathinfo($_FILES['quotation_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true)) {
            $base = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($_FILES['quotation_file']['name']));
            $base = substr($base ?: 'quotation', 0, 100);
            $base = date('Ymd_His') . '_' . $base;
            $dir = __DIR__ . '/../master/uploads/manufactures/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $mc) . '/rfq';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $path = $dir . '/' . $base;
            if (move_uploaded_file($_FILES['quotation_file']['tmp_name'], $path)) {
                $file_rel = 'master/uploads/manufactures/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $mc) . '/rfq/' . $base;
            }
        }
    }

    if (empty($err)) {
        $st = $pdo->prepare("SELECT id, rfq_code, title, deadline, status FROM pqp_rfq WHERE id=? AND status='open'");
        $st->execute([$rfq_id]);
        $rfq = $st->fetch(PDO::FETCH_ASSOC);
        if (!$rfq) {
            $_SESSION['mportal_flash'] = ['type' => 'danger', 'msg' => 'RFQ tidak ditemukan atau sudah ditutup.'];
        } elseif (strtotime($rfq['deadline']) < time()) {
            $_SESSION['mportal_flash'] = ['type' => 'danger', 'msg' => 'Deadline RFQ sudah lewat.'];
        } else {
            try {
                $st = $pdo->prepare("SELECT id FROM pqp_rfq_quotations WHERE rfq_id=? AND manufacture_id=?");
                $st->execute([$rfq_id, $mid ?: 0]);
                $existing = $st->fetch();
                $mname = '';
                $stM = $pdo->prepare("SELECT manufacture_name FROM master_manufactures WHERE id=? LIMIT 1");
                $stM->execute([$mid ?: 0]);
                if ($rM = $stM->fetch(PDO::FETCH_ASSOC)) $mname = $rM['manufacture_name'] ?? '';

                if ($existing) {
                    $up = $pdo->prepare("UPDATE pqp_rfq_quotations SET unit_price=?, currency=?, lead_time_days=?, payment_terms=?, notes=?, file_rel=COALESCE(?, file_rel), submitted_at=NOW(), submitted_by=? WHERE rfq_id=? AND manufacture_id=?");
                    $up->execute([$unit_price, $currency, $lead_time_days ?: null, $payment_terms ?: null, $notes ?: null, $file_rel, 'portal_' . $user['username'], $rfq_id, $mid]);
                } else {
                    $pdo->prepare("INSERT INTO pqp_rfq_quotations (rfq_id, manufacture_id, manufacture_code, unit_price, currency, lead_time_days, payment_terms, notes, file_rel, status, submitted_by) VALUES (?,?,?,?,?,?,?,?,?,'submitted',?)")
                        ->execute([$rfq_id, $mid ?: 0, $mc, $unit_price, $currency, $lead_time_days ?: null, $payment_terms ?: null, $notes ?: null, $file_rel, 'portal_' . $user['username']]);
                }
                pqp_rfq_audit($pdo, 'pqp_rfq_quotations', 'pqp_rfq_quotations', 'SUBMIT', $rfq_id, $rfq['rfq_code'], "Quotation submitted by {$mc}", ['manufacture_code' => $mc]);
                pqp_rfq_notify_pqp_submitted($pdo, $rfq, $mc, $mname);
                $_SESSION['mportal_flash'] = ['type' => 'success', 'msg' => 'Quotation berhasil disubmit.'];
            } catch (Throwable $e) {
                $_SESSION['mportal_flash'] = ['type' => 'danger', 'msg' => 'Gagal: ' . $e->getMessage()];
            }
        }
    } else {
        $_SESSION['mportal_flash'] = ['type' => 'danger', 'msg' => implode(' ', $err)];
    }
    rmi_redirect('rfq.php' . (isset($_GET['filter']) ? '?filter=' . urlencode((string)$_GET['filter']) : ''));
}

// Get manufacture_id if null
if (!$mid) {
    $st = $pdo->prepare("SELECT id FROM master_manufactures WHERE manufacture_code=? AND (deleted_at IS NULL OR deleted_at='') LIMIT 1");
    $st->execute([$mc]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if ($m) $mid = (int)$m['id'];
}

// List open RFQs (deadline not passed) — filter & sort
$filter = $_GET['filter'] ?? 'all'; // all | submitted | pending
$rfqs = [];
$my_quotes = [];
try {
    $rfqs = $pdo->query("SELECT * FROM pqp_rfq WHERE status='open' AND deadline > NOW() ORDER BY deadline ASC")->fetchAll(PDO::FETCH_ASSOC);
    if ($mid) {
        $st = $pdo->prepare("SELECT rfq_id, unit_price, currency, lead_time_days, payment_terms, notes, submitted_at, file_rel FROM pqp_rfq_quotations WHERE manufacture_id=? AND status='submitted'");
        $st->execute([$mid]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $my_quotes[(int)$r['rfq_id']] = $r;
        }
    }
    if ($filter === 'submitted') {
        $rfqs = array_filter($rfqs, fn($r) => isset($my_quotes[(int)$r['id']]));
    } elseif ($filter === 'pending') {
        $rfqs = array_filter($rfqs, fn($r) => !isset($my_quotes[(int)$r['id']]));
    }
    $rfqs = array_values($rfqs);
} catch (Throwable $e) {}

$csrf = function_exists('csrf_token') ? csrf_token() : '';

$rows = '';
foreach ($rfqs as $r) {
    $q = $my_quotes[(int)$r['id']] ?? null;
    $deadline_ts = strtotime($r['deadline']);
    $deadline_fmt = date('d/m/Y H:i', $deadline_ts);
    $rows .= '<tr class="mp-table-row">
        <td><strong class="mp-rfq-code">' . rmi_h($r['rfq_code']) . '</strong></td>
        <td>' . rmi_h($r['title']) . '</td>
        <td>' . rmi_h($r['deadline']) . '</td>
        <td>' . ($q ? '<span class="mp-badge-sub">' . rmi_h(mportal_t('has')) . '</span> ' . number_format((float)$q['unit_price'], 2) . ' ' . rmi_h($q['currency']) : '<span class="mp-badge-pend">' . rmi_h(mportal_t('pending')) . '</span>') . '</td>
        <td>' . ($q ? '<a href="#form-' . (int)$r['id'] . '" class="mp-btn mp-btn-sm mp-btn-outline">Edit</a>' : '<a href="#form-' . (int)$r['id'] . '" class="mp-btn mp-btn-sm mp-btn-primary">' . rmi_h(mportal_t('btn_upload')) . '</a>') . '</td>
    </tr>';
}

$forms = '';
foreach ($rfqs as $r) {
    $q = $my_quotes[(int)$r['id']] ?? null;
    $rid = (int)$r['id'];
    $comms = [];
    try {
        $stC = $pdo->prepare("SELECT * FROM pqp_rfq_comments WHERE rfq_id=? ORDER BY created_at ASC");
        $stC->execute([$rid]);
        $comms = $stC->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $forms .= '
    <div class="mp-rfq-card" id="form-' . $rid . '">
        <div class="mp-rfq-card-header">' . rmi_h($r['rfq_code']) . ' — ' . rmi_h($r['title']) . '</div>
        <div class="mp-rfq-card-body">
            <p class="mp-rfq-desc">' . nl2br(rmi_h($r['product_description'])) . '</p>
            <p class="mp-rfq-deadline"><strong>Deadline:</strong> ' . rmi_h($r['deadline']) . '</p>
            <form method="post" enctype="multipart/form-data" class="mp-rfq-form">
                <input type="hidden" name="csrf_token" value="' . rmi_h($csrf) . '">
                <input type="hidden" name="action" value="submit_quote">
                <input type="hidden" name="rfq_id" value="' . $rid . '">
                <div class="row g-3 align-items-end">
                    <div class="col-md-2"><label class="form-label small">Price *</label><input type="number" name="unit_price" class="form-control" step="0.01" required value="' . rmi_h($q['unit_price'] ?? '') . '" placeholder="0.00"></div>
                    <div class="col-md-2"><label class="form-label small">Currency</label><select name="currency" class="form-select"><option value="USD"' . (($q['currency'] ?? '') === 'USD' ? ' selected' : '') . '>USD</option><option value="CNY"' . (($q['currency'] ?? '') === 'CNY' ? ' selected' : '') . '>CNY</option><option value="IDR"' . (($q['currency'] ?? '') === 'IDR' ? ' selected' : '') . '>IDR</option><option value="EUR"' . (($q['currency'] ?? '') === 'EUR' ? ' selected' : '') . '>EUR</option></select></div>
                    <div class="col-md-2"><label class="form-label small">Lead Time (days)</label><input type="number" name="lead_time_days" class="form-control" value="' . rmi_h($q['lead_time_days'] ?? '') . '" placeholder="0"></div>
                    <div class="col-md-2"><label class="form-label small">Payment Terms</label><input type="text" name="payment_terms" class="form-control" value="' . rmi_h($q['payment_terms'] ?? '') . '" placeholder="e.g. 30 days"></div>
                    <div class="col-md-2"><label class="form-label small">' . rmi_h(mportal_t('note')) . '</label><input type="text" name="notes" class="form-control" value="' . rmi_h($q['notes'] ?? '') . '"></div>
                    <div class="col-md-2"><label class="form-label small">File (PDF/Excel)</label><input type="file" name="quotation_file" class="form-control form-control-sm" accept=".pdf,.xls,.xlsx,.doc,.docx"></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-primary">' . ($q ? 'Update' : rmi_h(mportal_t('btn_upload'))) . '</button></div>
                </div>
            </form>
            ' . (!empty($q['file_rel']) ? '<p class="small mt-2" style="color:#64748b">Attachment: ' . rmi_h(basename($q['file_rel'])) . '</p>' : '') . '
            <div class="mp-rfq-comments">
                <div class="mp-rfq-comments-title">💬 Diskusi</div>
                ' . (empty($comms) ? '<p class="mp-rfq-comment-meta mb-2">Belum ada komentar.</p>' : '') . '
                ' . implode('', array_map(function($c) {
                    return '<div class="mp-rfq-comment"><div>' . nl2br(rmi_h($c['body'])) . '</div><div class="mp-rfq-comment-meta">' . rmi_h($c['author_name']) . ' (' . rmi_h($c['author_type']) . ') · ' . rmi_h($c['created_at']) . '</div></div>';
                }, $comms)) . '
                <form method="post" class="mt-3 d-flex gap-2 align-items-center">
                    <input type="hidden" name="csrf_token" value="' . rmi_h($csrf) . '">
                    <input type="hidden" name="action" value="add_comment">
                    <input type="hidden" name="rfq_id" value="' . $rid . '">
                    <input type="text" name="body" class="form-control" style="max-width:400px" placeholder="Tulis komentar..." required>
                    <button type="submit" class="mp-btn mp-btn-sm mp-btn-primary">Kirim</button>
                </form>
            </div>
        </div>
    </div>';
}

$filterLinks = '<div class="mp-filter-bar"><a href="?filter=all" class="mp-filter-btn' . ($filter === 'all' ? ' active' : '') . '">All</a><a href="?filter=submitted" class="mp-filter-btn' . ($filter === 'submitted' ? ' active' : '') . '">' . rmi_h(mportal_t('has')) . '</a><a href="?filter=pending" class="mp-filter-btn' . ($filter === 'pending' ? ' active' : '') . '">' . rmi_h(mportal_t('pending')) . '</a></div>';

$content = '
<style>
.mp-page-header{background:linear-gradient(135deg,rgba(15,45,90,.9),rgba(29,78,216,.7));border:1px solid rgba(59,130,246,.25);border-radius:16px;padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.mp-page-title{font-size:18px;font-weight:800;color:#fff;margin:0}
.mp-page-sub{font-size:13px;color:rgba(255,255,255,.85);margin:4px 0 0}
.mp-back{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25);transition:all .2s;margin-bottom:16px}
.mp-back:hover{background:rgba(255,255,255,.25);color:#fff}
.mp-filter-bar{display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap}
.mp-filter-btn{padding:8px 16px;border-radius:10px;text-decoration:none;font-size:12px;font-weight:600;background:#fff;border:2px solid #e2e8f0;color:#64748b;transition:all .2s}
.mp-filter-btn:hover{border-color:#1d4ed8;color:#1d4ed8}
.mp-filter-btn.active{background:#1d4ed8;border-color:#1d4ed8;color:#fff}
.mp-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;margin-bottom:16px}
.mp-table{width:100%;border-collapse:collapse}
.mp-table th{background:#f8fafc;color:#334155;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:12px 16px;border-bottom:2px solid #e2e8f0;text-align:left}
.mp-table td{padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;color:#334155;vertical-align:middle}
.mp-table-row:hover{background:#f8fafc}
.mp-rfq-code{color:#1d4ed8;font-weight:700}
.mp-badge-sub{background:#dbeafe;color:#1d4ed8;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:600}
.mp-badge-pend{background:#f1f5f9;color:#64748b;padding:4px 10px;border-radius:8px;font-size:11px;font-weight:600}
.mp-btn{padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;transition:all .2s;border:none;cursor:pointer}
.mp-btn-sm{padding:5px 10px;font-size:11px}
.mp-btn-primary{background:linear-gradient(135deg,#0f2d5a,#1d4ed8);color:#fff}
.mp-btn-primary:hover{filter:brightness(1.1);color:#fff}
.mp-btn-outline{background:#fff;border:2px solid #94a3b8;color:#64748b}
.mp-btn-outline:hover{background:#f8fafc;border-color:#1d4ed8;color:#1d4ed8}
.mp-section-title{font-size:13px;font-weight:700;color:#334155;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid #e2e8f0}
.mp-rfq-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:20px;overflow:hidden}
.mp-rfq-card-header{background:#f8fafc;padding:14px 20px;border-bottom:1px solid #e2e8f0;font-weight:700;font-size:14px;color:#1e293b}
.mp-rfq-card-body{padding:20px}
.mp-rfq-desc{font-size:13px;color:#64748b;line-height:1.5;margin-bottom:12px}
.mp-rfq-deadline{font-size:12px;color:#334155;margin-bottom:16px}
.mp-rfq-form .row{margin-bottom:12px}
.mp-rfq-form label{font-size:12px;font-weight:600;color:#334155;margin-bottom:4px}
.mp-rfq-form .form-control,.mp-rfq-form .form-select{border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:13px}
.mp-rfq-form .form-control:focus,.mp-rfq-form .form-select:focus{border-color:#1d4ed8;outline:none;box-shadow:0 0 0 2px rgba(29,78,216,.2)}
.mp-rfq-form .btn-primary{background:linear-gradient(135deg,#0f2d5a,#1d4ed8);border:none;border-radius:8px;font-weight:600;padding:8px 16px}
.mp-rfq-comments{border-top:1px solid #e2e8f0;padding-top:16px;margin-top:16px}
.mp-rfq-comments-title{font-size:12px;font-weight:700;color:#334155;margin-bottom:10px}
.mp-rfq-comment{font-size:12px;color:#475569;margin-bottom:8px;padding:8px 12px;background:#f8fafc;border-radius:8px}
.mp-rfq-comment-meta{font-size:11px;color:#94a3b8;margin-top:4px}
.mp-empty{text-align:center;padding:40px 20px;color:#64748b;font-size:14px}
</style>

<a href="' . rmi_h($base) . '/manufacturer_portal/" class="mp-back">← ' . rmi_h(mportal_t('back_dashboard')) . '</a>

<div class="mp-page-header">
  <div>
    <h1 class="mp-page-title">📋 ' . rmi_h(mportal_t('rfq')) . '</h1>
    <p class="mp-page-sub">' . rmi_h(mportal_t('cases_for')) . ' ' . rmi_h($mc) . '</p>
  </div>
</div>

' . $filterLinks . '

<div class="mp-card">
  <div class="table-responsive">
    <table class="mp-table">
      <thead>
        <tr>
          <th>RFQ Code</th>
          <th>' . rmi_h(mportal_t('document')) . '</th>
          <th>' . rmi_h(mportal_t('deadline')) . '</th>
          <th>' . rmi_h(mportal_t('status')) . '</th>
          <th>' . rmi_h(mportal_t('action')) . '</th>
        </tr>
      </thead>
      <tbody>' . ($rows ?: '<tr><td colspan="5" class="mp-empty">Tidak ada RFQ terbuka saat ini.</td></tr>') . '</tbody>
    </table>
  </div>
</div>

<div class="mp-section-title">📤 Submit Quotation</div>
' . $forms . '
';

require __DIR__ . '/layout.php';
