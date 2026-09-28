<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_login();

$pdo = db_pdo();
require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/schema_mfa.php';
schema_ensure_mfa_columns($pdo);
$uid = (int)($_SESSION['user_id'] ?? 0);
$username = (string)($_SESSION['username'] ?? '');
$flash = null;
$backupCodesPlain = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'start_enroll') {
            $totp = new \App\Security\TotpService();
            $_SESSION['_mfa_enroll_secret'] = $totp->generateSecret();
            $flash = ['type' => 'info', 'msg' => 'Secret MFA dibuat. Verifikasi kode untuk aktivasi.'];
        } elseif ($action === 'confirm_enroll') {
            $secret = (string)($_SESSION['_mfa_enroll_secret'] ?? '');
            $otp = trim((string)($_POST['otp'] ?? ''));
            $totp = new \App\Security\TotpService();
            if ($secret === '' || !$totp->verifyCode($secret, $otp)) {
                $flash = ['type' => 'danger', 'msg' => 'OTP tidak valid.'];
            } else {
                for ($i = 0; $i < 8; $i++) {
                    $backupCodesPlain[] = strtoupper(bin2hex(random_bytes(3)));
                }
                $backupHash = array_map(static fn(string $c): string => password_hash($c, PASSWORD_DEFAULT), $backupCodesPlain);
                $pdo->prepare(
                    "UPDATE master_system_login
                     SET mfa_enabled=1, mfa_secret=?, mfa_confirmed_at=NOW(), mfa_backup_codes_hash=?, updated_at=NOW()
                     WHERE id=?"
                )->execute([$secret, json_encode($backupHash), $uid]);
                unset($_SESSION['_mfa_enroll_secret']);
                if (function_exists('master_audit')) {
                    master_audit(
                        $pdo,
                        'mfa_settings',
                        'master_system_login',
                        'MFA_ENABLED',
                        $uid,
                        $username,
                        'User enabled TOTP MFA',
                        ['user_id' => $uid]
                    );
                }
                $flash = ['type' => 'success', 'msg' => 'MFA aktif. Simpan backup code Anda.'];
            }
        } elseif ($action === 'disable_mfa') {
            $pdo->prepare(
                "UPDATE master_system_login
                 SET mfa_enabled=0, mfa_secret=NULL, mfa_confirmed_at=NULL, mfa_backup_codes_hash='[]', updated_at=NOW()
                 WHERE id=?"
            )->execute([$uid]);
            unset($_SESSION['_mfa_enroll_secret']);
            if (function_exists('master_audit')) {
                master_audit(
                    $pdo,
                    'mfa_settings',
                    'master_system_login',
                    'MFA_DISABLED',
                    $uid,
                    $username,
                    'User disabled MFA',
                    ['user_id' => $uid]
                );
            }
            $flash = ['type' => 'warning', 'msg' => 'MFA dinonaktifkan.'];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'danger', 'msg' => 'Gagal memproses MFA setting.'];
    }
}

$st = $pdo->prepare("SELECT mfa_enabled, mfa_secret, mfa_confirmed_at FROM master_system_login WHERE id=? LIMIT 1");
$st->execute([$uid]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$mfaEnabled = ((int)($row['mfa_enabled'] ?? 0) === 1);
$secret = (string)($_SESSION['_mfa_enroll_secret'] ?? '');
$uri = '';
if ($secret !== '') {
    $totp = new \App\Security\TotpService();
    $uri = $totp->getOtpAuthUri('ERP_RMI_SOFULL', $username, $secret);
}

require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('MFA Settings', [
    'active' => 'master',
    'subtitle' => 'Security - TOTP',
]);
?>
<div class="rmi-container" style="max-width:760px">
  <div class="card"><div class="card-body">
    <h5 class="mb-3">Multi-Factor Authentication (TOTP)</h5>
    <?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?><?php if ($flash['type'] === 'danger'): ?> <br><small class="mt-1 d-block">Tips: pastikan waktu HP & server sinkron; masukkan OTP dalam 30 detik; jangan Generate Secret ulang setelah scan QR.</small><?php endif; ?></div><?php endif; ?>
    <p class="text-muted">Status saat ini: <b><?= $mfaEnabled ? 'Enabled' : 'Disabled' ?></b><?php if ($secret !== '' && !$mfaEnabled): ?> <span class="text-info">(sedang proses aktivasi — masukkan OTP lalu klik Confirm & Enable MFA)</span><?php endif; ?></p>

    <?php if (!$mfaEnabled): ?>
      <form method="post" class="mb-3">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="start_enroll">
        <button class="btn btn-primary" type="submit">Generate Secret</button>
      </form>
    <?php endif; ?>

    <?php if ($secret !== ''): ?>
      <div class="alert alert-info">
        <div class="row g-3">
          <div class="col-md-4">
            <div class="mb-2"><b>Scan QR Code</b></div>
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($uri) ?>" alt="QR Code MFA" width="200" height="200" class="qr-mfa">
            <div class="small text-muted mt-1">Buka aplikasi authenticator (Google Authenticator, dll.) lalu scan QR</div>
          </div>
          <div class="col-md-8">
            <div class="mb-2"><b>Atau masukkan manual:</b></div>
            <div><b>Secret:</b> <code><?= h($secret) ?></code></div>
            <div class="mt-2"><b>OTP URI:</b> <code class="small"><?= h($uri) ?></code></div>
          </div>
        </div>
      </div>
      <form method="post" class="row g-2 mb-3">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="confirm_enroll">
        <div class="col-md-6">
          <label class="form-label">Masukkan OTP dari authenticator</label>
          <input class="form-control" type="text" name="otp" required>
        </div>
        <div class="col-md-6 d-flex align-items-end">
          <button class="btn btn-success" type="submit">Confirm & Enable MFA</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($mfaEnabled): ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="disable_mfa">
        <button class="btn btn-outline-danger" type="submit" onclick="return confirm('Disable MFA?')">Disable MFA</button>
      </form>
    <?php endif; ?>

    <?php if ($backupCodesPlain): ?>
      <div class="alert alert-warning mt-3">
        <b>Backup Codes (simpan sekali ini):</b><br>
        <?= h(implode(', ', $backupCodesPlain)) ?>
      </div>
    <?php endif; ?>
  </div></div>
</div>
<?php rmi_footer(); ?>
