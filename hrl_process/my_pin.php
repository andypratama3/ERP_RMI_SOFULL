<?php
require_once __DIR__ . '/_inc/bootstrap.php';
require_login(); // explicit guard + static scan marker
if (!hrlp_can_enter_module()) {
    http_response_code(403);
    flash_set('Tidak punya akses ke modul HRL Process.', 'danger');
    rmi_redirect(um('../master/login.php'));
}

$page_title = 'PIN TTD (Approval)';
require_once __DIR__ . '/_layout_top.php';

$stmt = $pdo->prepare("SELECT updated_at FROM hrl_user_pins WHERE username=? LIMIT 1");
$stmt->execute([me_username()]);
$pinUpdated = $stmt->fetchColumn();

if (($_POST['action'] ?? '') === 'set_pin') {
    csrf_check();
    try {
        $currentPw = (string)($_POST['current_password'] ?? '');
        $pin1 = trim((string)($_POST['pin1'] ?? ''));
        $pin2 = trim((string)($_POST['pin2'] ?? ''));

        if ($currentPw === '') throw new RuntimeException('Password wajib diisi untuk set PIN.');
        if (!hrlp_verify_password($pdo, me_username(), $currentPw)) throw new RuntimeException('Password salah.');
        if ($pin1 === '' || $pin2 === '') throw new RuntimeException('PIN wajib diisi.');
        if ($pin1 !== $pin2) throw new RuntimeException('PIN konfirmasi tidak sama.');
        if (!preg_match('/^\d{4,10}$/', $pin1)) throw new RuntimeException('PIN harus 4–10 digit angka.');

        $hash = password_hash($pin1, PASSWORD_DEFAULT);

        $pdo->prepare("INSERT INTO hrl_user_pins (username, pin_hash, updated_at) VALUES (?,?,NOW())
                       ON DUPLICATE KEY UPDATE pin_hash=VALUES(pin_hash), updated_at=NOW()")
            ->execute([me_username(), $hash]);

        hrlp_audit('SET_PIN', ['username'=>me_username()]);
        flash_set('PIN berhasil disimpan. Sekarang kamu bisa approve pakai PIN.', 'success');
        rmi_redirect(um('my_pin.php'));
    } catch (Throwable $e) {
        flash_set('Gagal set PIN: ' . $e->getMessage(), 'danger');
        rmi_redirect(um('my_pin.php'));
    }
}

?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card rmi-card">
      <div class="card-header">PIN untuk TTD Digital</div>
      <div class="card-body">
        <p class="help">
          PIN ini dipakai untuk konfirmasi approve/reject (TTD digital) sebagai alternatif dari re-enter password.
          Disarankan set PIN 4–10 digit dan jangan dibagikan.
        </p>

        <div class="mb-3">
          <div class="muted">Status PIN</div>
          <?php if ($pinUpdated): ?>
            <div class="badge-pill">SUDAH DISET • updated <?= h($pinUpdated) ?></div>
          <?php else: ?>
            <div class="badge-pill">BELUM DISET</div>
          <?php endif; ?>
        </div>

        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="action" value="set_pin">

          <div class="mb-2">
            <label class="form-label">Konfirmasi Password (wajib)</label>
            <input class="form-control" type="password" name="current_password" placeholder="password login">
          </div>

          <div class="row g-2 mb-2">
            <div class="col">
              <label class="form-label">PIN Baru</label>
              <input class="form-control" name="pin1" inputmode="numeric" placeholder="4-10 digit">
            </div>
            <div class="col">
              <label class="form-label">Ulangi PIN</label>
              <input class="form-control" name="pin2" inputmode="numeric" placeholder="ulang">
            </div>
          </div>

          <div class="d-grid">
            <button class="btn btn-soft">Simpan PIN</button>
          </div>
        </form>

      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card rmi-card">
      <div class="card-header">Catatan</div>
      <div class="card-body">
        <ul class="help mb-0">
          <li>PIN hanya dipakai untuk <b>TTD digital</b> di modul HRL Process.</li>
          <li>Kalau lupa PIN, cukup set ulang (butuh password).</li>
          <li>Password login tetap yang utama untuk akses sistem.</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/_layout_bottom.php'; ?>
