<?php

// --- Auth guard (static scan marker) ---
// Ensures this file is counted as protected by enterprise_audit (require_login()).
$__rmi_guard_dir = __DIR__;
for ($__rmi_guard_i = 0; $__rmi_guard_i < 5; $__rmi_guard_i++) {
    $__rmi_guard_auth = $__rmi_guard_dir . '/master/auth.php';
    if (file_exists($__rmi_guard_auth)) {
        require_once $__rmi_guard_auth;
        if (function_exists('require_login')) {
            require_login();
        }
        break;
    }
    $__rmi_guard_parent = dirname($__rmi_guard_dir);
    if ($__rmi_guard_parent === $__rmi_guard_dir) {
        break;
    }
    $__rmi_guard_dir = $__rmi_guard_parent;
}
unset($__rmi_guard_dir, $__rmi_guard_i, $__rmi_guard_auth, $__rmi_guard_parent);
// --- /Auth guard ---


require_once __DIR__ . '/_layout_top.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_inc/mpr_access.php';
try { mpr_access_schema_ensure($pdo); } catch (Throwable $e) {}
$MPR_ALLOWED_OFFICES = mpr_allowed_offices($pdo, $MPR_USER, $MPR_IS_ADMIN);


// --- Auto schema guard: second mandatory visit photo ---
function mpr_ensure_visit_photo2_column(PDO $pdo): void {
    try {
        $st = $pdo->query("SHOW COLUMNS FROM mpr_visits LIKE 'photo_path_2'");
        $exists = (bool)$st->fetch();
        if (!$exists) {
            $pdo->exec("ALTER TABLE mpr_visits ADD COLUMN photo_path_2 VARCHAR(255) NULL AFTER photo_path");
        }
    } catch (Throwable $e) {
        // Jika user DB tidak punya akses ALTER, jalankan SQL manual:
        // ALTER TABLE mpr_visits ADD COLUMN photo_path_2 VARCHAR(255) NULL AFTER photo_path;
    }
}
mpr_ensure_visit_photo2_column($pdo);
// --- /Auto schema guard ---

function mpr_can_manage_plan(array $user, bool $is_admin, array $plan): bool {
    global $pdo;
    if ($is_admin) return true;
    $deptOk = strtoupper((string)$plan['dept_code']) === strtoupper((string)$user['department']);
    $officeOk = function_exists('mpr_office_is_allowed')
        ? mpr_office_is_allowed($pdo, $user, false, (string)($plan['office_code'] ?? ''))
        : (strtoupper((string)$plan['office_code']) === strtoupper((string)$user['office_code']));
    return $deptOk && $officeOk;
}
function mpr_is_manager(array $user): bool {
    return in_array($user['role'], ['MANAGER'], true) || in_array($user['level'], ['MANAGER'], true);
}
function mpr_money($n): string {
    if ($n === null || $n === '') return '-';
    return number_format((float)$n, 0, ',', '.');
}

function mpr_trim(string $s, int $w = 60): string {
    if (function_exists('mb_strimwidth')) return (string)mb_strimwidth($s, 0, $w, '…');
    if (strlen($s) <= $w) return $s;
    return substr($s, 0, max(0, $w-1)) . '…';
}

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM mpr_plans WHERE id=? LIMIT 1");
$stmt->execute([$id]);
$plan = $stmt->fetch();
if (!$plan) {
  echo "<div class='rmi-card'><div class='rmi-card-body'>Plan tidak ditemukan.</div></div>";
  require_once __DIR__ . '/_layout_bottom.php';
  exit;
}
if (!mpr_can_manage_plan($MPR_USER,$MPR_IS_ADMIN,$plan)) {
  http_response_code(403);
  echo "<div class='rmi-card'><div class='rmi-card-body'>Akses ditolak (scope).</div></div>";
  require_once __DIR__ . '/_layout_bottom.php';
  exit;
}

$perm_plan_view = function_exists('can_any') ? can_any(['MPR.PLAN_VIEW', 'MPR.VIEW']) : true;
$perm_plan_create = function_exists('can_any') ? can_any(['MPR.PLAN_CREATE', 'MPR.PLAN_EDIT']) : true;
$perm_plan_edit = function_exists('can') ? can('MPR.PLAN_EDIT') : true;
$perm_plan_delete = function_exists('can_any') ? can_any(['MPR.PLAN_DELETE', 'MPR.PLAN_EDIT']) : true;

if (!$perm_plan_view) {
  http_response_code(403);
  echo "<div class='rmi-card'><div class='rmi-card-body'>Forbidden.</div></div>";
  require_once __DIR__ . '/_layout_bottom.php';
  exit;
}

// Actions add visit/progress/budget
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_or_die();
  $action = (string)($_POST['action'] ?? '');

  try {


// --- VISIT (GPS + Photo + Master Customers/PIC) ---
if ($action === 'add_visit') {
  if (!$perm_plan_create) throw new Exception("Tidak ada izin CREATE plan activity.");
  $visit_date = (string)($_POST['visit_date'] ?? '');
  if ($visit_date === '') throw new Exception("Tanggal kunjungan wajib.");

  // wajib pilih Customer (Rumah Sakit) & PIC (dokter/perawat/dll) dari master data
  $customer_id = (int)($_POST['customer_id'] ?? 0);
  $contact_id  = (int)($_POST['contact_id'] ?? 0);
  if ($customer_id <= 0) throw new Exception("Customer (Rumah Sakit) wajib dipilih.");
  if ($contact_id <= 0) throw new Exception("PIC (dokter/perawat/dll) wajib dipilih.");

  // Ambil data customer (snapshot)
  $stCust = $pdo->prepare("SELECT id, customers_code, customers_name, office_code, status FROM master_customers WHERE id=? LIMIT 1");
  $stCust->execute([$customer_id]);
  $cust = $stCust->fetch();
  if (!$cust) throw new Exception("Customer tidak ditemukan.");
  if (strtolower((string)$cust['status']) !== 'active') throw new Exception("Customer tidak aktif.");

  // Non-admin: customer harus berada dalam home office atau assignment/coverage office user.
  // Contoh: akun StaffMPR_SLO dapat input customer Malang jika sudah diberi akses MLG di user_office_access.
  if (!$MPR_IS_ADMIN) {
    $custOffice = strtoupper((string)($cust['office_code'] ?? ''));
    if ($custOffice !== '' && function_exists('mpr_office_is_allowed') && !mpr_office_is_allowed($pdo, $MPR_USER, false, $custOffice)) {
      throw new Exception("Customer ini bukan untuk area tugas akun kamu. Tambahkan assignment office " . $custOffice . " di user_office_access.");
    }
  }

  // Ambil data PIC (snapshot) dari master_mpr (master_user.php)
  $stPic = $pdo->prepare("SELECT id, customer_id, contact_name, role_title, department, phone, email, status FROM master_mpr WHERE id=? LIMIT 1");
  $stPic->execute([$contact_id]);
  $pic = $stPic->fetch();
  if (!$pic) throw new Exception("PIC tidak ditemukan.");
  if ((int)$pic['customer_id'] !== (int)$customer_id) throw new Exception("PIC tidak sesuai dengan customer.");
  if (strtolower((string)$pic['status']) !== 'active') throw new Exception("PIC tidak aktif.");

  $customer_code = (string)$cust['customers_code'];
  $customer_name = (string)$cust['customers_name'];
  $pic_name      = (string)$pic['contact_name'];
  $pic_role      = (string)($pic['role_title'] ?? '');
  $pic_dept      = (string)($pic['department'] ?? '');

  // Lokasi teks (opsional) mis. ruangan/poli/gedung
  $location = trim((string)($_POST['location'] ?? ''));
  $result = trim((string)($_POST['result'] ?? ''));
  $notes = trim((string)($_POST['notes'] ?? ''));

  // GPS (WAJIB)
  $gps_lat = trim((string)($_POST['gps_lat'] ?? ''));
  $gps_lng = trim((string)($_POST['gps_lng'] ?? ''));
  $gps_acc = trim((string)($_POST['gps_accuracy_m'] ?? ''));
  $lat = ($gps_lat !== '' && is_numeric($gps_lat)) ? (float)$gps_lat : null;
  $lng = ($gps_lng !== '' && is_numeric($gps_lng)) ? (float)$gps_lng : null;
  $acc = ($gps_acc !== '' && is_numeric($gps_acc)) ? (int)$gps_acc : null;

  if ($lat === null || $lng === null) {
    throw new Exception("GPS wajib. Klik tombol Buka GPS Realtime Khusus, lalu Kirim GPS ke Form.");
  }
  if ($acc === null || $acc <= 0) {
    throw new Exception("Akurasi GPS tidak terbaca. Silakan ambil ulang GPS.");
  }
  // GPS wajib realtime dari browser. Tidak boleh fallback IP.
  if ($acc > 250) {
    throw new Exception("Akurasi GPS terlalu besar ($acc m). Mohon ambil ulang realtime sampai <= 250 m.");
  }
  $gps_captured_at = date('Y-m-d H:i:s');

  // Foto 1 & Foto 2 (WAJIB) - validasi server side
  function mpr_save_visit_photo_upload(array $file, array $plan, string $prefix): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new Exception($prefix . " wajib diupload.");
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $allow = ['jpg','jpeg','png'];
    if (!in_array($ext, $allow, true)) {
      throw new Exception($prefix . " hanya boleh: jpg, jpeg, png.");
    }
    if ((int)($file['size'] ?? 0) > 8*1024*1024) {
      throw new Exception("Ukuran " . $prefix . " terlalu besar (maks 8MB).");
    }

    $dir = __DIR__ . '/../uploads/mpr/visits';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $safePrefix = strtoupper(preg_replace('/[^A-Z0-9_]/i', '_', $prefix));
    $fname = $safePrefix . '_' . $plan['plan_code'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $fname = rmi_safe_filename($fname, 180);
    $dest = $dir . '/' . $fname;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
      throw new Exception("Gagal simpan " . $prefix . ".");
    }

    return 'uploads/mpr/visits/' . $fname;
  }

  if (!isset($_FILES['photo'])) {
    throw new Exception("Foto 1 wajib diupload.");
  }
  if (!isset($_FILES['photo2'])) {
    throw new Exception("Foto 2 wajib diupload.");
  }

  $photo_path = mpr_save_visit_photo_upload($_FILES['photo'], $plan, 'Foto 1');
  $photo_path_2 = mpr_save_visit_photo_upload($_FILES['photo2'], $plan, 'Foto 2');

  // attachment OPTIONAL (pdf/image)
  $attach_path = null;
  if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $extA = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
    $allowA = ['jpg','jpeg','png','pdf'];
    if (!in_array($extA, $allowA, true)) throw new Exception("Attachment hanya boleh: jpg, jpeg, png, pdf.");
    if ((int)($_FILES['attachment']['size'] ?? 0) > 10*1024*1024) throw new Exception("Attachment terlalu besar (maks 10MB).");
    $dirA = __DIR__ . '/../uploads/mpr/visits';
    if (!is_dir($dirA)) @mkdir($dirA, 0775, true);
    $fnameA = 'VISITATT_' . $plan['plan_code'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $extA;

    $fnameA = rmi_safe_filename($fnameA, 180);
$destA = $dirA . '/' . $fnameA;
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $destA)) throw new Exception("Gagal simpan attachment.");
    $attach_path = 'uploads/mpr/visits/' . $fnameA;
  }


// Snapshot holder employee (akun jabatan) -> untuk objektif penilaian & pembayaran operasional by day
$employee_code = '';
$employee_name = '';
try {
  $stHold = $pdo->prepare("SELECT holder_employee_code FROM master_system_login WHERE username=? LIMIT 1");
  $stHold->execute([$MPR_USER['username']]);
  $employee_code = trim((string)($stHold->fetchColumn() ?: ''));
} catch (Throwable $e) {
  $employee_code = '';
}

if (!$MPR_IS_ADMIN) {
  if ($employee_code === '') {
    throw new Exception("Holder employee belum diset untuk akun ini. Set dulu di master_system_login (Holder Employee Code).");
  }
  $stEmp = $pdo->prepare("SELECT employee_name FROM master_employees WHERE employee_code=? LIMIT 1");
  $stEmp->execute([$employee_code]);
  $employee_name = (string)($stEmp->fetchColumn() ?: '');
  if ($employee_name === '') {
    throw new Exception("Holder employee_code '$employee_code' tidak ditemukan di master_employees.");
  }
} else {
  // Admin: boleh kosong (untuk testing)
  if ($employee_code !== '') {
    $stEmp = $pdo->prepare("SELECT employee_name FROM master_employees WHERE employee_code=? LIMIT 1");
    $stEmp->execute([$employee_code]);
    $employee_name = (string)($stEmp->fetchColumn() ?: '');
  }
}

// Visitor info (konsisten dengan mpr_visits.php)
  $visitor_username = $MPR_USER['username'];
  $visitor_level    = $MPR_USER['level'] ?: $MPR_USER['role'];

// Insert visit (simpan snapshot customer + pic + employee + visitor)
  $st = $pdo->prepare("
    INSERT INTO mpr_visits
      (plan_id, visit_date,
       customer_id, customers_code, customer_name,
       contact_id, contact_name, contact_role_title, contact_department,
       partner_name, location, result, notes,
       attachment_path, photo_path, photo_path_2,
       gps_lat, gps_lng, gps_accuracy_m, gps_captured_at,
       employee_code, employee_name,
       visitor_username, visitor_level,
       visit_type, outcome,
       created_by)
    VALUES
      (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ");
  $st->execute([
    (int)$plan['id'],
    $visit_date,

    $customer_id,
    $customer_code,
    $customer_name,

    $contact_id,
    $pic_name,
    $pic_role ?: null,
    $pic_dept ?: null,

    $customer_name ?: null,
    $location ?: null,
    $result ?: null,
    $notes ?: null,

    $attach_path,
    $photo_path,
    $photo_path_2,

    $lat,
    $lng,
    $acc,
    $gps_captured_at,

    $employee_code ?: null,
    $employee_name ?: null,

    $visitor_username,
    $visitor_level,

    'EXISTING_CUSTOMER',
    'PENDING',

    $MPR_USER['username']
  ]);

  $visit_id = (int)$pdo->lastInsertId();
  mpr_audit($pdo,$MPR_USER,'ADD_VISIT','mpr_visits',$visit_id,$plan['plan_code'],'Add visit',[
    'date'=>$visit_date,
    'customer_code'=>$customer_code,
    'customer_name'=>$customer_name,
    'contact_id'=>$contact_id,
    'contact_name'=>$pic_name,
    'employee_code'=>$employee_code,
    'employee_name'=>$employee_name,
    'visitor'=>$visitor_username,
    'lat'=>$lat,'lng'=>$lng,'acc_m'=>$acc,
    'photo'=>$photo_path
  ]);
  if (function_exists('master_audit')) {
    master_audit($pdo, 'mpr_plan_view', 'mpr_visits', 'ADD_VISIT', $visit_id, $plan['plan_code'], 'Add visit', ['date' => $visit_date, 'customer_code' => $customer_code]);
  }

  flash_set('success', "Visit berhasil ditambah.");
  rmi_redirect(url_mpr('mpr_plan_view.php?id='.(int)$plan['id']));
}

// --- PROGRESS ---// --- PROGRESS ---
    if ($action === 'add_progress') {
      if (!$perm_plan_create) throw new Exception("Tidak ada izin CREATE progress.");
      $progress_date = (string)($_POST['progress_date'] ?? '');
      if ($progress_date === '') throw new Exception("Tanggal progress wajib.");
      $pct = trim((string)($_POST['progress_pct'] ?? ''));
      $pct = ($pct === '' ? null : (int)$pct);
      if ($pct !== null && ($pct < 0 || $pct > 100)) throw new Exception("Progress (%) harus 0-100.");
      $milestone = trim((string)($_POST['milestone'] ?? ''));
      $issues = trim((string)($_POST['issues'] ?? ''));
      $next = trim((string)($_POST['next_step'] ?? ''));

      $st = $pdo->prepare("INSERT INTO mpr_progress(plan_id,progress_date,progress_pct,milestone,issues,next_step,created_by) VALUES (?,?,?,?,?,?,?)");
      $st->execute([
        (int)$plan['id'],
        $progress_date,
        $pct,
        $milestone ?: null,
        $issues ?: null,
        $next ?: null,
        $MPR_USER['username']
      ]);
      $progress_id = (int)$pdo->lastInsertId();
      mpr_audit($pdo,$MPR_USER,'ADD_PROGRESS','mpr_progress',$progress_id,$plan['plan_code'],'Add progress',['date'=>$progress_date,'pct'=>$pct,'milestone'=>$milestone]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'mpr_plan_view', 'mpr_progress', 'ADD_PROGRESS', $progress_id, $plan['plan_code'], 'Add progress', ['date' => $progress_date, 'pct' => $pct]);
      }
      flash_set('success', "Progress berhasil ditambah.");
      rmi_redirect(url_mpr('mpr_plan_view.php?id='.(int)$plan['id']).'#progress');
    }

    // --- BUDGET REQUEST (MPR -> FIN approval) ---
    if ($action === 'add_budget') {
      if (!$perm_plan_create) throw new Exception("Tidak ada izin CREATE budget request.");
      $req_date = (string)($_POST['request_date'] ?? '');
      if ($req_date === '') $req_date = date('Y-m-d');
      $amount_raw = trim((string)($_POST['amount'] ?? ''));
      // Indonesia biasanya pakai titik ribuan. Untuk aman: ambil digit saja.
      $amount_clean = preg_replace('/[^0-9]/', '', $amount_raw);
      if ($amount_clean === '') throw new Exception("Nominal budget wajib angka.");
      $amount_f = (float)$amount_clean;
      if ($amount_f <= 0) throw new Exception("Nominal budget harus lebih dari 0.");

      $purpose = trim((string)($_POST['purpose'] ?? ''));
      $vendor = trim((string)($_POST['vendor_name'] ?? ''));

      // attachment optional (pdf/image)
      $attach = null;
      if (isset($_FILES['budget_attachment']) && $_FILES['budget_attachment']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['budget_attachment']['name'], PATHINFO_EXTENSION));
        $allow = ['jpg','jpeg','png','pdf'];
        if (!in_array($ext, $allow, true)) throw new Exception("Attachment budget hanya boleh: jpg, jpeg, png, pdf.");
        if ((int)($_FILES['budget_attachment']['size'] ?? 0) > 12*1024*1024) throw new Exception("Ukuran attachment budget terlalu besar (maks 12MB).");

        $dir = __DIR__ . '/../uploads/mpr/budgets';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $fname = 'BUD_' . $plan['plan_code'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest = $dir . '/' . $fname;
        if (!move_uploaded_file($_FILES['budget_attachment']['tmp_name'], $dest)) throw new Exception("Gagal simpan attachment budget.");
        $attach = 'uploads/mpr/budgets/' . $fname;
      }

      $req_code = 'BRQ-' . ($plan['office_code'] ?: 'OFF') . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6));

      $st = $pdo->prepare("
        INSERT INTO mpr_budget_requests
          (request_code, plan_id, request_date, amount, purpose, vendor_name, attachment_path, status, dept_code, office_code, created_by)
        VALUES
          (?,?,?,?,?,?,?,?,?,?,?)
      ");
      $st->execute([
        $req_code,
        (int)$plan['id'],
        $req_date,
        $amount_f,
        $purpose ?: null,
        $vendor ?: null,
        $attach,
        'DRAFT',
        (string)$plan['dept_code'],
        (string)$plan['office_code'],
        $MPR_USER['username'],
      ]);

      $budget_id = (int)$pdo->lastInsertId();
      mpr_audit($pdo,$MPR_USER,'BUDGET_CREATE','mpr_budget_requests',$budget_id,$req_code,'Create budget request',[
        'plan'=>$plan['plan_code'],'amount'=>$amount_f,'office'=>$plan['office_code']
      ]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'mpr_plan_view', 'mpr_budget_requests', 'BUDGET_CREATE', $budget_id, $req_code, 'Create budget request', ['plan' => $plan['plan_code'], 'amount' => $amount_f]);
      }
      flash_set('success', "Budget Request <b>".e($req_code)."</b> dibuat (DRAFT).");
      rmi_redirect(url_mpr('mpr_plan_view.php?id='.(int)$plan['id']).'#budget');
    }

    if ($action === 'submit_budget') {
      if (!$perm_plan_edit) throw new Exception("Tidak ada izin EDIT/SUBMIT budget.");
      $rid = (int)($_POST['rid'] ?? 0);
      $st = $pdo->prepare("SELECT * FROM mpr_budget_requests WHERE id=? AND plan_id=? AND deleted_at IS NULL LIMIT 1");
      $st->execute([$rid,(int)$plan['id']]);
      $br = $st->fetch();
      if (!$br) throw new Exception("Budget request tidak ditemukan.");

      $status = strtoupper((string)$br['status']);
      if (!in_array($status, ['DRAFT','REJECTED'], true) && !$MPR_IS_ADMIN) {
        throw new Exception("Budget hanya bisa SUBMIT dari status DRAFT/REJECTED.");
      }

      $pdo->prepare("UPDATE mpr_budget_requests SET status='SUBMITTED', submitted_at=NOW() WHERE id=?")->execute([$rid]);
      mpr_audit($pdo,$MPR_USER,'BUDGET_SUBMIT','mpr_budget_requests',$rid,(string)$br['request_code'],'Submit budget request',[]);
      if (function_exists('master_audit')) {
        master_audit($pdo, 'mpr_plan_view', 'mpr_budget_requests', 'BUDGET_SUBMIT', $rid, (string)$br['request_code'], 'Submit budget request', []);
      }
      flash_set('success', "Budget <b>".e($br['request_code'])."</b> di-submit ke FIN.");
      rmi_redirect(url_mpr('mpr_plan_view.php?id='.(int)$plan['id']).'#budget');
    }

    if ($action === 'delete_visit' || $action === 'delete_progress' || $action === 'delete_budget') {
      if (!$perm_plan_delete) throw new Exception("Tidak ada izin DELETE activity.");
      $rid = (int)($_POST['rid'] ?? 0);

      if ($action === 'delete_budget') {
        $st = $pdo->prepare("SELECT * FROM mpr_budget_requests WHERE id=? AND plan_id=? LIMIT 1");
        $st->execute([$rid,(int)$plan['id']]);
        $br = $st->fetch();
        if (!$br) throw new Exception("Budget request tidak ditemukan.");

        $stt = strtoupper((string)$br['status']);
        if (!$MPR_IS_ADMIN && !mpr_is_manager($MPR_USER) && $stt !== 'DRAFT') {
          throw new Exception("Staff hanya boleh hapus budget saat DRAFT.");
        }
        if (!$MPR_IS_ADMIN && !mpr_is_manager($MPR_USER) && $stt === 'DRAFT') {
          // ok
        } elseif (!$MPR_IS_ADMIN && !mpr_is_manager($MPR_USER)) {
          throw new Exception("Hapus hanya Manager/Admin.");
        }

        $pdo->prepare("UPDATE mpr_budget_requests SET deleted_at=NOW() WHERE id=? AND plan_id=?")->execute([$rid,(int)$plan['id']]);
        mpr_audit($pdo,$MPR_USER,'SOFT_DELETE_BUDGET','mpr_budget_requests',$rid,(string)$br['request_code'],'Soft delete budget',[]);
        if (function_exists('master_audit')) {
          master_audit($pdo, 'mpr_plan_view', 'mpr_budget_requests', 'SOFT_DELETE_BUDGET', $rid, (string)$br['request_code'], 'Soft delete budget', []);
        }
        flash_set('warning', "Budget dihapus (soft).");
        rmi_redirect(url_mpr('mpr_plan_view.php?id='.(int)$plan['id']).'#budget');
      }

      if (!$MPR_IS_ADMIN && !mpr_is_manager($MPR_USER)) throw new Exception("Hapus hanya Manager/Admin.");
      if ($action === 'delete_visit') {
        $pdo->prepare("UPDATE mpr_visits SET deleted_at=NOW() WHERE id=? AND plan_id=?")->execute([$rid,(int)$plan['id']]);
        mpr_audit($pdo,$MPR_USER,'SOFT_DELETE_VISIT','mpr_visits',$rid,$plan['plan_code'],'Soft delete visit',[]);
        if (function_exists('master_audit')) {
          master_audit($pdo, 'mpr_plan_view', 'mpr_visits', 'SOFT_DELETE_VISIT', $rid, $plan['plan_code'], 'Soft delete visit', []);
        }
      } else {
        $pdo->prepare("UPDATE mpr_progress SET deleted_at=NOW() WHERE id=? AND plan_id=?")->execute([$rid,(int)$plan['id']]);
        mpr_audit($pdo,$MPR_USER,'SOFT_DELETE_PROGRESS','mpr_progress',$rid,$plan['plan_code'],'Soft delete progress',[]);
        if (function_exists('master_audit')) {
          master_audit($pdo, 'mpr_plan_view', 'mpr_progress', 'SOFT_DELETE_PROGRESS', $rid, $plan['plan_code'], 'Soft delete progress', []);
        }
      }
      flash_set('warning', "Item dihapus (soft).");
      rmi_redirect(url_mpr('mpr_plan_view.php?id='.(int)$plan['id']));
    }

  } catch (Throwable $e) {
    flash_set('danger', "Error: " . e($e->getMessage()));
    rmi_redirect(url_mpr('mpr_plan_view.php?id='.(int)$plan['id']));
  }
}

// Load master customers (Rumah Sakit) untuk dropdown Visit
// Revisi: tampilkan semua customer aktif lintas office agar StaffMPR dapat melihat seluruh RS.
// Sebelumnya non-admin hanya melihat customer sesuai office_code, sehingga dropdown bisa kosong
// jika office_code customer berbeda/kosong/tidak seragam.
$customers = [];
try {
  $stc = $pdo->query("
    SELECT id, customers_code, customers_name, office_code, status
    FROM master_customers
    WHERE status IS NULL
       OR status = ''
       OR LOWER(status) IN ('active','aktif','1','yes','y')
    ORDER BY customers_name ASC
  ");
  $customers = $stc->fetchAll();
} catch (Throwable $e) {
  // jika tabel belum ada, modul tetap jalan (hanya dropdown kosong)
  $customers = [];
}

// Load semua PIC aktif dari master_mpr untuk dropdown PIC.
// Revisi: data PIC dibawa langsung ke halaman agar tidak bergantung pada mpr_api_contacts.php
// dan tetap bisa tampil walaupun path API/route bermasalah.
$allPics = [];
try {
  $stp = $pdo->query("
    SELECT id, customer_id, contact_name, role_title, department, phone, email, status
    FROM master_mpr
    WHERE status IS NULL
       OR status = ''
       OR LOWER(status) IN ('active','aktif','1','yes','y')
    ORDER BY contact_name ASC
  ");
  $allPics = $stp->fetchAll();
} catch (Throwable $e) {
  $allPics = [];
}

// Load visits & progress & budget
$vis = $pdo->prepare("SELECT * FROM mpr_visits WHERE plan_id=? AND deleted_at IS NULL ORDER BY visit_date DESC, id DESC");
$vis->execute([(int)$plan['id']]);
$visits = $vis->fetchAll();

$pr = $pdo->prepare("SELECT * FROM mpr_progress WHERE plan_id=? AND deleted_at IS NULL ORDER BY progress_date DESC, id DESC");
$pr->execute([(int)$plan['id']]);
$progress = $pr->fetchAll();

// timeline order ASC
$progress_tl = $pdo->prepare("SELECT * FROM mpr_progress WHERE plan_id=? AND deleted_at IS NULL ORDER BY progress_date ASC, id ASC");
$progress_tl->execute([(int)$plan['id']]);
$progress_timeline = $progress_tl->fetchAll();

// latest pct
$latest_pct = null;
foreach ($progress as $g) {
  if ($g['progress_pct'] !== null) { $latest_pct = (int)$g['progress_pct']; break; }
}

$brs = $pdo->prepare("SELECT * FROM mpr_budget_requests WHERE plan_id=? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC");
$brs->execute([(int)$plan['id']]);
$budgets = $brs->fetchAll();

?>

<div class="rmi-card">
  <div class="rmi-card-header">
    <div>
      <h5>Plan Detail</h5>
      <div class="sub"><span class="code"><?= e($plan['plan_code']) ?></span> • <?= e($plan['title']) ?></div>
    </div>
    <div class="text-end">
      <span class="badge-soft"><?= e(strtoupper((string)$plan['status'])) ?></span>
      <div class="mini mt-1"><?= e($plan['dept_code']) ?> • <?= e($plan['office_code']) ?></div>
    </div>
  </div>
  <div class="rmi-card-body">
    <div class="row g-3">
      <div class="col-lg-6">
        <div class="mini">Objective</div>
        <div><?= nl2br(e((string)($plan['objective'] ?? '-'))) ?></div>
      </div>
      <div class="col-lg-6">
        <div class="mini">Target</div>
        <div><?= nl2br(e((string)($plan['target'] ?? '-'))) ?></div>
      </div>
    </div>
    <div class="row g-3 mt-2">
      <div class="col-md-3"><div class="mini">Start</div><div><?= e($plan['start_date'] ?: '-') ?></div></div>
      <div class="col-md-3"><div class="mini">End</div><div><?= e($plan['end_date'] ?: '-') ?></div></div>
      <div class="col-md-3"><div class="mini">Budget Plan</div><div><?= e($plan['budget'] !== null ? mpr_money($plan['budget']) : '-') ?></div></div>
      <div class="col-md-3"><div class="mini">Created</div><div><?= e($plan['created_by'] ?: '-') ?></div></div>
    </div>

    <?php if($latest_pct !== null): ?>
      <div class="mt-3">
        <div class="mini">Progress Terakhir</div>
        <div class="progress" style="height:12px;background:rgba(255,255,255,.08)">
          <div class="progress-bar" role="progressbar" style="width:<?= (int)$latest_pct ?>%"></div>
        </div>
        <div class="mini mt-1"><?= (int)$latest_pct ?>%</div>
      </div>
    <?php endif; ?>

    <div class="mt-3">
      <a class="btn btn-outline-light" href="<?= e(url_mpr('mpr_plans.php')) ?>">&laquo; Back</a>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="rmi-card">
      <div class="rmi-card-header">
        <div>
          <h5>Kunjungan (GPS + Foto)</h5>
          <div class="sub">Visit log dengan bukti lokasi dan foto</div>
        </div>
        <div class="mini"><?= count($visits) ?> item</div>
      </div>
      <div class="rmi-card-body">
        <form method="post" enctype="multipart/form-data" class="mb-3">
          <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
          <input type="hidden" name="action" value="add_visit">

          <div class="row g-2">
            <div class="col-md-4">
              <label class="mini">Tanggal</label>
              <input type="date" name="visit_date" class="form-control" required>
            </div>
            <div class="col-md-8">
              <label class="mini">Partner/Client</label>
              <input name="partner_name" class="form-control" placeholder="opsional">
            </div>
          </div>


<div class="row g-2 mt-1">
  <div class="col-md-6">
    <label class="mini">Customer (Rumah Sakit) <span class="text-danger">*</span></label>
    <select name="customer_id" id="customer_id" class="form-select" required>
      <option value="">-- pilih rumah sakit --</option>
      <?php foreach($customers as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= e(($c['customers_code'] ?? '').' — '.($c['customers_name'] ?? '')) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="mini text-muted mt-1">Sumber: master_customers</div>
  </div>
  <div class="col-md-6">
    <label class="mini">PIC (Dokter/Perawat/Direktur/dll) <span class="text-danger">*</span></label>
    <select name="contact_id" id="contact_id" class="form-select" required>
  <option value="">-- pilih Customer dulu --</option>
</select>
    <div class="mini text-muted mt-1">Sumber: master_user (tabel master_mpr)</div>
  </div>
</div>

<div class="row g-2 mt-1">
  <div class="col-md-8">
    <label class="mini">Lokasi detail (opsional)</label>
    <input name="location" class="form-control" placeholder="contoh: Poli Jantung / Gudang / Ruang Direktur (opsional)">
  </div>
  <div class="col-md-4">
    <label class="mini">Foto 1 (WAJIB) <span class="text-danger">*</span></label>
    <input type="file" name="photo" class="form-control" accept="image/*" capture="environment" required>
  </div>
</div>

<div class="row g-2 mt-1">
  <div class="col-md-8"></div>
  <div class="col-md-4">
    <label class="mini">Foto 2 (WAJIB) <span class="text-danger">*</span></label>
    <input type="file" name="photo2" class="form-control" accept="image/*" capture="environment" required>
  </div>
</div>
<div class="row g-2 mt-1">
            <div class="col-md-8">
              <label class="mini">GPS Realtime Khusus</label>
              <div class="d-flex gap-2">
                <input type="hidden" name="gps_lat" id="gps_lat" value="<?= e($edit['gps_lat'] ?? '') ?>">
                <input type="hidden" name="gps_lng" id="gps_lng" value="<?= e($edit['gps_lng'] ?? '') ?>">
                <input type="hidden" name="gps_accuracy_m" id="gps_acc" value="<?= e($edit['gps_accuracy_m'] ?? '') ?>">
                <a class="btn btn-outline-info" id="btnGpsSpecialOnly" target="_blank" rel="noopener" href="<?= e('mpr_gps_capture.php?return=' . rawurlencode('mpr_plan_view.php?id=' . (int)$plan['id']) . '&target=mpr_plan_view') ?>">Buka GPS Realtime Khusus</a>
              </div>
              <div class="mini mt-1" id="gps_status">Klik tombol Buka GPS Realtime Khusus, lalu Kirim GPS ke Form. Akurasi harus <= 250 m.</div>
            </div>
            <div class="col-md-4">
              <label class="mini">Attachment (opsional)</label>
              <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
            </div>
          </div>

          <div class="mt-2">
            <label class="mini">Hasil</label>
            <textarea name="result" class="form-control" rows="2"></textarea>
          </div>
          <div class="mt-2">
            <label class="mini">Catatan</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
          <button class="btn btn-primary mt-2">Tambah Visit</button>
        </form>

        <div class="table-responsive">
          <table id="tblVisits" class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>Tanggal</th>
                <th>Customer</th>
                <th>PIC</th>
                <th>Lokasi</th>
                <th>GPS</th>
                <th>Foto</th>
                <th style="width:150px">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($visits as $v): ?>
              <?php
                $map = '';
                if ($v['gps_lat'] !== null && $v['gps_lng'] !== null) {
                  $map = "https://www.google.com/maps?q=" . $v['gps_lat'] . "," . $v['gps_lng'];
                }
              ?>
              <tr>
                <td class="mini"><?= e($v['visit_date']) ?></td>
                <td class="mini"><?php if(!empty($v['customers_code']) || !empty($v['customer_name'])): ?>
                  <b><?= e(($v['customers_code'] ?? '')) ?></b><br><?= e(($v['customer_name'] ?? '')) ?>
                <?php else: ?><?= e($v['partner_name'] ?: '-') ?><?php endif; ?></td>
                <td class="mini"><?php if(!empty($v['contact_name'])): ?>
                  <?= e($v['contact_name']) ?><?php if(!empty($v['contact_role_title'])): ?>
                    <br><span class="text-muted"><?= e($v['contact_role_title']) ?></span>
                  <?php endif; ?>
                <?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                <td class="mini">
                  <?= e($v['location'] ?: '-') ?><br>
                  <span class="mini"><?= e(mpr_trim((string)($v['result'] ?: $v['notes'] ?: ''),55)) ?></span>
                  <?php if(!empty($v['attachment_path'])): ?>
                    <div><a class="mini" href="<?= e(base_project().'/'.ltrim((string)$v['attachment_path'],'/')) ?>" target="_blank">Attachment</a></div>
                  <?php endif; ?>
                </td>
                <td class="mini">
                  <?php if($map): ?>
                    <a href="<?= e($map) ?>" target="_blank">Map</a><br>
                    <?= e($v['gps_lat']) ?>, <?= e($v['gps_lng']) ?><br>
                    <?php if($v['gps_accuracy_m']!==null): ?>acc <?= e((string)$v['gps_accuracy_m']) ?>m<?php endif; ?>
                  <?php else: ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if(!empty($v['photo_path'])): ?>
                    <a href="<?= e(base_project().'/'.ltrim((string)$v['photo_path'],'/')) ?>" target="_blank" title="Foto 1">
                      <img class="thumb" src="<?= e(base_project().'/'.ltrim((string)$v['photo_path'],'/')) ?>" alt="Foto 1">
                    </a>
                  <?php endif; ?>

                  <?php if(!empty($v['photo_path_2'] ?? '')): ?>
                    <a href="<?= e(base_project().'/'.ltrim((string)$v['photo_path_2'],'/')) ?>" target="_blank" title="Foto 2">
                      <img class="thumb" src="<?= e(base_project().'/'.ltrim((string)$v['photo_path_2'],'/')) ?>" alt="Foto 2">
                    </a>
                  <?php endif; ?>

                  <?php if(empty($v['photo_path']) && empty($v['photo_path_2'] ?? '')): ?>
                    <span class="text-muted">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if($MPR_IS_ADMIN || mpr_is_manager($MPR_USER)): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                      <input type="hidden" name="action" value="delete_visit">
                      <input type="hidden" name="rid" value="<?= (int)$v['id'] ?>">
                      <button class="btn btn-sm btn-outline-light">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>

  <div class="col-lg-6" id="progress">
    <div class="rmi-card">
      <div class="rmi-card-header">
        <div>
          <h5>Progress + Timeline</h5>
          <div class="sub">Milestone timeline per plan</div>
        </div>
        <div class="mini"><?= count($progress) ?> item</div>
      </div>
      <div class="rmi-card-body">
        <form method="post" class="mb-3">
          <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
          <input type="hidden" name="action" value="add_progress">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="mini">Tanggal</label>
              <input type="date" name="progress_date" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="mini">Progress (%)</label>
              <input type="number" name="progress_pct" class="form-control" min="0" max="100" placeholder="0-100">
            </div>
            <div class="col-md-4">
              <label class="mini">Milestone</label>
              <input name="milestone" class="form-control" placeholder="opsional">
            </div>
          </div>
          <div class="mt-2">
            <label class="mini">Issues</label>
            <textarea name="issues" class="form-control" rows="2"></textarea>
          </div>
          <div class="mt-2">
            <label class="mini">Next Step</label>
            <textarea name="next_step" class="form-control" rows="2"></textarea>
          </div>
          <button class="btn btn-primary mt-2">Tambah Progress</button>
        </form>

        <?php if(count($progress_timeline)>0): ?>
          <div class="mb-3">
            <div class="mini mb-2">Timeline</div>
            <div class="timeline">
              <?php foreach($progress_timeline as $g): ?>
                <div class="timeline-item">
                  <div class="timeline-dot"></div>
                  <div class="timeline-content">
                    <div class="mini">
                      <?= e($g['progress_date']) ?>
                      <?php if($g['progress_pct']!==null): ?> • <?= e((string)$g['progress_pct']) ?>%<?php endif; ?>
                    </div>
                    <div><b><?= e($g['milestone'] ?: 'Progress Update') ?></b></div>
                    <?php if(!empty($g['issues'])): ?><div class="mini">Issues: <?= e(mpr_trim((string)$g['issues'],120)) ?></div><?php endif; ?>
                    <?php if(!empty($g['next_step'])): ?><div class="mini">Next: <?= e(mpr_trim((string)$g['next_step'],120)) ?></div><?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="table-responsive">
          <table id="tblProgress" class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>Tanggal</th>
                <th>%</th>
                <th>Milestone</th>
                <th>Ringkas</th>
                <th style="width:120px">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($progress as $g): ?>
              <tr>
                <td class="mini"><?= e($g['progress_date']) ?></td>
                <td><?= e($g['progress_pct'] !== null ? (string)$g['progress_pct'] : '-') ?></td>
                <td><?= e($g['milestone'] ?: '-') ?></td>
                <td class="mini">
                  <?= e(mpr_trim((string)($g['issues'] ?: $g['next_step'] ?: ''),60)) ?>
                </td>
                <td>
                  <?php if($MPR_IS_ADMIN || mpr_is_manager($MPR_USER)): ?>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                      <input type="hidden" name="action" value="delete_progress">
                      <input type="hidden" name="rid" value="<?= (int)$g['id'] ?>">
                      <button class="btn btn-sm btn-outline-light">Delete</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>
</div>

<div class="rmi-card" id="budget">
  <div class="rmi-card-header">
    <div>
      <h5>Budget Request → Approval FIN</h5>
      <div class="sub">Workflow lintas departemen: MPR submit → FIN approve/reject</div>
    </div>
    <div class="mini"><?= count($budgets) ?> item</div>
  </div>
  <div class="rmi-card-body">
    <form method="post" enctype="multipart/form-data" class="mb-3">
      <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
      <input type="hidden" name="action" value="add_budget">

      <div class="row g-2">
        <div class="col-md-3">
          <label class="mini">Tanggal Request</label>
          <input type="date" name="request_date" class="form-control" value="<?= e(date('Y-m-d')) ?>">
        </div>
        <div class="col-md-3">
          <label class="mini">Nominal (Rp)</label>
          <input name="amount" class="form-control" placeholder="contoh: 2500000" required>
        </div>
        <div class="col-md-3">
          <label class="mini">Vendor (opsional)</label>
          <input name="vendor_name" class="form-control" placeholder="opsional">
        </div>
        <div class="col-md-3">
          <label class="mini">Attachment (opsional)</label>
          <input type="file" name="budget_attachment" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
        </div>
      </div>

      <div class="mt-2">
        <label class="mini">Purpose / Keterangan</label>
        <textarea name="purpose" class="form-control" rows="2" placeholder="contoh: biaya transport kunjungan, materi promosi, dll"></textarea>
      </div>

      <button class="btn btn-primary mt-2">Buat Budget Request</button>
      <span class="mini ms-2">Status awal: <span class="code">DRAFT</span> → klik <span class="code">Submit</span> untuk kirim ke FIN.</span>
    </form>

    <div class="table-responsive">
      <table id="tblBudget" class="table table-sm table-striped align-middle">
        <thead>
          <tr>
            <th>Code</th>
            <th>Tanggal</th>
            <th>Nominal</th>
            <th>Status</th>
            <th>Vendor / Purpose</th>
            <th style="width:220px">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($budgets as $b): ?>
            <?php $stt = strtoupper((string)$b['status']); ?>
            <tr>
              <td>
                <div class="code"><?= e($b['request_code']) ?></div>
                <div class="mini"><?= e($b['created_by'] ?: '-') ?> • <?= e($b['created_at'] ?: '-') ?></div>
              </td>
              <td class="mini"><?= e($b['request_date']) ?></td>
              <td>Rp <?= e(mpr_money($b['amount'])) ?></td>
              <td>
                <span class="badge-soft"><?= e($stt) ?></span>
                <?php if(!empty($b['approval_note'])): ?><div class="mini mt-1">Note: <?= e(mpr_trim((string)$b['approval_note'],60)) ?></div><?php endif; ?>
              </td>
              <td class="mini">
                <?= e($b['vendor_name'] ?: '-') ?><br>
                <?= e(mpr_trim((string)($b['purpose'] ?: ''),70)) ?>
                <?php if(!empty($b['attachment_path'])): ?>
                  <div><a class="mini" href="<?= e(base_project().'/'.ltrim((string)$b['attachment_path'],'/')) ?>" target="_blank">Attachment</a></div>
                <?php endif; ?>
              </td>
              <td>
                <?php if(in_array($stt,['DRAFT','REJECTED'],true)): ?>
                  <form method="post" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                    <input type="hidden" name="action" value="submit_budget">
                    <input type="hidden" name="rid" value="<?= (int)$b['id'] ?>">
                    <button class="btn btn-sm btn-outline-light">Submit</button>
                  </form>
                <?php endif; ?>

                <?php
                  $can_del = $MPR_IS_ADMIN || mpr_is_manager($MPR_USER) || ($stt==='DRAFT');
                ?>
                <?php if($can_del): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('Hapus budget ini (soft delete)?')">
                    <input type="hidden" name="csrf_token" value="<?= e($CSRF_TOKEN) ?>">
                    <input type="hidden" name="action" value="delete_budget">
                    <input type="hidden" name="rid" value="<?= (int)$b['id'] ?>">
                    <button class="btn btn-sm btn-outline-light">Delete</button>
                  </form>
                <?php endif; ?>

                <?php if($stt==='SUBMITTED'): ?>
                  <div class="mini mt-1">Menunggu FIN</div>
                <?php endif; ?>
                <?php if($stt==='APPROVED'): ?>
                  <div class="mini mt-1">Approved by: <?= e($b['approved_by'] ?: '-') ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="mini mt-2">
      Catatan: FIN approval ada di menu <b>FIN Approval</b> (khusus Dept FIN Manager atau Admin).
    </div>
  </div>
</div>

<script>
window.addEventListener('load', function(){
  if (typeof rmiDataTable === 'function') {
    rmiDataTable('#tblVisits');
    rmiDataTable('#tblProgress');
    rmiDataTable('#tblBudget');
  }
});
</script>



<script>
const elCust = document.getElementById('customer_id');
const elPic  = document.getElementById('contact_id');

// PIC master data langsung dari PHP agar dropdown tidak kosong karena API/path bermasalah.
const MPR_ALL_PICS = <?= json_encode(array_map(function($p){
  $role = trim((string)($p['role_title'] ?? ''));
  $dept = trim((string)($p['department'] ?? ''));
  $phone = trim((string)($p['phone'] ?? ''));
  $labelExtra = [];
  if ($role !== '') $labelExtra[] = $role;
  if ($dept !== '') $labelExtra[] = $dept;
  if ($phone !== '') $labelExtra[] = $phone;
  return [
    'id' => (int)($p['id'] ?? 0),
    'customer_id' => (int)($p['customer_id'] ?? 0),
    'label' => trim((string)($p['contact_name'] ?? '')) . (count($labelExtra) ? ' — ' . implode(' / ', $labelExtra) : '')
  ];
}, $allPics), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

function addPicOption(pic){
  const opt = document.createElement('option');
  opt.value = pic.id;
  opt.textContent = pic.label || ('PIC #' + pic.id);
  elPic.appendChild(opt);
}

function loadPics(){
  if (!elPic) return;

  const cid = elCust && elCust.value ? parseInt(elCust.value, 10) : 0;
  elPic.disabled = false;
  elPic.innerHTML = '';

  const first = document.createElement('option');
  first.value = '';
  first.textContent = cid ? '-- pilih PIC --' : '-- pilih Customer dulu --';
  elPic.appendChild(first);

  if (!cid) return;

  const items = MPR_ALL_PICS.filter(function(pic){
    return parseInt(pic.customer_id, 10) === cid;
  });

  if (items.length > 0) {
    items.forEach(addPicOption);
  } else {
    const opt = document.createElement('option');
    opt.value = '';
    opt.textContent = 'PIC tidak ada untuk customer ini';
    elPic.appendChild(opt);
  }
}

if (elCust) {
  elCust.addEventListener('change', loadPics);
}

document.addEventListener('DOMContentLoaded', loadPics);
window.addEventListener('load', loadPics);
</script>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>

<script>
function mprNormalizeExternalGpsFix(fix) {
  if (!fix) return null;
  if (typeof fix === 'string') {
    try { fix = JSON.parse(fix); } catch(e) { return null; }
  }
  if (!fix || typeof fix !== 'object') return null;
  return {
    lat: fix.lat || fix.latitude || fix.gps_lat || fix.gpsLat || fix.mpr_gps_lat || '',
    lng: fix.lng || fix.lon || fix.longitude || fix.gps_lng || fix.gpsLng || fix.mpr_gps_lng || '',
    acc: fix.acc || fix.accuracy || fix.gps_acc || fix.gpsAcc || fix.gps_accuracy_m || fix.mpr_gps_acc || ''
  };
}

function mprApplyExternalGpsFix(fix) {
  fix = mprNormalizeExternalGpsFix(fix);
  if (!fix) return false;

  var lat = fix.lat || '';
  var lng = fix.lng || '';
  var acc = fix.acc || fix.accuracy || '';

  var elLat = document.getElementById('gps_lat');
  var elLng = document.getElementById('gps_lng');
  var elAcc = document.getElementById('gps_acc');

  if (elLat && elLng && elAcc && lat && lng && acc) {
    elLat.value = lat;
    elLng.value = lng;
    elAcc.value = acc;
  }

  var st = document.getElementById('gps_status');
  if (st && lat && lng && acc) {
    st.textContent = 'GPS realtime khusus sudah masuk: ' + lat + ', ' + lng + ' (akurasi ' + acc + ' m).';
    st.style.color = '#22c55e';
  }

  return true;
}

window.addEventListener('message', function(e) {
  if (e.origin !== location.origin) return;
  if (!e.data) return;

  var data = e.data;
  if (typeof data === 'string') {
    try { data = JSON.parse(data); } catch(err) {}
  }

  if (data && typeof data === 'object') {
    var t = String(data.type || data.event || '').toLowerCase();
    if (data.type === 'MPR_GPS_FIX' && data.fix) {
      mprApplyExternalGpsFix(data.fix);
    } else if (t.indexOf('gps') !== -1 || data.lat || data.latitude || data.gps_lat) {
      mprApplyExternalGpsFix(data);
    }
  }
});

function mprReadGpsFixFromStorage() {
  var keys = ['mpr_gps_last_fix','mpr_gps_last','mprGpsLast','mpr_gps_data','mprGpsData','gps_data','gpsData','mpr_realtime_gps','mprGpsRealtime','mpr_visit_gps','mprVisitGps'];
  for (var i = 0; i < keys.length; i++) {
    try {
      var raw = localStorage.getItem(keys[i]) || sessionStorage.getItem(keys[i]) || '';
      if (raw && mprApplyExternalGpsFix(raw)) return true;
    } catch(e) {}
  }
  try {
    var direct = {
      lat: localStorage.getItem('mpr_gps_lat') || sessionStorage.getItem('mpr_gps_lat') || localStorage.getItem('gps_lat') || sessionStorage.getItem('gps_lat') || '',
      lng: localStorage.getItem('mpr_gps_lng') || sessionStorage.getItem('mpr_gps_lng') || localStorage.getItem('gps_lng') || sessionStorage.getItem('gps_lng') || '',
      acc: localStorage.getItem('mpr_gps_acc') || sessionStorage.getItem('mpr_gps_acc') || localStorage.getItem('gps_acc') || sessionStorage.getItem('gps_acc') || ''
    };
    return mprApplyExternalGpsFix(direct);
  } catch(e) {}
  return false;
}

document.addEventListener('visibilitychange', function() {
  if (!document.hidden) mprReadGpsFixFromStorage();
});

document.addEventListener('DOMContentLoaded', function() {
  mprReadGpsFixFromStorage();

  var gpsBtn = document.getElementById('btnGpsSpecialOnly');
  if (gpsBtn) {
    gpsBtn.addEventListener('click', function() {
      ['mpr_gps_last_fix','mpr_gps_last','mprGpsLast','mpr_gps_data','mprGpsData','gps_data','gpsData','mpr_realtime_gps','mprGpsRealtime','mpr_visit_gps','mprVisitGps'].forEach(function(k){
        try { localStorage.removeItem(k); } catch(e) {}
        try { sessionStorage.removeItem(k); } catch(e) {}
      });
    });
  }

  var oldBtn = document.getElementById('btnGps');
  if (oldBtn) oldBtn.style.display = 'none';
  var oldStop = document.getElementById('btnGpsStop');
  if (oldStop) oldStop.style.display = 'none';
});
</script>