<?php
/**
 * customer_portal/login.php
 * Login Customer Portal (Hermina, dll).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$base = portal_base();
$error = null;
$next = trim((string)($_GET['next'] ?? $base . '/customer_portal/index.php'));
if ($next === '' || !str_starts_with($next, '/')) {
    $next = $base . '/customer_portal/index.php';
}

// Sudah login → redirect
if (!empty($_SESSION['portal_user_id'])) {
    rmi_redirect($next);
}

function portal_client_ip(): string {
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
        $error = 'Username dan password wajib diisi.';
    } else {
        $pdo = rmi_db_pdo();

        // Rate limit (LoginThrottle) — opsional, jika gagal login tetap jalan
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
                $lockState = $throttle->isLocked(portal_client_ip(), $username);
                if (!empty($lockState['locked'])) {
                    $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . max(1, (int)($lockState['seconds_left'] / 60)) . ' menit.';
                }
            }
        } catch (Throwable $e) {
            $throttle = null;
        }

        if ($error === null) {
        $stmt = $pdo->prepare("
            SELECT id, username, password_hash, full_name, customers_code, office_code, status
            FROM customer_portal_users WHERE username = ? LIMIT 1
        ");
        $stmt->execute([$username]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$u || !password_verify($password, (string)($u['password_hash'] ?? ''))) {
            $error = 'Username atau password salah.';
            if ($throttle) {
                $throttle->recordFailure(portal_client_ip(), $username);
            }
        } elseif (strtolower((string)($u['status'] ?? '')) !== 'active') {
            $error = 'Akun tidak aktif. Hubungi RMI.';
        } else {
            // Cek customer masih active (case-insensitive: active, Active, ACTIVE)
            $stC = $pdo->prepare("SELECT 1 FROM master_customers WHERE customers_code = ? AND LOWER(TRIM(COALESCE(status,''))) = 'active' LIMIT 1");
            $stC->execute([trim($u['customers_code'])]);
            if (!$stC->fetch()) {
                $error = 'Customer tidak aktif. Hubungi RMI.';
            } else {
                session_regenerate_id(true);
                $_SESSION['portal_user_id'] = (int)$u['id'];
                $_SESSION['portal_username'] = (string)$u['username'];
                $_SESSION['portal_full_name'] = (string)($u['full_name'] ?? '');
                $_SESSION['portal_customers_code'] = (string)$u['customers_code'];
                $_SESSION['portal_office_code'] = (string)($u['office_code'] ?? '');

                $pdo->prepare("UPDATE customer_portal_users SET last_login_at = NOW() WHERE id = ?")->execute([$u['id']]);
                if ($throttle) {
                    $throttle->clear(portal_client_ip(), $username);
                }
                rmi_redirect($next);
            }
        }
        } // end if ($error === null)
    }
}

$csrf   = function_exists('csrf_token') ? csrf_token() : '';
$assets = $base . '/public/assets/vendor';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login — RMI Customer Portal</title>
<link href="<?= $assets ?>/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;font-family:'Segoe UI',system-ui,sans-serif;background:#f0fdf4}

/* Left panel */
.login-left{flex:1;background:linear-gradient(160deg,#14532d 0%,#166534 40%,#1d4ed8 100%);display:flex;flex-direction:column;justify-content:center;align-items:center;padding:48px 40px;color:#fff;position:relative;overflow:hidden}
.login-left::before{content:"";position:absolute;top:-100px;right:-80px;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.06)}
.login-left::after{content:"";position:absolute;bottom:-80px;left:-60px;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.04)}
.brand-logo{font-size:56px;margin-bottom:16px}
.brand-name{font-size:22px;font-weight:800;margin-bottom:6px}
.brand-tagline{font-size:14px;color:rgba(255,255,255,.7);margin-bottom:32px}
.feature-list{list-style:none;padding:0;margin:0;width:100%}
.feature-list li{display:flex;align-items:center;gap:10px;padding:8px 0;font-size:14px;color:rgba(255,255,255,.85)}
.feature-list li span.icon{font-size:18px}

/* Right panel */
.login-right{width:420px;background:#fff;display:flex;flex-direction:column;justify-content:center;padding:48px 40px;box-shadow:-4px 0 24px rgba(0,0,0,.08)}
.login-title{font-size:20px;font-weight:800;color:#1e293b;margin-bottom:4px}
.login-sub{font-size:13px;color:#64748b;margin-bottom:28px}
.form-label{font-size:13px;font-weight:600;color:#374151;margin-bottom:5px}
.form-control{border:2px solid #e2e8f0;border-radius:10px;padding:10px 14px;font-size:14px;transition:border-color .2s}
.form-control:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.12);outline:none}
.btn-login{background:linear-gradient(135deg,#16a34a,#1d4ed8);color:#fff;border:none;border-radius:10px;padding:12px;font-size:15px;font-weight:700;width:100%;cursor:pointer;transition:opacity .2s}
.btn-login:hover{opacity:.9}
.alert-danger{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:10px;padding:10px 14px;font-size:13px}
.help-text{text-align:center;font-size:12px;color:#94a3b8;margin-top:16px}
.login-footer{margin-top:auto;padding-top:24px;text-align:center;font-size:11px;color:#94a3b8}

@media(max-width:768px){
  body{flex-direction:column}
  .login-left{padding:32px 24px;min-height:200px}
  .login-left .feature-list{display:none}
  .login-right{width:100%;padding:32px 24px;box-shadow:none}
}
</style>
</head>
<body>

<!-- Left: Branding -->
<div class="login-left">
  <div class="brand-logo">🏥</div>
  <div class="brand-name">RMI Customer Portal</div>
  <div class="brand-tagline">Rizqullah Mediska Indonesia</div>
  <ul class="feature-list">
    <li><span class="icon">📦</span> Pesan produk alat kesehatan kapan saja</li>
    <li><span class="icon">📋</span> Pantau status & riwayat order</li>
    <li><span class="icon">💰</span> Lihat harga khusus untuk institusi Anda</li>
    <li><span class="icon">📄</span> Upload dan kelola dokumen pesanan</li>
    <li><span class="icon">🚚</span> Informasi pengiriman real-time</li>
  </ul>
  <div style="margin-top:32px;font-size:13px;color:rgba(255,255,255,.5)">"We Are Healthy Together"</div>
</div>

<!-- Right: Login Form -->
<div class="login-right">
  <div class="login-title">Selamat Datang 👋</div>
  <div class="login-sub">Masuk ke portal customer RMI untuk mulai memesan</div>

  <?php if ($error): ?>
    <div class="alert-danger mb-3">❌ <?= rmi_h($error) ?></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= rmi_h($csrf) ?>">
    <div class="mb-3">
      <label class="form-label">Username</label>
      <input type="text" name="username" class="form-control"
             placeholder="Masukkan username Anda"
             value="<?= rmi_h($_POST['username'] ?? '') ?>"
             required autofocus>
    </div>
    <div class="mb-4">
      <label class="form-label">Password</label>
      <input type="password" name="password" class="form-control"
             placeholder="Masukkan password" required>
    </div>
    <button type="submit" class="btn-login">Masuk ke Portal →</button>
  </form>

  <div class="help-text">
    Lupa password atau belum punya akses?<br>
    Hubungi <strong>Staff CRM RMI</strong> untuk bantuan.
  </div>

  <div class="login-footer">
    &copy; <?= date('Y') ?> Rizqullah Mediska Indonesia
  </div>
</div>

</body>
</html>
