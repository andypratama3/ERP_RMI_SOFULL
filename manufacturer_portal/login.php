<?php
/**
 * manufacturer_portal/login.php
 * Login Manufacturer Portal (pabrikan Reg Alkes).
 */
declare(strict_types=1);
require_once __DIR__ . '/../_shared/rmi_icons.php';

require_once __DIR__ . '/_bootstrap.php';

$base = mportal_base();
$error = null;
$next = trim((string)($_GET['next'] ?? $base . '/manufacturer_portal/index.php'));
if ($next === '' || !str_starts_with($next, '/')) {
    $next = $base . '/manufacturer_portal/index.php';
}

if (!empty($_SESSION['mportal_user_id'])) {
    rmi_redirect($next);
}

function mportal_client_ip(): string {
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $parts = explode(',', $ip);
    return trim((string)($parts[0] ?? '0.0.0.0'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('verify_csrf')) {
        verify_csrf((string)($_POST['csrf_token'] ?? ''));
    }
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = mportal_t('err_required');
    } else {
        $pdo = rmi_db_pdo();
        $throttle = null;
        try {
            if (is_file(__DIR__ . '/../master/schema_mfa.php')) {
                require_once __DIR__ . '/../master/schema_mfa.php';
                if (function_exists('schema_ensure_auth_login_attempts')) {
                    schema_ensure_auth_login_attempts($pdo);
                }
            }
            if (class_exists(\App\Security\LoginThrottle::class)) {
                $throttle = new \App\Security\LoginThrottle($pdo);
                $lockState = $throttle->isLocked(mportal_client_ip(), $username);
                if (!empty($lockState['locked'])) {
                    $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . max(1, (int)($lockState['seconds_left'] / 60)) . ' menit.';
                }
            }
        } catch (Throwable $e) {
            $throttle = null;
        }

        if ($error === null) {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, username, password_hash, full_name, manufacture_code, manufacture_id, status
                    FROM manufacturer_portal_users WHERE username = ? LIMIT 1
                ");
                $stmt->execute([$username]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $u = null;
                $error = 'Tabel manufacturer_portal_users belum tersedia. Jalankan migration 140 terlebih dahulu.';
            }
            if ($error === null) {
            if (!$u || !password_verify($password, (string)($u['password_hash'] ?? ''))) {
                $error = 'Username atau password salah.';
                if ($throttle) {
                    $throttle->recordFailure(mportal_client_ip(), $username);
                }
            } elseif (strtolower((string)($u['status'] ?? '')) !== 'active') {
                $error = 'Akun tidak aktif. Hubungi PQP RMI.';
            } else {
                $stM = $pdo->prepare("SELECT 1 FROM master_manufactures WHERE manufacture_code = ? AND (deleted_at IS NULL OR deleted_at = '') LIMIT 1");
                $stM->execute([trim((string)($u['manufacture_code'] ?? ''))]);
                if (!$stM->fetch()) {
                    $error = mportal_t('err_manufacture');
                } else {
                    session_regenerate_id(true);
                    $_SESSION['mportal_user_id'] = (int)$u['id'];
                    $_SESSION['mportal_username'] = (string)$u['username'];
                    $_SESSION['mportal_full_name'] = (string)($u['full_name'] ?? '');
                    $_SESSION['mportal_manufacture_code'] = (string)$u['manufacture_code'];
                    $_SESSION['mportal_manufacture_id'] = $u['manufacture_id'] ? (int)$u['manufacture_id'] : null;

                    $pdo->prepare("UPDATE manufacturer_portal_users SET last_login_at = NOW() WHERE id = ?")->execute([$u['id']]);
                    if ($throttle) {
                        $throttle->clear(mportal_client_ip(), $username);
                    }
                    rmi_redirect($next);
                }
            }
            }
        }
    }
}

$csrf = function_exists('csrf_token') ? csrf_token() : '';
$lang = mportal_lang();
$reqUri = $_SERVER['REQUEST_URI'] ?? '/manufacturer_portal/login.php';
$sep = (strpos($reqUri, '?') !== false) ? '&' : '?';
?>
<!DOCTYPE html>
<html lang="<?= $lang === 'zh' ? 'zh-CN' : ($lang === 'id' ? 'id' : 'en') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= rmi_h(mportal_t('login_title')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center min-vh-100">
<div class="container">
    <div class="text-end mb-2">
        <a href="<?= rmi_h($reqUri . $sep . 'lang=en') ?>" class="text-muted small me-2">EN</a>
        <a href="<?= rmi_h($reqUri . $sep . 'lang=zh') ?>" class="text-muted small me-2">中文</a>
        <a href="<?= rmi_h($reqUri . $sep . 'lang=id') ?>" class="text-muted small">ID</a>
    </div>
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <span style="font-size: 2.5rem;"><?= rmi_icon('office') ?></span>
                        <h4 class="card-title mb-1 mt-2"><?= rmi_h(mportal_t('portal_title')) ?></h4>
                        <p class="text-muted small mb-0"><?= rmi_h(mportal_t('reg_alkes')) ?></p>
                    </div>
                    <p class="text-muted small"><?= rmi_h(mportal_t('login_desc')) ?></p>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= rmi_h($error) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">
                        <div class="mb-3">
                            <label class="form-label"><?= rmi_h(mportal_t('username')) ?></label>
                            <input type="text" name="username" class="form-control" required autofocus autocomplete="username"
                                   value="<?= rmi_h($_POST['username'] ?? '') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><?= rmi_h(mportal_t('password')) ?></label>
                            <input type="password" name="password" class="form-control" required autocomplete="current-password">
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><?= rmi_h(mportal_t('btn_signin')) ?></button>
                        <div class="text-center mt-3">
                            <small class="text-muted"><?= rmi_h(mportal_t('forgot_password')) ?></small>
                        </div>
                        <div class="text-center mt-2">
                            <small class="text-muted"><?= rmi_h(mportal_t('pqp_contact')) ?></small>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
