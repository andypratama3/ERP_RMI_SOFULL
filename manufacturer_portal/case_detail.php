<?php
/**
 * manufacturer_portal/case_detail.php
 * Detail case Reg Alkes + upload dokumen.
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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    rmi_redirect($base . '/manufacturer_portal/cases.php');
}

$stmt = $pdo->prepare("SELECT * FROM hrl_reg_alkes_cases WHERE id = ? AND manufacture_code = ? LIMIT 1");
$stmt->execute([$id, $mc]);
$case = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$case) {
    $_SESSION['mportal_flash'] = ['type' => 'danger', 'msg' => 'Case tidak ditemukan atau tidak ada akses.'];
    rmi_redirect($base . '/manufacturer_portal/cases.php');
}

$pageTitle = 'Case ' . ($case['case_code'] ?? '');
$flash = $_SESSION['mportal_flash'] ?? null;
unset($_SESSION['mportal_flash']);

// Fallback: isi manufacture_id dari master_manufactures jika kosong di case
if (empty($case['manufacture_id']) && !empty($case['manufacture_code'])) {
    $st = $pdo->prepare("SELECT id FROM master_manufactures WHERE manufacture_code = ? AND (deleted_at IS NULL OR deleted_at = '') LIMIT 1");
    $st->execute([$case['manufacture_code']]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if ($m) {
        $case['manufacture_id'] = (int)$m['id'];
    }
}

$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$case_dir = $root . '/master/uploads/manufactures/' . $case['manufacture_code'] . '/hrl/cases/' . $case['case_code'];

function mportal_safe_filename(string $name): string {
    $name = preg_replace('/[^\w\-. ]+/u', '_', $name);
    $name = preg_replace('/\s+/', '_', $name);
    return trim($name, '._');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'upload_doc') {
        $doc_type = strtoupper(trim((string)($_POST['doc_type'] ?? 'OTHER')));
        $doc_note = trim((string)($_POST['doc_note'] ?? ''));
        $allowed_types = ['CATALOG', 'QUOTATION', 'REG_DOSSIER', 'AKSESORIS_LIST', 'IFU_ID', 'OEM_PROPOSAL', 'NIE', 'OTHER'];
        if (!in_array($doc_type, $allowed_types, true)) {
            $doc_type = 'OTHER';
        }
        if (!isset($_FILES['doc_file']) || (isset($_FILES['doc_file']['error']) ? (int)$_FILES['doc_file']['error'] : UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
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
                if (!is_dir($case_dir)) {
                    @mkdir($case_dir, 0775, true);
                }
                $base_name = mportal_safe_filename($doc_type . '_' . date('Ymd_His') . '_' . $orig);
                if ($base_name === '') $base_name = 'doc_' . date('Ymd_His') . '.' . $ext;
                $dest = $case_dir . '/' . $base_name;
                if (!move_uploaded_file((string)$_FILES['doc_file']['tmp_name'], $dest)) {
                    $flash = ['type' => 'danger', 'msg' => 'Gagal menyimpan file.'];
                } else {
                    $file_rel = 'master/uploads/manufactures/' . $case['manufacture_code'] . '/hrl/cases/' . $case['case_code'] . '/' . $base_name;
                    $has_uploaded_via = false;
                    try {
                        $chk = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_case_docs' AND column_name='uploaded_via'");
                        $has_uploaded_via = $chk && $chk->fetch();
                    } catch (Throwable $e) {}
                    $doc_id = null;
                    if ($has_uploaded_via) {
                        $pdo->prepare("INSERT INTO hrl_reg_alkes_case_docs (case_id, doc_type, file_name, file_rel, note, uploaded_by, uploaded_via, portal_user_id) VALUES (?, ?, ?, ?, ?, ?, 'portal', ?)")
                            ->execute([$id, $doc_type, $base_name, $file_rel, $doc_note ?: null, 'portal_' . $user['username'], $user['id']]);
                        $doc_id = (int)$pdo->lastInsertId();
                    } else {
                        $pdo->prepare("INSERT INTO hrl_reg_alkes_case_docs (case_id, doc_type, file_name, file_rel, note, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$id, $doc_type, $base_name, $file_rel, $doc_note ?: null, 'portal_' . $user['username']]);
                        $doc_id = (int)$pdo->lastInsertId();
                    }
                    if (function_exists('master_audit')) {
                        $_SESSION['username'] = $_SESSION['username'] ?? 'mportal_' . $user['username'];
                        master_audit($pdo, 'manufacturer_portal', 'hrl_reg_alkes_case_docs', 'UPLOAD_DOC', $doc_id, (string)($case['case_code'] ?? ''), "Case doc uploaded: {$doc_type}", ['case_id' => $id, 'doc_type' => $doc_type]);
                    }
                    try {
                        $chk2 = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='hrl_reg_alkes_cases' AND column_name='source'");
                        if ($chk2 && $chk2->fetch()) {
                            $pdo->prepare("UPDATE hrl_reg_alkes_cases SET source='portal', portal_user_id=? WHERE id=?")->execute([$user['id'], $id]);
                        }
                    } catch (Throwable $e) {}
                    if ($doc_type === 'NIE') {
                        try {
                            $pdo->prepare("UPDATE hrl_reg_alkes_cases SET nie_file_rel=?, updated_by=? WHERE id=?")
                                ->execute([$file_rel, 'portal_' . $user['username'], $id]);
                        } catch (Throwable $e) {}
                    }
                    $flash = ['type' => 'success', 'msg' => 'Dokumen berhasil diupload.'];
                }
            }
        }
    } elseif ($action === 'upload_manu_doc' && !empty($case['manufacture_id'])) {
        $doc_type = strtoupper(trim((string)($_POST['doc_type'] ?? '')));
        $doc_note = trim((string)($_POST['doc_note'] ?? ''));
        $allowed_manu = ['PKS', 'LOA', 'LOA_KBRI'];
        if (!in_array($doc_type, $allowed_manu, true)) {
            $flash = ['type' => 'danger', 'msg' => 'Tipe dokumen manufacture harus PKS, LOA, atau LOA_KBRI.'];
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
                $manu_docs_dir = $root . '/master/uploads/manufactures/' . $case['manufacture_code'] . '/hrl/manufactures_docs';
                if (!is_dir($manu_docs_dir)) @mkdir($manu_docs_dir, 0775, true);
                $base_name = mportal_safe_filename($doc_type . '_' . date('Ymd_His') . '_' . $orig);
                if ($base_name === '') $base_name = 'doc_' . date('Ymd_His') . '.' . $ext;
                $dest = $manu_docs_dir . '/' . $base_name;
                if (!move_uploaded_file((string)$_FILES['doc_file']['tmp_name'], $dest)) {
                    $flash = ['type' => 'danger', 'msg' => 'Gagal menyimpan file.'];
                } else {
                    $file_rel = 'master/uploads/manufactures/' . $case['manufacture_code'] . '/hrl/manufactures_docs/' . $base_name;
                    try {
                        $pdo->prepare("INSERT INTO master_manufactures_docs (manufacture_id, doc_type, file_name, file_rel, note, uploaded_by) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([(int)$case['manufacture_id'], $doc_type, $base_name, $file_rel, $doc_note ?: null, 'portal_' . $user['username']]);
                        $manu_doc_id = (int)$pdo->lastInsertId();
                        if (function_exists('master_audit')) {
                            $_SESSION['username'] = $_SESSION['username'] ?? 'mportal_' . $user['username'];
                            master_audit($pdo, 'manufacturer_portal', 'master_manufactures_docs', 'UPLOAD_DOC', $manu_doc_id, (string)($case['manufacture_code'] ?? ''), "Manufacture doc uploaded: {$doc_type}", ['manufacture_id' => (int)$case['manufacture_id'], 'doc_type' => $doc_type]);
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

$stages = [
    1 => '1. PQP cari principal', 2 => '2. PQP quotation', 3 => '3. PQP PKS/LOA', 4 => '4. Legal LOA/KBRI',
    5 => '5. PQP minta berkas', 6 => '6. PQP pendukung', 7 => '7. Legal cek', 8 => '8. Legal OSS',
    9 => '9. Legal berkas ttd', 10 => '10. Legal submit', 11 => '11. Revisi diminta', 12 => '12. PQP revisi',
    13 => '13. Legal submit revisi', 14 => '14. Legal review NIE', 15 => '15. NIE terbit',
];
$stage_no = (int)$case['stage_no'];
$stage_label = $stages[$stage_no] ?? (string)$case['stage_code'];
$deadline = $case['revision_deadline'] && $case['revision_deadline'] !== '0000-00-00'
    ? date('d/m/Y', strtotime($case['revision_deadline'])) : '-';

$docs = [];
try {
    $d = $pdo->prepare("SELECT * FROM hrl_reg_alkes_case_docs WHERE case_id=? ORDER BY id DESC");
    $d->execute([$id]);
    $docs = $d->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$manu_docs = [];
$manu_docs_latest = [];
if (!empty($case['manufacture_id'])) {
    try {
        $md = $pdo->prepare("SELECT * FROM master_manufactures_docs WHERE manufacture_id=? ORDER BY uploaded_at DESC, id DESC");
        $md->execute([(int)$case['manufacture_id']]);
        $manu_docs = $md->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($manu_docs as $r) {
            $t = (string)($r['doc_type'] ?? '');
            if ($t !== '' && !isset($manu_docs_latest[$t])) $manu_docs_latest[$t] = $r;
        }
    } catch (Throwable $e) {}
}

$csrf = function_exists('csrf_token') ? csrf_token() : '';
$doc_types = ['REG_DOSSIER' => 'Berkas Registrasi', 'AKSESORIS_LIST' => 'Daftar Aksesoris', 'IFU_ID' => 'IFU (Bahasa Indonesia)', 'OEM_PROPOSAL' => 'OEM Proposal', 'CATALOG' => 'Katalog', 'QUOTATION' => 'Quotation', 'NIE' => 'NIE', 'OTHER' => 'Lainnya'];

$doc_options = '';
foreach ($doc_types as $k => $v) {
    $doc_options .= '<option value="' . rmi_h($k) . '">' . rmi_h($v) . '</option>';
}

$docs_rows = '';
foreach ($docs as $d) {
    $docs_rows .= '<tr><td><span class="badge bg-secondary">' . rmi_h($d['doc_type']) . '</span></td><td>' . rmi_h($d['file_name']) . '</td><td>' . rmi_h($d['uploaded_at'] ?? '') . '</td></tr>';
}

$manu_docs_card = '';
if (!empty($case['manufacture_id'])) {
    $manu_status = '';
    foreach (['PKS', 'LOA', 'LOA_KBRI'] as $t) {
        $r = $manu_docs_latest[$t] ?? null;
        $manu_status .= '<tr><td class="mono">' . rmi_h($t) . '</td><td>' . ($r ? '<span class="badge bg-success">' . rmi_h(mportal_t('has')) . '</span> ' . rmi_h($r['file_name']) : '<span class="badge bg-secondary">' . rmi_h(mportal_t('pending')) . '</span>') . '</td></tr>';
    }
    $manu_docs_card = '
<div class="card mb-4 border-primary">
    <div class="card-header bg-light"><strong>' . rmi_h(mportal_t('manu_docs_pks_loa')) . '</strong></div>
    <div class="card-body">
        <p class="small text-muted">' . rmi_h(mportal_t('manu_docs_desc')) . '</p>
        <table class="table table-sm mb-3"><thead><tr><th>' . rmi_h(mportal_t('type')) . '</th><th>' . rmi_h(mportal_t('status')) . '</th></tr></thead><tbody>' . $manu_status . '</tbody></table>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
            <input type="hidden" name="csrf_token" value="' . rmi_h($csrf) . '">
            <input type="hidden" name="action" value="upload_manu_doc">
            <div class="col-md-3"><label class="form-label small">' . rmi_h(mportal_t('type')) . '</label><select name="doc_type" class="form-select" required><option value="PKS">PKS</option><option value="LOA">LOA</option><option value="LOA_KBRI">LOA KBRI</option></select></div>
            <div class="col-md-4"><label class="form-label small">' . rmi_h(mportal_t('file')) . '</label><input type="file" name="doc_file" class="form-control" required accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.zip"></div>
            <div class="col-md-3"><label class="form-label small">' . rmi_h(mportal_t('note')) . '</label><input type="text" name="doc_note" class="form-control"></div>
            <div class="col-md-2"><button type="submit" class="btn btn-outline-primary">' . rmi_h(mportal_t('btn_upload')) . '</button></div>
        </form>
    </div>
</div>';
}

$content = '
<a href="' . rmi_h($base) . '/manufacturer_portal/cases.php" class="btn btn-outline-secondary btn-sm mb-3">' . rmi_h(mportal_t('back_cases')) . '</a>
<a href="' . rmi_h($base) . '/manufacturer_portal/" class="btn btn-outline-secondary btn-sm mb-3">' . rmi_h(mportal_t('dashboard')) . '</a>
<div class="alert alert-primary mb-3"><strong>' . rmi_icon('outbox') . ' ' . rmi_h(mportal_t('upload_form_hint')) . '</strong></div>
' . $manu_docs_card . '
<div class="card mb-4">
    <div class="card-header">Case ' . rmi_h($case['case_code']) . '</div>
    <div class="card-body">
        <p><strong>' . rmi_h(mportal_t('product_label')) . '</strong> ' . rmi_h($case['product_name']) . '</p>
        <p><strong>' . rmi_h(mportal_t('stage_label')) . '</strong> <span class="badge bg-info">' . rmi_h($stage_label) . '</span></p>
        <p><strong>' . rmi_h(mportal_t('status_label')) . '</strong> ' . rmi_h($case['status']) . '</p>
        <p><strong>' . rmi_h(mportal_t('revision_deadline')) . '</strong> ' . rmi_h($deadline) . '</p>
    </div>
</div>
<div class="card mb-4">
    <div class="card-header">' . rmi_h(mportal_t('upload_documents')) . '</div>
    <div class="card-body">
        <p class="small text-muted mb-2">' . rmi_h(mportal_t('format_max')) . '</p>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="' . rmi_h($csrf) . '">
            <input type="hidden" name="action" value="upload_doc">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">' . rmi_h(mportal_t('doc_type')) . '</label>
                    <select name="doc_type" class="form-select" required>
                        ' . $doc_options . '
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">' . rmi_h(mportal_t('file')) . '</label>
                    <input type="file" name="doc_file" class="form-control" required accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.zip">
                </div>
                <div class="col-md-4">
                    <label class="form-label">' . rmi_h(mportal_t('note_optional')) . '</label>
                    <input type="text" name="doc_note" class="form-control" placeholder="' . rmi_h(mportal_t('note')) . '">
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-2">' . rmi_h(mportal_t('btn_upload')) . '</button>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-header">' . rmi_h(mportal_t('uploaded_docs')) . '</div>
    <div class="card-body">
        <table class="table table-sm"><thead><tr><th>' . rmi_h(mportal_t('type')) . '</th><th>' . rmi_h(mportal_t('file')) . '</th><th>' . rmi_h(mportal_t('date')) . '</th></tr></thead><tbody>
        ' . ($docs_rows ?: '<tr><td colspan="3" class="text-muted">' . rmi_h(mportal_t('no_docs')) . '</td></tr>') . '
        </tbody></table>
    </div>
</div>
';

require __DIR__ . '/layout.php';
