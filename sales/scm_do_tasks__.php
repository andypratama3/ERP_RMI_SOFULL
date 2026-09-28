<?php

// --- upload safety (auto-enforced) ---
require_once dirname(__DIR__, 1) . '/_shared/upload_safety.php';
if (!empty($_FILES)) {
    // enforce safe_filename() for all uploaded names
    rmi_sanitize_uploads($_FILES);
}
// --- /upload safety ---
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/rbac.php';
require_once __DIR__ . '/../app/Services/SalesTrackingService.php';
require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['SALES.EDIT', 'SALES.VIEW']);
} else {
    require_role(['SYS','SUPERADMIN','ADMIN','SCM']);
}
require_once __DIR__ . '/_audit_helper.php';
require_once __DIR__ . '/../master/_audit_master.php';
require_once __DIR__ . '/_do_office_scope.php';
require_once __DIR__ . '/_do_task_helpers.php';
// sales/scm_do_tasks.php
// SCM - Task DO dari WQS (terima barang, kirim, serah terima ke customer, upload bukti foto/video + tanda tangan)
// ERP_RMI_SOFULL flow: CRM -> WQS -> SCM -> ACT -> FIN
// Catatan: auth/role parkir dulu (sementara), fokus UI/UX & flow.
// --- DB (centralized) ---
$pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
// Aktor username (bukan kode dept) untuk last_updated_by.
$__scm_actor = 'SCM';
try {
    if (function_exists('auth_user')) {
        $__au = auth_user();
        $aun = trim((string)(is_array($__au) ? ($__au['username'] ?? '') : $__au));
        if ($aun !== '') $__scm_actor = $aun;
    }
} catch (Throwable $e) { /* fallback 'SCM' */ }
$trackingSvc = new \App\Services\SalesTrackingService();

// ---------------- helpers ----------------
if (!function_exists('h')) {
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

function ensure_column(PDO $pdo, string $table, string $column, string $ddl): void {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $exists = (bool)$stmt->fetch();
        if (!$exists) $pdo->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
    } catch (Throwable $e) {
        // fail-soft
    }
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function select_col(bool $hasCol, string $col): string {
    return $hasCol ? "`{$col}`" : "NULL AS `{$col}`";
}

function select_col_d(bool $hasCol, string $col): string {
    return $hasCol ? "d.`{$col}`" : "NULL AS `{$col}`";
}

function generate_tracking_token(): string {
    return bin2hex(random_bytes(24));
}

function normalize_tracking_status_from_do(string $status): string {
    $s = strtolower(trim($status));
    if (in_array($s, ['delivered', 'scm_done', 'paid', 'paid_done', 'closed'], true)) return 'DELIVERED';
    if ($s === 'on_delivery') return 'ON_DELIVERY';
    if (in_array($s, ['ready_scm', 'wqs_done'], true)) return 'READY_TO_SHIP';
    return 'PENDING';
}

function sanitize_https_url(string $url): string {
    $url = trim($url);
    if ($url === '') return '';
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception("Fallback Live Location URL tidak valid.");
    }
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme !== 'https') {
        throw new Exception("Fallback Live Location URL wajib menggunakan https.");
    }
    return $url;
}

function build_tracking_public_url(string $token): string {
    if ($token === '') return '';
    $base = (string)(defined('BASE_PROJECT') ? BASE_PROJECT : (function_exists('auth_base_project') ? auth_base_project() : ''));
    $path = ($base !== '' ? rtrim($base, '/') . '/' : '') . 'sales/tracking_public.php?t=' . rawurlencode($token);
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '/' . ltrim($path, '/');
    }
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function build_wa_share_url(string $doCode, string $trackingUrl): string {
    $msg = "Halo, berikut link tracking pengiriman DO {$doCode}:\n{$trackingUrl}";
    return 'https://wa.me/?text=' . rawurlencode($msg);
}

function upload_file(string $field, string $dirRel, array $allowExt): ?string {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    $f = $_FILES[$field];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;

    $tmp = $f['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) return null;

    $name = (string)($f['name'] ?? 'file');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExt, true)) return null;

    $base = realpath(__DIR__ . '/..'); // project root
    if ($base === false) return null;

    $dirAbs = $base . '/' . trim($dirRel, '/');
    if (!is_dir($dirAbs)) @mkdir($dirAbs, 0777, true);

    $safe = preg_replace('/[^a-zA-Z0-9\-_\.]/', '_', pathinfo($name, PATHINFO_FILENAME));
    $fname = $safe . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;

    $destAbs = $dirAbs . '/' . $fname;
    if (!@move_uploaded_file($tmp, $destAbs)) return null;

    return '/' . trim($dirRel, '/') . '/' . $fname;
}

// -------------- schema guards --------------
ensure_column($pdo, 'sales_do', 'status', "status VARCHAR(50) NOT NULL DEFAULT 'crm_to_wqs'");

ensure_column($pdo, 'sales_do', 'scm_status', "scm_status VARCHAR(20) NULL"); // Pending/Open/Done (UI)
ensure_column($pdo, 'sales_do', 'scm_note', "scm_note TEXT NULL");

ensure_column($pdo, 'sales_do', 'scm_receive_photo', "scm_receive_photo VARCHAR(255) NULL");
ensure_column($pdo, 'sales_do', 'scm_receive_video', "scm_receive_video VARCHAR(255) NULL");

ensure_column($pdo, 'sales_do', 'scm_delivery_photo', "scm_delivery_photo VARCHAR(255) NULL");
ensure_column($pdo, 'sales_do', 'scm_delivery_video', "scm_delivery_video VARCHAR(255) NULL");

ensure_column($pdo, 'sales_do', 'scm_signature_data', "scm_signature_data LONGTEXT NULL"); // base64 png (simple)
ensure_column($pdo, 'sales_do', 'scm_delivered_at', "scm_delivered_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'scm_on_delivery_at', "scm_on_delivery_at DATETIME NULL");

ensure_column($pdo, 'sales_do', 'last_updated_by', "last_updated_by VARCHAR(50) NULL");
ensure_column($pdo, 'sales_do', 'last_updated_at', "last_updated_at DATETIME NULL");

// Delivery method (Sales DO delivery: internal team vs vendor jasa logistik)
ensure_column($pdo, 'sales_do', 'delivery_mode', "delivery_mode VARCHAR(20) NULL");
ensure_column($pdo, 'sales_do', 'delivery_vendor_id', "delivery_vendor_id INT NULL");
ensure_column($pdo, 'sales_do', 'carrier_provider', "carrier_provider VARCHAR(30) NULL");
ensure_column($pdo, 'sales_do', 'carrier_tracking_no', "carrier_tracking_no VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'carrier_courier_code', "carrier_courier_code VARCHAR(50) NULL");
ensure_column($pdo, 'sales_do', 'tracking_public_token', "tracking_public_token VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'tracking_last_sync_at', "tracking_last_sync_at DATETIME NULL");
ensure_column($pdo, 'sales_do', 'tracking_last_status', "tracking_last_status VARCHAR(100) NULL");
ensure_column($pdo, 'sales_do', 'tracking_last_payload_json', "tracking_last_payload_json LONGTEXT NULL");
ensure_column($pdo, 'sales_do', 'fallback_live_location_url', "fallback_live_location_url VARCHAR(500) NULL");
ensure_column($pdo, 'sales_do', 'scm_live_lat', "scm_live_lat DECIMAL(10,7) NULL");
ensure_column($pdo, 'sales_do', 'scm_live_lng', "scm_live_lng DECIMAL(10,7) NULL");
ensure_column($pdo, 'sales_do', 'scm_live_accuracy_m', "scm_live_accuracy_m DECIMAL(8,2) NULL");
ensure_column($pdo, 'sales_do', 'scm_live_at', "scm_live_at DATETIME NULL");

$hasScmStatus         = table_has_column($pdo, 'sales_do', 'scm_status');
$hasScmNote           = table_has_column($pdo, 'sales_do', 'scm_note');
$hasScmReceivePhoto   = table_has_column($pdo, 'sales_do', 'scm_receive_photo');
$hasScmReceiveVideo   = table_has_column($pdo, 'sales_do', 'scm_receive_video');
$hasScmDeliveryPhoto  = table_has_column($pdo, 'sales_do', 'scm_delivery_photo');
$hasScmDeliveryVideo  = table_has_column($pdo, 'sales_do', 'scm_delivery_video');
$hasScmSignatureData  = table_has_column($pdo, 'sales_do', 'scm_signature_data');
$hasScmDeliveredAt    = table_has_column($pdo, 'sales_do', 'scm_delivered_at');
$hasScmOnDeliveryAt   = table_has_column($pdo, 'sales_do', 'scm_on_delivery_at');
$hasLastUpdatedBy     = table_has_column($pdo, 'sales_do', 'last_updated_by');
$hasLastUpdatedAt     = table_has_column($pdo, 'sales_do', 'last_updated_at');
$hasDeliveryMode      = table_has_column($pdo, 'sales_do', 'delivery_mode');
$hasDeliveryVendorId  = table_has_column($pdo, 'sales_do', 'delivery_vendor_id');
$hasCarrierProvider   = table_has_column($pdo, 'sales_do', 'carrier_provider');
$hasCarrierTrackingNo = table_has_column($pdo, 'sales_do', 'carrier_tracking_no');
$hasCarrierCourierCode = table_has_column($pdo, 'sales_do', 'carrier_courier_code');
$hasTrackingPublicToken = table_has_column($pdo, 'sales_do', 'tracking_public_token');
$hasTrackingLastSyncAt = table_has_column($pdo, 'sales_do', 'tracking_last_sync_at');
$hasTrackingLastStatus = table_has_column($pdo, 'sales_do', 'tracking_last_status');
$hasTrackingLastPayload = table_has_column($pdo, 'sales_do', 'tracking_last_payload_json');
$hasFallbackLiveLocationUrl = table_has_column($pdo, 'sales_do', 'fallback_live_location_url');
$hasScmLiveLat = table_has_column($pdo, 'sales_do', 'scm_live_lat');
$hasScmLiveLng = table_has_column($pdo, 'sales_do', 'scm_live_lng');
$hasScmLiveAccuracy = table_has_column($pdo, 'sales_do', 'scm_live_accuracy_m');
$hasScmLiveAt = table_has_column($pdo, 'sales_do', 'scm_live_at');


$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id     = (int)($_POST['id'] ?? 0);

    try {
        $stmt = $pdo->prepare("SELECT id, do_code, status, office_code, tracking_code, "
                                      . select_col($hasDeliveryMode, 'delivery_mode') . ", "
                                      . select_col($hasDeliveryVendorId, 'delivery_vendor_id') . ", "
                                      . select_col($hasCarrierProvider, 'carrier_provider') . ", "
                                      . select_col($hasCarrierTrackingNo, 'carrier_tracking_no') . ", "
                                      . select_col($hasCarrierCourierCode, 'carrier_courier_code') . ", "
                                      . select_col($hasTrackingPublicToken, 'tracking_public_token') . ", "
                                      . select_col($hasTrackingLastStatus, 'tracking_last_status') . ", "
                                      . select_col($hasScmReceivePhoto, 'scm_receive_photo') . ", "
                                      . select_col($hasScmReceiveVideo, 'scm_receive_video') . ", "
                                      . select_col($hasScmDeliveryPhoto, 'scm_delivery_photo') . ", "
                                      . select_col($hasScmDeliveryVideo, 'scm_delivery_video') . ", "
                                      . select_col($hasScmSignatureData, 'scm_signature_data') . "
                               FROM sales_do WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) throw new Exception("DO tidak ditemukan.");
        do_scope_assert_row($r); // Guard: BRANCH hanya boleh akses DO kantornya sendiri

        $curStatus = (string)($r['status'] ?? ''); // status sekarang untuk validasi transisi

        $note = trim((string)($_POST['scm_note'] ?? ''));
        $scm_status = (string)($_POST['scm_status'] ?? 'Pending');
        if (!in_array($scm_status, ['Pending','Open','Done'], true)) $scm_status = 'Pending';

        // delivery method (SCM)
        $delivery_mode = strtoupper(trim((string)($_POST['delivery_mode'] ?? ($r['delivery_mode'] ?? ''))));
        if (!in_array($delivery_mode, ['INTERNAL','VENDOR'], true)) $delivery_mode = 'INTERNAL';
        $delivery_vendor_id = (int)($_POST['delivery_vendor_id'] ?? ($r['delivery_vendor_id'] ?? 0));
        if ($delivery_mode !== 'VENDOR') $delivery_vendor_id = 0;
        $carrier_provider = strtoupper(trim((string)($_POST['carrier_provider'] ?? ($r['carrier_provider'] ?? 'BITESHIP'))));
        if (!in_array($carrier_provider, ['BITESHIP'], true)) $carrier_provider = 'BITESHIP';
        $carrier_tracking_no = trim((string)($_POST['carrier_tracking_no'] ?? ($r['carrier_tracking_no'] ?? '')));
        $carrier_courier_code = trim((string)($_POST['carrier_courier_code'] ?? ($r['carrier_courier_code'] ?? '')));
        $fallback_live_location_url = sanitize_https_url((string)($_POST['fallback_live_location_url'] ?? ''));
        $tracking_public_token = trim((string)($r['tracking_public_token'] ?? ''));
        if ($tracking_public_token === '') $tracking_public_token = generate_tracking_token();


        // uploads
        $imgExt = ['jpg','jpeg','png','webp','pdf'];
        $vidExt = ['mp4','mov','m4v','webm'];

        $recv_photo_up = upload_file('scm_receive_photo', 'uploads/sales_scm', $imgExt);
        $recv_video_up = upload_file('scm_receive_video', 'uploads/sales_scm', $vidExt);

        $del_photo_up  = upload_file('scm_delivery_photo', 'uploads/sales_scm', $imgExt);
        $del_video_up  = upload_file('scm_delivery_video', 'uploads/sales_scm', $vidExt);

        $recv_photo = $recv_photo_up ?: ($r['scm_receive_photo'] ?? '');
        $recv_video = $recv_video_up ?: ($r['scm_receive_video'] ?? '');
        $del_photo  = $del_photo_up  ?: ($r['scm_delivery_photo'] ?? '');
        $del_video  = $del_video_up  ?: ($r['scm_delivery_video'] ?? '');

        // signature (base64 png from canvas)
        $sig = trim((string)($_POST['scm_signature_data'] ?? ''));
        if ($sig && str_starts_with($sig, 'data:image/png;base64,')) {
            // keep
        } else {
            $sig = '';
        }
        $sig_final = $sig ?: ($r['scm_signature_data'] ?? '');

        if ($action === 'save') {
            $sets = [];
            $params = [];
            if ($hasScmNote) { $sets[] = "scm_note=?"; $params[] = $note; }
            if ($hasScmStatus) { $sets[] = "scm_status=?"; $params[] = $scm_status; }
            if ($hasDeliveryMode) { $sets[] = "delivery_mode=?"; $params[] = $delivery_mode; }
            if ($hasDeliveryVendorId) { $sets[] = "delivery_vendor_id=?"; $params[] = ($delivery_vendor_id?:null); }
            if ($hasCarrierProvider) { $sets[] = "carrier_provider=?"; $params[] = $carrier_provider; }
            if ($hasCarrierTrackingNo) { $sets[] = "carrier_tracking_no=?"; $params[] = $carrier_tracking_no !== '' ? $carrier_tracking_no : null; }
            if ($hasCarrierCourierCode) { $sets[] = "carrier_courier_code=?"; $params[] = $carrier_courier_code !== '' ? $carrier_courier_code : null; }
            if ($hasFallbackLiveLocationUrl) { $sets[] = "fallback_live_location_url=?"; $params[] = $fallback_live_location_url !== '' ? $fallback_live_location_url : null; }
            if ($hasTrackingPublicToken) { $sets[] = "tracking_public_token=?"; $params[] = $tracking_public_token; }
            if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=" . $pdo->quote($__scm_actor); }
            if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }

            if ($hasScmReceivePhoto && $recv_photo_up) { $sets[] = "scm_receive_photo=?"; $params[] = $recv_photo_up; }
            if ($hasScmReceiveVideo && $recv_video_up) { $sets[] = "scm_receive_video=?"; $params[] = $recv_video_up; }
            if ($hasScmDeliveryPhoto && $del_photo_up) { $sets[] = "scm_delivery_photo=?"; $params[] = $del_photo_up; }
            if ($hasScmDeliveryVideo && $del_video_up) { $sets[] = "scm_delivery_video=?"; $params[] = $del_video_up; }
            if ($hasScmSignatureData && $sig) { $sets[] = "scm_signature_data=?"; $params[] = $sig; }

            if (!empty($sets)) {
                $params[] = $id;
                $pdo->prepare("UPDATE sales_do SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
            }
            $code = (string)($r['do_code'] ?? '');
            rmi_audit_safe('UPDATE', 'SALES.DO', $id, null, null, ['event' => 'scm_save', 'do_code' => $code]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'sales_do', 'sales_do', 'SCM_SAVE', $id, $code, "DO SCM save: {$code}", []);
            }
            $success = "Tersimpan (SCM).";
        }

        if ($action === 'refresh_tracking') {
            $sets = [];
            $params = [];
            if ($hasCarrierProvider) { $sets[] = "carrier_provider=?"; $params[] = $carrier_provider; }
            if ($hasCarrierTrackingNo) { $sets[] = "carrier_tracking_no=?"; $params[] = $carrier_tracking_no !== '' ? $carrier_tracking_no : null; }
            if ($hasCarrierCourierCode) { $sets[] = "carrier_courier_code=?"; $params[] = $carrier_courier_code !== '' ? $carrier_courier_code : null; }
            if ($hasFallbackLiveLocationUrl) { $sets[] = "fallback_live_location_url=?"; $params[] = $fallback_live_location_url !== '' ? $fallback_live_location_url : null; }
            if ($hasTrackingPublicToken) { $sets[] = "tracking_public_token=?"; $params[] = $tracking_public_token; }
            if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=" . $pdo->quote($__scm_actor); }
            if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }
            if (!empty($sets)) {
                $params[] = $id;
                $pdo->prepare("UPDATE sales_do SET " . implode(', ', $sets) . " WHERE id=?")->execute($params);
            }
            $code = (string)($r['do_code'] ?? '');
            rmi_audit_safe('UPDATE', 'SALES.DO', $id, null, null, ['event' => 'scm_refresh_tracking', 'do_code' => $code]);
            if (function_exists('master_audit')) {
                master_audit($pdo, 'sales_do', 'sales_do', 'SCM_REFRESH_TRACKING', $id, $code, "DO SCM refresh tracking: {$code}", []);
            }
            $sync = $trackingSvc->syncByDoId($pdo, $id, true);
            if (empty($sync['ok'])) {
                throw new Exception((string)($sync['error'] ?? 'Sinkronisasi gagal'));
            }
            $success = "Tracking di-refresh dari provider: " . h((string)($sync['provider_status'] ?? 'UNKNOWN'));
        }

        if ($action === 'on_delivery') {
    if ($delivery_mode === 'VENDOR' && $delivery_vendor_id <= 0) {
        throw new Exception("Pilih Vendor/Logistik jika Metode Pengiriman = VENDOR.");
    }

    // Guard status agar tidak transisi ke state yang sama
    if ($curStatus === 'on_delivery') {
        throw new Exception("DO ini sudah berstatus ON DELIVERY. Lanjutkan ke DELIVERED jika barang sudah sampai.");
    }
    if ($curStatus === 'delivered') {
        throw new Exception("DO ini sudah berstatus DELIVERED dan tidak bisa dikirim ulang ke ON DELIVERY.");
    }
    if ($curStatus !== 'ready_scm') {
        throw new Exception("Transisi ke ON DELIVERY hanya boleh dari status READY SCM.");
    }

    if (!$recv_photo && !$recv_video) {
        throw new Exception("Wajib upload bukti terima barang dari WQS (foto atau video) sebelum set ON DELIVERY.");
    }

    if (function_exists('auth_sales_do_require_transition')) {
        auth_sales_do_require_transition($curStatus, 'on_delivery', 'SCM_ON_DELIVERY');
    }

    $sets = ["status='on_delivery'"];
    $params = [];
    if ($hasScmOnDeliveryAt) { $sets[] = "scm_on_delivery_at=NOW()"; }
    if ($hasScmNote) { $sets[] = "scm_note=?"; $params[] = $note; }
    if ($hasScmStatus) { $sets[] = "scm_status='Open'"; }
    if ($hasScmReceivePhoto) { $sets[] = "scm_receive_photo=?"; $params[] = $recv_photo; }
    if ($hasScmReceiveVideo) { $sets[] = "scm_receive_video=?"; $params[] = $recv_video; }
    if ($hasDeliveryMode) { $sets[] = "delivery_mode=?"; $params[] = $delivery_mode; }
    if ($hasDeliveryVendorId) { $sets[] = "delivery_vendor_id=?"; $params[] = ($delivery_vendor_id ?: null); }
    if ($hasTrackingPublicToken) { $sets[] = "tracking_public_token=?"; $params[] = $tracking_public_token; }
    if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=" . $pdo->quote($__scm_actor); }
    if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }
    $params[] = $id;

    $pdo->prepare(
        "UPDATE sales_do SET " . implode(",\n                ", $sets) . "
         WHERE id=?"
    )->execute($params);

    $code = (string)($r['do_code'] ?? '');
    rmi_audit_safe('UPDATE', 'SALES.DO', $id, null, null, [
        'event' => 'scm_on_delivery',
        'do_code' => $code,
        'from_status' => $curStatus,
        'to_status' => 'on_delivery',
    ]);

    if (function_exists('master_audit')) {
        master_audit(
            $pdo,
            'sales_do',
            'sales_do',
            'SCM_ON_DELIVERY',
            $id,
            $code,
            "DO SCM on delivery: {$code}",
            ['from_status' => $curStatus, 'to_status' => 'on_delivery']
        );
    }

    if (function_exists('sales_do_audit_append')) {
        sales_do_audit_append($pdo, $id, $curStatus, 'on_delivery', 'SCM', $note);
    }

    $success = "Status: ON DELIVERY (CRM bisa info customer) ✅";
}

     if ($action === 'delivered') {
    if ($delivery_mode === 'VENDOR' && $delivery_vendor_id <= 0) {
        throw new Exception("Pilih Vendor/Logistik jika Metode Pengiriman = VENDOR.");
    }

    // Guard status agar delivered hanya dari on_delivery
    if ($curStatus === 'delivered') {
        throw new Exception("DO ini sudah berstatus DELIVERED.");
    }
    if ($curStatus !== 'on_delivery') {
        throw new Exception("Transisi ke DELIVERED hanya boleh dari status ON DELIVERY.");
    }

    if (!$del_photo && !$del_video) {
        throw new Exception("Wajib upload bukti serah terima ke customer (foto atau video) sebelum set DELIVERED.");
    }
    if (!$sig_final) {
        throw new Exception("Wajib tanda tangan digital (signature) sebelum set DELIVERED.");
    }

    if (function_exists('auth_sales_do_require_transition')) {
        auth_sales_do_require_transition($curStatus, 'delivered', 'SCM_DELIVERED');
    }

    $sets = ["status='delivered'"];
    $params = [];
    if ($hasScmDeliveredAt) { $sets[] = "scm_delivered_at=NOW()"; }
    if ($hasScmNote) { $sets[] = "scm_note=?"; $params[] = $note; }
    if ($hasScmStatus) { $sets[] = "scm_status='Done'"; }
    if ($hasScmDeliveryPhoto) { $sets[] = "scm_delivery_photo=?"; $params[] = $del_photo; }
    if ($hasScmDeliveryVideo) { $sets[] = "scm_delivery_video=?"; $params[] = $del_video; }
    if ($hasScmSignatureData) { $sets[] = "scm_signature_data=?"; $params[] = $sig_final; }
    if ($hasDeliveryMode) { $sets[] = "delivery_mode=?"; $params[] = $delivery_mode; }
    if ($hasDeliveryVendorId) { $sets[] = "delivery_vendor_id=?"; $params[] = ($delivery_vendor_id ?: null); }
    if ($hasTrackingPublicToken) { $sets[] = "tracking_public_token=?"; $params[] = $tracking_public_token; }
    if ($hasLastUpdatedBy) { $sets[] = "last_updated_by=" . $pdo->quote($__scm_actor); }
    if ($hasLastUpdatedAt) { $sets[] = "last_updated_at=NOW()"; }
    $params[] = $id;

    $pdo->prepare(
        "UPDATE sales_do SET " . implode(",\n                ", $sets) . "
         WHERE id=?"
    )->execute($params);

    $code = (string)($r['do_code'] ?? '');
    rmi_audit_safe('UPDATE', 'SALES.DO', $id, null, null, [
        'event' => 'scm_delivered',
        'do_code' => $code,
        'from_status' => $curStatus,
        'to_status' => 'delivered',
    ]);

    if (function_exists('master_audit')) {
        master_audit(
            $pdo,
            'sales_do',
            'sales_do',
            'SCM_DELIVERED',
            $id,
            $code,
            "DO SCM delivered: {$code}",
            ['from_status' => $curStatus, 'to_status' => 'delivered']
        );
    }

    if (function_exists('sales_do_audit_append')) {
        sales_do_audit_append($pdo, $id, $curStatus, 'delivered', 'SCM', $note);
    }

    $success = "Status: DELIVERED ✅ (Trigger ACT)";
}

    } catch (Throwable $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// --- Filter GET ---
$_f_status  = trim((string)($_GET['f_status'] ?? ''));
$_f_date_fr = trim((string)($_GET['f_date_fr'] ?? ''));
$_f_date_to = trim((string)($_GET['f_date_to'] ?? ''));
$_f_q       = trim((string)($_GET['f_q'] ?? ''));

$_scm_scope = do_scope_where('d');
$_scm_extra_sql    = '';
$_scm_extra_params = [];

$_allowed_scm_status = ['ready_scm','on_delivery','delivered'];
if ($_f_status !== '' && in_array($_f_status, $_allowed_scm_status, true)) {
    $_scm_extra_sql    .= " AND d.status = ?";
    $_scm_extra_params[] = $_f_status;
}
if ($_f_date_fr !== '') { $_scm_extra_sql .= " AND d.do_date >= ?"; $_scm_extra_params[] = $_f_date_fr; }
if ($_f_date_to !== '') { $_scm_extra_sql .= " AND d.do_date <= ?"; $_scm_extra_params[] = $_f_date_to; }
if ($_f_q !== '') {
    $_scm_extra_sql    .= " AND (d.do_code LIKE ? OR d.customers_code LIKE ? OR c.customers_name LIKE ?)";
    $_like = '%' . $_f_q . '%';
    $_scm_extra_params[] = $_like;
    $_scm_extra_params[] = $_like;
    $_scm_extra_params[] = $_like;
}

$_scm_sql = "
    SELECT d.id, d.do_code, d.tracking_code, d.do_date, d.customers_code,
           c.customers_name,
           d.office_code, d.grand_total,
           " . select_col_d($hasDeliveryMode, 'delivery_mode') . ",
           " . select_col_d($hasDeliveryVendorId, 'delivery_vendor_id') . ",
           " . select_col_d($hasCarrierProvider, 'carrier_provider') . ",
           " . select_col_d($hasCarrierTrackingNo, 'carrier_tracking_no') . ",
           " . select_col_d($hasCarrierCourierCode, 'carrier_courier_code') . ",
           " . select_col_d($hasTrackingPublicToken, 'tracking_public_token') . ",
           " . select_col_d($hasTrackingLastSyncAt, 'tracking_last_sync_at') . ",
           " . select_col_d($hasTrackingLastStatus, 'tracking_last_status') . ",
           " . select_col_d($hasFallbackLiveLocationUrl, 'fallback_live_location_url') . ",
           " . select_col_d($hasScmLiveLat, 'scm_live_lat') . ",
           " . select_col_d($hasScmLiveLng, 'scm_live_lng') . ",
           " . select_col_d($hasScmLiveAccuracy, 'scm_live_accuracy_m') . ",
           " . select_col_d($hasScmLiveAt, 'scm_live_at') . ",
           d.status,
           " . select_col_d($hasScmStatus, 'scm_status') . ",
           " . select_col_d($hasScmNote, 'scm_note') . ",
           " . select_col_d($hasScmReceivePhoto, 'scm_receive_photo') . ",
           " . select_col_d($hasScmReceiveVideo, 'scm_receive_video') . ",
           " . select_col_d($hasScmDeliveryPhoto, 'scm_delivery_photo') . ",
           " . select_col_d($hasScmDeliveryVideo, 'scm_delivery_video') . ",
           " . select_col_d($hasScmSignatureData, 'scm_signature_data') . "
    FROM sales_do d
    LEFT JOIN master_customers c ON c.customers_code = d.customers_code
    WHERE d.status IN ('ready_scm','on_delivery','delivered')
    " . $_scm_scope['sql'] . $_scm_extra_sql . "
    ORDER BY "
    . ($hasLastUpdatedAt ? "COALESCE(d.last_updated_at, d.do_date) DESC, " : "")
    . "d.do_date DESC, d.id DESC";
$_scm_st = $pdo->prepare($_scm_sql);
$_scm_st->execute(array_merge($_scm_scope['params'], $_scm_extra_params));
$rows = $_scm_st->fetchAll();

if ($hasTrackingPublicToken && !empty($rows)) {
    foreach ($rows as &$rowRef) {
        $token = trim((string)($rowRef['tracking_public_token'] ?? ''));
        if ($token === '') {
            $token = generate_tracking_token();
            try {
                $pdo->prepare("UPDATE sales_do SET tracking_public_token=? WHERE id=?")->execute([$token, (int)$rowRef['id']]);
                $rowRef['tracking_public_token'] = $token;
            } catch (Throwable $e) {
                // fail-soft
            }
        }
    }
    unset($rowRef);
}

// vendor list (jasa logistik / pengiriman)
$vendorList = [];
try {
    $vendorList = $pdo->query("
    SELECT id, vendors_code, vendors_name FROM master_vendors
    WHERE status='active'
    AND (vendor_type = 'Forwarding' OR vendor_type = 'Forwarder'
         OR vendor_type IN ('Logistic/Expedisi','Logistics','Logistic','Ekspedisi','Expedisi'))
    ORDER BY vendors_name ASC
")->fetchAll();
} catch (Throwable $e) { $vendorList = []; }

// Audit log (last 50) - sales_do (SCM)
$audit_rows = [];
try {
    if (function_exists('master_audit_ensure_table')) {
        master_audit_ensure_table($pdo);
    }
    $st = $pdo->prepare("
        SELECT action, record_code, username, description, created_at
        FROM system_audit_logs
        WHERE module = 'sales_do'
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $st->execute();
    $audit_rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $audit_rows = [];
}

function pill($label, $active=false) {
    $cls = $active ? 'pill active' : 'pill';
    return '<span class="'.$cls.'">'.h($label).'</span>';
}

function badgeStatus($s): string {
    $s = strtolower((string)$s);
    $map = [
        'ready_scm'   => ['READY SCM','tag green'],
        'on_delivery' => ['ON DELIVERY','tag yellow'],
        'delivered'   => ['DELIVERED','tag blue'],
    ];
    $v = $map[$s] ?? [strtoupper($s), 'tag'];
    return '<span class="'.$v[1].'">'.h($v[0]).'</span>';
}
?>
<?php
require_once __DIR__ . '/../_shared/rmi_layout.php';
$baseProject = rmi_layout_base_project();

rmi_header('SCM - Task DO', [
  'active' => 'sales',
  'breadcrumbs' => [
    ['label' => 'Sales (CRM)', 'url' => $baseProject . '/sales/sales_dashboard.php'],
    'SCM - Task DO',
  ],
  'actions' => [
    ['label' => '📚 Panduan Task', 'url' => $baseProject . '/sales/panduan_do_tasks.php', 'class' => 'btn btn-sm btn-outline-light'],
    ['label' => '🗼 Control Tower', 'url' => $baseProject . '/sales/sales_control_tower.php', 'class' => 'btn btn-sm btn-outline-light'],
  ],
  'extra_head' => '<style>
    :root{
      --bg:#080e1a; --surface:#0f1724; --surface2:#141d2e; --border:rgba(255,255,255,.07);
      --border-hover:rgba(255,255,255,.13); --text:#e2e8f0; --muted:#64748b; --muted2:#94a3b8;
      --blue:#3b82f6; --blue-s:rgba(59,130,246,.15); --blue-b:rgba(59,130,246,.35);
      --green:#22c55e; --green-s:rgba(34,197,94,.12); --green-b:rgba(34,197,94,.3);
      --yellow:#f59e0b; --yellow-s:rgba(245,158,11,.12); --yellow-b:rgba(245,158,11,.3);
      --red:#ef4444; --red-s:rgba(239,68,68,.12); --red-b:rgba(239,68,68,.3);
      --radius:14px; --radius-sm:9px;
    }
    *{box-sizing:border-box;margin:0;padding:0}
    body{background:var(--bg);color:var(--text);font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}
    a{color:#60a5fa;text-decoration:none}
    a:hover{text-decoration:underline}
    code{font-family:monospace;font-size:11px;background:rgba(255,255,255,.06);padding:2px 6px;border-radius:4px}

    /* Layout */
    .scm-wrap{max-width:1060px;margin:24px auto;padding:0 16px}

    /* Page header */
    .page-hd{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px;flex-wrap:wrap}
    .page-hd-left h1{font-size:19px;font-weight:700;letter-spacing:-.3px;margin-bottom:3px}
    .page-hd-left .sub{font-size:12px;color:var(--muted2);margin-bottom:10px}
    .page-hd-right{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}

    /* Flow stepper */
    .stepper{display:flex;align-items:center;gap:4px;flex-wrap:wrap}
    .step{display:flex;align-items:center;gap:4px;font-size:11px;font-weight:600;padding:4px 10px;border-radius:999px;
          border:1px solid var(--border);color:var(--muted);background:transparent;letter-spacing:.4px;text-transform:uppercase}
    .step.active{border-color:var(--green-b);color:#bbf7d0;background:var(--green-s)}
    .step-arrow{color:var(--muted);font-size:10px}

    /* Buttons */
    .btn-nav{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);background:rgba(255,255,255,.04);
             color:var(--muted2);padding:7px 12px;border-radius:var(--radius-sm);font-size:12px;cursor:pointer;white-space:nowrap;text-decoration:none}
    .btn-nav:hover{background:rgba(255,255,255,.07);color:var(--text);text-decoration:none}
    .btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);background:rgba(255,255,255,.05);
         color:var(--text);padding:8px 14px;border-radius:var(--radius-sm);font-size:12px;cursor:pointer;font-weight:500;white-space:nowrap}
    .btn:hover{background:rgba(255,255,255,.08)}
    .btn.primary{background:var(--blue-s);border-color:var(--blue-b);color:#bfdbfe}
    .btn.primary:hover{background:rgba(59,130,246,.22)}
    .btn.warn{background:var(--yellow-s);border-color:var(--yellow-b);color:#fde68a}
    .btn.warn:hover{background:rgba(245,158,11,.2)}
    .btn.success{background:var(--green-s);border-color:var(--green-b);color:#bbf7d0}
    .btn.success:hover{background:rgba(34,197,94,.18)}
    .btn.danger{background:var(--red-s);border-color:var(--red-b);color:#fca5a5}
    .btn.sm{padding:5px 10px;font-size:11px;border-radius:7px}

    /* Alerts */
    .alert{padding:11px 14px;border-radius:var(--radius-sm);margin:12px 0;font-size:12px;border:1px solid}
    .alert.ok{background:var(--green-s);border-color:var(--green-b);color:#bbf7d0}
    .alert.bad{background:var(--red-s);border-color:var(--red-b);color:#fca5a5}

    /* Badges */
    .badge{display:inline-flex;align-items:center;gap:4px;border-radius:999px;padding:3px 9px;font-size:11px;font-weight:600;border:1px solid;letter-spacing:.3px;text-transform:uppercase}
    .badge.green{background:var(--green-s);border-color:var(--green-b);color:#bbf7d0}
    .badge.yellow{background:var(--yellow-s);border-color:var(--yellow-b);color:#fde68a}
    .badge.blue{background:var(--blue-s);border-color:var(--blue-b);color:#bfdbfe}
    .badge.gray{background:rgba(255,255,255,.05);border-color:var(--border);color:var(--muted2)}
    .badge.red{background:var(--red-s);border-color:var(--red-b);color:#fca5a5}
    .dot{width:6px;height:6px;border-radius:999px;display:inline-block;flex-shrink:0}
    .dot.green{background:var(--green)}
    .dot.yellow{background:var(--yellow)}
    .dot.blue{background:var(--blue)}

    /* Card */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px}
    .card+.card{margin-top:10px}

    /* Filter bar */
    .filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
    .filter-group{display:flex;flex-direction:column;gap:5px}
    .filter-group label{font-size:11px;color:var(--muted2);font-weight:500;letter-spacing:.3px;text-transform:uppercase}
    .field{width:100%;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--text);
           border-radius:var(--radius-sm);padding:7px 10px;outline:none;font-size:12px;transition:border .15s}
    .field:focus{border-color:rgba(59,130,246,.5);background:rgba(59,130,246,.04)}
    .select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 24 24%27 fill=%27none%27 stroke=%27%2364748b%27 stroke-width=%272%27%3E%3Cpath d=%27M6 9l6 6 6-6%27/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 10px center;padding-right:28px}
    .filter-actions{display:flex;gap:6px;padding-bottom:1px}
    .result-count{font-size:11px;color:var(--muted);padding:7px 0;align-self:flex-end}

    /* Section header */
    .section-hd{display:flex;align-items:center;gap:10px;margin-bottom:12px}
    .section-hd h2{font-size:13px;font-weight:600;color:var(--text)}
    .section-hd .count{background:rgba(255,255,255,.08);border:1px solid var(--border);border-radius:999px;
                       padding:1px 8px;font-size:11px;color:var(--muted2);font-weight:600}
    .section-hd .desc{font-size:11px;color:var(--muted);margin-left:4px}
    .section-divider{height:1px;background:var(--border);margin:16px 0}

    /* DO Card (active) */
    .do-card{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:border .15s}
    .do-card:hover{border-color:var(--border-hover)}
    .do-card+.do-card{margin-top:10px}
    .do-card.status-ready{border-left:3px solid var(--green)}
    .do-card.status-delivery{border-left:3px solid var(--yellow)}

    .do-card-top{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:16px;padding:14px 16px;align-items:start;border-bottom:1px solid var(--border)}
    .do-meta-label{font-size:10px;font-weight:600;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);margin-bottom:3px}
    .do-meta-val{font-size:13px;font-weight:600;color:var(--text)}
    .do-meta-sub{font-size:11px;color:var(--muted2);margin-top:1px}
    .do-meta-link{font-size:11px;color:#60a5fa;margin-top:3px;display:block}
    .do-status-col{display:flex;flex-direction:column;gap:6px;align-items:flex-start}
    .do-amount{font-size:15px;font-weight:700;letter-spacing:-.3px}

    /* DO card body (form) */
    .do-card-body{padding:14px 16px;display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:800px){.do-card-body,.do-card-top{grid-template-columns:1fr}}
    .form-section{display:flex;flex-direction:column;gap:10px}
    .form-section-title{font-size:11px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);padding-bottom:6px;border-bottom:1px solid var(--border);margin-bottom:4px}

    /* Form fields */
    .field-row{display:flex;flex-direction:column;gap:4px}
    .field-row label{font-size:11px;color:var(--muted2);font-weight:500}
    .field-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    textarea.field{resize:vertical;min-height:54px}

    /* Track box */
    .track-box{background:rgba(2,6,23,.4);border:1px dashed rgba(100,116,139,.35);border-radius:var(--radius-sm);padding:10px 12px;display:flex;flex-direction:column;gap:8px}
    .track-info-row{display:flex;align-items:baseline;gap:6px;font-size:11px}
    .track-info-row .lbl{color:var(--muted);width:90px;flex-shrink:0}
    .track-info-row .val{color:var(--muted2)}
    .track-info-row a{font-size:11px}

    /* Upload / proof area */
    .proof-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .proof-item{background:rgba(255,255,255,.03);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px}
    .proof-item .lbl{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:5px}
    .proof-link{font-size:11px;color:#60a5fa}
    .proof-section-hd{font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--muted2);margin:6px 0 4px;display:flex;align-items:center;gap:6px}

    /* Signature */
    .sig-wrap{background:#06101e;border:1px solid rgba(255,255,255,.1);border-radius:var(--radius-sm);overflow:hidden}
    .sig-wrap .sig-label{font-size:10px;font-weight:600;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);padding:8px 10px 4px;display:flex;align-items:center;gap:6px}
    canvas.sig{display:block;width:100%;height:110px;touch-action:none}
    .sig-bar{display:flex;gap:6px;justify-content:flex-end;padding:6px 8px;border-top:1px solid rgba(255,255,255,.06)}

    /* Action bar */
    .action-bar{display:flex;gap:6px;flex-wrap:wrap;align-items:center;padding:12px 16px;background:rgba(255,255,255,.015);border-top:1px solid var(--border)}
    .action-bar .sep{flex:1}

    /* Delivery toggle */
    .delivery-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .delivery-row .field{flex:1;min-width:120px}

    /* History table */
    .hist-table-wrap{overflow-x:auto;border-radius:var(--radius-sm)}
    .hist-table{width:100%;border-collapse:collapse;min-width:780px;font-size:12px}
    .hist-table thead th{padding:8px 12px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap;background:var(--surface)}
    .hist-table tbody td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:top;color:var(--muted2)}
    .hist-table tbody tr:hover td{background:rgba(255,255,255,.02)}
    .hist-table tbody tr:last-child td{border-bottom:none}
    .hist-table .bold{font-weight:600;color:var(--text)}

    /* Audit log */
    .audit-table{width:100%;border-collapse:collapse;font-size:11px}
    .audit-table thead th{padding:7px 10px;text-align:left;font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--muted);border-bottom:1px solid var(--border)}
    .audit-table tbody td{padding:7px 10px;border-bottom:1px solid rgba(255,255,255,.04);color:var(--muted2)}
    .audit-table tbody tr:last-child td{border-bottom:none}

    /* Misc */
    .empty-state{text-align:center;padding:32px 16px;color:var(--muted);font-size:12px}
    .empty-state svg{display:block;margin:0 auto 10px;opacity:.3}
    .file-exists{display:inline-flex;align-items:center;gap:4px;font-size:11px}
    .chip{display:inline-flex;align-items:center;gap:5px;font-size:11px;border-radius:6px;padding:3px 8px;border:1px solid var(--border);background:rgba(255,255,255,.04);color:var(--muted2)}
  </style>',
]);
?>

<div class="scm-wrap">

  <!-- Page header -->
  <div class="page-hd">
    <div class="page-hd-left">
      <h1>SCM — Task DO dari WQS</h1>
      <div class="sub">Terima barang, update pengiriman, upload bukti serah-terima, tanda tangan digital.</div>
      <div class="stepper">
        <span class="step">CRM</span><span class="step-arrow">›</span>
        <span class="step">WQS</span><span class="step-arrow">›</span>
        <span class="step active"><span class="dot green"></span>SCM</span><span class="step-arrow">›</span>
        <span class="step">ACT</span><span class="step-arrow">›</span>
        <span class="step">FIN</span>
      </div>
    </div>
    <div class="page-hd-right">
      <a class="btn-nav" href="<?= h($salesU) ?>/sales_dashboard.php">← Sales</a>
      <a class="btn-nav" href="../stock/wqs_do_tasks.php">WQS Tasks</a>
      <a class="btn-nav" href="<?= h($salesU) ?>/scm_tracker_mobile.php">📍 Tracker</a>
    </div>
  </div>

  <?php if ($success): ?><div class="alert ok">✓ <?php echo h($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert bad">⚠ <?php echo h($error); ?></div><?php endif; ?>

  <!-- Filter -->
  <div class="card" style="margin-bottom:12px">
    <form method="get" class="filter-bar">
      <div class="filter-group">
        <label>Cari</label>
        <input class="field" style="width:200px" name="f_q" placeholder="DO code, customer…" value="<?= h($_f_q) ?>">
      </div>
      <div class="filter-group">
        <label>Status</label>
        <select class="field select" style="width:150px" name="f_status">
          <option value="">Semua</option>
          <option value="ready_scm"   <?= $_f_status==='ready_scm'   ?'selected':'' ?>>READY SCM</option>
          <option value="on_delivery" <?= $_f_status==='on_delivery' ?'selected':'' ?>>ON DELIVERY</option>
          <option value="delivered"   <?= $_f_status==='delivered'   ?'selected':'' ?>>DELIVERED</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Tanggal dari</label>
        <input class="field" style="width:138px" type="date" name="f_date_fr" value="<?= h($_f_date_fr) ?>">
      </div>
      <div class="filter-group">
        <label>Tanggal s/d</label>
        <input class="field" style="width:138px" type="date" name="f_date_to" value="<?= h($_f_date_to) ?>">
      </div>
      <div class="filter-actions">
        <button class="btn primary" type="submit">Filter</button>
        <a class="btn" href="?">Reset</a>
      </div>
      <div class="result-count"><?= count($rows) ?> DO ditemukan</div>
    </form>
  </div>

  <?php
  $rows_active  = array_filter($rows, fn($x) => in_array($x['status'], ['ready_scm','on_delivery'], true));
  $rows_history = array_filter($rows, fn($x) => $x['status'] === 'delivered');
  ?>

  <!-- Active section header -->
  <div class="section-hd">
    <h2>Aktif</h2>
    <span class="count"><?= count($rows_active) ?></span>
    <span class="desc">READY SCM &amp; ON DELIVERY — perlu tindakan SCM</span>
  </div>

  <?php if (!$rows_active): ?>
    <div class="card">
      <div class="empty-state">
        <svg width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Tidak ada DO aktif untuk SCM
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($rows_active as $r):
    $mode  = strtoupper((string)($r['delivery_mode'] ?? 'INTERNAL'));
    if ($mode === '') $mode = 'INTERNAL';
    $vid   = (int)($r['delivery_vendor_id'] ?? 0);
    $vname = '';
    if ($vid > 0 && $vendorList) {
      foreach ($vendorList as $vv) { if ((int)$vv['id'] === $vid) { $vname = ($vv['vendors_name'] ?? $vv['vendors_code']); break; } }
    }
    $token = trim((string)($r['tracking_public_token'] ?? ''));
    $trackingPublicUrl = $trackingSvc->publicTrackingUrl($pdo, $token);
    $waShareUrl = build_wa_share_url((string)$r['do_code'], $trackingPublicUrl);
    $statusClass = $r['status'] === 'on_delivery' ? 'status-delivery' : 'status-ready';
    $cur_scm = $r['scm_status'] ?: 'Pending';
    $cp = strtoupper((string)($r['carrier_provider'] ?? 'BITESHIP'));
    if (!in_array($cp, ['BITESHIP'], true)) $cp = 'BITESHIP';
    $dm = strtoupper((string)($r['delivery_mode'] ?? 'INTERNAL'));
    if (!in_array($dm, ['INTERNAL','VENDOR'], true)) $dm = 'INTERNAL';
    $selVid = (int)($r['delivery_vendor_id'] ?? 0);
    $custName = ($r['customers_name'] ?? '') !== '' ? $r['customers_name'] : $r['customers_code'];
  ?>
  <div class="do-card <?= $statusClass ?>">

    <!-- Card top: summary info -->
    <div class="do-card-top">
      <div>
        <div class="do-meta-label">DO Code</div>
        <div class="do-meta-val"><?= h($r['do_code']) ?></div>
        <div class="do-meta-sub"><?= h($r['do_date']) ?> &nbsp;·&nbsp; <?= h($r['office_code']) ?></div>
        <a class="do-meta-link" href="<?= h($salesU) ?>/sales_do_view.php?id=<?= (int)$r['id'] ?>" target="_blank">Detail / Print ↗</a>
      </div>
      <div>
        <div class="do-meta-label">Customer</div>
        <div class="do-meta-val"><?= h($custName) ?></div>
        <div class="do-meta-sub"><?= h($r['customers_code'] ?? '') ?></div>
      </div>
      <div>
        <div class="do-meta-label">Grand Total</div>
        <div class="do-amount">Rp <?= h(number_format((float)($r['grand_total'] ?? 0), 0, ',', '.')) ?></div>
        <div class="do-meta-sub" style="margin-top:6px">
          <?php if ($mode === 'VENDOR'): ?>
            <span class="chip">🚚 <?= h($vname ?: 'Vendor #'.$vid) ?></span>
          <?php else: ?>
            <span class="chip">🏠 Internal SCM</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="do-status-col">
        <?= badgeStatus($r['status']) ?>
        <span class="badge gray"><?= h($cur_scm) ?></span>
        <?php if ($r['scm_note'] ?? ''): ?>
          <span style="font-size:11px;color:var(--muted2);"><?= h($r['scm_note']) ?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Card body: two columns -->
    <form method="post" enctype="multipart/form-data" class="scm-form">
      <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="scm_signature_data" class="sig-data" value="">

      <div class="do-card-body">

        <!-- Left column: status/note + tracking -->
        <div class="form-section">
          <div class="form-section-title">Status &amp; Catatan</div>
          <div class="field-grid-2">
            <div class="field-row">
              <label>Status SCM</label>
              <select class="field select" name="scm_status">
                <option value="Pending" <?= $cur_scm==='Pending'?'selected':'' ?>>Pending</option>
                <option value="Open"    <?= $cur_scm==='Open'   ?'selected':'' ?>>Open</option>
                <option value="Done"    <?= $cur_scm==='Done'   ?'selected':'' ?>>Done</option>
              </select>
            </div>
            <div class="field-row">
              <label>Catatan</label>
              <input class="field" name="scm_note" placeholder="Catatan…" value="<?= h($r['scm_note'] ?? '') ?>">
            </div>
          </div>

          <div class="form-section-title" style="margin-top:6px">Metode Pengiriman</div>
          <div class="delivery-row">
            <select class="field select delivery-mode" name="delivery_mode" style="flex:0 0 160px">
              <option value="INTERNAL" <?= $dm==='INTERNAL'?'selected':'' ?>>INTERNAL (Team SCM)</option>
              <option value="VENDOR"   <?= $dm==='VENDOR'  ?'selected':'' ?>>VENDOR (Jasa Logistik)</option>
            </select>
            <select class="field select delivery-vendor" name="delivery_vendor_id" style="flex:1">
              <option value="0">— Pilih Vendor —</option>
              <?php foreach ($vendorList as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= $selVid===(int)$v['id']?'selected':'' ?>>
                  <?= h(($v['vendors_name'] ?? '') . ' (' . ($v['vendors_code'] ?? '') . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-section-title" style="margin-top:6px">Tracking</div>
          <div class="track-box">
            <div class="field-grid-2">
              <div class="field-row">
                <label>Provider</label>
                <select class="field select" name="carrier_provider">
                  <option value="BITESHIP" <?= $cp==='BITESHIP'?'selected':'' ?>>BITESHIP</option>
                </select>
              </div>
              <div class="field-row">
                <label>No Resi / AWB</label>
                <input class="field" name="carrier_tracking_no" placeholder="No resi…" value="<?= h($r['carrier_tracking_no'] ?? '') ?>">
              </div>
            </div>
            <div class="field-grid-2">
              <div class="field-row">
                <label>Kode Kurir</label>
                <input class="field" name="carrier_courier_code" placeholder="jne, jnt, sicepat…" value="<?= h($r['carrier_courier_code'] ?? '') ?>">
              </div>
              <div class="field-row">
                <label>Fallback Live URL</label>
                <input class="field" name="fallback_live_location_url" placeholder="https://…" value="<?= h($r['fallback_live_location_url'] ?? '') ?>">
              </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:3px;padding-top:2px">
              <div class="track-info-row"><span class="lbl">Status terakhir</span><span class="val"><?= h($r['tracking_last_status'] ?? '-') ?></span></div>
              <div class="track-info-row"><span class="lbl">Last sync</span><span class="val"><?= h($r['tracking_last_sync_at'] ?? '-') ?></span></div>
              <div class="track-info-row"><span class="lbl">Live GPS</span><span class="val"><?= h(($r['scm_live_lat'] && $r['scm_live_lng']) ? ($r['scm_live_lat'].','.$r['scm_live_lng'].' @ '.($r['scm_live_at']??'-')) : '-') ?></span></div>
              <?php if ($trackingPublicUrl !== ''): ?>
              <div class="track-info-row"><span class="lbl">Public link</span><a href="<?= h($trackingPublicUrl) ?>" target="_blank">Buka ↗</a></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Right column: uploads + signature -->
        <div class="form-section">
          <div class="form-section-title">Bukti Terima dari WQS</div>
          <div class="proof-grid">
            <div class="proof-item">
              <div class="lbl">📷 Foto saat ini</div>
              <?php if ($r['scm_receive_photo']): ?>
                <a class="proof-link" href="<?= h($r['scm_receive_photo']) ?>" target="_blank">Lihat ↗</a>
              <?php else: ?><span style="font-size:11px;color:var(--muted)">Belum ada</span><?php endif; ?>
            </div>
            <div class="proof-item">
              <div class="lbl">🎥 Video saat ini</div>
              <?php if ($r['scm_receive_video']): ?>
                <a class="proof-link" href="<?= h($r['scm_receive_video']) ?>" target="_blank">Lihat ↗</a>
              <?php else: ?><span style="font-size:11px;color:var(--muted)">Belum ada</span><?php endif; ?>
            </div>
          </div>
          <div class="field-grid-2">
            <div class="field-row">
              <label>Upload foto baru</label>
              <input class="field" type="file" name="scm_receive_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="field-row">
              <label>Upload video baru</label>
              <input class="field" type="file" name="scm_receive_video" accept=".mp4,.mov,.m4v,.webm">
            </div>
          </div>

          <div class="form-section-title" style="margin-top:8px">Bukti Serah ke Customer (POD)</div>
          <div class="proof-grid">
            <div class="proof-item">
              <div class="lbl">📷 Foto saat ini</div>
              <?php if ($r['scm_delivery_photo']): ?>
                <a class="proof-link" href="<?= h($r['scm_delivery_photo']) ?>" target="_blank">Lihat ↗</a>
              <?php else: ?><span style="font-size:11px;color:var(--muted)">Belum ada</span><?php endif; ?>
            </div>
            <div class="proof-item">
              <div class="lbl">🎥 Video saat ini</div>
              <?php if ($r['scm_delivery_video']): ?>
                <a class="proof-link" href="<?= h($r['scm_delivery_video']) ?>" target="_blank">Lihat ↗</a>
              <?php else: ?><span style="font-size:11px;color:var(--muted)">Belum ada</span><?php endif; ?>
            </div>
          </div>
          <div class="field-grid-2">
            <div class="field-row">
              <label>Upload foto baru</label>
              <input class="field" type="file" name="scm_delivery_photo" accept=".jpg,.jpeg,.png,.webp,.pdf">
            </div>
            <div class="field-row">
              <label>Upload video baru</label>
              <input class="field" type="file" name="scm_delivery_video" accept=".mp4,.mov,.m4v,.webm">
            </div>
          </div>

          <div class="form-section-title" style="margin-top:8px">Tanda Tangan Digital Customer</div>
          <div class="sig-wrap">
            <div class="sig-label">
              <?php if ($r['scm_signature_data']): ?><span class="badge green" style="font-size:10px">Ada ✓</span>
              <?php else: ?><span class="badge gray" style="font-size:10px">Belum ada — wajib untuk DELIVERED</span><?php endif; ?>
            </div>
            <canvas class="sig" width="900" height="200"></canvas>
            <div class="sig-bar">
              <button type="button" class="btn sm" data-sig-clear>Bersihkan</button>
              <button type="button" class="btn sm primary" data-sig-save>Ambil TTD ✓</button>
            </div>
          </div>
        </div>

      </div><!-- /do-card-body -->

      <!-- Action bar -->
<div class="action-bar">
  <button class="btn primary" name="action" value="save" type="submit">💾 Simpan</button>
  <button class="btn" name="action" value="refresh_tracking" type="submit">↺ Refresh Tracking</button>
  <button class="btn gps-start-btn" data-do-id="<?= (int)$r['id'] ?>" type="button">📍 GPS ON</button>
  <button class="btn gps-stop-btn" data-do-id="<?= (int)$r['id'] ?>" type="button">⏹ GPS OFF</button>
  <span class="sep"></span>

  <?php if (($r['status'] ?? '') === 'ready_scm'): ?>
    <button class="btn warn" name="action" value="on_delivery" type="submit">🚚 Set ON DELIVERY</button>
  <?php endif; ?>

  <?php if (($r['status'] ?? '') === 'on_delivery'): ?>
    <button class="btn success" name="action" value="delivered" type="submit">✅ Set DELIVERED</button>
  <?php endif; ?>

  <a class="btn" href="<?= h($waShareUrl) ?>" target="_blank" rel="noopener">💬 Share WA</a>
</div>

    </form>
  </div><!-- /do-card -->
  <?php endforeach; ?>

  <!-- History section -->
  <div class="section-divider" style="margin-top:20px"></div>
  <div class="section-hd">
    <h2>History</h2>
    <span class="count"><?= count($rows_history) ?></span>
    <span class="desc badge blue" style="font-size:10px">DELIVERED</span>
    <span class="desc">Selesai di SCM · menunggu proses ACT</span>
  </div>

  <div class="card">
    <?php if (!$rows_history): ?>
      <div class="empty-state">Belum ada DO yang berstatus DELIVERED.</div>
    <?php else: ?>
    <div class="hist-table-wrap">
      <table class="hist-table">
        <thead>
          <tr>
            <th>DO Code</th>
            <th>Tanggal</th>
            <th>Customer</th>
            <th>Office</th>
            <th>Grand Total</th>
            <th>Delivery</th>
            <th>Bukti</th>
            <th>Tracking</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows_history as $r):
            $mode2  = strtoupper((string)($r['delivery_mode'] ?? 'INTERNAL'));
            $vid2   = (int)($r['delivery_vendor_id'] ?? 0);
            $vname2 = '';
            if ($vid2 > 0 && $vendorList) {
              foreach ($vendorList as $vv) { if ((int)$vv['id'] === $vid2) { $vname2 = ($vv['vendors_name'] ?? $vv['vendors_code']); break; } }
            }
          ?>
          <tr>
            <td>
              <div class="bold"><?= h($r['do_code']) ?></div>
              <div style="font-size:11px;color:var(--muted);margin-top:1px"><?= h($r['tracking_code'] ?? '') ?></div>
              <a style="font-size:11px" href="<?= h($salesU) ?>/sales_do_view.php?id=<?= (int)$r['id'] ?>" target="_blank">Detail ↗</a>
            </td>
            <td><?= h($r['do_date']) ?></td>
            <td>
              <div class="bold"><?= h(($r['customers_name'] ?? '') !== '' ? $r['customers_name'] : $r['customers_code']) ?></div>
              <div style="font-size:11px"><?= h($r['customers_code'] ?? '') ?></div>
            </td>
            <td><?= h($r['office_code']) ?></td>
            <td class="bold">Rp <?= h(number_format((float)($r['grand_total'] ?? 0), 0, ',', '.')) ?></td>
            <td>
              <span class="badge <?= $mode2==='VENDOR' ? 'yellow':'green' ?>"><?= h($mode2) ?></span>
              <?php if ($mode2 === 'VENDOR'): ?><div style="font-size:11px;margin-top:3px"><?= h($vname2 ?: '#'.$vid2) ?></div><?php endif; ?>
            </td>
            <td>
              <div style="display:flex;flex-direction:column;gap:3px">
                <span>Foto: <?= $r['scm_receive_photo'] ? '<a class="proof-link" href="'.h($r['scm_receive_photo']).'" target="_blank">terima ↗</a>' : '<span style="color:var(--muted)">-</span>' ?></span>
                <span>POD: <?= $r['scm_delivery_photo'] ? '<a class="proof-link" href="'.h($r['scm_delivery_photo']).'" target="_blank">lihat ↗</a>' : '<span style="color:var(--muted)">-</span>' ?></span>
                <span>TTD: <?= $r['scm_signature_data'] ? '<span class="badge green" style="font-size:10px">Ada</span>' : '<span style="color:var(--muted)">-</span>' ?></span>
              </div>
            </td>
            <td>
              <div style="font-size:11px;color:var(--muted2)"><?= h($r['tracking_last_status'] ?: '-') ?></div>
              <div style="font-size:10px;color:var(--muted)">sync: <?= h($r['tracking_last_sync_at'] ?: '-') ?></div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Audit log -->
  <div class="section-divider" style="margin-top:20px"></div>
  <div class="section-hd">
    <h2>Audit Log</h2>
    <span class="count"><?= count($audit_rows) ?></span>
    <span class="desc">50 event terbaru</span>
  </div>
  <div class="card">
    <?php if (empty($audit_rows)): ?>
      <div class="empty-state">Belum ada audit log.</div>
    <?php else: ?>
    <div class="hist-table-wrap">
      <table class="audit-table">
        <thead><tr><th>Waktu</th><th>Action</th><th>DO Code</th><th>User</th><th>Keterangan</th></tr></thead>
        <tbody>
        <?php foreach ($audit_rows as $a): ?>
          <tr>
            <td><?= h($a['created_at'] ?? '') ?></td>
            <td><code><?= h($a['action'] ?? '') ?></code></td>
            <td><?= h($a['record_code'] ?? '') ?></td>
            <td><?= h($a['username'] ?? '') ?></td>
            <td><?= h($a['description'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div style="font-size:11px;color:var(--muted);margin:16px 0 8px;padding:0 2px">
    Upload tersimpan ke <code>/uploads/sales_scm/</code> &nbsp;·&nbsp; Status <strong>DELIVERED</strong> memicu halaman ACT.
  </div>

</div><!-- /scm-wrap -->

<script>
(function(){
  var GPS_API = "<?php echo h(rtrim($baseProject, '/')); ?>/api/v1/internal/sales_scm_geo_ping.php";
  var CSRF_TOKEN = "<?php echo h(csrf_token()); ?>";
  var gpsWatchId = null;
  var gpsActiveDoId = 0;

  function sendGps(doId, lat, lng, acc){
    var fd = new FormData();
    fd.append('csrf_token', CSRF_TOKEN);
    fd.append('do_id', String(doId));
    fd.append('lat', String(lat));
    fd.append('lng', String(lng));
    fd.append('accuracy_m', String(acc || ''));
    return fetch(GPS_API, {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); });
  }

  function stopGps(){
    if (gpsWatchId !== null && navigator.geolocation) {
      navigator.geolocation.clearWatch(gpsWatchId);
    }
    gpsWatchId = null;
    gpsActiveDoId = 0;
  }

  function startGps(doId){
    if (!navigator.geolocation) {
      alert('Browser tidak support geolocation.');
      return;
    }
    stopGps();
    gpsActiveDoId = doId;
    gpsWatchId = navigator.geolocation.watchPosition(function(pos){
      if (!gpsActiveDoId) return;
      sendGps(gpsActiveDoId, pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy)
        .then(function(){ /* silent */ })
        .catch(function(){ /* silent */ });
    }, function(err){
      alert('Gagal akses GPS: ' + (err && err.message ? err.message : 'unknown'));
      stopGps();
    }, {
      enableHighAccuracy: true,
      timeout: 15000,
      maximumAge: 5000
    });
  }

  function setupCanvas(form){
    var canvas = form.querySelector('canvas.sig');
    var out = form.querySelector('input.sig-data');
    var btnSave = form.querySelector('[data-sig-save]');
    var btnClear = form.querySelector('[data-sig-clear]');
    if (!canvas || !out || !btnSave || !btnClear) return;

    var ctx = canvas.getContext('2d');
    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#e5e7eb';

    var drawing = false;
    function pos(e){
      var r = canvas.getBoundingClientRect();
      var x = (e.touches?e.touches[0].clientX:e.clientX) - r.left;
      var y = (e.touches?e.touches[0].clientY:e.clientY) - r.top;
      return {x:x*(canvas.width/r.width), y:y*(canvas.height/r.height)};
    }
    function start(e){ drawing=true; var p=pos(e); ctx.beginPath(); ctx.moveTo(p.x,p.y); e.preventDefault(); }
    function move(e){ if(!drawing) return; var p=pos(e); ctx.lineTo(p.x,p.y); ctx.stroke(); e.preventDefault(); }
    function end(e){ drawing=false; e.preventDefault(); }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);

    canvas.addEventListener('touchstart', start, {passive:false});
    canvas.addEventListener('touchmove', move, {passive:false});
    window.addEventListener('touchend', end, {passive:false});

    btnClear.addEventListener('click', function(){
      ctx.clearRect(0,0,canvas.width,canvas.height);
      out.value = '';
    });

    btnSave.addEventListener('click', function(){
      out.value = canvas.toDataURL('image/png');
      btnSave.textContent = 'TTD tersimpan ✅';
      setTimeout(function(){ btnSave.textContent='Ambil TTD'; }, 1200);
    });
  }

  // Delivery mode toggle (internal vs vendor)
  function setupDeliveryToggle(form){
    var modeSel = form.querySelector('select.delivery-mode');
    var vendorSel = form.querySelector('select.delivery-vendor');
    if(!modeSel || !vendorSel) return;
    function apply(){
      var v = (modeSel.value||'INTERNAL').toUpperCase();
      if(v !== 'VENDOR'){
        vendorSel.value = '0';
        vendorSel.setAttribute('disabled','disabled');
        vendorSel.style.opacity = '0.6';
      } else {
        vendorSel.removeAttribute('disabled');
        vendorSel.style.opacity = '1';
      }
    }
    modeSel.addEventListener('change', apply);
    apply();
  }

  document.querySelectorAll('form.scm-form').forEach(function(f){ setupCanvas(f); setupDeliveryToggle(f); });

  document.querySelectorAll('.gps-start-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      var doId = parseInt(btn.getAttribute('data-do-id') || '0', 10);
      if (!doId) return;
      startGps(doId);
      btn.textContent = 'Auto GPS ON';
      setTimeout(function(){ btn.textContent='Start Auto GPS'; }, 1800);
    });
  });
  document.querySelectorAll('.gps-stop-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
      stopGps();
      btn.textContent = 'Auto GPS OFF';
      setTimeout(function(){ btn.textContent='Stop Auto GPS'; }, 1800);
    });
  });
  window.addEventListener('beforeunload', function(){ stopGps(); });
})();
</script>
<?php rmi_footer(); ?>
