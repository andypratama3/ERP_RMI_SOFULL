<?php
if (!function_exists('rmi_icon')) { require_once __DIR__ . '/../_shared/rmi_icons.php'; }

// --- Auth guard ---
$__rmi_guard_dir = __DIR__;
for ($__rmi_guard_i = 0; $__rmi_guard_i < 5; $__rmi_guard_i++) {
    $__rmi_guard_auth = $__rmi_guard_dir . '/master/auth.php';
    if (file_exists($__rmi_guard_auth)) {
        require_once $__rmi_guard_auth;
        if (function_exists('require_login')) require_login();
        break;
    }
    $__rmi_guard_parent = dirname($__rmi_guard_dir);
    if ($__rmi_guard_parent === $__rmi_guard_dir) break;
    $__rmi_guard_dir = $__rmi_guard_parent;
}
unset($__rmi_guard_dir, $__rmi_guard_i, $__rmi_guard_auth, $__rmi_guard_parent);
// --- /Auth guard ---

require_once __DIR__ . '/_inc/bootstrap.php';
require_once __DIR__ . '/_inc/schema.php';
mpr_schema_ensure($pdo);
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_inc/mpr_access.php';
try { mpr_access_schema_ensure($pdo); } catch (Throwable $e) {}
$MPR_ALLOWED_OFFICES = mpr_allowed_offices($pdo, $MPR_USER, $MPR_IS_ADMIN);

// -------- helpers --------
function mpr_can_manage_plan(array $user, bool $is_admin, array $plan): bool {
    global $pdo;
    if ($is_admin) return true;
    $deptOk = strtoupper((string)$plan['dept_code']) === strtoupper((string)$user['department']);
    $officeOk = function_exists('mpr_office_is_allowed')
        ? mpr_office_is_allowed($pdo, $user, false, (string)($plan['office_code'] ?? ''))
        : (strtoupper((string)$plan['office_code']) === strtoupper((string)$user['office_code']));
    return $deptOk && $officeOk;
}
function mpr_can_edit_plan(array $user, bool $is_admin, array $plan): bool {
    if (!mpr_can_manage_plan($user, $is_admin, $plan)) return false;
    $status = strtoupper((string)$plan['status']);
    if ($is_admin) return $status !== 'LOCKED';
    return in_array($status, ['DRAFT','REJECTED'], true);
}
function mpr_is_manager(array $user): bool {
    return in_array($user['role'], ['MANAGER'], true) || in_array($user['level'], ['MANAGER'], true);
}
function mpr_plan_money(?string $n): string {
    if ($n === null || $n === '') return '-';
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}


// -------- customer/PIC support for MPR Plans --------
function mpr_plans_has_column(PDO $pdo, string $column): bool {
    static $cache = [];
    $key = 'mpr_plans.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = $pdo->prepare("SHOW COLUMNS FROM mpr_plans LIKE ?");
        $st->execute([$column]);
        $cache[$key] = (bool)$st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}
function mpr_plans_add_column(PDO $pdo, string $column, string $ddl): void {
    if (mpr_plans_has_column($pdo, $column)) return;
    try {
        $pdo->exec("ALTER TABLE mpr_plans ADD COLUMN `{$column}` {$ddl}");
    } catch (Throwable $e) {
        $msg = strtolower($e->getMessage());
        if (strpos($msg, 'duplicate column') !== false || strpos($msg, '1060') !== false || strpos($msg, 'already exists') !== false) return;
        throw $e;
    }
}
function mpr_plans_ensure_customer_pic_schema(PDO $pdo): void {
    // Dibuat aman dan opsional, supaya tidak mengganggu proses plan lama.
    mpr_plans_add_column($pdo, 'customer_id', "INT NULL");
    mpr_plans_add_column($pdo, 'customers_code', "VARCHAR(50) NULL");
    mpr_plans_add_column($pdo, 'customer_name', "VARCHAR(255) NULL");
    mpr_plans_add_column($pdo, 'pic_id', "INT NULL");
    mpr_plans_add_column($pdo, 'pic_name', "VARCHAR(150) NULL");
    mpr_plans_add_column($pdo, 'pic_role', "VARCHAR(150) NULL");
    mpr_plans_add_column($pdo, 'pic_phone', "VARCHAR(80) NULL");
    try { $pdo->exec("CREATE INDEX idx_mpr_plans_customer_id ON mpr_plans (customer_id)"); } catch (Throwable $e) {}
    try { $pdo->exec("CREATE INDEX idx_mpr_plans_pic_id ON mpr_plans (pic_id)"); } catch (Throwable $e) {}
}
function mpr_plans_customer_by_id(PDO $pdo, int $customerId): ?array {
    if ($customerId <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT id, customers_code, customers_name, office_code FROM master_customers WHERE id=? LIMIT 1");
        $st->execute([$customerId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}
function mpr_plans_pic_by_id(PDO $pdo, int $picId): ?array {
    if ($picId <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT m.id, m.customer_id, m.contact_name, m.role_title, m.phone, m.status FROM master_mpr m WHERE m.id=? LIMIT 1");
        $st->execute([$picId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}
function mpr_plans_load_all_customers(PDO $pdo): array {
    try {
        $st = $pdo->query("\n            SELECT id, customers_code, customers_name, office_code, status\n            FROM master_customers\n            WHERE TRIM(COALESCE(customers_name,'')) <> ''\n              AND (LOWER(TRIM(COALESCE(status,''))) = 'active' OR TRIM(COALESCE(status,'')) = '')\n            ORDER BY customers_name ASC\n            LIMIT 5000\n        ");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows) return $rows;
    } catch (Throwable $e) {}
    try {
        $st = $pdo->query("\n            SELECT id, customers_code, customers_name, office_code, status\n            FROM master_customers\n            WHERE TRIM(COALESCE(customers_name,'')) <> ''\n            ORDER BY customers_name ASC\n            LIMIT 5000\n        ");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}
function mpr_plans_load_all_pics(PDO $pdo): array {
    try {
        $st = $pdo->query("\n            SELECT\n                m.id, m.customer_id, m.contact_name, m.role_title, m.phone, m.status,\n                c.customers_code, c.customers_name, c.office_code\n            FROM master_mpr m\n            LEFT JOIN master_customers c ON c.id = m.customer_id\n            WHERE TRIM(COALESCE(m.contact_name,'')) <> ''\n              AND (LOWER(TRIM(COALESCE(m.status,''))) = 'active' OR TRIM(COALESCE(m.status,'')) = '')\n            ORDER BY c.customers_name ASC, m.contact_name ASC\n            LIMIT 8000\n        ");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows) return $rows;
    } catch (Throwable $e) {}
    try {
        $st = $pdo->query("\n            SELECT\n                m.id, m.customer_id, m.contact_name, m.role_title, m.phone, m.status,\n                c.customers_code, c.customers_name, c.office_code\n            FROM master_mpr m\n            LEFT JOIN master_customers c ON c.id = m.customer_id\n            WHERE TRIM(COALESCE(m.contact_name,'')) <> ''\n            ORDER BY c.customers_name ASC, m.contact_name ASC\n            LIMIT 8000\n        ");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
}
/** Valid status transitions (non-admin) */
function mpr_allowed_transitions(): array {
    return [
        'DRAFT'     => ['SUBMITTED','CANCELLED'],
        'SUBMITTED' => ['APPROVED','REJECTED'],
        'APPROVED'  => ['ACTIVE','DONE'],
        'REJECTED'  => ['DRAFT'],
        'ACTIVE'    => ['DONE','CANCELLED'],
        'DONE'      => [],
        'CANCELLED' => ['DRAFT'],
    ];
}

// -------- permissions --------
// Branch operational: cabang/depo tanpa staff MPR boleh membuat plan seperti MPR,
// tetapi tetap dibatasi office/assignment dan tidak otomatis boleh approve budget.
$isBranchOperational = !empty($MPR_BRANCH_OPERATIONAL);
require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
$MPR_DEPO_RESTRICTED = function_exists('rmi_is_depo_branch_session') && rmi_is_depo_branch_session();
$MPR_DEPO_OFFICE = $MPR_DEPO_RESTRICTED && function_exists('rmi_depo_branch_office') ? rmi_depo_branch_office() : '';

// HRL Manager bisa membuka Plan sebagai monitoring/read-only.
// Flag $MPR_HRL_READONLY dibuat di /mpr/_inc/bootstrap.php.
$isHrlReadonly = !empty($MPR_HRL_READONLY);

$perm_plan_view    = $isHrlReadonly ? true  : ($isBranchOperational ? true : (function_exists('can_any') ? can_any(['MPR.PLAN_VIEW','MPR.VIEW']) : true));
$perm_plan_create  = $isHrlReadonly ? false : ($isBranchOperational ? true : (function_exists('can_any') ? can_any(['MPR.PLAN_CREATE','MPR.PLAN_EDIT']) : true));
$perm_plan_edit    = $isHrlReadonly ? false : ($isBranchOperational ? true : (function_exists('can')     ? can('MPR.PLAN_EDIT')   : true));
$perm_plan_delete  = $isHrlReadonly ? false : ($isBranchOperational ? true : (function_exists('can_any') ? can_any(['MPR.PLAN_DELETE','MPR.PLAN_EDIT']) : true));
$perm_plan_approve = $isHrlReadonly ? false : (function_exists('can')     ? can('MPR.PLAN_APPROVE'): true);
$perm_plan_import  = $isHrlReadonly ? false : ($isBranchOperational ? false : (function_exists('can')     ? can('MPR.PLAN_IMPORT') : true));
$perm_plan_export  = $perm_plan_view;

if ($MPR_DEPO_RESTRICTED) {
    // Depo boleh plan operasional sendiri, tetapi tidak delete/import/approve budget.
    $perm_plan_delete = false;
    $perm_plan_import = false;
    $perm_plan_approve = false;
}

if (!$perm_plan_view) { http_response_code(403); echo 'Forbidden'; exit; }

try {
    mpr_plans_ensure_customer_pic_schema($pdo);
} catch (Throwable $e) {
    if (function_exists('rmi_log_module_error')) rmi_log_module_error('mpr_plans', $e, ['action' => 'MPR_PLAN_CUSTOMER_PIC_SCHEMA']);
}

// -------- EXPORT (before any output) --------
$do_export = (string)($_GET['export'] ?? '') === '1' && $perm_plan_export;
if ($do_export) {
    $filter_status_ex = strtoupper(trim((string)($_GET['status'] ?? '')));
    $q_ex   = trim((string)($_GET['q'] ?? ''));
    $p_ex   = [];
    $w_ex   = '1=1';
    if (!$MPR_IS_ADMIN && !$isHrlReadonly) {
        $w_ex .= ' AND dept_code=? AND ';
        $p_ex[] = $MPR_USER['department'];
        $w_ex .= mpr_apply_allowed_office_filter($pdo, $MPR_USER, false, 'office_code', $p_ex);
    }
    if ($filter_status_ex !== '') { $w_ex .= ' AND status=?'; $p_ex[] = $filter_status_ex; }
    $w_ex .= ' AND deleted_at IS NULL';
    if ($q_ex !== '') {
        $w_ex .= ' AND (plan_code LIKE ? OR title LIKE ?)';
        $p_ex[] = "%$q_ex%"; $p_ex[] = "%$q_ex%";
    }
    $stEx = $pdo->prepare("SELECT * FROM mpr_plans WHERE {$w_ex} ORDER BY created_at DESC");
    $stEx->execute($p_ex);
    $rows_ex = $stEx->fetchAll();

    $fname = 'mpr_plans_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Pragma: no-cache');
    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($fh, ['plan_code','title','customer_name','pic_name','pic_role','pic_phone','objective','target','start_date','end_date','budget','status','dept_code','office_code','created_by','created_at','approved_by','approval_note']);
    foreach ($rows_ex as $r) {
        fputcsv($fh, [
            $r['plan_code'],$r['title'],$r['customer_name'] ?? '',$r['pic_name'] ?? '',$r['pic_role'] ?? '',$r['pic_phone'] ?? '',$r['objective'],$r['target'],
            $r['start_date'],$r['end_date'],$r['budget'],$r['status'],
            $r['dept_code'],$r['office_code'],$r['created_by'],$r['created_at'],
            $r['approved_by'],$r['approval_note'],
        ]);
    }
    fclose($fh);
    exit;
}

// Template CSV download
if ((string)($_GET['export'] ?? '') === 'template' && $perm_plan_import) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="mpr_plans_template.csv"');
    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($fh, ['plan_code','title','customer_name','pic_name','objective','target','start_date','end_date','budget','status','office_code','dept_code']);
    fputcsv($fh, ['','Kunjungan RS Hermina','RS HERMINA CONTOH','Nama PIC Contoh','Tujuan plan','Target pencapaian','2025-01-01','2025-12-31','5000000','DRAFT','BGR','MPR']);
    fclose($fh);
    exit;
}

// -------- actions --------
$action = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_or_die();
    try {
        if ($action === 'create' || $action === 'update') {
            if ($action === 'create' && !$perm_plan_create) throw new Exception("Tidak ada izin CREATE plan.");
            if ($action === 'update' && !$perm_plan_edit)   throw new Exception("Tidak ada izin EDIT plan.");

            $id         = (int)($_POST['id'] ?? 0);
            $plan_code  = trim((string)($_POST['plan_code'] ?? ''));
            $title      = trim((string)($_POST['title'] ?? ''));
            $objective  = trim((string)($_POST['objective'] ?? ''));
            $target     = trim((string)($_POST['target'] ?? ''));
            $start_date = (string)($_POST['start_date'] ?? '');
            $end_date   = (string)($_POST['end_date'] ?? '');
            $budget     = trim((string)($_POST['budget'] ?? ''));
            $status     = strtoupper(trim((string)($_POST['status'] ?? 'DRAFT')));
            $customer_id = (int)($_POST['customer_id'] ?? 0);
            $pic_id      = (int)($_POST['pic_id'] ?? 0);
            $customer_name = '';
            $customers_code = '';
            $pic_name = '';
            $pic_role = '';
            $pic_phone = '';

            if ($customer_id > 0) {
                $custSel = mpr_plans_customer_by_id($pdo, $customer_id);
                if ($custSel) {
                    $customer_name = trim((string)($custSel['customers_name'] ?? ''));
                    $customers_code = trim((string)($custSel['customers_code'] ?? ''));
                    if (!$MPR_IS_ADMIN && function_exists('mpr_office_is_allowed')) {
                        $custOfficeCheck = strtoupper(trim((string)($custSel['office_code'] ?? '')));
                        if ($MPR_DEPO_RESTRICTED && $custOfficeCheck !== '' && $custOfficeCheck !== $MPR_DEPO_OFFICE) {
                            throw new Exception("Customer ini bukan milik office Depo " . $MPR_DEPO_OFFICE . ".");
                        }
                        if ($custOfficeCheck !== '' && !mpr_office_is_allowed($pdo, $MPR_USER, false, $custOfficeCheck)) {
                            throw new Exception("Customer ini bukan untuk area tugas akun kamu. Tambahkan assignment office " . $custOfficeCheck . " di user_office_access.");
                        }
                    }
                }
            }
            if ($pic_id > 0) {
                $picSel = mpr_plans_pic_by_id($pdo, $pic_id);
                if (!$picSel) throw new Exception("PIC Customer tidak ditemukan.");

                $picCustomerId = (int)($picSel['customer_id'] ?? 0);
                if ($picCustomerId <= 0) throw new Exception("PIC belum terhubung ke Master Customer.");

                // HARDENING: kombinasi Customer + PIC wajib berasal dari customer yang sama.
                // Jangan hanya mengandalkan filter JavaScript karena POST dapat dimanipulasi.
                if ($customer_id > 0 && $picCustomerId !== $customer_id) {
                    throw new Exception("PIC yang dipilih bukan milik Customer tersebut. Pilih PIC dari customer yang sama.");
                }

                if ($customer_id <= 0) {
                    $customer_id = $picCustomerId;
                    $custSel = mpr_plans_customer_by_id($pdo, $customer_id);
                    if (!$custSel) throw new Exception("Master Customer untuk PIC tidak ditemukan.");
                    $customer_name = trim((string)($custSel['customers_name'] ?? ''));
                    $customers_code = trim((string)($custSel['customers_code'] ?? ''));
                }

                $pic_name = trim((string)($picSel['contact_name'] ?? ''));
                $pic_role = trim((string)($picSel['role_title'] ?? ''));
                $pic_phone = trim((string)($picSel['phone'] ?? ''));
            }

            if ($title === '') throw new Exception("Judul plan wajib diisi.");
            if ($start_date && $end_date && $end_date < $start_date) throw new Exception("End date tidak boleh lebih awal dari start date.");
            if ($plan_code === '') {
                $plan_code = 'MPR-' . ($MPR_USER['office_code'] ?: 'OFF') . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));
            }

            $office = $MPR_IS_ADMIN
                ? strtoupper(trim((string)($_POST['office_code'] ?? $MPR_USER['office_code'])))
                : (function_exists('mpr_customer_scope_office_or_default') ? mpr_customer_scope_office_or_default($pdo, $MPR_USER, false, $custSel ?? null) : $MPR_USER['office_code']);
            if ($MPR_DEPO_RESTRICTED) $office = $MPR_DEPO_OFFICE;
            // Non-admin menyimpan dept sesuai akun. Untuk akun BRANCH, dept tetap BRANCH agar CRM/WQS/SCM tidak berubah,
            // tetapi modul MPR tetap bisa membaca plan/visit berdasarkan scope office/assignment.
            $dept   = $MPR_IS_ADMIN ? strtoupper(trim((string)($_POST['dept_code'] ?? 'MPR'))) : $MPR_USER['department'];

            if ($office === '') throw new Exception("office_code akun kosong. Jalankan sync master_departments dulu.");

            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO mpr_plans
                      (plan_code,title,customer_id,customers_code,customer_name,pic_id,pic_name,pic_role,pic_phone,objective,target,start_date,end_date,budget,status,dept_code,office_code,created_by)
                    VALUES
                      (:code,:title,:customer_id,:customers_code,:customer_name,:pic_id,:pic_name,:pic_role,:pic_phone,:obj,:target,:sd,:ed,:budget,:status,:dept,:office,:by)
                ");
                $stmt->execute([
                    ':code'=>$plan_code, ':title'=>$title,
                    ':customer_id'=>($customer_id > 0 ? $customer_id : null), ':customers_code'=>($customers_code !== '' ? $customers_code : null), ':customer_name'=>($customer_name !== '' ? $customer_name : null),
                    ':pic_id'=>($pic_id > 0 ? $pic_id : null), ':pic_name'=>($pic_name !== '' ? $pic_name : null), ':pic_role'=>($pic_role !== '' ? $pic_role : null), ':pic_phone'=>($pic_phone !== '' ? $pic_phone : null),
                    ':obj'=>$objective ?: null,
                    ':target'=>$target ?: null, ':sd'=>$start_date ?: null, ':ed'=>$end_date ?: null,
                    ':budget'=>($budget===''?null:$budget), ':status'=>$status ?: 'DRAFT',
                    ':dept'=>$dept ?: 'MPR', ':office'=>$office, ':by'=>$MPR_USER['username'],
                ]);
                $new_id = (int)$pdo->lastInsertId();
                mpr_audit($pdo,$MPR_USER,'CREATE','mpr_plans',$new_id,$plan_code,'Create plan',['title'=>$title,'office'=>$office,'dept'=>$dept]);
                if (function_exists('master_audit')) master_audit($pdo,'mpr_plans','mpr_plans','CREATE',$new_id,$plan_code,'Create plan',['title'=>$title,'office'=>$office]);
                flash_set('success', "Plan <b>".e($plan_code)."</b> berhasil dibuat.");
            } else {
                $stmt = $pdo->prepare("SELECT * FROM mpr_plans WHERE id=? LIMIT 1");
                $stmt->execute([$id]);
                $plan = $stmt->fetch();
                if (!$plan) throw new Exception("Plan tidak ditemukan.");
                if (!mpr_can_edit_plan($MPR_USER,$MPR_IS_ADMIN,$plan)) throw new Exception("Tidak boleh edit plan ini (status/scope).");

                // Validate status transition for non-admin
                if (!$MPR_IS_ADMIN && $status !== strtoupper((string)$plan['status'])) {
                    $allowed = mpr_allowed_transitions()[strtoupper((string)$plan['status'])] ?? [];
                    if (!in_array($status, $allowed, true)) {
                        throw new Exception("Transisi status " . $plan['status'] . " → {$status} tidak diizinkan.");
                    }
                }

                $pdo->prepare("
                    UPDATE mpr_plans SET
                      plan_code=:code, title=:title,
                      customer_id=:customer_id, customers_code=:customers_code, customer_name=:customer_name,
                      pic_id=:pic_id, pic_name=:pic_name, pic_role=:pic_role, pic_phone=:pic_phone,
                      objective=:obj, target=:target,
                      start_date=:sd, end_date=:ed, budget=:budget, status=:status,
                      dept_code=:dept, office_code=:office, updated_at=NOW()
                    WHERE id=:id
                ")->execute([
                    ':code'=>$plan_code, ':title'=>$title,
                    ':customer_id'=>($customer_id > 0 ? $customer_id : null), ':customers_code'=>($customers_code !== '' ? $customers_code : null), ':customer_name'=>($customer_name !== '' ? $customer_name : null),
                    ':pic_id'=>($pic_id > 0 ? $pic_id : null), ':pic_name'=>($pic_name !== '' ? $pic_name : null), ':pic_role'=>($pic_role !== '' ? $pic_role : null), ':pic_phone'=>($pic_phone !== '' ? $pic_phone : null),
                    ':obj'=>$objective ?: null,
                    ':target'=>$target ?: null, ':sd'=>$start_date ?: null, ':ed'=>$end_date ?: null,
                    ':budget'=>($budget===''?null:$budget), ':status'=>$status ?: $plan['status'],
                    ':dept'=>$dept ?: $plan['dept_code'], ':office'=>$office ?: $plan['office_code'], ':id'=>$id,
                ]);
                mpr_audit($pdo,$MPR_USER,'UPDATE','mpr_plans',$id,$plan_code,'Update plan',['title'=>$title,'status'=>$status]);
                flash_set('success', "Plan <b>".e($plan_code)."</b> berhasil diupdate.");
            }
            rmi_redirect(url_mpr('mpr_plans.php'));
        }

        if ($action === 'submit') {
            if (!$perm_plan_edit) throw new Exception("Tidak ada izin SUBMIT plan.");
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM mpr_plans WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $plan = $stmt->fetch();
            if (!$plan) throw new Exception("Plan tidak ditemukan.");
            if (!mpr_can_manage_plan($MPR_USER,$MPR_IS_ADMIN,$plan)) throw new Exception("Tidak boleh akses plan ini.");
            $status = strtoupper((string)$plan['status']);
            if (!in_array($status, ['DRAFT','REJECTED'], true) && !$MPR_IS_ADMIN) throw new Exception("Plan hanya bisa submit dari status DRAFT/REJECTED.");
            $pdo->prepare("UPDATE mpr_plans SET status='SUBMITTED', submitted_at=NOW(), updated_at=NOW() WHERE id=?")->execute([$id]);
            mpr_audit($pdo,$MPR_USER,'SUBMIT','mpr_plans',$id,(string)$plan['plan_code'],'Submit plan',[]);
            flash_set('success', "Plan <b>".e($plan['plan_code'])."</b> berhasil di-submit.");
            rmi_redirect(url_mpr('mpr_plans.php'));
        }

        if ($action === 'approve' || $action === 'reject') {
            if (!$perm_plan_approve) throw new Exception("Tidak ada izin APPROVE plan.");
            $id   = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['approval_note'] ?? ''));
            $stmt = $pdo->prepare("SELECT * FROM mpr_plans WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $plan = $stmt->fetch();
            if (!$plan) throw new Exception("Plan tidak ditemukan.");
            if (!$MPR_IS_ADMIN) {
                if (!mpr_is_manager($MPR_USER)) throw new Exception("Hanya Manager yang bisa approve/reject.");
                if (!mpr_can_manage_plan($MPR_USER,false,$plan)) throw new Exception("Scope tidak sesuai.");
            }
            if (strtoupper((string)$plan['status']) !== 'SUBMITTED' && !$MPR_IS_ADMIN) throw new Exception("Hanya plan SUBMITTED yang bisa diproses.");
            $newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';
            $pdo->prepare("UPDATE mpr_plans SET status=?, approved_at=NOW(), approved_by=?, approval_note=?, updated_at=NOW() WHERE id=?")
                ->execute([$newStatus, $MPR_USER['username'], $note ?: null, $id]);
            mpr_audit($pdo,$MPR_USER,strtoupper($action),'mpr_plans',$id,(string)$plan['plan_code'],ucfirst($action).' plan',['note'=>$note]);
            flash_set($action==='approve'?'success':'warning', "Plan <b>".e($plan['plan_code'])."</b> {$newStatus}.");
            rmi_redirect(url_mpr('mpr_plans.php'));
        }

        if ($action === 'soft_delete' || $action === 'restore') {
            if (!$perm_plan_delete) throw new Exception("Tidak ada izin DELETE/RESTORE plan.");
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM mpr_plans WHERE id=? LIMIT 1");
            $stmt->execute([$id]);
            $plan = $stmt->fetch();
            if (!$plan) throw new Exception("Plan tidak ditemukan.");
            if (!mpr_can_manage_plan($MPR_USER,$MPR_IS_ADMIN,$plan)) throw new Exception("Scope tidak sesuai.");
            if ($action === 'soft_delete') {
                if (!$MPR_IS_ADMIN && strtoupper((string)$plan['status']) !== 'DRAFT') throw new Exception("Staff hanya boleh hapus plan status DRAFT.");
                $pdo->prepare("UPDATE mpr_plans SET deleted_at=NOW() WHERE id=?")->execute([$id]);
                mpr_audit($pdo,$MPR_USER,'SOFT_DELETE','mpr_plans',$id,(string)$plan['plan_code'],'Soft delete plan',[]);
                if (function_exists('master_audit')) master_audit($pdo,'mpr_plans','mpr_plans','SOFT_DELETE',$id,(string)$plan['plan_code'],'Soft delete',[]);
                flash_set('warning', "Plan <b>".e($plan['plan_code'])."</b> dihapus.");
            } else {
                if (!$MPR_IS_ADMIN && !mpr_is_manager($MPR_USER)) throw new Exception("Restore hanya Manager/Admin.");
                $pdo->prepare("UPDATE mpr_plans SET deleted_at=NULL WHERE id=?")->execute([$id]);
                mpr_audit($pdo,$MPR_USER,'RESTORE','mpr_plans',$id,(string)$plan['plan_code'],'Restore plan',[]);
                if (function_exists('master_audit')) master_audit($pdo,'mpr_plans','mpr_plans','RESTORE',$id,(string)$plan['plan_code'],'Restore',[]);
                flash_set('success', "Plan <b>".e($plan['plan_code'])."</b> direstore.");
            }
            rmi_redirect(url_mpr('mpr_plans.php'));
        }

        if ($action === 'bulk_action') {
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids) || count($ids) === 0) throw new Exception("Tidak ada item dipilih.");
            $ids  = array_map('intval', $ids);
            $bulk = strtoupper(trim((string)($_POST['bulk'] ?? '')));
            if (!in_array($bulk, ['SOFT_DELETE','RESTORE','SET_STATUS'], true)) throw new Exception("Bulk action tidak valid.");
            if (in_array($bulk, ['SOFT_DELETE','RESTORE'], true) && !$perm_plan_delete)  throw new Exception("Tidak ada izin DELETE.");
            if ($bulk === 'SET_STATUS' && !$perm_plan_approve) throw new Exception("Tidak ada izin SET STATUS.");

            $new_status = strtoupper(trim((string)($_POST['new_status'] ?? '')));
            if ($bulk === 'SET_STATUS') {
                if ($new_status === '') throw new Exception("Status baru wajib diisi.");
                if (!in_array($new_status, ['DRAFT','SUBMITTED','APPROVED','ACTIVE','REJECTED','DONE','CANCELLED'], true)) throw new Exception("Status tidak valid.");
                if (!$MPR_IS_ADMIN && !mpr_is_manager($MPR_USER)) throw new Exception("Bulk set status hanya Manager/Admin.");
            }

            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT * FROM mpr_plans WHERE id IN ($in)");
            $stmt->execute($ids);
            $rows = $stmt->fetchAll();
            if (count($rows) !== count($ids)) throw new Exception("Sebagian plan tidak ditemukan.");
            foreach ($rows as $p) {
                if (!mpr_can_manage_plan($MPR_USER,$MPR_IS_ADMIN,$p)) throw new Exception("Ada plan di luar scope kamu.");
            }

            if ($bulk === 'SOFT_DELETE') {
                $pdo->prepare("UPDATE mpr_plans SET deleted_at=NOW() WHERE id IN ($in)")->execute($ids);
                mpr_audit($pdo,$MPR_USER,'BULK_SOFT_DELETE','mpr_plans',null,null,'Bulk soft delete',['ids'=>$ids]);
                flash_set('warning', "Bulk delete: " . count($ids) . " plan.");
            } elseif ($bulk === 'RESTORE') {
                if (!$MPR_IS_ADMIN && !mpr_is_manager($MPR_USER)) throw new Exception("Bulk restore hanya Manager/Admin.");
                $pdo->prepare("UPDATE mpr_plans SET deleted_at=NULL WHERE id IN ($in)")->execute($ids);
                mpr_audit($pdo,$MPR_USER,'BULK_RESTORE','mpr_plans',null,null,'Bulk restore',['ids'=>$ids]);
                flash_set('success', "Bulk restore: " . count($ids) . " plan.");
            } else {
                // Validate each plan transition
                $skipped = 0;
                foreach ($rows as $p) {
                    $curStatus = strtoupper((string)$p['status']);
                    if (!$MPR_IS_ADMIN) {
                        $allowed = mpr_allowed_transitions()[$curStatus] ?? [];
                        if (!in_array($new_status, $allowed, true)) { $skipped++; continue; }
                    }
                    $pdo->prepare("UPDATE mpr_plans SET status=?, updated_at=NOW() WHERE id=?")->execute([$new_status, (int)$p['id']]);
                }
                mpr_audit($pdo,$MPR_USER,'BULK_STATUS','mpr_plans',null,null,'Bulk set status',['ids'=>$ids,'status'=>$new_status,'skipped'=>$skipped]);
                $msg = "Bulk status → {$new_status}: " . (count($ids)-$skipped) . " diupdate.";
                if ($skipped) $msg .= " {$skipped} dilewati (transisi tidak valid).";
                flash_set('success', $msg);
            }
            rmi_redirect(url_mpr('mpr_plans.php'));
        }

        if ($action === 'import_csv') {
            if (!$perm_plan_import) throw new Exception("Tidak ada izin IMPORT plan.");
            if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) throw new Exception("File CSV tidak valid.");
            $origName  = (string)($_FILES['csv']['name'] ?? '');
            $cleanName = rmi_safe_filename($origName, 'csv');
            $ext       = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));
            if ($ext !== 'csv') throw new Exception("Format file harus CSV.");
            if ((int)($_FILES['csv']['size'] ?? 0) > 5*1024*1024) throw new Exception("Ukuran CSV terlalu besar (max 5MB).");
            $fh = fopen($_FILES['csv']['tmp_name'], 'r');
            if (!$fh) throw new Exception("Gagal membaca CSV.");
            $header = fgetcsv($fh);
            if (!$header) throw new Exception("CSV kosong.");
            $header = array_map(fn($h) => strtolower(trim((string)$h)), $header);
            $map = [];
            foreach (['plan_code','title','customer_name','pic_name','objective','target','start_date','end_date','budget','status','office_code','dept_code'] as $col) {
                $map[$col] = array_search($col, $header);
            }
            if ($map['title'] === false) throw new Exception("Header CSV minimal harus ada kolom: title");
            $created = 0; $skipped = 0; $errors = []; $rowNum = 1;
            while (($row = fgetcsv($fh)) !== false) {
                $rowNum++;
                try {
                    $title = trim((string)($row[$map['title']] ?? ''));
                    if ($title === '') { $skipped++; continue; }
                    $code   = ($map['plan_code']!==false) ? trim((string)($row[$map['plan_code']] ?? '')) : '';
                    $office = ($map['office_code']!==false) ? strtoupper(trim((string)($row[$map['office_code']] ?? ''))) : '';
                    $dept   = ($map['dept_code']!==false)   ? strtoupper(trim((string)($row[$map['dept_code']] ?? '')))   : '';
                    if (!$MPR_IS_ADMIN) { $office = $MPR_USER['office_code']; $dept = $MPR_USER['department']; }
                    else { $office = $office ?: ($MPR_USER['office_code'] ?: 'OFF'); $dept = $dept ?: 'MPR'; }
                    if ($code === '') $code = 'MPR-' . $office . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));
                    $st = $pdo->prepare("SELECT id FROM mpr_plans WHERE plan_code=? LIMIT 1");
                    $st->execute([$code]);
                    if ($st->fetch()) { $skipped++; continue; }
                    $csvCustomerName = ($map['customer_name']!==false) ? trim((string)($row[$map['customer_name']] ?? '')) : '';
                    $csvPicName      = ($map['pic_name']!==false)      ? trim((string)($row[$map['pic_name']] ?? ''))      : '';
                    $customer_id = null; $customers_code = null; $customer_name = null; $pic_id = null; $pic_name = null; $pic_role = null; $pic_phone = null;
                    if ($csvCustomerName !== '') {
                        $stc = $pdo->prepare("SELECT id, customers_code, customers_name FROM master_customers WHERE UPPER(TRIM(customers_name)) = UPPER(TRIM(?)) LIMIT 1");
                        $stc->execute([$csvCustomerName]);
                        if ($cr = $stc->fetch(PDO::FETCH_ASSOC)) { $customer_id = (int)$cr['id']; $customers_code = $cr['customers_code']; $customer_name = $cr['customers_name']; }
                    }
                    if ($csvPicName !== '') {
                        $sqlPic = "SELECT m.id, m.customer_id, m.contact_name, m.role_title, m.phone FROM master_mpr m WHERE UPPER(TRIM(m.contact_name)) = UPPER(TRIM(?))";
                        $pp = [$csvPicName];
                        if ($customer_id) { $sqlPic .= " AND m.customer_id=?"; $pp[] = $customer_id; }
                        $sqlPic .= " LIMIT 1";
                        $stp = $pdo->prepare($sqlPic); $stp->execute($pp);
                        if ($pr = $stp->fetch(PDO::FETCH_ASSOC)) { $pic_id = (int)$pr['id']; $pic_name = $pr['contact_name']; $pic_role = $pr['role_title']; $pic_phone = $pr['phone']; }
                    }
                    $objective = ($map['objective']!==false)  ? trim((string)($row[$map['objective']] ?? ''))  : '';
                    $target    = ($map['target']!==false)     ? trim((string)($row[$map['target']] ?? ''))     : '';
                    $sd        = ($map['start_date']!==false) ? trim((string)($row[$map['start_date']] ?? '')) : '';
                    $ed        = ($map['end_date']!==false)   ? trim((string)($row[$map['end_date']] ?? ''))   : '';
                    $budget    = ($map['budget']!==false)     ? trim((string)($row[$map['budget']] ?? ''))     : '';
                    $rstatus   = ($map['status']!==false)     ? strtoupper(trim((string)($row[$map['status']] ?? 'DRAFT'))) : 'DRAFT';
                    $pdo->prepare("INSERT INTO mpr_plans (plan_code,title,customer_id,customers_code,customer_name,pic_id,pic_name,pic_role,pic_phone,objective,target,start_date,end_date,budget,status,dept_code,office_code,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$code,$title,$customer_id,$customers_code,$customer_name,$pic_id,$pic_name,$pic_role,$pic_phone,$objective?:null,$target?:null,$sd?:null,$ed?:null,$budget?:null,$rstatus?:'DRAFT',$dept?:'MPR',$office,$MPR_USER['username']]);
                    $created++;
                } catch (Throwable $er) {
                    $errors[] = "Baris {$rowNum}: " . $er->getMessage();
                }
            }
            fclose($fh);
            mpr_audit($pdo,$MPR_USER,'IMPORT_CSV','mpr_plans',null,null,'Import CSV',['created'=>$created,'skipped'=>$skipped,'errors'=>count($errors)]);
            if (function_exists('master_audit')) master_audit($pdo,'mpr_plans','mpr_plans','IMPORT_CSV',null,null,'Import CSV',['created'=>$created,'skipped'=>$skipped]);
            $msg = "Import selesai: created={$created}, skipped={$skipped}, errors=" . count($errors) . ".";
            if (!empty($errors)) $msg .= ' Detail: ' . implode(' | ', array_slice($errors, 0, 3));
            flash_set(count($errors) > 0 ? 'warning' : 'success', $msg);
            rmi_redirect(url_mpr('mpr_plans.php#import'));
        }

    } catch (Throwable $e) {
        if (function_exists('rmi_log_module_error')) rmi_log_module_error('mpr_plans', $e, ['action' => 'MPR_PLAN_SAVE']);
        flash_set('danger', "Error: " . e($e->getMessage()));
        rmi_redirect(url_mpr('mpr_plans.php'));
    }
}

require_once __DIR__ . '/_layout_top.php';

// -------- fetch data --------
$filter_status = strtoupper(trim((string)($_GET['status'] ?? '')));
$q             = trim((string)($_GET['q'] ?? ''));
$show_deleted  = (string)($_GET['show_deleted'] ?? '') === '1';

$where  = '1=1';
$params = [];
if (!$MPR_IS_ADMIN && !$isHrlReadonly) {
    $where .= ' AND dept_code=? AND ';
    $params[] = $MPR_USER['department'];
    $where .= mpr_apply_allowed_office_filter($pdo, $MPR_USER, false, 'office_code', $params);
}
if ($filter_status !== '') { $where .= ' AND status=?'; $params[] = $filter_status; }
if (!$show_deleted) $where .= ' AND deleted_at IS NULL';
if ($q !== '') {
    $where .= ' AND (plan_code LIKE ? OR title LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%";
}

$stmt  = $pdo->prepare("SELECT * FROM mpr_plans WHERE {$where} ORDER BY created_at DESC");
$stmt->execute($params);
$plans = $stmt->fetchAll();

// Count by status for quick summary
$summary = ['DRAFT'=>0,'SUBMITTED'=>0,'APPROVED'=>0,'ACTIVE'=>0,'DONE'=>0,'REJECTED'=>0,'CANCELLED'=>0];
foreach ($plans as $p) {
    $s = strtoupper((string)$p['status']);
    if (isset($summary[$s])) $summary[$s]++;
}

$edit_id = (int)($_GET['edit'] ?? 0);
$edit    = null;
if ($edit_id && !$isHrlReadonly) {
    $st = $pdo->prepare("SELECT * FROM mpr_plans WHERE id=? LIMIT 1");
    $st->execute([$edit_id]);
    $edit = $st->fetch() ?: null;
    if ($edit && !$perm_plan_edit) $edit = null;
}

$customersAll = mpr_plans_load_all_customers($pdo);
$picsAll = mpr_plans_load_all_pics($pdo);
if ($MPR_DEPO_RESTRICTED) {
    $customersAll = array_values(array_filter($customersAll, static fn($r) => strtoupper(trim((string)($r['office_code'] ?? ''))) === $MPR_DEPO_OFFICE));
    $picsAll = array_values(array_filter($picsAll, static fn($r) => strtoupper(trim((string)($r['office_code'] ?? ''))) === $MPR_DEPO_OFFICE));
}
$today = date('Y-m-d');
?>

<div class="rmi-card">
  <div class="rmi-card-header d-flex flex-wrap gap-2 justify-content-between align-items-start">
    <div>
      <h5>Plans</h5>
      <div class="sub"><?= $isHrlReadonly ? 'HRL Manager monitoring read-only' : 'Staff edit DRAFT/REJECTED &bull; Manager approve SUBMITTED &bull; Admin override' ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($perm_plan_view): ?>
        <a class="btn btn-sm btn-outline-light" href="<?= e(url_mpr('mpr_plans.php?export=1&status=' . urlencode($filter_status) . '&q=' . urlencode($q))) ?>">
          <?=rmi_icon('outbox')?> Export CSV
        </a>
      <?php endif; ?>
      <?php if ($perm_plan_import): ?>
        <a class="btn btn-sm btn-outline-light" href="<?= e(url_mpr('mpr_plans.php?export=template')) ?>">
          <?=rmi_icon('clipboard')?> Download Template
        </a>
      <?php endif; ?>
    </div>
  </div>
  <div class="rmi-card-body">

    <!-- Status Summary Strip -->
    <div class="d-flex gap-2 flex-wrap mb-3" style="font-size:12px">
      <?php foreach ($summary as $s => $c): if ($c === 0) continue; ?>
        <a href="<?= e(url_mpr('mpr_plans.php?status=' . $s)) ?>"
           class="px-2 py-1 rounded text-decoration-none"
           style="background:rgba(255,255,255,.07);color:#e2e8f0;border:1px solid rgba(255,255,255,.1)">
          <?= e($s) ?> <strong><?= $c ?></strong>
        </a>
      <?php endforeach; ?>
      <?php if (array_sum($summary) > 0): ?>
        <a href="<?= e(url_mpr('mpr_plans.php')) ?>" class="px-2 py-1 rounded text-decoration-none" style="background:rgba(255,255,255,.04);color:#64748b">
          Total <strong><?= array_sum($summary) ?></strong>
        </a>
      <?php endif; ?>
    </div>

    <!-- Filter -->
    <form class="row g-2 align-items-end mb-3" method="get">
      <div class="col-md-3">
        <label class="mini">Cari</label>
        <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="plan_code / judul">
      </div>
      <div class="col-md-2">
        <label class="mini">Status</label>
        <select class="form-select" name="status">
          <option value="">All</option>
          <?php foreach(['DRAFT','SUBMITTED','APPROVED','ACTIVE','REJECTED','DONE','CANCELLED'] as $s): ?>
            <option value="<?= e($s) ?>" <?= $filter_status===$s?'selected':'' ?>><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="mini">Deleted</label>
        <select class="form-select" name="show_deleted">
          <option value="0" <?= !$show_deleted?'selected':'' ?>>Hide</option>
          <option value="1" <?= $show_deleted?'selected':'' ?>>Show</option>
        </select>
      </div>
      <div class="col-md-3">
        <button class="btn btn-outline-light">Apply</button>
        <a class="btn btn-outline-light" href="<?= e(url_mpr('mpr_plans.php')) ?>">Reset</a>
      </div>
    </form>

    <div class="row g-3">
      <!-- Form Create/Edit -->
      <div class="col-lg-5">
        <div class="p-3 rounded-3 border" style="border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.05)">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div class="mini mb-1"><?= $edit ? 'Edit Plan' : 'Buat Plan Baru' ?></div>
              <div class="mini">Office/Dept mengikuti akun (Admin bisa pilih bebas).</div>
            </div>
            <span class="badge-soft"><?= $edit ? 'UPDATE' : 'CREATE' ?></span>
          </div>
          <hr style="border-color:rgba(255,255,255,.12)">
          <?php if (($edit && $perm_plan_edit) || (!$edit && $perm_plan_create)): ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
            <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

            <div class="mb-2">
              <label class="mini">Plan Code</label>
              <input class="form-control code" name="plan_code" value="<?= e($edit['plan_code'] ?? '') ?>" placeholder="auto jika kosong">
            </div>
            <div class="mb-2">
              <label class="mini">Judul <span style="color:#ef4444">*</span></label>
              <input class="form-control" name="title" value="<?= e($edit['title'] ?? '') ?>" required>
            </div>
            <div class="mb-2">
              <label class="mini">Customer / Rumah Sakit Target</label>
              <select class="form-select" name="customer_id" id="planCustomerSelect">
                <option value="0">-- Semua / belum ditentukan --</option>
                <?php foreach ($customersAll as $cust):
                  $cid = (int)($cust['id'] ?? 0);
                  $cName = trim((string)($cust['customers_name'] ?? ''));
                  if ($cid <= 0 || $cName === '') continue;
                  $cCode = trim((string)($cust['customers_code'] ?? ''));
                  $cOffice = trim((string)($cust['office_code'] ?? ''));
                  $label = trim(($cCode !== '' ? $cCode . ' — ' : '') . $cName . ($cOffice !== '' ? ' — ' . $cOffice : ''));
                ?>
                  <option value="<?= $cid ?>" <?= (int)($edit['customer_id'] ?? 0) === $cid ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="mini mt-1" style="color:#64748b">Sumber: master_customers semua office/cabang.</div>
            </div>
            <div class="mb-2">
              <label class="mini">PIC Customer Target</label>
              <select class="form-select" name="pic_id" id="planPicSelect">
                <option value="0" data-customer-id="0">-- Semua / belum ditentukan --</option>
                <?php foreach ($picsAll as $pic):
                  $pid = (int)($pic['id'] ?? 0);
                  $pCustomerId = (int)($pic['customer_id'] ?? 0);
                  $pName = trim((string)($pic['contact_name'] ?? ''));
                  if ($pid <= 0 || $pName === '') continue;
                  $pRole = trim((string)($pic['role_title'] ?? ''));
                  $cName = trim((string)($pic['customers_name'] ?? ''));
                  $cOffice = trim((string)($pic['office_code'] ?? ''));
                  $label = trim($pName . ($pRole !== '' ? ' — ' . $pRole : '') . ($cName !== '' ? ' — ' . $cName : '') . ($cOffice !== '' ? ' — ' . $cOffice : ''));
                ?>
                  <option value="<?= $pid ?>" data-customer-id="<?= $pCustomerId ?>" <?= (int)($edit['pic_id'] ?? 0) === $pid ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="mini mt-1" style="color:#64748b">Sumber: master_mpr / Master PIC Customers semua office/cabang.</div>
            </div>
            <div class="mb-2">
              <label class="mini">Objective</label>
              <textarea class="form-control" name="objective" rows="2"><?= e($edit['objective'] ?? '') ?></textarea>
            </div>
            <div class="mb-2">
              <label class="mini">Target</label>
              <textarea class="form-control" name="target" rows="2"><?= e($edit['target'] ?? '') ?></textarea>
            </div>
            <div class="row g-2">
              <div class="col-6 mb-2">
                <label class="mini">Start</label>
                <input class="form-control" type="date" name="start_date" value="<?= e($edit['start_date'] ?? '') ?>">
              </div>
              <div class="col-6 mb-2">
                <label class="mini">End</label>
                <input class="form-control" type="date" name="end_date" id="endDateInput" value="<?= e($edit['end_date'] ?? '') ?>">
              </div>
            </div>
            <div class="row g-2">
              <div class="col-6 mb-2">
                <label class="mini">Budget (Rp)</label>
                <input class="form-control" name="budget" id="budgetInput" value="<?= e($edit['budget'] ?? '') ?>" placeholder="contoh: 2500000">
                <div class="mini mt-1" id="budgetPreview" style="color:#94a3b8"></div>
              </div>
              <div class="col-6 mb-2">
                <label class="mini">Status</label>
                <select class="form-select" name="status">
                  <?php
                  $editStatus = strtoupper((string)($edit['status'] ?? 'DRAFT'));
                  $statusOptions = $MPR_IS_ADMIN
                    ? ['DRAFT','SUBMITTED','APPROVED','ACTIVE','REJECTED','DONE','CANCELLED']
                    : (mpr_allowed_transitions()[$editStatus] ?? ['DRAFT','CANCELLED']);
                  if (!in_array($editStatus, $statusOptions, true)) array_unshift($statusOptions, $editStatus);
                  foreach ($statusOptions as $s): ?>
                    <option value="<?= e($s) ?>" <?= $editStatus===$s?'selected':'' ?>><?= e($s) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <?php if ($MPR_IS_ADMIN): ?>
            <div class="row g-2">
              <div class="col-6 mb-2">
                <label class="mini">Dept Code</label>
                <input class="form-control" name="dept_code" value="<?= e($edit['dept_code'] ?? 'MPR') ?>">
              </div>
              <div class="col-6 mb-2">
                <label class="mini">Office Code</label>
                <input class="form-control" name="office_code" value="<?= e($edit['office_code'] ?? $MPR_USER['office_code']) ?>">
              </div>
            </div>
            <?php endif; ?>
            <div class="d-flex gap-2 mt-2">
              <button class="btn btn-primary"><?= $edit ? 'Update' : 'Buat Plan' ?></button>
              <?php if ($edit): ?>
                <a class="btn btn-outline-light" href="<?= e(url_mpr('mpr_plans.php')) ?>">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
          <?php else: ?>
            <div class="mini text-warning">
              <?= $isHrlReadonly ? 'Mode HRL Manager: hanya boleh melihat Plan MPR. Aksi create/edit/import/approve tidak diizinkan.' : ('Tidak ada izin untuk ' . ($edit ? 'EDIT' : 'CREATE') . ' plan.') ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Import CSV -->
        <?php if ($perm_plan_import): ?>
        <div id="import" class="p-3 rounded-3 border mt-3" style="border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.05)">
          <div class="d-flex justify-content-between">
            <div>
              <div class="mini mb-1">Import CSV</div>
              <div class="mini">Kolom minimal: <span class="code">title</span>. Opsional: plan_code, objective, target, start_date, end_date, budget, status, office_code, dept_code.</div>
            </div>
            <div class="d-flex flex-column gap-1 align-items-end">
              <span class="badge-soft">CSV</span>
              <a class="mini" href="<?= e(url_mpr('mpr_plans.php?export=template')) ?>"><?=rmi_icon('clipboard')?> Template</a>
            </div>
          </div>
          <hr style="border-color:rgba(255,255,255,.12)">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
            <input type="hidden" name="action" value="import_csv">
            <input class="form-control mb-2" type="file" name="csv" accept=".csv" required>
            <button class="btn btn-outline-light">Import</button>
          </form>
        </div>
        <?php endif; ?>
      </div>

      <!-- Tabel Plans -->
      <div class="col-lg-7">
        <?php $canBulkPlan = (!$isHrlReadonly && ($perm_plan_delete || $perm_plan_approve)); ?>
        <form method="post" id="formBulk">
          <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
          <input type="hidden" name="action" value="bulk_action">

          <?php if ($canBulkPlan): ?>
          <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
            <div style="min-width:170px">
              <label class="mini">Bulk Action</label>
              <select class="form-select form-select-sm" name="bulk" id="bulkSelect">
                <option value="SOFT_DELETE">Soft Delete</option>
                <option value="RESTORE">Restore</option>
                <option value="SET_STATUS">Set Status</option>
              </select>
            </div>
            <div style="min-width:150px" id="newStatusWrap" class="d-none">
              <label class="mini">Status Baru</label>
              <select class="form-select form-select-sm" name="new_status">
                <?php foreach(['DRAFT','SUBMITTED','APPROVED','ACTIVE','REJECTED','DONE','CANCELLED'] as $s): ?>
                  <option value="<?= e($s) ?>"><?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-sm btn-outline-light" onclick="return confirmBulk()">Apply Bulk</button>
          </div>
          <?php endif; ?>

          <div class="table-responsive">
            <table id="mprPlans" class="table table-sm table-striped align-middle" style="font-size:12px">
              <thead>
                <tr>
                  <?php if ($canBulkPlan): ?><th style="width:28px"><input type="checkbox" id="chkAll"></th><?php endif; ?>
                  <th>Plan</th>
                  <th>Customer / PIC</th>
                  <th>Periode</th>
                  <th>Budget</th>
                  <th>Status</th>
                  <th>Office</th>
                  <th style="width:200px">Aksi</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($plans as $p):
                $can_manage = mpr_can_manage_plan($MPR_USER,$MPR_IS_ADMIN,$p);
                $can_edit   = mpr_can_edit_plan($MPR_USER,$MPR_IS_ADMIN,$p);
                $st         = strtoupper((string)$p['status']);
                $deleted    = !empty($p['deleted_at']);
                $overdue    = !$deleted && $p['end_date'] && $p['end_date'] < $today && !in_array($st, ['DONE','CANCELLED','REJECTED'], true);
              ?>
                <tr <?= $deleted ? 'style="opacity:.5"' : '' ?>>
                  <?php if ($canBulkPlan): ?><td><input type="checkbox" name="ids[]" value="<?= (int)$p['id'] ?>"></td><?php endif; ?>
                  <td>
                    <div class="code"><?= e($p['plan_code']) ?></div>
                    <div><?= e($p['title']) ?></div>
                    <div class="mini" style="color:#64748b"><?= e($p['dept_code']) ?></div>
                  </td>
                  <td class="mini">
                    <div style="color:#e2e8f0;font-weight:700"><?= e($p['customer_name'] ?: '-') ?></div>
                    <?php if (!empty($p['pic_name'])): ?>
                      <div><?=rmi_icon('user')?> <?= e($p['pic_name']) ?><?= !empty($p['pic_role']) ? ' — ' . e($p['pic_role']) : '' ?></div>
                    <?php else: ?>
                      <div style="color:#64748b">PIC: -</div>
                    <?php endif; ?>
                  </td>
                  <td class="mini">
                    <div><?= e($p['start_date'] ?: '-') ?></div>
                    <div><?= e($p['end_date'] ?: '-') ?></div>
                    <?php if ($overdue): ?>
                      <div style="color:#ef4444;font-weight:700"><?=rmi_icon('warn')?> Overdue</div>
                    <?php endif; ?>
                  </td>
                  <td class="mini" style="white-space:nowrap">
                    <?= e(mpr_plan_money($p['budget'])) ?>
                  </td>
                  <td>
                    <span class="badge-soft <?= $overdue ? 'text-danger' : '' ?>"><?= e($st) ?><?= $deleted?' • DEL':'' ?></span>
                    <?php if (!empty($p['approval_note'])): ?>
                      <div class="mini mt-1" style="color:#94a3b8"><?= e(mb_strimwidth((string)$p['approval_note'],0,50,'…')) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?= e($p['office_code']) ?></td>
                  <td>
                    <a class="btn btn-xs btn-outline-light" href="<?= e(url_mpr('mpr_plan_view.php?id='.(int)$p['id'])) ?>"><?=rmi_icon('search')?></a>

                    <?php if ($can_edit && $perm_plan_edit): ?>
                      <a class="btn btn-xs btn-outline-light" href="<?= e(url_mpr('mpr_plans.php?edit='.(int)$p['id'])) ?>"><?=rmi_icon('memo')?></a>
                    <?php endif; ?>

                    <?php if ($can_manage && $perm_plan_edit && in_array($st,['DRAFT','REJECTED'],true) && !$deleted): ?>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                        <input type="hidden" name="action" value="submit">
                        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                        <button class="btn btn-xs btn-outline-light"><?=rmi_icon('outbox')?></button>
                      </form>
                    <?php endif; ?>

                    <?php if (!$deleted && $perm_plan_approve && $st==='SUBMITTED' && ($MPR_IS_ADMIN || mpr_is_manager($MPR_USER)) && $can_manage): ?>
                      <button class="btn btn-xs btn-outline-light" data-bs-toggle="modal" data-bs-target="#appr<?= (int)$p['id'] ?>"><?=rmi_icon('check')?></button>
                      <div class="modal fade" id="appr<?= (int)$p['id'] ?>" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-sm">
                          <div class="modal-content" style="background:#0b1220;color:#e5e7eb;border:1px solid rgba(255,255,255,.12)">
                            <div class="modal-header py-2">
                              <h6 class="modal-title">Approve / Reject</h6>
                              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body py-2">
                              <div class="mini mb-2">Plan: <span class="code"><?= e($p['plan_code']) ?></span></div>
                              <form id="formAppr<?= (int)$p['id'] ?>" method="post">
                                <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                <textarea class="form-control mb-2" name="approval_note" rows="2" placeholder="Catatan (opsional)"></textarea>
                                <div class="d-flex gap-2">
                                  <button name="action" value="approve" class="btn btn-sm btn-primary flex-fill"><?=rmi_icon('check')?> Approve</button>
                                  <button name="action" value="reject"  class="btn btn-sm btn-outline-danger flex-fill"><?=rmi_icon('cross')?> Reject</button>
                                </div>
                              </form>
                            </div>
                          </div>
                        </div>
                      </div>
                    <?php endif; ?>

                    <?php if ($can_manage && $perm_plan_delete): ?>
                      <?php if (!$deleted): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Hapus plan ini?')">
                          <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                          <input type="hidden" name="action" value="soft_delete">
                          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                          <button class="btn btn-xs btn-outline-danger"><?=rmi_icon('x')?></button>
                        </form>
                      <?php else: ?>
                        <form method="post" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                          <input type="hidden" name="action" value="restore">
                          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                          <button class="btn btn-xs btn-outline-light"><?=rmi_icon('refresh')?></button>
                        </form>
                      <?php endif; ?>
                    <?php endif; ?>
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
</div>

<style>
.btn-xs{padding:2px 7px;font-size:11px;border-radius:5px}
</style>

<script>
// DataTable
rmiDataTable('#mprPlans');

// Filter PIC mengikuti customer yang dipilih, tetapi tetap bisa pilih semua PIC bila customer belum ditentukan.
(function(){
  var customerSelect = document.getElementById('planCustomerSelect');
  var picSelect = document.getElementById('planPicSelect');
  if (!customerSelect || !picSelect) return;
  function applyPicFilter(){
    var cid = customerSelect.value || '0';
    Array.prototype.forEach.call(picSelect.options, function(opt){
      var ocid = opt.getAttribute('data-customer-id') || '0';
      var show = (cid === '0' || opt.value === '0' || ocid === cid);
      opt.hidden = !show;
      opt.disabled = !show;
    });
    var selected = picSelect.options[picSelect.selectedIndex];
    if (selected && selected.disabled) picSelect.value = '0';
  }
  customerSelect.addEventListener('change', applyPicFilter);
  applyPicFilter();
})();

// Checkbox all
document.getElementById('chkAll')?.addEventListener('change', function(){
  document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = this.checked);
});

// Bulk: show/hide new_status field
document.getElementById('bulkSelect')?.addEventListener('change', function(){
  document.getElementById('newStatusWrap').classList.toggle('d-none', this.value !== 'SET_STATUS');
});

// Bulk confirm
function confirmBulk() {
  var bulk   = document.getElementById('bulkSelect')?.value;
  var checked = document.querySelectorAll('input[name="ids[]"]:checked').length;
  if (checked === 0) { alert('Pilih minimal 1 plan.'); return false; }
  var label = bulk === 'SOFT_DELETE' ? 'Hapus ' + checked + ' plan?' :
              bulk === 'RESTORE' ? 'Restore ' + checked + ' plan?' :
              'Ubah status ' + checked + ' plan?';
  return confirm(label);
}

// Budget preview
var budgetInput   = document.getElementById('budgetInput');
var budgetPreview = document.getElementById('budgetPreview');
if (budgetInput && budgetPreview) {
  budgetInput.addEventListener('input', function(){
    var n = parseFloat(this.value.replace(/[^0-9.]/g,''));
    budgetPreview.textContent = isNaN(n) ? '' : '= Rp ' + n.toLocaleString('id-ID');
  });
  budgetInput.dispatchEvent(new Event('input'));
}
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>