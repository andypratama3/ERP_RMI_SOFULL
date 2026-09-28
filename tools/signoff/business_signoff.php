<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools_remote_check.php';
require_once __DIR__ . '/../../_shared/helpers.php';
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../../tools/tools_ui_helpers.php';
require_once __DIR__ . '/../../tools/tools_state_lib.php';
require_once __DIR__ . '/../../tools/tools_access_helpers.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';
require_once __DIR__ . '/../../_shared/db.php';
require_once __DIR__ . '/../../master/_audit_master.php';

tools_require_access('signoff/business_signoff.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);

$msg = '';
$msgType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    } elseif (function_exists('rmi_csrf_validate')) {
        rmi_csrf_validate((string)($_POST['csrf_token'] ?? ''));
    } else {
        http_response_code(403);
        exit('CSRF validator missing');
    }

    $action = trim((string)($_POST['action'] ?? 'submit'));
    if ($action === 'revoke') {
        $role = (string)($_SESSION['role'] ?? $_SESSION['level'] ?? '');
        if (!in_array($role, ['SUPERADMIN', 'SYS'], true)) {
            $msg = 'Revoke hanya untuk SUPERADMIN.';
            $msgType = 'danger';
        } elseif (!ts_write_json(business_signoff_state_path(), [
            'state_version' => 1,
            'signed' => false,
            'signed_at' => null,
            'approver_name' => '',
            'department_role' => '',
            'evidence_file' => '',
            'sha256' => '',
            'request_id' => '',
            'app_env' => strtolower((string)(getenv('APP_ENV') ?: 'local')),
            'notes' => '',
            'revoked_at' => date(DateTimeInterface::ATOM),
            'revoked_by' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
        ])) {
            $msg = 'Gagal revoke sign-off.';
            $msgType = 'danger';
        } else {
            try {
                $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
                if (function_exists('master_audit')) {
                    master_audit($pdo, 'tools_signoff', 'business_signoff', 'BUSINESS_SIGNOFF_REVOKED', null, '', 'Business sign-off revoked', [
                        'revoked_by' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
                    ]);
                }
            } catch (Throwable $e) {
                // audit optional
            }
            ts_append_run_history('business_signoff_revoke', 'OK', [
                'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
                'source' => 'tools/signoff/business_signoff.php',
            ]);
            $msg = 'Sign-off revoked.';
            $msgType = 'success';
        }
    } else {
    $name = trim((string)($_POST['business_approver_name'] ?? ''));
    $dept = trim((string)($_POST['department_role'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if ($name === '') {
        $msg = 'Nama approver wajib diisi.';
        $msgType = 'danger';
    } elseif (!isset($_FILES['signoff_file']) || !is_array($_FILES['signoff_file'])) {
        $msg = 'File sign-off wajib diupload.';
        $msgType = 'danger';
    } else {
        $f = $_FILES['signoff_file'];
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            $msg = 'Upload gagal. Kode error: ' . $err;
            $msgType = 'danger';
        } else {
            $tmp = (string)($f['tmp_name'] ?? '');
            $size = (int)($f['size'] ?? 0);
            $orig = (string)($f['name'] ?? 'signoff.bin');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowedExt = ['pdf', 'png', 'jpg', 'jpeg'];
            if ($size <= 0 || $size > (10 * 1024 * 1024)) {
                $msg = 'Ukuran file harus 1 byte - 10MB.';
                $msgType = 'danger';
            } elseif (!in_array($ext, $allowedExt, true)) {
                $msg = 'Ekstensi file tidak diizinkan.';
                $msgType = 'danger';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = strtolower((string)$finfo->file($tmp));
                $allowedMime = ['application/pdf', 'image/png', 'image/jpeg'];
                if (!in_array($mime, $allowedMime, true)) {
                    $msg = 'MIME file tidak valid.';
                    $msgType = 'danger';
                } else {
                    $signoffDir = ts_root() . '/storage/uploads/business_signoff';
                    if (!is_dir($signoffDir)) {
                        @mkdir($signoffDir, 0775, true);
                    }
                    $random = bin2hex(random_bytes(8));
                    $filename = 'business_signoff_' . date('Ymd_His') . '_' . $random . '.' . $ext;
                    $dest = $signoffDir . '/' . $filename;
                    if (!@move_uploaded_file($tmp, $dest)) {
                        $msg = 'Gagal menyimpan file sign-off.';
                        $msgType = 'danger';
                    } else {
                        $sha = (string)hash_file('sha256', $dest);
                        $requestId = bin2hex(random_bytes(8));
                        $appEnv = strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'local')));
                        $baseUrl = trim((string)(getenv('APP_URL') ?: ($_ENV['APP_URL'] ?? '')));
                        $evidenceMasked = ts_mask($dest);
                        $statePayload = [
                            'state_version' => 1,
                            'signed' => true,
                            'signed_at' => date(DateTimeInterface::ATOM),
                            'approver_name' => $name,
                            'department_role' => $dept,
                            'evidence_file' => $evidenceMasked,
                            'sha256' => $sha,
                            'request_id' => $requestId,
                            'app_env' => $appEnv,
                            'base_url' => $baseUrl !== '' ? $baseUrl : null,
                            'notes' => tools_mask_sensitive($notes),
                            'uploaded_by' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
                        ];
                        if (!ts_write_json(business_signoff_state_path(), $statePayload)) {
                            @unlink($dest);
                            $msg = 'Gagal menyimpan state sign-off.';
                            $msgType = 'danger';
                        } else {
                            try {
                                $pdo = function_exists('rmi_db_pdo') ? rmi_db_pdo() : db_pdo();
                                if (function_exists('master_audit')) {
                                    master_audit($pdo, 'tools_signoff', 'business_signoff', 'BUSINESS_SIGNOFF_SUBMITTED', null, $requestId, 'Business sign-off submitted', [
                                        'approver_name' => $name,
                                        'department_role' => $dept,
                                        'sha256' => $sha,
                                        'file' => $evidenceMasked,
                                        'request_id' => $requestId,
                                    ]);
                                }
                            } catch (Throwable $e) {
                                // audit optional, don't fail submit
                            }
                            ts_append_run_history('business_signoff_submit', 'OK', [
                                'actor_username' => function_exists('current_actor_username') ? current_actor_username() : 'SYSTEM',
                                'source' => 'tools/signoff/business_signoff.php',
                                'approver_name' => $name,
                                'request_id' => $requestId,
                            ]);
                            $msg = 'Sign-off recorded';
                            $msgType = 'success';
                        }
                    }
                }
            }
        }
    }
    }
}

$state = business_signoff_read_state();
$signoff = is_array($state['data'] ?? null) ? (array)$state['data'] : [];
$baseProject = rmi_layout_base_project();
rmi_header('Business Sign-off', [
    'active' => 'tools',
    'subtitle' => 'Capture formal business UAT sign-off',
    'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Business Sign-off'],
]);
?>
<div class="row g-3">
  <?php if ($msg !== ''): ?><div class="col-12"><div class="alert alert-<?= rmi_h($msgType) ?> mb-0 py-2"><?= rmi_h(tools_mask_sensitive($msg)) ?></div></div><?php endif; ?>
  <div class="col-lg-7">
    <div class="rmi-card p-3">
      <div class="fw-semibold mb-2">Submit Business Sign-off</div>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= rmi_h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <div class="mb-2">
          <label class="form-label">Business Approver Name</label>
          <input class="form-control" type="text" name="business_approver_name" required maxlength="120">
        </div>
        <div class="mb-2">
          <label class="form-label">Department/Role</label>
          <input class="form-control" type="text" name="department_role" maxlength="120">
        </div>
        <div class="mb-2">
          <label class="form-label">Notes</label>
          <textarea class="form-control" rows="3" name="notes" placeholder="Notes (will be masked on state output)"></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label">Upload Signed Evidence (PDF/PNG/JPG, max 10MB)</label>
          <input class="form-control" type="file" name="signoff_file" accept=".pdf,.png,.jpg,.jpeg" required>
        </div>
        <button class="btn btn-rmi btn-sm" type="submit">Submit Sign-off</button>
      </form>
      <?php
      $role = (string)($_SESSION['role'] ?? $_SESSION['level'] ?? '');
      if ($state['ok'] && !empty($signoff['signed']) && in_array($role, ['SUPERADMIN', 'SYS'], true)):
      ?>
      <hr class="my-3">
      <form method="post" onsubmit="return confirm('Yakin revoke sign-off?');">
        <input type="hidden" name="csrf_token" value="<?= rmi_h((string)(function_exists('csrf_token') ? csrf_token() : '')) ?>">
        <input type="hidden" name="action" value="revoke">
        <button class="btn btn-outline-danger btn-sm" type="submit">Revoke / Reset sign-off</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="rmi-card p-3 h-100">
      <div class="fw-semibold mb-2">Latest Business Sign-off State</div>
      <?php if (!$state['ok']): ?>
        <div class="small"><?= tools_badge('ATTENTION') ?> Belum ada sign-off valid.</div>
      <?php else: ?>
        <div class="small mb-1">Signed: <?= tools_badge(!empty($signoff['signed']) ? 'HEALTHY' : 'ATTENTION', !empty($signoff['signed']) ? 'YES' : 'NO') ?></div>
        <div class="small mb-1">Signed at: <b><?= rmi_h(tools_fmt_ts((string)($signoff['signed_at'] ?? ''))) ?></b></div>
        <div class="small mb-1">Approver: <b><?= rmi_h((string)($signoff['approver_name'] ?? '-')) ?></b></div>
        <div class="small mb-1">Role: <?= rmi_h((string)($signoff['department_role'] ?? '-')) ?></div>
        <div class="small mb-1">File: <code><?= rmi_h((string)($signoff['evidence_file'] ?? $signoff['file_path_masked'] ?? '-')) ?></code></div>
        <div class="small">SHA256: <code><?= rmi_h((string)($signoff['sha256'] ?? '-')) ?></code></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php rmi_footer(); ?>
