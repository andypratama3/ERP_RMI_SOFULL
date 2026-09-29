<?php
declare(strict_types=1);

/**
 * Reset / nonaktifkan MFA untuk user lain — hanya privileged (SYS / ADMIN / SUPERADMIN di sesi).
 * Audit: system_audit_logs + erp_audit_log.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_login();

if (!function_exists('auth_is_sys_tier') || !auth_is_sys_tier()) {
    http_response_code(403);
    require_once __DIR__ . '/../_shared/rmi_layout.php';
    $bp = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';
    rmi_header('Akses ditolak', [
        'active' => 'master',
        'breadcrumbs' => [
            ['label' => 'Master', 'url' => $bp . '/master/index.php'],
            'Akses ditolak',
        ],
    ]);
    echo '<div class="rmi-container"><p>Hanya akun bertingkat <strong>SYS</strong> atau <strong>Admin</strong> (privileged) yang dapat mereset MFA user lain.</p><p class="rmi-muted small">Gate: <code>auth_is_sys_tier()</code> — bukan lewat ITC biasa.</p></div>';
    rmi_footer();
    exit;
}

require_once __DIR__ . '/schema_mfa.php';
require_once __DIR__ . '/_audit_master.php';
require_once __DIR__ . '/../_shared/erp_audit.php';

$pdo = db_pdo();
schema_ensure_mfa_columns($pdo);
erp_audit_ensure($pdo);

$flash = ['type' => '', 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    verify_csrf();
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'clear_mfa') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $confirmUser = trim((string)($_POST['confirm_username'] ?? ''));
        if ($targetId <= 0) {
            $flash = ['type' => 'danger', 'msg' => 'Pilih user tujuan.'];
        } elseif (mb_strlen($reason) < 10) {
            $flash = ['type' => 'danger', 'msg' => 'Alasan wajib diisi (minimal 10 karakter) untuk jejak audit.'];
        } else {
            try {
                $st = $pdo->prepare(
                    "SELECT id, username, full_name, mfa_enabled, mfa_confirmed_at, status, deleted_at
                     FROM master_system_login WHERE id = ? LIMIT 1"
                );
                $st->execute([$targetId]);
                $target = $st->fetch(PDO::FETCH_ASSOC);
                if (!$target || ($target['deleted_at'] ?? null) !== null) {
                    $flash = ['type' => 'danger', 'msg' => 'User tidak ditemukan atau sudah dihapus (soft delete).'];
                } elseif (strcasecmp($confirmUser, (string)($target['username'] ?? '')) !== 0) {
                    $flash = ['type' => 'danger', 'msg' => 'Konfirmasi username tidak cocok dengan user terpilih.'];
                } else {
                    $hadMfa = ((int)($target['mfa_enabled'] ?? 0) === 1)
                        || (($target['mfa_confirmed_at'] ?? null) !== null && (string)$target['mfa_confirmed_at'] !== '');

                    $pdo->prepare(
                        "UPDATE master_system_login
                         SET mfa_enabled = 0, mfa_secret = NULL, mfa_confirmed_at = NULL,
                             mfa_backup_codes_hash = '[]', updated_at = NOW()
                         WHERE id = ?"
                    )->execute([$targetId]);

                    $tuname = (string)($target['username'] ?? '');
                    if ($hadMfa && function_exists('master_audit')) {
                        master_audit(
                            $pdo,
                            'mfa_admin_reset',
                            'master_system_login',
                            'MFA_CLEARED_BY_SYS',
                            $targetId,
                            $tuname,
                            'MFA dinonaktifkan oleh admin (SYS/privileged): ' . $tuname,
                            [
                                'target_user_id' => $targetId,
                                'target_username' => $tuname,
                                'reason' => $reason,
                                'by_user_id' => (int)($_SESSION['user_id'] ?? 0),
                                'by_username' => (string)($_SESSION['username'] ?? ''),
                            ]
                        );
                    }
                    if ($hadMfa) {
                        erp_audit($pdo, 'MFA_ADMIN_RESET', 'USER#' . $targetId, 'MFA_CLEAR', [
                            'target_username' => $tuname,
                            'reason' => $reason,
                        ]);
                    }
                    if ($hadMfa) {
                        $flash = ['type' => 'success', 'msg' => 'MFA untuk ' . $tuname . ' telah dinonaktifkan. User harus aktifkan ulang lewat MFA Settings setelah login.'];
                    } else {
                        $flash = ['type' => 'warning', 'msg' => 'User ' . $tuname . ' tidak punya MFA aktif — data MFA sudah dibersihkan (sinkron).'];
                    }
                }
            } catch (Throwable $e) {
                $flash = ['type' => 'danger', 'msg' => 'Gagal memproses: ' . $e->getMessage()];
            }
        }
    }
}

$users = [];
try {
    $users = $pdo->query(
        "SELECT id, username, full_name, role, department, office_code, status,
                COALESCE(mfa_enabled, 0) AS mfa_enabled, mfa_confirmed_at
         FROM master_system_login
         WHERE deleted_at IS NULL
         ORDER BY username ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $flash = ['type' => 'danger', 'msg' => 'Gagal memuat daftar user: ' . $e->getMessage()];
}

$bp = function_exists('rmi_layout_base_project') ? rmi_layout_base_project() : '';
require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('Reset MFA (User lain)', [
    'active' => 'master',
    'subtitle' => 'Hanya SYS / Admin — audit wajib',
    'breadcrumbs' => [
        ['label' => 'Master', 'url' => $bp . '/master/index.php'],
        ['label' => 'MFA Policy', 'url' => $bp . '/master/mfa_policy.php'],
        'Reset MFA user',
    ],
]);
?>
<div class="rmi-container" style="max-width:820px">
  <?php if ($flash['msg'] !== ''): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'danger') ?> mb-3"><?= rmi_h($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="alert alert-secondary mb-3">
    <strong>Peringatan keamanan:</strong> tindakan ini menghapus TOTP &amp; backup code di DB untuk user yang dipilih.
    Pastikan alasan tercatat dan sesuai prosedur internal. Semua aksi dicatat di audit log.
  </div>

  <div class="card mb-3"><div class="card-body">
    <h5 class="mb-3">Nonaktifkan MFA untuk user</h5>
    <form method="post" class="vstack gap-3">
      <input type="hidden" name="action" value="clear_mfa">
      <?php if (function_exists('csrf_token')): ?><input type="hidden" name="csrf_token" value="<?= rmi_h(csrf_token()) ?>"><?php endif; ?>

      <div>
        <label class="form-label">User</label>
        <select name="user_id" class="form-select" required>
          <option value="">— Pilih —</option>
          <?php foreach ($users as $u): ?>
            <?php
            $mid = (int)($u['id'] ?? 0);
            $mun = (string)($u['username'] ?? '');
            $mfaOn = ((int)($u['mfa_enabled'] ?? 0) === 1) && !empty($u['mfa_confirmed_at']);
            $tag = $mfaOn ? ' ' . rmi_icon('gear') . ' MFA' : '';
            ?>
            <option value="<?= $mid ?>"><?= rmi_h($mun . ' — ' . (string)($u['full_name'] ?? '') . ' (' . (string)($u['department'] ?? '') . ')' . $tag) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label">Ketik ulang username (konfirmasi)</label>
        <input type="text" name="confirm_username" class="form-control" autocomplete="off" required placeholder="username persis, case-insensitive">
      </div>

      <div>
        <label class="form-label">Alasan (audit, min. 10 karakter)</label>
        <textarea name="reason" class="form-control" rows="3" required placeholder="Contoh: User kehilangan device autentikator — reset untuk setup ulang."></textarea>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-warning">Nonaktifkan MFA</button>
        <a class="btn btn-outline-secondary" href="<?= rmi_h($bp) ?>/master/mfa_policy.php">MFA Policy</a>
        <a class="btn btn-outline-secondary" href="<?= rmi_h($bp) ?>/master/index.php">Master Hub</a>
      </div>
    </form>
  </div></div>
</div>
<?php rmi_footer(); ?>
