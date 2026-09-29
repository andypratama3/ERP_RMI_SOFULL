<?php
/**
 * manufacturer_portal/manufacture_docs.php
 * Penawaran Kerjasama: upload catalog, quotation, proposal, company profile, PKS, LOA, LOA_KBRI.
 * Tersedia tanpa perlu case — manufacturer bisa submit penawaran kapan saja.
 */
declare(strict_types=1);
require_once __DIR__ . '/../_shared/rmi_icons.php';

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_mportal_login();

$pdo = rmi_db_pdo();
$user = mportal_user();
$mc = $user['manufacture_code'];
$base = mportal_base();

// Ambil manufacture_id dari master_manufactures
$manufacture_id = null;
$manuName = $mc;
$st = $pdo->prepare("SELECT id, manufacture_name FROM master_manufactures WHERE manufacture_code = ? AND (deleted_at IS NULL OR deleted_at = '') LIMIT 1");
$st->execute([$mc]);
$m = $st->fetch(PDO::FETCH_ASSOC);
if ($m) {
    $manufacture_id = (int)$m['id'];
    $manuName = (string)($m['manufacture_name'] ?? $mc);
}

$pageTitle = mportal_t('partnership_proposal') . ' & ' . mportal_t('upload_documents');
$flash = $_SESSION['mportal_flash'] ?? null;
unset($_SESSION['mportal_flash']);

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);

function mportal_safe_filename(string $name): string {
    $name = preg_replace('/[^\w\-. ]+/u', '_', $name);
    $name = preg_replace('/\s+/', '_', $name);
    return trim($name, '._');
}

// Penawaran: CATALOG, QUOTATION, PENAWARAN, COMPANY_PROFILE | Perjanjian: PKS, LOA, LOA_KBRI
$allowed_manu = ['CATALOG', 'QUOTATION', 'PENAWARAN', 'COMPANY_PROFILE', 'PKS', 'LOA', 'LOA_KBRI'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $manufacture_id) {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'upload_manu_doc') {
        $doc_type = strtoupper(trim((string)($_POST['doc_type'] ?? '')));
        $doc_note = trim((string)($_POST['doc_note'] ?? ''));
        if (!in_array($doc_type, $allowed_manu, true)) {
            $flash = ['type' => 'danger', 'msg' => 'Tipe dokumen tidak valid.'];
        } elseif (!isset($_FILES['doc_file']) || (isset($_FILES['doc_file']['error']) ? (int)$_FILES['doc_file']['error'] : UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errCode = isset($_FILES['doc_file']) ? (int)($_FILES['doc_file']['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            $flash = ['type' => 'danger', 'msg' => $errCode === UPLOAD_ERR_NO_FILE ? 'File belum dipilih.' : ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE ? 'File terlalu besar (maks 10MB).' : 'Error upload kode ' . $errCode)];
        } else {
            $orig = (string)($_FILES['doc_file']['name'] ?? 'file');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed_ext = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'zip'];
            $max_size = 10 * 1024 * 1024; // 10MB
            if (($_FILES['doc_file']['size'] ?? 0) > $max_size) {
                $flash = ['type' => 'danger', 'msg' => 'File terlalu besar. Maksimal 10MB.'];
            } elseif (!in_array($ext, $allowed_ext, true)) {
                $flash = ['type' => 'danger', 'msg' => 'Ekstensi tidak diizinkan. Diizinkan: ' . implode(', ', $allowed_ext)];
            } else {
                $manu_docs_dir = $root . '/master/uploads/manufactures/' . $mc . '/hrl/manufactures_docs';
                if (!is_dir($manu_docs_dir)) {
                    @mkdir($manu_docs_dir, 0775, true);
                }
                $base_name = mportal_safe_filename($doc_type . '_' . date('Ymd_His') . '_' . $orig);
                if ($base_name === '') $base_name = 'doc_' . date('Ymd_His') . '.' . $ext;
                $dest = $manu_docs_dir . '/' . $base_name;
                if (!move_uploaded_file((string)$_FILES['doc_file']['tmp_name'], $dest)) {
                    $flash = ['type' => 'danger', 'msg' => 'Gagal menyimpan file.'];
                } else {
                    $file_rel = 'master/uploads/manufactures/' . $mc . '/hrl/manufactures_docs/' . $base_name;
                    try {
                        $pdo->prepare("INSERT INTO master_manufactures_docs (manufacture_id, doc_type, file_name, file_rel, note, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$manufacture_id, $doc_type, $base_name, $file_rel, $doc_note ?: null, 'portal_' . $user['username']]);
                        $manu_doc_id = (int)$pdo->lastInsertId();
                        if (function_exists('master_audit')) {
                            $_SESSION['username'] = $_SESSION['username'] ?? 'mportal_' . $user['username'];
                            master_audit($pdo, 'manufacturer_portal', 'master_manufactures_docs', 'UPLOAD_DOC', $manu_doc_id, (string)$mc, "Manufacture doc uploaded: {$doc_type}", ['manufacture_id' => $manufacture_id, 'doc_type' => $doc_type]);
                        }
                        $flash = ['type' => 'success', 'msg' => 'Dokumen ' . $doc_type . ' berhasil diupload.'];
                    } catch (Throwable $e) {
                        $flash = ['type' => 'danger', 'msg' => 'Gagal menyimpan: ' . $e->getMessage()];
                    }
                }
            }
        }
    }
}

$manu_docs = [];
$manu_docs_latest = [];
if ($manufacture_id) {
    try {
        $md = $pdo->prepare("SELECT * FROM master_manufactures_docs WHERE manufacture_id=? ORDER BY uploaded_at DESC, id DESC");
        $md->execute([$manufacture_id]);
        $manu_docs = $md->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($manu_docs as $r) {
            $t = (string)($r['doc_type'] ?? '');
            if ($t !== '' && !isset($manu_docs_latest[$t])) $manu_docs_latest[$t] = $r;
        }
    } catch (Throwable $e) {}
}

$csrf = function_exists('csrf_token') ? csrf_token() : '';

$doc_labels = [
    'CATALOG' => mportal_t('doc_catalog'),
    'QUOTATION' => mportal_t('doc_quotation'),
    'PENAWARAN' => mportal_t('doc_penawaran'),
    'COMPANY_PROFILE' => mportal_t('doc_company_profile'),
    'PKS' => mportal_t('doc_pks'),
    'LOA' => mportal_t('doc_loa'),
    'LOA_KBRI' => mportal_t('doc_loa_kbri'),
];

$manu_status = '';
foreach (['CATALOG', 'QUOTATION', 'PENAWARAN', 'COMPANY_PROFILE', 'PKS', 'LOA', 'LOA_KBRI'] as $t) {
    $r = $manu_docs_latest[$t] ?? null;
    $label = $doc_labels[$t] ?? $t;
    $hasBadge = rmi_h(mportal_t('has'));
    $pendingBadge = rmi_h(mportal_t('pending'));
    $manu_status .= '<tr><td>' . rmi_h($label) . '</td><td>' . ($r ? '<span class="badge bg-success">' . $hasBadge . '</span> ' . rmi_h($r['file_name']) . ' (' . rmi_h($r['uploaded_at'] ?? '') . ')' : '<span class="badge bg-secondary">' . $pendingBadge . '</span>') . '</td></tr>';
}

$doc_options = '';
foreach ($doc_labels as $k => $v) {
    $doc_options .= '<option value="' . rmi_h($k) . '">' . rmi_h($v) . '</option>';
}

$upload_form = '';
if ($manufacture_id) {
    $upload_form = '
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
        <input type="hidden" name="csrf_token" value="' . rmi_h($csrf) . '">
        <input type="hidden" name="action" value="upload_manu_doc">
        <div class="col-md-3"><label class="form-label small">' . rmi_h(mportal_t('doc_type')) . ' *</label><select name="doc_type" class="form-select" required>' . $doc_options . '</select></div>
        <div class="col-md-4"><label class="form-label small">' . rmi_h(mportal_t('file')) . ' *</label><input type="file" name="doc_file" class="form-control" required accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.zip"></div>
        <div class="col-md-3"><label class="form-label small">' . rmi_h(mportal_t('note')) . '</label><input type="text" name="doc_note" class="form-control" placeholder="' . rmi_h(mportal_t('optional')) . '"></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary">' . rmi_h(mportal_t('btn_upload')) . '</button></div>
    </form>';
} else {
    $upload_form = '<div class="alert alert-warning">' . rmi_h(mportal_t('manufacture_not_found')) . '</div>';
}

$content = '
<a href="' . rmi_h($base) . '/manufacturer_portal/" class="btn btn-outline-secondary btn-sm mb-3">' . rmi_h(mportal_t('back_dashboard')) . '</a>
<div class="card border-success mb-4">
    <div class="card-header bg-success text-white"><strong>' . rmi_icon('outbox') . ' ' . rmi_h(mportal_t('partnership_proposal_title')) . '</strong></div>
    <div class="card-body">
        <p class="mb-2">' . rmi_h(mportal_t('upload_catalog_desc')) . '</p>
        <p class="text-muted small mb-3">' . rmi_h($manuName) . ' (' . rmi_h($mc) . ')</p>
        <table class="table table-sm mb-3"><thead><tr><th>' . rmi_h(mportal_t('document')) . '</th><th>' . rmi_h(mportal_t('status')) . '</th></tr></thead><tbody>' . $manu_status . '</tbody></table>
        ' . $upload_form . '
        <p class="small text-muted mt-2 mb-0">' . rmi_h(mportal_t('format_max')) . '</p>
    </div>
</div>
';

require __DIR__ . '/layout.php';
