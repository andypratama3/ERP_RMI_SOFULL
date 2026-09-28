<?php
declare(strict_types=1);
require_once __DIR__ . '/../_shared/assets.php';
require_once __DIR__ . '/../master/auth.php';
require_login();

require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../_shared/helpers.php';
require_once __DIR__ . '/../purchases/_purchases_lib.php';
require_once __DIR__ . '/../master/_audit_master.php';

$perm_pr_view   = function_exists('can_any') ? can_any(['WQS.PR_VIEW','WQS.VIEW']) : true;
$perm_pr_create = function_exists('can') ? can('WQS.PR_CREATE') : p_can_create_pr();
$perm_pr_edit   = function_exists('can') ? can('WQS.PR_EDIT') : p_can_create_pr();
$perm_pr_delete = function_exists('can') ? can('WQS.PR_DELETE') : p_is_admin_plus();

if (function_exists('require_any_permission')) {
    require_any_permission(['WQS.PR_VIEW','WQS.PR_CREATE','WQS.PR_EDIT','WQS.VIEW','PURCHASES.PO_VIEW','PURCHASES.PO_CREATE','PURCHASES.PO_EDIT']);
} else {
    require_role(['ADMIN','SUPERADMIN','SYS','WQS','BRANCH','MANAGER','STAFF']);
}

$pdo = p_pdo();
p_ensure_schema($pdo);
p_ensure_col($pdo, 'wqs_pr', 'category', "`category` VARCHAR(20) NOT NULL DEFAULT 'BMHP'");
p_ensure_col($pdo, 'wqs_pr', 'business_group', "`business_group` VARCHAR(20) NULL AFTER `category`");
p_ensure_col($pdo, 'wqs_pr', 'submitted_at', "`submitted_at` DATETIME NULL AFTER `status`");
p_ensure_col($pdo, 'wqs_pr_items', 'business_group', "`business_group` VARCHAR(20) NULL AFTER `products_name`");
p_ensure_col($pdo, 'wqs_pr_items', 'category', "`category` VARCHAR(20) NULL AFTER `business_group`");

function pr_expected_business_group(string $category): string {
    $category = strtoupper(trim($category));
    if ($category === 'BMHP') return 'BMHP';
    if (in_array($category, ['UNIT_ACC','ALKES','AKSESORIS'], true)) return 'UNIT_ACC';
    return '';
}

function pr_document_group(array $row): string {
    $bg = strtoupper(trim((string)($row['business_group'] ?? '')));
    if (in_array($bg, ['BMHP','UNIT_ACC'], true)) return $bg;
    $cat = strtoupper(trim((string)($row['category'] ?? '')));
    $derived = pr_expected_business_group($cat);
    return $derived !== '' ? $derived : 'BMHP';
}

function pr_product_matches_group(array $product, string $documentGroup): bool {
    $documentGroup = strtoupper(trim($documentGroup));
    $productGroup = strtoupper(trim((string)($product['business_group'] ?? '')));
    $productCategory = strtoupper(trim((string)($product['category'] ?? '')));
    if ($documentGroup === 'BMHP') {
        return $productGroup === 'BMHP' && $productCategory === 'BMHP';
    }
    if ($documentGroup === 'UNIT_ACC') {
        return $productGroup === 'UNIT_ACC' && in_array($productCategory, ['ALKES','AKSESORIS'], true);
    }
    return false;
}

$__pr_elg = __DIR__ . '/../_shared/rmi_error_logger.php';
if (is_file($__pr_elg)) require_once $__pr_elg;
unset($__pr_elg);

// ── Office Scope (BRANCH staff: hanya office sendiri) ──
$_pr_user       = function_exists('auth_user') ? auth_user() : [];
$_pr_user_dept  = strtoupper(trim((string)($_pr_user['department'] ?? $_pr_user['level'] ?? '')));
$_pr_user_role  = strtoupper(trim((string)($_pr_user['role'] ?? '')));
$_pr_user_office = strtoupper(trim((string)($_pr_user['office_code'] ?? '')));
if ($_pr_user_dept === '' && function_exists('auth_dept')) $_pr_user_dept = strtoupper(auth_dept());
if ($_pr_user_office === '' && function_exists('auth_office_code')) $_pr_user_office = strtoupper(trim(auth_office_code()));

$_pr_is_branch = in_array($_pr_user_dept, ['BRANCH'], true)
              && !in_array($_pr_user_role, ['SYS','ADMIN','SUPERADMIN'], true);
$_pr_office_scope = ($_pr_is_branch && $_pr_user_office !== '') ? $_pr_user_office : null;

$PR_EDITABLE_STATUSES = ['DRAFT','REVISION_WQS'];
// SUBMITTED boleh dihapus/arsip HANYA bila belum memiliki PO aktif.
// Ini diperlukan untuk membersihkan PR trial yang sudah terlanjur disubmit ke PQP.
$PR_DELETABLE_STATUSES = ['DRAFT','REVISION_WQS','SUBMITTED'];

// ── Schema check ──
$pr_schema_errors = [];
if (!p_has_table($pdo, 'wqs_pr'))       $pr_schema_errors[] = 'Table wqs_pr belum tersedia.';
if (!p_has_table($pdo, 'wqs_pr_items')) $pr_schema_errors[] = 'Table wqs_pr_items belum tersedia.';
try {
    $prColsCheck = p_cols($pdo, 'wqs_pr');
    $prItemColsCheck = p_cols($pdo, 'wqs_pr_items');
    $mpColsCheck = p_cols($pdo, 'master_products');
    if (!in_array('business_group', $prColsCheck, true)) $pr_schema_errors[] = 'Kolom wqs_pr.business_group belum tersedia.';
    if (!in_array('business_group', $prItemColsCheck, true) || !in_array('category', $prItemColsCheck, true)) $pr_schema_errors[] = 'Kolom snapshot business_group/category pada wqs_pr_items belum tersedia.';
    if (!in_array('business_group', $mpColsCheck, true) || !in_array('category', $mpColsCheck, true)) $pr_schema_errors[] = 'Master Product belum memiliki business_group/category.';
} catch (Throwable $e) {
    $pr_schema_errors[] = 'Gagal memeriksa schema klasifikasi produk.';
}

$flash = p_flash_get();

// ── Rekonsiliasi status PR dengan PO aktual ──
// Tujuan:
// 1) PR DRAFT / REVISION_WQS / SUBMITTED yang SUDAH mempunyai PO aktif harus menjadi PO_CREATED.
// 2) PR yang PO-nya CANCELLED/CANCELED/VOID tidak dianggap selesai.
// 3) Soft-deleted PR/PO tidak ikut.
// 4) Relasi utama tetap pr_id; fallback legacy hanya exact reference, bukan tebakan tanggal/office.
//
// Catatan penting:
// UI dan KPI harus memakai definisi active PO yang sama agar tidak muncul kasus
// dashboard masih SUBMITTED tetapi daftar PR menampilkan PO_CREATED, atau sebaliknya.

$__pr_po_link_conditions = [];
$__po_active_status_sql = "UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')";

try {
    if (p_has_table($pdo, 'purchases_po')) {
        $poCols = p_cols($pdo, 'purchases_po');

        // Relasi ID adalah evidence paling kuat.
        if (in_array('pr_id', $poCols, true)) {
            $__pr_po_link_conditions[] = "po.pr_id = pr.id";
        }
        if (in_array('source_pr_id', $poCols, true)) {
            $__pr_po_link_conditions[] = "po.source_pr_id = pr.id";
        }
        if (in_array('wqs_pr_id', $poCols, true)) {
            $__pr_po_link_conditions[] = "po.wqs_pr_id = pr.id";
        }

        // Fallback exact PR code untuk data legacy.
        foreach (['pr_code','source_pr_code','wqs_pr_code'] as $c) {
            if (in_array($c, $poCols, true)) {
                $__pr_po_link_conditions[] =
                    "TRIM(COALESCE(pr.pr_code,''))<>'' AND " .
                    "UPPER(TRIM(COALESCE(po.`{$c}`,''))) = UPPER(TRIM(COALESCE(pr.pr_code,'')))";
            }
        }

        // Legacy note fallback: PR code lengkap harus benar-benar ada di note PO.
        if (in_array('note', $poCols, true)) {
            $__pr_po_link_conditions[] =
                "TRIM(COALESCE(pr.pr_code,''))<>'' AND " .
                "INSTR(UPPER(COALESCE(po.note,'')), UPPER(TRIM(pr.pr_code))) > 0";
        }

        // Jika schema tidak punya satupun referensi legacy, minimal pakai pr_id bila ada.
        if (!$__pr_po_link_conditions) {
            $__pr_po_link_conditions[] = "0=1";
        }

        $__pr_po_link_sql = '(' . implode(' OR ', $__pr_po_link_conditions) . ')';

        // A. Sinkronisasi direct / exact-reference.
        // UPDATE JOIN dipakai agar aman pada MySQL dan benar-benar menyimpan PO_CREATED di DB,
        // bukan hanya mengganti badge pada UI.
        $joinCond = $__pr_po_link_sql;
        $pdo->exec("
            UPDATE wqs_pr pr
            INNER JOIN purchases_po po
                ON {$joinCond}
               AND po.deleted_at IS NULL
               AND {$__po_active_status_sql}
            SET pr.status = 'PO_CREATED'
            WHERE pr.deleted_at IS NULL
              AND UPPER(TRIM(COALESCE(pr.status,''))) IN ('DRAFT','REVISION_WQS','SUBMITTED')
        ");

        // B. Sinkronisasi PR saudara/revisi legacy.
        // Dipakai hanya bila business reference/note sama persis dan tidak kosong.
        // Relasi PO terhadap pr_src tetap harus nyata melalui po.pr_id.
        if (in_array('pr_id', $poCols, true)) {
            $pdo->exec("
                UPDATE wqs_pr pr
                INNER JOIN wqs_pr pr_src
                  ON pr_src.id <> pr.id
                 AND pr_src.deleted_at IS NULL
                 AND UPPER(TRIM(COALESCE(pr_src.office_code,''))) = UPPER(TRIM(COALESCE(pr.office_code,'')))
                 AND COALESCE(pr_src.pr_date,'0000-00-00') = COALESCE(pr.pr_date,'0000-00-00')
                 AND UPPER(TRIM(COALESCE(pr_src.category,'BMHP'))) = UPPER(TRIM(COALESCE(pr.category,'BMHP')))
                 AND TRIM(COALESCE(pr.note,'')) <> ''
                 AND UPPER(TRIM(COALESCE(pr_src.note,''))) = UPPER(TRIM(COALESCE(pr.note,'')))
                INNER JOIN purchases_po po
                  ON po.pr_id = pr_src.id
                 AND po.deleted_at IS NULL
                 AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')
                SET pr.status = 'PO_CREATED'
                WHERE pr.deleted_at IS NULL
                  AND UPPER(TRIM(COALESCE(pr.status,''))) IN ('DRAFT','REVISION_WQS','SUBMITTED')
            ");
        }
    }
} catch (Throwable $e) {
    if (function_exists('rmi_log_module_error')) {
        rmi_log_module_error('wqs_pr', $e, ['stage' => 'SYNC_PR_PO_CREATED']);
    }
}

if (!isset($__pr_po_link_sql)) {
    $__pr_po_link_sql = '(0=1)';
}
// ── Master data ──
$offices  = [];
$products = [];
try {
    $offices = $pdo->query("SELECT office_code, office_name FROM master_office ORDER BY office_name")->fetchAll();
    if ($_pr_office_scope !== null) {
        $offices = array_values(array_filter($offices, fn($o) => strtoupper(trim($o['office_code'] ?? '')) === $_pr_office_scope));
    }
} catch (Throwable $e) {}

try {
    $skuCol = 'sku'; $nameCol = 'products_name';
    try { $pdo->query("SELECT sku FROM master_products LIMIT 1"); } catch (Throwable $e) { $skuCol = 'products_code'; }
    try { $pdo->query("SELECT products_name FROM master_products LIMIT 1"); } catch (Throwable $e) { $nameCol = 'product_name'; }
    $products = $pdo->query("SELECT id, {$skuCol} AS sku, {$nameCol} AS products_name, unit, UPPER(TRIM(COALESCE(category,''))) AS category, UPPER(TRIM(COALESCE(business_group,''))) AS business_group FROM master_products WHERE status='active' AND UPPER(TRIM(COALESCE(category,''))) IN ('BMHP','ALKES','AKSESORIS') AND UPPER(TRIM(COALESCE(business_group,''))) IN ('BMHP','UNIT_ACC') ORDER BY UPPER({$skuCol}), {$nameCol}")->fetchAll();
} catch (Throwable $e) { $products = []; }

// ================================================================
// POST: Bulk Actions
// ================================================================
if (empty($pr_schema_errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $action = (string)($_POST['bulk_action'] ?? '');
    $ids    = $_POST['selected_ids'] ?? [];
    $idsInt = array_values(array_filter(array_map('intval', (array)$ids), fn($v) => $v > 0));

    if (empty($idsInt)) {
        p_flash_set('danger', 'Tidak ada PR yang dipilih.');
    } elseif ($action === 'bulk_submit') {
        if (!$perm_pr_edit) {
            p_flash_set('danger', 'Tidak ada izin EDIT untuk bulk submit PR.');
        } else {
            try {
                $in = implode(',', array_fill(0, count($idsInt), '?'));
                $stChk = $pdo->prepare("
                    SELECT pr.id, pr.status, pr.pr_code,
                           (SELECT COUNT(*) FROM purchases_po po
                            WHERE {$__pr_po_link_sql}
                              AND po.deleted_at IS NULL
                              AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')) AS active_po_count
                    FROM wqs_pr pr
                    WHERE pr.id IN ($in) AND pr.deleted_at IS NULL
                ");
                $stChk->execute($idsInt);
                $okIds = [];
                $bad = [];
                while ($rr = $stChk->fetch(PDO::FETCH_ASSOC)) {
                    if ((int)($rr['active_po_count'] ?? 0) === 0 && in_array(strtoupper($rr['status'] ?? ''), $PR_EDITABLE_STATUSES, true)) {
                        $okIds[] = (int)$rr['id'];
                    } else {
                        $bad[] = ($rr['pr_code'] ?? '') . ' (' . ($rr['status'] ?? '') . ')';
                    }
                }
                if (!empty($bad)) {
                    p_flash_set('warning', 'Beberapa PR tidak bisa di-submit (bukan DRAFT/REVISION_WQS): ' . rmi_h(implode(', ', $bad)));
                }
                if (!empty($okIds)) {
                    $inOk = implode(',', array_fill(0, count($okIds), '?'));
                    $pdo->prepare("UPDATE wqs_pr SET status='SUBMITTED', submitted_at=NOW() WHERE id IN ($inOk)")->execute($okIds);
                    foreach ($okIds as $oid) {
                        p_audit($pdo, 'PR', '', 'BULK_SUBMIT', ['id' => $oid]);
                    }
                    p_flash_set('success', count($okIds) . ' PR berhasil di-SUBMIT ke PQP.');
                }
            } catch (Throwable $e) {
                p_flash_set('danger', 'Bulk submit gagal: ' . rmi_h($e->getMessage()));
            }
        }
    } elseif ($action === 'bulk_delete') {
        if (!$perm_pr_delete) {
            p_flash_set('danger', 'Tidak ada izin DELETE untuk bulk hapus PR.');
        } else {
            try {
                $in = implode(',', array_fill(0, count($idsInt), '?'));
                $stChk = $pdo->prepare("
                    SELECT pr.id, pr.status, pr.pr_code,
                           (SELECT COUNT(*) FROM purchases_po po
                            WHERE {$__pr_po_link_sql}
                              AND po.deleted_at IS NULL
                              AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')) AS active_po_count
                    FROM wqs_pr pr
                    WHERE pr.id IN ($in) AND pr.deleted_at IS NULL
                ");
                $stChk->execute($idsInt);
                $okIds = [];
                $bad = [];
                while ($rr = $stChk->fetch(PDO::FETCH_ASSOC)) {
                    if ((int)($rr['active_po_count'] ?? 0) === 0 && in_array(strtoupper($rr['status'] ?? ''), $PR_DELETABLE_STATUSES, true)) {
                        $okIds[] = (int)$rr['id'];
                    } else {
                        $bad[] = ($rr['pr_code'] ?? '') . ' (' . ($rr['status'] ?? '') . ')';
                    }
                }
                if (!empty($bad)) {
                    p_flash_set('warning', 'Beberapa PR tidak bisa dihapus (sudah diproses): ' . rmi_h(implode(', ', $bad)));
                }
                if (!empty($okIds)) {
                    $inOk = implode(',', array_fill(0, count($okIds), '?'));
                    $pdo->prepare("UPDATE wqs_pr SET deleted_at=NOW() WHERE id IN ($inOk)")->execute($okIds);
                    foreach ($okIds as $oid) {
                        p_audit($pdo, 'PR', '', 'BULK_DELETE', ['id' => $oid]);
                    }
                    p_flash_set('success', count($okIds) . ' PR berhasil dihapus.');
                }
            } catch (Throwable $e) {
                p_flash_set('danger', 'Bulk delete gagal: ' . rmi_h($e->getMessage()));
            }
        }
    }
    rmi_redirect($_SERVER['PHP_SELF']);
}


// ================================================================
// POST: Submit Single ke PQP — START SLA PQP
// Canonical start: saat WQS benar-benar mengubah DRAFT/REVISION_WQS -> SUBMITTED.
// Tidak menyentuh PR yang sudah memiliki PO aktif.
// ================================================================
if (empty($pr_schema_errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_single'])) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));

    if (!$perm_pr_edit) {
        p_flash_set('danger', 'Tidak ada izin EDIT untuk submit PR.');
    } else {
        $submit_id = (int)($_POST['submit_single'] ?? 0);
        try {
            $st = $pdo->prepare("
                SELECT pr.id, pr.status, pr.pr_code, pr.office_code,
                       (SELECT COUNT(*) FROM purchases_po po
                        WHERE {$__pr_po_link_sql}
                          AND po.deleted_at IS NULL
                          AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')) AS active_po_count
                FROM wqs_pr pr
                WHERE pr.id=? AND pr.deleted_at IS NULL
                LIMIT 1
            ");
            $st->execute([$submit_id]);
            $rowSubmit = $st->fetch(PDO::FETCH_ASSOC);

            if (!$rowSubmit) {
                p_flash_set('danger', 'PR tidak ditemukan.');
            } elseif ($_pr_office_scope !== null && strtoupper(trim((string)($rowSubmit['office_code'] ?? ''))) !== $_pr_office_scope) {
                p_flash_set('danger', 'Akses ditolak: bukan PR kantor Anda.');
            } elseif ((int)($rowSubmit['active_po_count'] ?? 0) > 0) {
                p_flash_set('danger', 'PR sudah mempunyai PO aktif. Submit ulang diblokir.');
            } elseif (!in_array(strtoupper(trim((string)($rowSubmit['status'] ?? ''))), $PR_EDITABLE_STATUSES, true)) {
                p_flash_set('danger', 'PR hanya dapat di-submit dari status DRAFT / REVISION_WQS.');
            } else {
                // START SLA PQP. Re-submit setelah REVISION_WQS memulai siklus SLA baru.
                $upd = $pdo->prepare("
                    UPDATE wqs_pr
                    SET status='SUBMITTED', submitted_at=NOW()
                    WHERE id=?
                      AND deleted_at IS NULL
                      AND UPPER(TRIM(COALESCE(status,''))) IN ('DRAFT','REVISION_WQS')
                ");
                $upd->execute([$submit_id]);

                if ($upd->rowCount() === 1) {
                    p_audit($pdo, 'PR', (string)$rowSubmit['pr_code'], 'SUBMIT_TO_PQP', [
                        'id' => $submit_id,
                        'sla_start' => 'submitted_at'
                    ]);
                    if (function_exists('master_audit')) {
                        master_audit(
                            $pdo, 'wqs_pr', 'wqs_pr', 'SUBMIT_TO_PQP',
                            $submit_id, (string)$rowSubmit['pr_code'],
                            'PR submitted to PQP; SLA PQP started',
                            ['sla_start' => 'submitted_at']
                        );
                    }
                    p_flash_set('success', 'PR ' . rmi_h((string)$rowSubmit['pr_code']) . ' berhasil di-SUBMIT ke PQP. Durasi SLA PQP mulai dihitung.');
                } else {
                    p_flash_set('warning', 'Status PR berubah sebelum proses submit selesai. Silakan refresh dan cek kembali.');
                }
            }
        } catch (Throwable $e) {
            p_flash_set('danger', 'Submit PR gagal: ' . rmi_h($e->getMessage()));
        }
    }
    rmi_redirect($_SERVER['PHP_SELF']);
}

// ================================================================
// POST: Delete Single
// ================================================================
if (empty($pr_schema_errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_single'])) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    if (!$perm_pr_delete) {
        p_flash_set('danger', 'Tidak ada izin DELETE.');
    } else {
        $del_id = (int)$_POST['delete_single'];
        try {
            $st = $pdo->prepare("
                SELECT pr.id, pr.status, pr.pr_code, pr.office_code,
                       (SELECT COUNT(*) FROM purchases_po po
                        WHERE {$__pr_po_link_sql}
                          AND po.deleted_at IS NULL
                          AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')) AS active_po_count
                FROM wqs_pr pr
                WHERE pr.id=? AND pr.deleted_at IS NULL
                LIMIT 1
            ");
            $st->execute([$del_id]);
            $delRow = $st->fetch(PDO::FETCH_ASSOC);
            if (!$delRow) {
                p_flash_set('danger', 'PR tidak ditemukan.');
            } elseif ($_pr_office_scope !== null && strtoupper($delRow['office_code'] ?? '') !== $_pr_office_scope) {
                p_flash_set('danger', 'Akses ditolak: bukan PR kantor Anda.');
            } elseif ((int)($delRow['active_po_count'] ?? 0) > 0) {
                p_flash_set('danger', 'PR sudah mempunyai PO aktif. Tidak bisa dihapus.');
            } elseif (!in_array(strtoupper($delRow['status'] ?? ''), $PR_DELETABLE_STATUSES, true)) {
                p_flash_set('danger', 'PR sudah diproses (status: ' . rmi_h($delRow['status']) . '). Tidak bisa dihapus.');
            } else {
                $pdo->prepare("UPDATE wqs_pr SET deleted_at=NOW() WHERE id=?")->execute([$del_id]);
                p_audit($pdo, 'PR', $delRow['pr_code'], 'DELETE', ['id' => $del_id]);
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'wqs_pr', 'wqs_pr', 'DELETE', $del_id, $delRow['pr_code'], "PR deleted: {$delRow['pr_code']}", []);
                }
                p_flash_set('success', 'PR ' . rmi_h($delRow['pr_code']) . ' berhasil dihapus.');
            }
        } catch (Throwable $e) {
            p_flash_set('danger', 'Gagal menghapus: ' . rmi_h($e->getMessage()));
        }
    }
    rmi_redirect($_SERVER['PHP_SELF']);
}

// ================================================================
// Load PR for edit/revision
// ================================================================
$edit_pr_id = (int)($_GET['edit_pr'] ?? 0);
$edit_pr = null;
$edit_pr_items = [];
if ($edit_pr_id > 0 && $perm_pr_edit && empty($pr_schema_errors)) {
    try {
        $st = $pdo->prepare("
            SELECT pr.*,
                   (SELECT COUNT(*) FROM purchases_po po
                    WHERE {$__pr_po_link_sql}
                      AND po.deleted_at IS NULL
                      AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')) AS active_po_count
            FROM wqs_pr pr
            WHERE pr.id=? AND pr.deleted_at IS NULL
            LIMIT 1
        ");
        $st->execute([$edit_pr_id]);
        $tmp = $st->fetch(PDO::FETCH_ASSOC);
        if ($tmp && (int)($tmp['active_po_count'] ?? 0) === 0 && in_array(strtoupper($tmp['status'] ?? ''), $PR_EDITABLE_STATUSES, true)) {
            if ($_pr_office_scope === null || strtoupper(trim((string)($tmp['office_code'] ?? ''))) === $_pr_office_scope) {
                $edit_pr = $tmp;
                $sti = $pdo->prepare("SELECT * FROM wqs_pr_items WHERE pr_id=? AND deleted_at IS NULL ORDER BY line_no,id");
                $sti->execute([$edit_pr_id]);
                $edit_pr_items = $sti->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {}
}

// ================================================================
// POST: Update PR (DRAFT / REVISION_WQS only)
// ================================================================
if (empty($pr_schema_errors) && isset($_POST['action']) && $_POST['action'] === 'update_pr') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    if (!$perm_pr_edit) {
        p_flash_set('danger', 'Tidak ada izin EDIT PR.');
        rmi_redirect('wqs_pr.php');
    }
    $pr_id       = (int)($_POST['pr_id'] ?? 0);
    $pr_date     = trim((string)($_POST['pr_date'] ?? ''));
    $office_code = up($_POST['office_code'] ?? '');
    $note        = trim((string)($_POST['note'] ?? ''));
    $pr_category = strtoupper(trim((string)($_POST['pr_category'] ?? 'BMHP')));
    if (!in_array($pr_category, ['BMHP','UNIT_ACC'], true)) $pr_category = 'BMHP';
    $pr_business_group = $pr_category;

    try {
        $st = $pdo->prepare("SELECT * FROM wqs_pr WHERE id=? AND deleted_at IS NULL LIMIT 1");
        $st->execute([$pr_id]);
        $cur = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cur) throw new RuntimeException('PR tidak ditemukan.');
        $curStatus = strtoupper((string)($cur['status'] ?? ''));
        if (!in_array($curStatus, $PR_EDITABLE_STATUSES, true)) throw new RuntimeException('PR status '.$curStatus.' sudah tidak dapat direvisi.');
        if ($_pr_office_scope !== null && strtoupper(trim((string)($cur['office_code'] ?? ''))) !== $_pr_office_scope) throw new RuntimeException('Akses ditolak: bukan PR kantor Anda.');
        if ($_pr_office_scope !== null && $office_code !== $_pr_office_scope) throw new RuntimeException('Staff BRANCH hanya boleh menggunakan office '.$_pr_office_scope.'.');
        if ($office_code === '') throw new RuntimeException('Office wajib dipilih.');

        $d = DateTime::createFromFormat('Y-m-d', $pr_date);
        if (!$d || $d->format('Y-m-d') !== $pr_date) throw new RuntimeException('Format tanggal PR tidak valid.');

        $pids = $_POST['product_id'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $units = $_POST['unit'] ?? [];
        $validItems = [];
        $line = 1;
        for ($i=0; $i<count($pids); $i++) {
            $pid=(int)$pids[$i]; $qty=(float)($qtys[$i] ?? 0); $unit=trim((string)($units[$i] ?? 'pcs'));
            if ($pid<=0 || $qty<=0) continue;
            $selectedProduct = null;
            foreach ($products as $pp) { if ((int)$pp['id'] === $pid) { $selectedProduct = $pp; break; } }
            if (!$selectedProduct) throw new RuntimeException('Produk tidak aktif / tidak ditemukan pada Master Product.');
            if (!pr_product_matches_group($selectedProduct, $pr_business_group)) {
                throw new RuntimeException('Produk ' . rmi_sku((string)$selectedProduct['sku']) . ' tidak sesuai kelompok PR ' . $pr_business_group . '. BMHP hanya menerima BMHP; UNIT ACC hanya menerima ALKES/AKSESORIS dari business_group UNIT_ACC.');
            }
            $sku = rmi_sku((string)$selectedProduct['sku']);
            $pname = rmi_product_name((string)$selectedProduct['products_name']);
            $punit = (string)($selectedProduct['unit'] ?? 'pcs');
            if ($unit==='') $unit=$punit;
            $itemBusinessGroup = strtoupper(trim((string)($selectedProduct['business_group'] ?? '')));
            $itemCategory = strtoupper(trim((string)($selectedProduct['category'] ?? '')));
            $validItems[]=['line_no'=>$line++,'product_id'=>$pid,'sku'=>$sku,'products_name'=>$pname,'business_group'=>$itemBusinessGroup,'category'=>$itemCategory,'qty'=>$qty,'unit'=>$unit];
        }
        if (!$validItems) throw new RuntimeException('Minimal 1 produk valid (qty > 0) wajib diisi.');

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE wqs_pr SET pr_date=?, office_code=?, category=?, business_group=?, note=? WHERE id=?")->execute([$pr_date,$office_code,$pr_category,$pr_business_group,$note,$pr_id]);
        $pdo->prepare("DELETE FROM wqs_pr_items WHERE pr_id=?")->execute([$pr_id]);
        $sti=$pdo->prepare("INSERT INTO wqs_pr_items (pr_id,line_no,product_id,sku,products_name,business_group,category,qty,unit) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach($validItems as $it) $sti->execute([$pr_id,$it['line_no'],$it['product_id'],$it['sku'],$it['products_name'],$it['business_group'],$it['category'],$it['qty'],$it['unit']]);
        $pdo->commit();
        p_audit($pdo,'PR',(string)$cur['pr_code'],'REVISION_SAVE',['id'=>$pr_id,'status'=>$curStatus]);
        if (function_exists('master_audit')) master_audit($pdo,'wqs_pr','wqs_pr','REVISION_SAVE',$pr_id,(string)$cur['pr_code'],'PR revision saved',['status'=>$curStatus]);
        p_flash_set('success','Revisi PR '.rmi_h((string)$cur['pr_code']).' berhasil disimpan. Silakan submit ulang ke PQP.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        p_flash_set('danger','Gagal menyimpan revisi PR: '.rmi_h($e->getMessage()));
    }
    rmi_redirect('wqs_pr.php');
}

// ================================================================
// POST: Create PR
// ================================================================
if (empty($pr_schema_errors) && isset($_POST['action']) && $_POST['action'] === 'create_pr') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));

    if (!$perm_pr_create) {
        p_flash_set('danger', 'Tidak ada izin CREATE PR.');
        rmi_redirect('wqs_pr.php');
    }

    $pr_date     = trim((string)($_POST['pr_date'] ?? date('Y-m-d')));
    $office_code = up($_POST['office_code'] ?? '');
    $note        = trim((string)($_POST['note'] ?? ''));
    $pr_category = strtoupper(trim((string)($_POST['pr_category'] ?? 'BMHP')));

    if (!in_array($pr_category, ['BMHP','UNIT_ACC'], true)) $pr_category = 'BMHP';
    $pr_business_group = $pr_category;

    $errors = [];
    if ($office_code === '') $errors[] = 'Office wajib dipilih.';

    // Guard BRANCH
    if ($_pr_office_scope !== null && $office_code !== '' && $office_code !== $_pr_office_scope) {
        $errors[] = 'Staff BRANCH hanya boleh buat PR untuk kantor ' . $_pr_office_scope . '.';
    }

    // Validate date
    if ($pr_date !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $pr_date);
        if (!$d || $d->format('Y-m-d') !== $pr_date) {
            $errors[] = 'Format tanggal PR tidak valid.';
        }
    } else {
        $errors[] = 'Tanggal PR wajib diisi.';
    }

    // Validate items
    $pids = $_POST['product_id'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $units = $_POST['unit'] ?? [];
    $validItems = [];
    $line = 1;
    for ($i = 0; $i < count($pids); $i++) {
        $pid  = (int)$pids[$i];
        $qty  = (float)($qtys[$i] ?? 0);
        $unit = trim((string)($units[$i] ?? 'pcs'));
        if ($pid <= 0 || $qty <= 0) continue;

        $selectedProduct = null;
        foreach ($products as $pp) { if ((int)$pp['id'] === $pid) { $selectedProduct = $pp; break; } }
        if (!$selectedProduct) { $errors[] = 'Produk ID ' . $pid . ' tidak aktif / tidak ditemukan pada Master Product.'; continue; }
        if (!pr_product_matches_group($selectedProduct, $pr_business_group)) {
            $errors[] = 'Produk ' . rmi_sku((string)$selectedProduct['sku']) . ' tidak sesuai kelompok PR ' . $pr_business_group . '. BMHP hanya menerima BMHP; UNIT ACC hanya menerima ALKES/AKSESORIS dari business_group UNIT_ACC.';
            continue;
        }
        $sku   = rmi_sku((string)$selectedProduct['sku']);
        $pname = rmi_product_name((string)$selectedProduct['products_name']);
        $punit = (string)($selectedProduct['unit'] ?? 'pcs');
        if ($unit === '') $unit = $punit;
        $itemBusinessGroup = strtoupper(trim((string)($selectedProduct['business_group'] ?? '')));
        $itemCategory = strtoupper(trim((string)($selectedProduct['category'] ?? '')));
        $validItems[] = ['line_no' => $line, 'product_id' => $pid, 'sku' => $sku, 'products_name' => $pname, 'business_group' => $itemBusinessGroup, 'category' => $itemCategory, 'qty' => $qty, 'unit' => $unit];
        $line++;
    }
    if (empty($validItems)) $errors[] = 'Minimal 1 produk valid (qty > 0) wajib diisi.';

    if (!empty($errors)) {
        p_flash_set('danger', implode('<br>', array_map('rmi_h', $errors)));
        rmi_redirect('wqs_pr.php');
    }

    try {
        $dateYmd = date('ymd', strtotime($pr_date));
        if (!function_exists('doc_prefix_pr')) require_once __DIR__ . '/../config/doc_numbering.php';
        $prefix  = ($pr_business_group === 'UNIT_ACC')
            ? ('UNITACC-' . strtoupper($office_code) . '-' . $dateYmd . '-')
            : doc_prefix_pr($office_code, $dateYmd, 'BMHP');

        // Thread-safe: advisory lock
        $lockKey = 'pr_code_' . md5($prefix);
        try { $pdo->query("SELECT GET_LOCK(" . $pdo->quote($lockKey) . ", 5)")->fetchColumn(); } catch (Throwable $e) {}

        try {
            $pr_code = p_generate_code($pdo, 'wqs_pr', 'pr_code', $prefix);

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO wqs_pr (pr_code, pr_date, office_code, category, business_group, status, note, created_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$pr_code, $pr_date, $office_code, $pr_category, $pr_business_group, 'DRAFT', $note, p_username()]);
            $pr_id = (int)$pdo->lastInsertId();

            $stItem = $pdo->prepare("INSERT INTO wqs_pr_items (pr_id, line_no, product_id, sku, products_name, business_group, category, qty, unit) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($validItems as $it) {
                $stItem->execute([$pr_id, $it['line_no'], $it['product_id'], $it['sku'], $it['products_name'], $it['business_group'], $it['category'], $it['qty'], $it['unit']]);
            }

            $pdo->commit();
            p_audit($pdo, 'PR', $pr_code, 'CREATE', ['pr_id' => $pr_id, 'category' => $pr_category, 'business_group' => $pr_business_group]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'wqs_pr', 'wqs_pr', 'CREATE', $pr_id, $pr_code, "PR created: {$pr_code} [{$pr_category}]", ['office_code' => $office_code, 'category' => $pr_category, 'business_group' => $pr_business_group]);
            }
            p_flash_set('success', "PR dibuat: {$pr_code} [{$pr_category}] (status DRAFT)");
            rmi_redirect('wqs_pr_view.php?id=' . $pr_id);
        } finally {
            try { $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lockKey) . ")")->fetchColumn(); } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();

        if (function_exists('rmi_log_module_error')) {
            rmi_log_module_error('wqs_pr', $e, [
                'action'      => 'PR_CREATE_FAILED',
                'office_code' => $office_code ?? null,
                'pr_date'     => $pr_date ?? null,
                'actor'       => p_username(),
            ]);
        }
        p_flash_set('danger', 'Gagal memproses PR: ' . rmi_h($e->getMessage()));
        rmi_redirect('wqs_pr.php');
    }
}

// ================================================================
// Load PR list (with item count + BRANCH scope)
// ================================================================
$rows = [];
// Drill-down dari PQP Dashboard. Filter ini hanya mempengaruhi daftar yang ditampilkan;
// tidak mengubah status, workflow, permission, maupun data PR/PO.
$_pr_status_filter = strtoupper(trim((string)($_GET['status'] ?? '')));
try {
    $sql = "SELECT pr.*, o.office_name,
                   (SELECT COUNT(*) FROM wqs_pr_items i WHERE i.pr_id=pr.id AND i.deleted_at IS NULL) AS item_count,
                   (SELECT GROUP_CONCAT(DISTINCT UPPER(TRIM(COALESCE(i2.category,''))) ORDER BY UPPER(TRIM(COALESCE(i2.category,''))) SEPARATOR ' + ')
                      FROM wqs_pr_items i2 WHERE i2.pr_id=pr.id AND i2.deleted_at IS NULL) AS item_categories,
                   (SELECT COUNT(*) FROM purchases_po po
                     WHERE {$__pr_po_link_sql}
                       AND po.deleted_at IS NULL
                       AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')) AS active_po_count,
                   (SELECT po.po_code FROM purchases_po po
                     WHERE {$__pr_po_link_sql}
                       AND po.deleted_at IS NULL
                       AND UPPER(TRIM(COALESCE(po.status,''))) NOT IN ('CANCELLED','CANCELED','VOID')
                     ORDER BY po.id DESC LIMIT 1) AS active_po_code
            FROM wqs_pr pr
            LEFT JOIN master_office o ON o.office_code=pr.office_code
            WHERE pr.deleted_at IS NULL";
    $params = [];
    if ($_pr_office_scope !== null) {
        $sql .= " AND pr.office_code = ?";
        $params[] = $_pr_office_scope;
    }
    if ($_pr_status_filter === 'SUBMITTED_WAITING_PO') {
        // active_po_count memakai definisi canonical di atas. Karena alias SELECT tidak dapat
        // dipakai di WHERE, gunakan NOT EXISTS dengan relasi PO yang sama persis.
        $sql .= " AND UPPER(TRIM(COALESCE(pr.status,'')))='SUBMITTED'";
        $sql .= " AND NOT EXISTS (SELECT 1 FROM purchases_po po_filter
                    WHERE " . str_replace('po.', 'po_filter.', $__pr_po_link_sql) . "
                      AND po_filter.deleted_at IS NULL
                      AND UPPER(TRIM(COALESCE(po_filter.status,''))) NOT IN ('CANCELLED','CANCELED','VOID'))";
    }
    $sql .= " ORDER BY pr.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// ── Status counts for KPI ──
// Gunakan effective status: bila PO aktif ditemukan, selalu dihitung PO_CREATED
// meskipun record legacy belum sempat tersinkron permanen.
$kpi = ['total' => 0, 'DRAFT' => 0, 'SUBMITTED' => 0, 'PO_CREATED' => 0, 'OTHER' => 0];
foreach ($rows as $r) {
    $kpi['total']++;
    $hasActivePo = ((int)($r['active_po_count'] ?? 0) > 0);
    $s = $hasActivePo ? 'PO_CREATED' : strtoupper(trim((string)($r['status'] ?? '')));
    if (isset($kpi[$s])) $kpi[$s]++;
    else $kpi['OTHER']++;
}

// ================================================================
// HTML
// ================================================================
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

$extraHead = '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css?v=20260209">' .
    '<link rel="stylesheet" href="' . rmi_assets_base() . '/public/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css?v=20260209">' .
    '<style>
        body {
            background:
                radial-gradient(circle at 0% -20%, #0f172a 0, transparent 50%),
                radial-gradient(circle at 100% 120%, #111827 0, transparent 55%),
                radial-gradient(circle at 50% 0%, #0b1120 0, transparent 55%),
                #020617;
            color: #e5e7eb;
            font-size: 14px;
            min-height: 100vh;
        }
        .rmi-container { max-width: 1200px; margin: 20px auto 30px auto; padding: 0 14px; }
        .rmi-card {
            border-radius: 14px;
            border: 1px solid #1f2937;
            background: rgba(15, 23, 42, 0.96);
            box-shadow: 0 18px 45px rgba(0,0,0,.7), 0 0 0 1px rgba(15,23,42,.7);
            backdrop-filter: blur(18px);
            margin-bottom: 20px;
        }
        .rmi-card-header {
            padding: 14px 18px;
            border-bottom: 1px solid #1f2937;
            background: linear-gradient(135deg, #020617 0%, #020617 40%, #0f172a 100%);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 14px 14px 0 0;
        }
        .rmi-card-header h5 {
            margin: 0; font-size: 15px; font-weight: 600;
            letter-spacing: .06em; text-transform: uppercase; color: #e5e7eb;
        }
        .rmi-card-body { padding: 16px 18px 18px 18px; }
        .text-muted-small { font-size: 11px; color: #9ca3af; }
        .form-control, .form-select {
            background-color: #020617; border: 1px solid #374151;
            color: #e5e7eb; font-size: 13px;
        }
        .form-control::placeholder { color: #6b7280; }
        .form-control:focus, .form-select:focus {
            background-color: #020617; color: #e5e7eb;
            border-color: #38bdf8; box-shadow: 0 0 0 1px rgba(56,189,248,.4);
        }
        .form-label { color: #e5e7eb; }
        .btn-primary { background: linear-gradient(135deg, #0ea5e9, #2563eb); border: none; }
        .btn-primary:hover { background: linear-gradient(135deg, #38bdf8, #1d4ed8); }
        .btn-outline-light { border-color: #4b5563; color: #e5e7eb; }
        .btn-outline-light:hover { background-color: #111827; color: #e5e7eb; }
        .btn-outline-danger { border-color: #f97373; color: #fecaca; }
        .btn-outline-danger:hover { background-color: #ef4444; color: #0f172a; }
        .badge-step { border-radius: 999px; padding: 6px 14px; font-size: 11px; margin-right: 6px; font-weight: 600; }
        .badge-step.active { background: #2563eb; color: #fff; }
        .badge-step.muted { background: #111827; color: #9ca3af; border: 1px solid #1f2937; }
        .kpi-card {
            border-radius: 12px; padding: 12px 16px;
            background: rgba(255,255,255,.03); border: 1px solid #1f2937;
            text-align: center; min-width: 100px;
        }
        .kpi-val { font-size: 22px; font-weight: 700; }
        .kpi-label { font-size: 11px; color: #9ca3af; margin-top: 2px; }
        .badge-status { border-radius: 999px; padding: 2px 10px; font-size: 11px; font-weight: 600; }
        .badge-DRAFT { background: #374151; color: #e5e7eb; }
        .badge-SUBMITTED { background: #2563eb; color: #dbeafe; }
        .badge-REVISION_WQS { background: #f59e0b; color: #111827; }
        .badge-PO_CREATED { background: #22c55e; color: #dcfce7; }
        .badge-CLOSED { background: #10b981; color: #ecfdf5; }
        .badge-CANCELLED { background: #ef4444; color: #fecaca; }
        .cat-radio {
            cursor: pointer; display: flex; align-items: center; gap: 8px;
            padding: 8px 14px; border-radius: 10px; min-width: 180px;
            transition: all .15s;
        }
        .cat-radio:hover { filter: brightness(1.15); }
        .table-dark-custom {
            --bs-table-color: #e5e7eb;
            --bs-table-bg: transparent;
            --bs-table-striped-color: #e5e7eb;
            --bs-table-striped-bg: rgba(255,255,255,.02);
            --bs-table-hover-color: #f9fafb;
            --bs-table-hover-bg: rgba(255,255,255,.06);
            color: var(--bs-table-color) !important;
        }
        .table-dark-custom th,
        .table-dark-custom td { color: var(--bs-table-color) !important; }
        .table thead th { background-color: #020617; font-size: 11px; color: #e5e7eb; white-space: nowrap; }
        .table tbody td { vertical-align: middle; font-size: 12px; }
        table.dataTable.table-dark-custom tbody tr.odd td,
        table.dataTable.table-dark-custom tbody tr.even td { color: #e5e7eb; }
        table.dataTable.table-dark-custom tbody tr.even td { background-color: #020617 !important; }
        .num { text-align: right; }
        .product-picker-search {
            margin-bottom: 6px;
            background-color: #020617;
            border-color: #374151;
        }
        .product-picker-search:focus {
            border-color: #38bdf8;
            box-shadow: 0 0 0 1px rgba(56,189,248,.4);
        }
        .product-picker-hint { font-size:10px; color:#94a3b8; margin-top:4px; }
        .product-autocomplete { position:relative; }
        .product-autocomplete-input { width:100%; }
        .product-autocomplete-results { display:none; position:absolute; z-index:1060; left:0; right:0; top:calc(100% - 14px); max-height:280px; overflow-y:auto; background:#020617; border:1px solid #334155; border-radius:8px; box-shadow:0 12px 28px rgba(0,0,0,.55); }
        .product-autocomplete-results.show { display:block; }
        .product-autocomplete-item { width:100%; display:flex; flex-direction:column; align-items:flex-start; gap:2px; padding:8px 10px; background:transparent; color:#e5e7eb; border:0; border-bottom:1px solid #172033; text-align:left; font-size:12px; }
        .product-autocomplete-item:hover, .product-autocomplete-item.active { background:#172554; }
        .product-autocomplete-item.is-duplicate { opacity:.48; cursor:not-allowed; }
        .product-autocomplete-item .product-main { font-weight:600; }
        .product-autocomplete-item .product-meta { color:#94a3b8; font-size:10px; }
        .product-autocomplete-empty { padding:10px; color:#94a3b8; font-size:12px; }
    </style>';

rmi_header('WQS Purchase Request (PR)', 'stock', [
    'subtitle' => 'Pengajuan PR untuk pembelian (WQS).',
    'breadcrumbs' => [
        ['label' => 'Stock (WQS)', 'url' => $baseProject . '/stock/index.php'],
        'WQS PR',
    ],
    'actions' => [
        ['label' => 'Panduan PR', 'url' => 'panduan_wqs_pr.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'PQP Dashboard', 'url' => '../purchases/purchases_dashboard.php', 'class' => 'btn btn-sm btn-outline-light'],
        ['label' => 'WQS Incoming', 'url' => 'wqs_incoming.php', 'class' => 'btn btn-sm btn-outline-light'],
    ],
    'extra_head' => $extraHead,
]);
?>

<div class="rmi-container">

    <!-- FLOW + KPI -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>WQS &mdash; Purchase Request (PR)</h5>
                <small class="text-muted-small">WQS membuat PR &rarr; submit ke PQP &rarr; PO dibuat &rarr; GR &rarr; selesai.</small>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge-step active">PR (WQS)</span>
                <span class="badge-step muted">PO (PQP)</span>
                <span class="badge-step muted">GR</span>
                <span class="badge-step muted">AP / Payment</span>
                <a href="../purchases/purchases_dashboard.php" class="btn btn-sm btn-outline-light ms-3">&laquo; Purchases</a>
            </div>
        </div>
        <div class="rmi-card-body">
            <div class="d-flex gap-3 flex-wrap">
                <div class="kpi-card">
                    <div class="kpi-val"><?= $kpi['total'] ?></div>
                    <div class="kpi-label">Total PR</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-val" style="color:#9ca3af"><?= $kpi['DRAFT'] ?></div>
                    <div class="kpi-label">Draft</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-val" style="color:#60a5fa"><?= $kpi['SUBMITTED'] ?></div>
                    <div class="kpi-label">Submitted</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-val" style="color:#34d399"><?= $kpi['PO_CREATED'] ?></div>
                    <div class="kpi-label">PO Created</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= rmi_h($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= $flash['msg'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($pr_schema_errors)): ?>
        <div class="alert alert-danger"><?= implode('<br>', array_map('rmi_h', $pr_schema_errors)) ?></div>
    <?php endif; ?>

    <!-- CREATE PR FORM -->
    <?php if ($perm_pr_create && empty($pr_schema_errors)): ?>
    <form method="post" id="pr-form">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="<?= $edit_pr ? 'update_pr' : 'create_pr' ?>">
        <?php if ($edit_pr): ?><input type="hidden" name="pr_id" value="<?= (int)$edit_pr['id'] ?>"><?php endif; ?>

        <div class="card rmi-card">
            <div class="rmi-card-header"><h5><?= $edit_pr ? 'Revisi Purchase Request — ' . rmi_h($edit_pr['pr_code']) : 'Buat Purchase Request Baru' ?></h5></div>
            <div class="rmi-card-body">

                <!-- Kategori PR -->
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Kelompok Dokumen (PR)<span class="text-danger">*</span></label>
                        <?php $editPrGroup = $edit_pr ? pr_document_group($edit_pr) : 'BMHP'; ?>
                        <div class="d-flex gap-2 flex-wrap mt-1">
                            <?php foreach ([
                                'BMHP'     => ['label' => 'BMHP',     'sub' => 'Hanya produk BMHP', 'color' => 'rgba(59,130,246,.2)', 'border' => 'rgba(59,130,246,.5)'],
                                'UNIT_ACC' => ['label' => 'UNIT ACC', 'sub' => 'Boleh campur Alat Kesehatan + Aksesoris dalam 1 PR', 'color' => 'rgba(245,158,11,.2)', 'border' => 'rgba(245,158,11,.5)'],
                            ] as $catVal => $catInfo): ?>
                            <label class="cat-radio" style="border:2px solid <?= $catInfo['border'] ?>;background:<?= $catInfo['color'] ?>">
                                <input type="radio" name="pr_category" value="<?= $catVal ?>" <?= ($editPrGroup === $catVal) ? 'checked' : '' ?> style="accent-color:currentColor">
                                <div>
                                    <div style="font-weight:700;font-size:13px"><?= $catInfo['label'] ?></div>
                                    <div style="font-size:11px;opacity:.7"><?= $catInfo['sub'] ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-muted-small mt-1">BMHP menghasilkan PR BMHP. UNIT ACC boleh berisi item kategori <strong>ALKES</strong> dan <strong>AKSESORIS</strong> sekaligus; kategori tetap melekat per item. Office tetap kantor yang membutuhkan barang.</div>
                    </div>
                </div>

                <!-- Info Umum -->
                <div class="row g-3 mb-3">
                    <div class="col-md-2">
                        <label class="form-label">Tanggal PR<span class="text-danger">*</span></label>
                        <input class="form-control form-control-sm" type="date" name="pr_date" value="<?= rmi_h($edit_pr['pr_date'] ?? date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Office<span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" name="office_code" required>
                            <option value="">-- Pilih Office --</option>
                            <?php foreach ($offices as $o): ?>
                                <option value="<?= rmi_h(strtoupper($o['office_code'] ?? '')) ?>" <?= strtoupper($edit_pr['office_code'] ?? '') === strtoupper($o['office_code'] ?? '') ? 'selected' : '' ?>><?= rmi_h(strtoupper($o['office_name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted-small">Kantor / cabang yang butuh barang.</div>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Catatan</label>
                        <input class="form-control form-control-sm" name="note" value="<?= rmi_h($edit_pr['note'] ?? '') ?>" placeholder="Keterangan kebutuhan, urgensi, dll.">
                    </div>
                </div>

                <!-- Items -->
                <div class="mb-2">
                    <label class="form-label fw-bold">Daftar Produk<span class="text-danger">*</span></label>
                    <div class="text-muted-small mb-2">PR hanya berisi kebutuhan (tanpa harga). Harga diatur saat PO oleh PQP.</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-dark-custom align-middle mb-0" id="itemsTable">
                            <thead>
                                <tr>
                                    <th style="width:40px">#</th>
                                    <th style="min-width:280px">Produk</th>
                                    <th style="width:120px" class="num">Qty</th>
                                    <th style="width:120px">Unit</th>
                                    <th style="width:60px" class="text-center">Aksi</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-light mt-2" id="btn-add-row">+ Tambah Baris</button>
                </div>

                <!-- Save -->
                <div class="d-flex justify-content-between align-items-center mt-3 pt-3" style="border-top:1px solid #1f2937">
                    <div class="text-muted-small">
                        <?= $edit_pr ? 'PR revisi tetap berstatus <strong>REVISION_WQS</strong> sampai WQS submit ulang ke PQP.' : 'PR akan tersimpan sebagai <strong>DRAFT</strong>. Saat WQS klik <strong>Submit PQP</strong> / bulk submit, <strong>submitted_at</strong> dicatat sebagai START Durasi SLA PQP.' ?>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary px-4"><?= $edit_pr ? 'Simpan Revisi PR' : 'Simpan PR (DRAFT)' ?></button>
                </div>

            </div>
        </div>
    </form>
    <?php elseif (!$perm_pr_create): ?>
        <div class="alert alert-warning">Anda tidak memiliki izin CREATE PR.</div>
    <?php endif; ?>

    <!-- PR LIST + BULK ACTIONS -->
    <div class="card rmi-card">
        <div class="rmi-card-header">
            <div>
                <h5>Daftar Purchase Request</h5>
                <small class="text-muted-small">Export: Copy / CSV / Excel / PDF / Print.</small>
            </div>
            <?php if (function_exists('auth_is_admin') && auth_is_admin()): ?>
            <div class="d-flex gap-2">
                <a href="<?= rmi_h($baseProject . '/master/audit_logs.php?module=wqs_pr') ?>" class="btn btn-sm btn-outline-light" target="_blank" rel="noopener">Audit Log</a>
            </div>
            <?php endif; ?>
        </div>
        <div class="rmi-card-body">

            <form method="post" id="bulk-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                <?php if ($perm_pr_edit || $perm_pr_delete): ?>
                <div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
                    <span class="text-muted-small">Bulk:</span>
                    <button type="button" class="btn btn-sm btn-outline-light" onclick="bulkCheck(true)">Pilih Semua DRAFT/REVISI/SUBMITTED</button>
                    <button type="button" class="btn btn-sm btn-outline-light" onclick="bulkCheck(false)">Batal Pilih</button>
                    <?php if ($perm_pr_edit): ?>
                    <button type="submit" name="bulk_action" value="bulk_submit" class="btn btn-sm btn-primary"
                            onclick="return confirm('Submit semua PR DRAFT yang dipilih ke PQP?')">Submit Terpilih ke PQP</button>
                    <?php endif; ?>
                    <?php if ($perm_pr_delete): ?>
                    <button type="submit" name="bulk_action" value="bulk_delete" class="btn btn-sm btn-outline-danger"
                            onclick="return confirm('Hapus/arsip semua PR terpilih yang belum memiliki PO aktif?')">Hapus Terpilih</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table id="tbl" class="table table-sm table-dark-custom align-middle" style="width:100%">
                        <thead>
                            <tr>
                                <?php if ($perm_pr_edit || $perm_pr_delete): ?><th style="width:30px"><input type="checkbox" id="chk-all"></th><?php endif; ?>
                                <th>PR Code</th>
                                <th>Kelompok</th>
                                <th>Jenis Item</th>
                                <th>Tanggal</th>
                                <th>Office</th>
                                <th>Items</th>
                                <th>Status</th>
                                <th>Dibuat Oleh</th>
                                <th>Catatan</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r):
                                $st = strtoupper($r['status'] ?? '');
                                $hasActivePo = ((int)($r['active_po_count'] ?? 0) > 0);
                                if ($hasActivePo) {
                                    $st = 'PO_CREATED';
                                }
                                $isDraft = !$hasActivePo && in_array($st, $PR_EDITABLE_STATUSES, true);
                                $canDeleteRow = !$hasActivePo && in_array($st, $PR_DELETABLE_STATUSES, true);
                                $badgeClass = 'badge-' . str_replace(' ', '_', $st);
                            ?>
                            <tr>
                                <?php if ($perm_pr_edit || $perm_pr_delete): ?>
                                <td>
                                    <?php if ($isDraft || $canDeleteRow): ?>
                                        <input type="checkbox" name="selected_ids[]" value="<?= (int)$r['id'] ?>" class="chk-row" data-status="<?= rmi_h($st) ?>">
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td><a href="wqs_pr_view.php?id=<?= (int)$r['id'] ?>" style="color:#60a5fa;text-decoration:none;font-weight:600"><?= rmi_h(strtoupper($r['pr_code'] ?? '')) ?></a></td>
                                <?php $rowCat = strtoupper(trim((string)($r['category'] ?? 'BMHP'))); $rowBg = pr_document_group($r); $rowKinds = strtoupper(trim((string)($r['item_categories'] ?? ''))); ?>
                                <td><span class="text-muted-small"><?= rmi_h($rowBg) ?></span></td>
                                <td><span class="text-muted-small"><?= rmi_h($rowKinds !== '' ? $rowKinds : ($rowBg === 'BMHP' ? 'BMHP' : '-')) ?></span></td>
                                <td><?= rmi_h($r['pr_date'] ?? '') ?></td>
                                <td><?= rmi_h(strtoupper($r['office_name'] ?? $r['office_code'] ?? '')) ?></td>
                                <td class="text-center"><?= (int)($r['item_count'] ?? 0) ?></td>
                                <td>
                                    <span class="badge-status <?= $badgeClass ?>"><?= rmi_h($st) ?></span>
                                    <?php if ($hasActivePo && !empty($r['active_po_code'])): ?>
                                        <div class="text-muted-small mt-1">PO: <?= rmi_h(strtoupper((string)$r['active_po_code'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= rmi_h($r['created_by'] ?? '') ?></td>
                                <td><span class="text-muted-small"><?= rmi_h(mb_strimwidth($r['note'] ?? '', 0, 40, '...')) ?></span></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a class="btn btn-sm btn-outline-light" href="wqs_pr_view.php?id=<?= (int)$r['id'] ?>">View</a>
                                        <?php if ($isDraft && $perm_pr_edit): ?>
                                            <a class="btn btn-sm btn-primary" href="wqs_pr_view.php?id=<?= (int)$r['id'] ?>">Edit/Revisi</a>
                                            <button type="submit"
                                                    name="submit_single"
                                                    value="<?= (int)$r['id'] ?>"
                                                    class="btn btn-sm btn-success"
                                                    onclick="return confirm('Submit PR <?= rmi_h($r['pr_code'] ?? '') ?> ke PQP? Durasi SLA PQP akan mulai dihitung sekarang.')"
                                                    form="bulk-form">Submit PQP</button>
                                        <?php endif; ?>
                                        <a class="btn btn-sm btn-outline-light" href="wqs_pr_print.php?id=<?= (int)$r['id'] ?>" target="_blank">Print</a>
                                        <?php if ($canDeleteRow && $perm_pr_delete): ?>
                                        <button type="submit" name="delete_single" value="<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-danger"
                                                onclick="return confirm('Arsipkan/Hapus PR <?= rmi_h($r['pr_code'] ?? '') ?>? PR hanya dapat dihapus bila belum memiliki PO aktif.')" form="bulk-form">Del</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>

        </div>
    </div>

</div>

<script>
const PRODUCTS = <?= json_encode(array_map(fn($p) => [
    'id' => $p['id'],
    'sku' => rmi_sku($p['sku']),
    'products_name' => rmi_product_name($p['products_name']),
    'unit' => $p['unit'] ?? 'pcs',
    'business_group' => strtoupper((string)($p['business_group'] ?? '')),
    'category' => strtoupper((string)($p['category'] ?? '')),
], $products), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function currentPrGroup() {
    return (document.querySelector('input[name="pr_category"]:checked')?.value || 'BMHP').toUpperCase();
}
function productMatchesCurrentGroup(p) {
    const group = currentPrGroup();
    const bg = String(p.business_group || '').toUpperCase();
    const cat = String(p.category || '').toUpperCase();
    if (group === 'BMHP') return bg === 'BMHP' && cat === 'BMHP';
    if (group === 'UNIT_ACC') return bg === 'UNIT_ACC' && ['ALKES','AKSESORIS'].includes(cat);
    return false;
}
function productLabel(p) {
    return `[${String(p.category || '').toUpperCase()}] ${String(p.sku || '').toUpperCase()} - ${String(p.products_name || '').toUpperCase()}`;
}
function productSearchText(p) {
    return [p.category, p.sku, p.products_name, p.unit].map(v => String(v || '').toUpperCase()).join(' ');
}
function selectedIds(exceptRow = null) {
    return new Set([...document.querySelectorAll('#itemsTable tbody tr')]
        .filter(tr => tr !== exceptRow)
        .map(tr => tr.querySelector('input[name="product_id[]"]')?.value || '')
        .filter(Boolean));
}

let rowCounter = 0;
function closeAllPickers(except = null) {
    document.querySelectorAll('.product-autocomplete-results.show').forEach(el => {
        if (el !== except) el.classList.remove('show');
    });
}

function addRow(prefill) {
    const tbody = document.querySelector('#itemsTable tbody');
    const tr = document.createElement('tr');
    rowCounter++;

    const tdNo = document.createElement('td');
    tdNo.textContent = rowCounter;
    tdNo.style.color = '#9ca3af';
    tr.appendChild(tdNo);

    const wrap = document.createElement('div');
    wrap.className = 'product-autocomplete';

    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'product_id[]';
    hidden.value = prefill?.product_id ?? '';

    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'form-control form-control-sm product-autocomplete-input';
    search.placeholder = 'Ketik SKU / nama produk...';
    search.autocomplete = 'off';
    search.setAttribute('aria-label', 'Cari produk berdasarkan SKU atau nama');

    const results = document.createElement('div');
    results.className = 'product-autocomplete-results';
    results.setAttribute('role', 'listbox');

    const hint = document.createElement('div');
    hint.className = 'product-picker-hint';
    hint.textContent = 'Ketik SKU / nama barang, lalu pilih hasil yang sesuai.';

    const initialProduct = PRODUCTS.find(p => String(p.id) === String(hidden.value));
    if (initialProduct && productMatchesCurrentGroup(initialProduct)) search.value = productLabel(initialProduct);
    else hidden.value = '';

    let activeIndex = -1;
    let currentMatches = [];

    function renderResults() {
        const q = search.value.trim().toUpperCase();
        const used = selectedIds(tr);
        currentMatches = PRODUCTS.filter(productMatchesCurrentGroup)
            .filter(p => !q || productSearchText(p).includes(q))
            .slice(0, 20);

        results.innerHTML = '';
        activeIndex = -1;
        if (!currentMatches.length) {
            const empty = document.createElement('div');
            empty.className = 'product-autocomplete-empty';
            empty.textContent = 'Produk tidak ditemukan.';
            results.appendChild(empty);
        } else {
            currentMatches.forEach((p, idx) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'product-autocomplete-item';
                item.setAttribute('role', 'option');
                const duplicate = used.has(String(p.id));
                item.innerHTML = `<span class="product-main">${escapeHtml(productLabel(p))}</span><span class="product-meta">Unit: ${escapeHtml(p.unit || 'pcs')}${duplicate ? ' · SUDAH DIPILIH' : ''}</span>`;
                if (duplicate) item.classList.add('is-duplicate');
                item.addEventListener('mousedown', ev => {
                    ev.preventDefault();
                    if (duplicate) {
                        alert('Produk ini sudah dipilih pada baris lain. Silakan ubah qty pada baris tersebut.');
                        return;
                    }
                    chooseProduct(p);
                });
                results.appendChild(item);
            });
        }
        results.classList.add('show');
        closeAllPickers(results);
    }

    function chooseProduct(p) {
        hidden.value = String(p.id);
        search.value = productLabel(p);
        const unit = tr.querySelector('input[name="unit[]"]');
        if (unit) unit.value = p.unit || 'pcs';
        results.classList.remove('show');
        search.classList.remove('is-invalid');
    }

    search.addEventListener('focus', () => {
        // Bila sudah terpilih, fokus tidak langsung membuang pilihan.
        renderResults();
    });
    search.addEventListener('input', () => {
        const chosen = PRODUCTS.find(p => String(p.id) === String(hidden.value));
        if (!chosen || search.value !== productLabel(chosen)) hidden.value = '';
        renderResults();
    });
    search.addEventListener('keydown', ev => {
        const items = [...results.querySelectorAll('.product-autocomplete-item:not(.is-duplicate)')];
        if (ev.key === 'ArrowDown' && items.length) {
            ev.preventDefault(); activeIndex = Math.min(activeIndex + 1, items.length - 1);
        } else if (ev.key === 'ArrowUp' && items.length) {
            ev.preventDefault(); activeIndex = Math.max(activeIndex - 1, 0);
        } else if (ev.key === 'Enter' && results.classList.contains('show') && activeIndex >= 0 && items[activeIndex]) {
            ev.preventDefault(); items[activeIndex].dispatchEvent(new MouseEvent('mousedown', {bubbles:true})); return;
        } else if (ev.key === 'Escape') {
            results.classList.remove('show'); return;
        } else return;
        items.forEach((el, i) => el.classList.toggle('active', i === activeIndex));
        items[activeIndex]?.scrollIntoView({block:'nearest'});
    });
    search.addEventListener('blur', () => {
        setTimeout(() => {
            results.classList.remove('show');
            const chosen = PRODUCTS.find(p => String(p.id) === String(hidden.value));
            if (chosen) search.value = productLabel(chosen);
        }, 120);
    });

    wrap.append(hidden, search, results, hint);

    const qty = document.createElement('input');
    qty.type = 'number'; qty.step = '0.01'; qty.min = '0.01'; qty.name = 'qty[]';
    qty.className = 'form-control form-control-sm text-end'; qty.value = prefill?.qty ?? ''; qty.required = true;

    const unit = document.createElement('input');
    unit.type = 'text'; unit.name = 'unit[]'; unit.className = 'form-control form-control-sm';
    unit.value = prefill?.unit ?? initialProduct?.unit ?? 'pcs';

    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'btn btn-outline-danger btn-sm'; btn.innerHTML = '&times;';
    btn.onclick = () => { tr.remove(); renumber(); };

    const td1 = document.createElement('td'); td1.appendChild(wrap); tr.appendChild(td1);
    const td2 = document.createElement('td'); td2.appendChild(qty); tr.appendChild(td2);
    const td3 = document.createElement('td'); td3.appendChild(unit); tr.appendChild(td3);
    const td4 = document.createElement('td'); td4.className = 'text-center'; td4.appendChild(btn); tr.appendChild(td4);
    tbody.appendChild(tr);
}

function escapeHtml(v) {
    return String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
}
function renumber() {
    const rows = document.querySelectorAll('#itemsTable tbody tr');
    rowCounter = 0;
    rows.forEach(tr => { rowCounter++; tr.cells[0].textContent = rowCounter; });
}

document.getElementById('btn-add-row')?.addEventListener('click', () => addRow());
const EDIT_ITEMS = <?= json_encode(array_map(fn($it) => ['product_id'=>(int)$it['product_id'],'qty'=>$it['qty'],'unit'=>$it['unit']], $edit_pr_items), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
if (EDIT_ITEMS.length) EDIT_ITEMS.forEach(it => addRow(it)); else addRow();

document.querySelectorAll('input[name="pr_category"]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
            const hidden = tr.querySelector('input[name="product_id[]"]');
            const search = tr.querySelector('.product-autocomplete-input');
            const unit = tr.querySelector('input[name="unit[]"]');
            const p = PRODUCTS.find(x => String(x.id) === String(hidden?.value || ''));
            if (p && !productMatchesCurrentGroup(p)) {
                hidden.value = '';
                search.value = '';
                unit.value = 'pcs';
            }
            tr.querySelector('.product-autocomplete-results')?.classList.remove('show');
        });
    });
});

document.addEventListener('click', ev => {
    if (!ev.target.closest('.product-autocomplete')) closeAllPickers();
});

document.getElementById('pr-form')?.addEventListener('submit', ev => {
    const group = currentPrGroup();
    const rows = [...document.querySelectorAll('#itemsTable tbody tr')];
    let invalid = false;
    const seen = new Set();
    for (const tr of rows) {
        const hidden = tr.querySelector('input[name="product_id[]"]');
        const search = tr.querySelector('.product-autocomplete-input');
        const pid = hidden?.value || '';
        const p = PRODUCTS.find(x => String(x.id) === String(pid));
        if (!pid || !p || !productMatchesCurrentGroup(p) || seen.has(pid)) {
            invalid = true;
            search?.classList.add('is-invalid');
        } else {
            seen.add(pid);
            search?.classList.remove('is-invalid');
        }
    }
    if (invalid) {
        ev.preventDefault();
        alert(group === 'UNIT_ACC'
            ? 'Periksa Daftar Produk. Setiap baris wajib memilih produk UNIT_ACC kategori ALKES/AKSESORIS dan produk tidak boleh duplikat.'
            : 'Periksa Daftar Produk. Setiap baris wajib memilih produk BMHP dan produk tidak boleh duplikat.');
    }
});

// Bulk checkbox logic — alur lama dipertahankan.
document.getElementById('chk-all')?.addEventListener('change', function () {
    document.querySelectorAll('.chk-row').forEach(c => c.checked = this.checked);
});
function bulkCheck(selectAll) {
    document.querySelectorAll('.chk-row').forEach(c => {
        if (selectAll) { if (c.dataset.status === 'DRAFT' || c.dataset.status === 'REVISION_WQS' || c.dataset.status === 'SUBMITTED') c.checked = true; }
        else c.checked = false;
    });
    const ca = document.getElementById('chk-all');
    if (ca) ca.checked = selectAll;
}
</script>


<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jquery/3.7.1/jquery.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/jszip/3.10.1/jszip.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/pdfmake.min.js?v=20260209"></script>
<script src="<?= rmi_assets_base() ?>/public/assets/vendor/pdfmake/0.2.9/vfs_fonts.js?v=20260209"></script>
<script>
$(function () {
    new DataTable('#tbl', {
        pageLength: 25,
        dom: 'Bfrtip',
        buttons: ['copy','csv','excel','pdf','print'],
        order: [],
        columnDefs: [{ orderable: false, targets: [0, -1] }]
    });
});
</script>

<?php rmi_footer(); ?>
