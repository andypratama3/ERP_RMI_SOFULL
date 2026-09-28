<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../_shared/app_init.php';

$pending = $_SESSION['_mfa_pending'] ?? null;
if (!is_array($pending) || empty($pending['user'])) {
    rmi_redirect('login.php');
}

$pdo = db_pdo();
require_once __DIR__ . '/schema_mfa.php';
schema_ensure_mfa_columns($pdo);
$next = safe_next($_GET['next'] ?? ($pending['next'] ?? ''), $fallback_home ?? 'index.php');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) { verify_csrf(); }
    $code = trim((string)($_POST['code'] ?? ''));
    if ($code === '') {
        $error = 'Kode OTP wajib diisi.';
    } else {
        try {
            $user = $pending['user'];
            $st = $pdo->prepare("SELECT mfa_secret, mfa_backup_codes_hash FROM master_system_login WHERE id=? LIMIT 1");
            $st->execute([(int)$user['id']]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $secret = (string)($row['mfa_secret'] ?? '');
            $backupRaw = (string)($row['mfa_backup_codes_hash'] ?? '[]');

            $totp = new \App\Security\TotpService();
            $valid = $secret !== '' && $totp->verifyCode($secret, $code);
            if (!$valid && preg_match('/^[A-Z0-9\-]{6,20}$/', strtoupper($code))) {
                $backup = json_decode($backupRaw, true);
                if (is_array($backup)) {
                    foreach ($backup as $i => $hash) {
                        if (is_string($hash) && password_verify($code, $hash)) {
                            $valid = true;
                            unset($backup[$i]);
                            $pdo->prepare("UPDATE master_system_login SET mfa_backup_codes_hash=?, updated_at=NOW() WHERE id=?")
                                ->execute([json_encode(array_values($backup)), (int)$user['id']]);
                            break;
                        }
                    }
                }
            }

            if (!$valid) {
                $error = 'Kode OTP tidak valid.';
            } else {
                if (function_exists('auth_session_mark_login_complete')) {
                    auth_session_mark_login_complete();
                } elseif (session_status() === PHP_SESSION_ACTIVE) {
                    session_regenerate_id(true);
                }
                $_SESSION['user_id'] = (int)($user['id'] ?? 0);
                $_SESSION['username'] = (string)($user['username'] ?? '');
                $_SESSION['full_name'] = (string)($user['full_name'] ?? '');
                $_SESSION['role'] = strtoupper((string)($user['role'] ?? 'STAFF'));
                $_SESSION['level'] = strtoupper((string)($user['level'] ?? ($user['role'] ?? 'STAFF')));
                $_SESSION['department'] = strtoupper((string)($user['department'] ?? ''));
                $_SESSION['office_code'] = strtoupper((string)($user['office_code'] ?? ''));
                $_SESSION['user'] = [
                    'id' => (int)($user['id'] ?? 0),
                    'username' => (string)($user['username'] ?? ''),
                    'full_name' => (string)($user['full_name'] ?? ''),
                    'role' => $_SESSION['role'],
                    'level' => $_SESSION['level'],
                    'department' => $_SESSION['department'],
                    'office_code' => $_SESSION['office_code'],
                    'src' => (string)($pending['src'] ?? 'master_system_login'),
                ];
                unset($_SESSION['_mfa_pending']);
                $pdo->prepare("UPDATE master_system_login SET last_login_at=NOW(), updated_at=NOW() WHERE id=?")
                    ->execute([(int)$user['id']]);

                // Landing: sama seperti login.php (Nav Manager + default per dept)
                $target = $next;
                if ($target === ($fallback_home ?? '')) {
                    $bp = (isset($BASE_PROJECT) ? $BASE_PROJECT : '') ?: '';
                    $path = function_exists('auth_post_login_landing_path')
                        ? auth_post_login_landing_path((string)($user['department'] ?? ''))
                        : auth_landing_path_for_dept((string)($user['department'] ?? ''));
                    $target = $bp . $path;
                }

                rmi_redirect($target);
            }
        } catch (Throwable $e) {
            $error = 'Verifikasi MFA gagal.';
        }
    }
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('MFA Verification', [
    'active' => 'master',
    'subtitle' => 'Two-factor authentication',
]);
?>
<div class="rmi-container" style="max-width:460px">
  <div class="card"><div class="card-body">
    <h5 class="mb-3">Masukkan kode MFA</h5>
    <?php if ($error): ?><div class="alert alert-danger"><?= function_exists('rmi_h') ? rmi_h($error) : htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>"><?php endif; ?>
      <div class="mb-3">
        <label class="form-label">Kode OTP / Backup Code</label>
        <input class="form-control" type="text" name="code" autocomplete="one-time-code" required>
      </div>
      <button class="btn btn-primary" type="submit">Verify</button>
      <a class="btn btn-outline-secondary" href="login.php">Kembali Login</a>
    </form>
  </div></div>
</div>
<?php rmi_footer(); ?>
