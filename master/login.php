<?php
// require_login(); // static scan marker (file ini memang boleh diakses tanpa login)

// master/master_login.php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../_shared/erp_audit.php';
require_once __DIR__ . '/../_shared/login_policy.php';
require_once __DIR__ . '/_audit_master.php';

$pdo = db_pdo(); // koneksi DB (dipakai untuk login)

$next = safe_next($_GET['next'] ?? '', $fallback_home ?? 'index.php');
$error = null;
$reason = (string) ($_GET['reason'] ?? '');
if ($reason === 'session_idle') {
    $error = 'Sesi berakhir karena tidak ada aktivitas (batas idle). Silakan login kembali.';
} elseif ($reason === 'session_ip') {
    $error = 'Sesi diakhiri (akses IP tidak diizinkan untuk akun ini).';
}

function login_client_ip(): string {
    $ip = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $parts = explode(',', $ip);
    return trim((string)($parts[0] ?? '0.0.0.0'));
}

function login_finalize_session(array $u, ?string $src): void {
    // Regenerate session ID + stempel idle — implementasi di master/auth.php
    if (function_exists('auth_session_mark_login_complete')) {
        auth_session_mark_login_complete();
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id']    = (int)($u['id'] ?? 0);
    $_SESSION['username']   = (string)($u['username'] ?? '');
    $_SESSION['full_name']  = (string)($u['full_name'] ?? '');

    $sessRole  = strtoupper((string)($u['role'] ?? 'staff'));
    $sessLevel = strtoupper((string)($u['level'] ?? ($u['role'] ?? 'staff')));
    if ($sessLevel === '') { $sessLevel = $sessRole; }
    // Fallback: jika DB berikan role STAFF untuk akun SYS, koreksi ke SYS
    // Hanya berlaku untuk username yang memang terdaftar sebagai SYS: admin, superadmin
    $un = strtolower(trim((string)($u['username'] ?? '')));
    if (in_array($un, ['admin', 'superadmin'], true) && ($sessRole === 'STAFF' || $sessLevel === 'STAFF')) {
        $sessRole = 'SYS';
        $sessLevel = 'SYS';
    }
    $sessDept  = strtoupper((string)($u['department'] ?? ''));
    $sessOffice= strtoupper((string)($u['office_code'] ?? ''));

    $_SESSION['role']       = $sessRole;
    $_SESSION['level']      = $sessLevel;
    $_SESSION['department'] = $sessDept;
    if ($sessOffice !== '') {
        $_SESSION['office_code'] = $sessOffice;
    }

    $_SESSION['user'] = [
        'id'         => (int)($u['id'] ?? 0),
        'username'   => (string)($u['username'] ?? ''),
        'full_name'  => (string)($u['full_name'] ?? ''),
        'role'       => $sessRole,
        'level'      => $sessLevel,
        'department' => $sessDept,
        'office_code'=> $sessOffice,
        'src'        => (string)$src,
    ];
}

function login_audit_denied(PDO $pdo, string $username, string $reason): void {
    try {
        $ip = login_client_ip(); // X-Forwarded-For → IP asli user
        // Tulis sekali ke system_audit_logs (tampil di UI) via master_audit()
        if (function_exists('master_audit')) {
            master_audit($pdo, 'auth', 'master_system_login', 'LOGIN_DENIED', null, 'USER#' . $username,
                "Login gagal ({$reason}): {$username} dari IP {$ip}", [
                'target_username' => $username,
                'reason'          => $reason,
                'ip'              => $ip,
            ]);
        }
        // Tulis juga ke erp_audit_log (operational/backup log) tanpa dual-write
        erp_audit_ensure($pdo);
        $pdo->prepare("INSERT INTO erp_audit_log
            (module, entity_key, action, username, ip_address, user_agent, payload_json, created_at)
            VALUES ('auth',?,?,?,?,?,?,NOW())")
            ->execute([
                'USER#' . $username,
                'LOGIN_DENIED',
                $username,
                $ip,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                json_encode(['reason' => $reason], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Throwable $e) {
        // fail-soft
    }
}

function login_audit_success(PDO $pdo, array $u, string $ip): void {
    $username = (string)($u['username'] ?? '');
    $dept     = strtoupper((string)($u['department'] ?? ''));
    $role     = strtoupper((string)($u['role'] ?? ''));
    $level    = strtoupper((string)($u['level'] ?? $u['role'] ?? ''));
    $userId   = (int)($u['id'] ?? 0);
    $ua       = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $descr    = "Login berhasil: {$username} ({$dept}/{$role}) dari {$ip}";
    $details  = json_encode(['dept'=>$dept,'role'=>$role,'ip'=>$ip], JSON_UNESCAPED_SLASHES);

    // --- Direct write ke system_audit_logs (tabel yang tampil di UI) ---
    // Tidak melalui erp_audit() karena itu nulis ke erp_audit_log (tabel berbeda).
    // Tidak melalui master_audit() karena bisa throw sebelum session siap.
    try {
        if (function_exists('master_audit_ensure_table')) {
            master_audit_ensure_table($pdo);
        }
        $stmt = $pdo->prepare("
            INSERT INTO system_audit_logs
                (module, action, record_table, record_id, record_code,
                 description, details, user_id, username, role, level,
                 ip, user_agent, created_at)
            VALUES
                ('auth','LOGIN_SUCCESS','master_system_login',?,?,
                 ?,?,?,?,?,?,
                 ?,?,NOW())
        ");
        $stmt->execute([
            $userId,                           // record_id
            $username,                         // record_code
            $descr,                            // description
            $details,                          // details
            $userId,                           // user_id
            $username,                         // username
            $role,                             // role
            $level,                            // level
            substr($ip, 0, 45),                // ip
            $ua,                               // user_agent
        ]);
    } catch (Throwable $e) {
        // fail-soft — jangan gagalkan login karena audit error
        @error_log('[login_audit_success] ' . $e->getMessage());
    }

    // --- Juga tulis ke erp_audit_log (operational/backup log, tanpa dual-write) ---
    try {
        erp_audit_ensure($pdo);
        $pdo->prepare("INSERT INTO erp_audit_log
            (module, entity_key, action, user_id, username, role, level, ip_address, user_agent, payload_json, created_at)
            VALUES ('auth',?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([
                'USER#' . $username, 'LOGIN_SUCCESS', $userId, $username, $role, $level,
                substr($ip, 0, 45), $ua,
                json_encode(['dept' => $dept, 'role' => $role], JSON_UNESCAPED_SLASHES),
            ]);
    } catch (Throwable $e) { /* fail-soft */ }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $throttle = null;
        if (class_exists(\App\Security\LoginThrottle::class)) {
            require_once __DIR__ . '/schema_mfa.php';
            schema_ensure_auth_login_attempts($pdo);
            $throttle = new \App\Security\LoginThrottle($pdo);
        }
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $ip = login_client_ip();

        if ($username === '' || $password === '') {
            $error = 'Username & password wajib diisi.';
        } else {
            if ($throttle) {
                try {
                    $lockState = $throttle->isLocked($ip, $username);
                    if (!empty($lockState['locked'])) {
                        $error = 'Login gagal. Coba lagi beberapa menit.';
                    }
                } catch (Throwable $e) {
                    $throttle = null;
                }
            }
            if ($error !== null) {
                throw new RuntimeException($error);
            }

            $u = null;
            $src = null;

            // 1) Prioritas: master_system_login (Enterprise login — source of truth untuk role/dept)
            try {
                $stmt = $pdo->prepare("SELECT id, username, password_hash, role, level, department, office_code, status, full_name, deleted_at
                                       FROM master_system_login WHERE username = ? LIMIT 1");
                $stmt->execute([$username]);
                $u = $stmt->fetch();
                if ($u) $src = 'master_system_login';
            } catch (Throwable $e) {
                try {
                    $stmt = $pdo->prepare("SELECT id, username, password_hash, status
                                           FROM master_system_login WHERE username = ? LIMIT 1");
                    $stmt->execute([$username]);
                    $u = $stmt->fetch();
                    if ($u) {
                        $src = 'master_system_login';
                        $un = strtolower(trim((string)($u['username'] ?? '')));
                        if (in_array($un, ['admin', 'superadmin'], true)) {
                            $u['role']       = 'SYS';   // uppercase agar konsisten dengan strtoupper() check di rbac
                            $u['level']      = 'SYS';
                            $u['department'] = 'SYS';
                        }
                    }
                } catch (Throwable $e2) {
                    // ignore
                }
            }

            if (!$u) {
                if ($throttle) { try { $throttle->recordFailure($ip, $username); } catch (Throwable $e) {} }
                login_audit_denied($pdo, $username, 'user_not_found');
                $error = 'Kredensial tidak valid.';
            } else {
                $denyReason = rmi_login_denial_reason(is_array($u) ? $u : null);
                if ($denyReason === 'deleted') {
                if ($throttle) { try { $throttle->recordFailure($ip, $username); } catch (Throwable $e) {} }
                login_audit_denied($pdo, $username, 'deleted');
                $error = 'Akun dinonaktifkan, hubungi admin.';
            } elseif ($denyReason === 'inactive') {
                if ($throttle) { try { $throttle->recordFailure($ip, $username); } catch (Throwable $e) {} }
                login_audit_denied($pdo, $username, 'inactive');
                $error = 'Akun dinonaktifkan, hubungi admin.';
            } elseif (!password_verify($password, (string)($u['password_hash'] ?? ''))) {
                if ($throttle) { try { $throttle->recordFailure($ip, $username); } catch (Throwable $e) {} }
                login_audit_denied($pdo, $username, 'wrong_password');
                $error = 'Kredensial tidak valid.';
            } else {
                if ($throttle) { try { $throttle->clear($ip, $username); } catch (Throwable $e) {} }

                // --- IP Restriction: admin/superadmin built-in hanya dari IP whitelist ---
                if (rmi_is_builtin_admin_username($username) && !rmi_builtin_admin_ip_allowed($ip)) {
                    if ($throttle) { try { $throttle->recordFailure($ip, $username); } catch (Throwable $e) {} }
                    login_audit_denied($pdo, $username, 'ip_restricted');
                    $error = 'Login dari lokasi ini tidak diizinkan untuk akun ini.';
                    throw new RuntimeException($error);
                }
                // --- End IP Restriction ---

                $mfaEnabled = false;
                $mfaRequiredByPolicy = false;
                if ($src === 'master_system_login') {
                    try {
                        $stm = $pdo->prepare("SELECT mfa_enabled, mfa_secret FROM master_system_login WHERE id=? LIMIT 1");
                        $stm->execute([(int)$u['id']]);
                        $mfa = $stm->fetch(PDO::FETCH_ASSOC);
                        $mfaEnabled = ((int)($mfa['mfa_enabled'] ?? 0) === 1) && !empty($mfa['mfa_secret']);

                        if (class_exists(\App\Security\MFAPolicyService::class)) {
                            $policy = new \App\Security\MFAPolicyService();
                            $mfaRequiredByPolicy = $policy->isMfaRequired(
                                $pdo,
                                (string)($u['role'] ?? ''),
                                (string)($u['department'] ?? '')
                            );
                        }
                    } catch (Throwable $e) {
                        $mfaEnabled = false;
                        $mfaRequiredByPolicy = false;
                    }
                }

                if ($mfaRequiredByPolicy && !$mfaEnabled) {
                    $hasBypass = false;
                    try {
                        if (class_exists(\App\Security\MFABypassService::class)) {
                            schema_ensure_auth_mfa_bypass_tickets($pdo);
                            $bypassSvc = new \App\Security\MFABypassService();
                            $hasBypass = $bypassSvc->hasActiveBypass($pdo, (int)($u['id'] ?? 0));
                        }
                    } catch (Throwable $e) {
                        $hasBypass = false;
                    }
                    if (!$hasBypass) {
                        $error = 'Akun Anda wajib MFA sesuai kebijakan. Aktifkan MFA melalui admin/security setting.';
                        throw new RuntimeException($error);
                    }
                }

                if ($mfaEnabled) {
                    $_SESSION['_mfa_pending'] = [
                        'user' => $u,
                        'src' => (string)$src,
                        'next' => $next,
                        'ip' => $ip,
                    ];
                    rmi_redirect('mfa_verify.php?next=' . urlencode($next));
                }

                login_finalize_session($u, $src);

                // Audit login berhasil
                login_audit_success($pdo, $u, $ip);

                // Update last_login_at (best effort)
                if ($src === 'master_system_login') {
                    try {
                        $st = $pdo->prepare("UPDATE master_system_login SET last_login_at=NOW(), updated_at=NOW() WHERE id=?");
                        $st->execute([(int)$u['id']]);
                    } catch (Throwable $e) {}
                }

                // Landing: semua role memakai auth_post_login_landing_path (Nav Manager + default per dept)
                $target = $next;
                if ($target === ($fallback_home ?? '')) {
                    $bp = (isset($BASE_PROJECT) ? $BASE_PROJECT : '') ?: '';
                    $path = function_exists('auth_post_login_landing_path')
                        ? auth_post_login_landing_path((string)($u['department'] ?? ''))
                        : auth_landing_path_for_dept((string)($u['department'] ?? ''));
                    $target = $bp . $path;
                }

                // Safety redirect: jangan kirim staff/manager PQP ke Import Control Tower
                // jika tidak punya permission PURCHASES.IMPORT_CONTROL.
                if (function_exists('auth_post_login_target_normalize')) {
                    $target = auth_post_login_target_normalize($target, is_array($u) ? $u : []);
                }

                rmi_redirect($target);
            }
            }
        }
    } catch (Throwable $e) {
        if ($error === null) {
            $error = 'Login gagal. Periksa kembali kredensial Anda.';
        }
        // Log error teknis login (bukan wrong password — itu sudah di login_audit_denied)
        if (function_exists('rmi_log_module_error')) {
            rmi_log_module_error('login', $e, ['action' => 'LOGIN_EXCEPTION', 'username' => $username ?? '', 'ip' => $ip ?? '']);
        } else {
            // Fallback minimal
            $root = defined('RMI_ROOT') ? rtrim((string)RMI_ROOT, '/') : dirname(__DIR__);
            @file_put_contents(
                $root . '/storage/logs/auth_errors.log',
                date('[Y-m-d H:i:s]') . ' LOGIN_EXCEPTION | user=' . ($username ?? '?') . ' | ' . $e->getMessage() . "\n",
                FILE_APPEND | LOCK_EX
            );
        }
    }
}
require_once __DIR__ . '/../_shared/rmi_layout.php';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>RMI ERP Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <style>
    *,*::before,*::after{box-sizing:border-box}
    :root{
      --cyan:#35d9ff;
      --blue:#147cff;
      --panel:rgba(3,12,35,.90);
      --line:rgba(83,205,255,.58);
      --text:#eef8ff;
      --muted:#a9c7ea;
      --purple:#9b5cff;
      --danger:#ff6b81;
    }

    html,body{
      width:100%;
      min-height:100%;
      margin:0;
    }

    body{
      font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
      color:var(--text);
      background:#020617;
      overflow:hidden;
    }

    .page{
      width:100vw;
      height:100dvh;
      min-height:720px;
      position:relative;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:0;
      isolation:isolate;
      background:
        linear-gradient(90deg,rgba(2,6,23,.16),rgba(2,6,23,.02) 50%,rgba(2,6,23,.16)),
        linear-gradient(180deg,rgba(2,6,23,.02),rgba(2,6,23,.15)),
        url('../assets/rmi-login-bg.png') center center / cover no-repeat;
    }

    .page::before{
      content:"";
      position:absolute;
      inset:0;
      z-index:-1;
      pointer-events:none;
      background:
        radial-gradient(circle at 50% 47%,transparent 0 28%,rgba(2,6,23,.06) 50%,rgba(2,6,23,.40) 100%),
        linear-gradient(180deg,rgba(0,0,0,.02),rgba(0,0,0,.20));
    }

    /* FIX: panel gelap tengah full atas-bawah agar background tidak bertumpuk */
    .page::after{
      content:"";
      position:absolute;
      top:0;
      bottom:0;
      left:50%;
      width:min(780px,100vw);
      transform:translateX(-50%);
      z-index:0;
      pointer-events:none;
      background:
        linear-gradient(90deg,
          rgba(2,6,23,0) 0%,
          rgba(2,10,31,.86) 8%,
          rgba(4,17,48,.99) 22%,
          rgba(4,17,48,1) 78%,
          rgba(2,10,31,.86) 92%,
          rgba(2,6,23,0) 100%
        );
      box-shadow:
        0 0 90px rgba(0,0,0,.72),
        inset 0 0 80px rgba(0,180,255,.12);
    }

    .top-actions{
      position:fixed;
      top:22px;
      right:24px;
      display:flex;
      gap:9px;
      z-index:10;
    }

    .top-actions span{
      min-height:38px;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      padding:8px 14px;
      border-radius:12px;
      border:1px solid rgba(160,225,255,.38);
      color:#e8fbff;
      background:rgba(4,12,30,.34);
      backdrop-filter:blur(10px);
      font-weight:850;
      font-size:13px;
      box-shadow:0 0 16px rgba(29,125,255,.10);
    }

    /*
      FIX UTAMA:
      Panel login dibuat full tinggi halaman dan solid,
      supaya area atas tidak terlihat bertumpuk dengan background.
    */
    .login-card{
      width:min(640px,94vw);
      height:100dvh;
      min-height:720px;
      max-height:none;
      padding:26px 54px 22px;
      border-radius:0;
      border-left:1px solid rgba(97,210,255,.62);
      border-right:1px solid rgba(97,210,255,.62);
      border-top:0;
      border-bottom:0;
      background:
        linear-gradient(180deg,rgba(8,26,66,.985),rgba(3,12,35,.998)),
        rgba(4,14,38,1);
      box-shadow:
        0 0 90px rgba(0,0,0,.72),
        0 0 70px rgba(34,138,255,.32),
        inset 0 0 0 1px rgba(255,255,255,.045);
      backdrop-filter:blur(13px);
      position:relative;
      display:flex;
      flex-direction:column;
      justify-content:center;
      overflow:hidden;
      z-index:2;
      transform:none;
      margin:0 auto;
    }

    .login-card::before{
      content:"";
      position:absolute;
      inset:0;
      pointer-events:none;
      border-radius:inherit;
      background:
        radial-gradient(circle at 50% 4%,rgba(53,217,255,.14),transparent 29%),
        radial-gradient(circle at 0 48%,rgba(53,217,255,.14),transparent 32%),
        radial-gradient(circle at 100% 72%,rgba(155,92,255,.17),transparent 34%),
        linear-gradient(180deg,rgba(255,255,255,.025),rgba(255,255,255,0));
    }

    .login-card::after{
      content:"";
      position:absolute;
      inset:0;
      border-radius:0;
      border-left:1px solid rgba(83,205,255,.14);
      border-right:1px solid rgba(83,205,255,.14);
      border-top:0;
      border-bottom:0;
      pointer-events:none;
    }

    .login-card>*{position:relative;z-index:1}

    .brand{
      text-align:center;
      margin-bottom:6px;
      margin-top:0;
      padding:0 10px 0;
    }

    .brand-logo{
      width:400px;
      max-width:96%;
      height:auto;
      max-height:190px;
      object-fit:contain;
      display:block;
      margin:0 auto 4px;
      filter:
        brightness(1.06)
        contrast(1.08)
        saturate(1.10)
        drop-shadow(0 0 22px rgba(53,217,255,.34))
        drop-shadow(0 0 16px rgba(155,92,255,.18))
        drop-shadow(0 0 10px rgba(246,199,102,.18));
      mix-blend-mode:normal;
      opacity:1;
    }

    .brand-fallback{
      display:none;
      margin:0 auto 8px;
      color:#f6c766;
      font-weight:950;
      font-size:58px;
      letter-spacing:.04em;
      line-height:1;
      text-shadow:0 0 22px rgba(246,199,102,.28),0 0 18px rgba(53,217,255,.22);
    }

    .title{
      text-align:center;
      margin:4px 0 22px;
    }

    .title h1{
      margin:0;
      color:var(--cyan);
      text-transform:uppercase;
      letter-spacing:.15em;
      font-size:22px;
      text-shadow:0 0 18px rgba(53,217,255,.58);
    }

    .title p{
      margin:8px 0 0;
      color:#e1f0ff;
      text-transform:uppercase;
      letter-spacing:.20em;
      font-size:13px;
      font-weight:850;
    }

    .err{
      margin:0 0 14px;
      padding:11px 13px;
      border-radius:13px;
      border:1px solid rgba(255,107,129,.46);
      background:rgba(127,29,45,.34);
      color:#ffe1e6;
      font-size:13px;
      line-height:1.45;
    }

    .field{
      position:relative;
      margin-bottom:14px;
    }

    .field .ico{
      position:absolute;
      left:17px;
      top:50%;
      transform:translateY(-50%);
      width:22px;
      height:22px;
      color:#a9edff;
      opacity:.95;
      z-index:2;
      filter:drop-shadow(0 0 9px rgba(53,217,255,.28));
    }

    .input{
      display:block;
      width:100%;
      height:58px;
      padding:0 54px;
      border-radius:15px;
      border:1px solid rgba(83,205,255,.52);
      outline:none;
      background:rgba(235,244,255,.95);
      color:#06142d;
      font-size:15px;
      font-weight:650;
      box-shadow:inset 0 0 0 1px rgba(255,255,255,.05),0 10px 28px rgba(0,0,0,.18);
      transition:.18s;
    }

    .input::placeholder{color:rgba(15,35,72,.60)}

    .input:focus{
      border-color:#7aeaff;
      background:#f2f7ff;
      box-shadow:0 0 0 3px rgba(53,217,255,.14),0 0 24px rgba(53,217,255,.22),0 10px 28px rgba(0,0,0,.18);
    }

    .pwd-toggle{
      position:absolute;
      right:10px;
      top:50%;
      transform:translateY(-50%);
      width:40px;
      height:40px;
      border:0;
      border-radius:11px;
      background:rgba(53,217,255,.08);
      color:#8beeff;
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      justify-content:center;
      transition:.18s;
      padding:0;
    }

    .pwd-toggle:hover{
      background:rgba(53,217,255,.16);
      color:#06142d;
    }

    .login-row{
      margin:2px 0 18px;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:12px;
      color:#dcecff;
      font-size:13px;
    }

    .remember{
      display:flex;
      align-items:center;
      gap:9px;
      white-space:nowrap;
    }

    .remember input{
      width:17px;
      height:17px;
      accent-color:var(--cyan);
    }

    .forgot{
      color:#9edfff;
      text-decoration:none;
    }

    .forgot:hover{
      color:white;
      text-shadow:0 0 10px rgba(53,217,255,.55);
    }

    .login-btn{
      width:100%;
      height:60px;
      border-radius:15px;
      border:1px solid rgba(168,232,255,.88);
      color:white;
      background:
        linear-gradient(180deg,rgba(102,218,255,.98),rgba(26,131,255,.96) 42%,rgba(28,68,207,.96));
      font-size:19px;
      font-weight:950;
      letter-spacing:.18em;
      text-transform:uppercase;
      cursor:pointer;
      box-shadow:
        0 0 30px rgba(53,217,255,.48),
        inset 0 1px 0 rgba(255,255,255,.58),
        inset 0 -13px 24px rgba(7,40,120,.25);
      transition:.18s;
    }

    .login-btn:hover{
      transform:translateY(-1px);
      box-shadow:0 0 44px rgba(53,217,255,.70),0 14px 30px rgba(29,125,255,.25);
    }

    .divider{
      display:flex;
      align-items:center;
      gap:14px;
      margin:24px 0 16px;
      color:#6edbff;
      font-size:12px;
      letter-spacing:.18em;
      text-transform:uppercase;
    }

    .divider::before,
    .divider::after{
      content:"";
      flex:1;
      height:1px;
      background:linear-gradient(90deg,transparent,rgba(53,217,255,.42),transparent);
    }

    .bio{
      display:flex;
      align-items:center;
      gap:13px;
      padding:12px 15px;
      min-height:60px;
      border-radius:15px;
      border:1px solid rgba(139,92,246,.46);
      background:rgba(6,17,44,.62);
    }

    .bio-icon{
      width:42px;
      height:42px;
      border-radius:13px;
      border:1px solid rgba(168,85,247,.75);
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:22px;
      color:#e9d5ff;
      background:rgba(88,28,135,.24);
      box-shadow:0 0 22px rgba(168,85,247,.18);
    }

    .bio b{
      display:block;
      font-size:13px;
      letter-spacing:.08em;
      text-transform:uppercase;
    }

    .bio span{
      display:block;
      color:rgba(219,234,254,.72);
      font-size:12px;
      margin-top:2px;
    }

    .secure-text{
      text-align:center;
      margin-top:22px;
      color:#dbeafe;
      font-size:12px;
      letter-spacing:.34em;
      text-transform:uppercase;
    }

    .side-card{
      position:fixed;
      bottom:32px;
      width:245px;
      min-height:112px;
      padding:18px;
      border-radius:16px;
      border:1px solid rgba(53,217,255,.28);
      background:rgba(3,12,30,.55);
      backdrop-filter:blur(9px);
      box-shadow:0 18px 50px rgba(0,0,0,.32),0 0 25px rgba(29,125,255,.12);
      z-index:1;
    }

    .side-card.left{left:28px}
    .side-card.right{right:28px}

    .side-card b{
      display:block;
      color:#e8fbff;
      font-size:13px;
      line-height:1.45;
      letter-spacing:.04em;
      text-transform:uppercase;
      margin-bottom:8px;
    }

    .side-card p{
      margin:0;
      color:rgba(219,234,254,.75);
      font-size:12px;
      line-height:1.55;
    }

    .status-dot{
      display:inline-block;
      width:8px;
      height:8px;
      border-radius:999px;
      background:#22c55e;
      box-shadow:0 0 13px #22c55e;
      margin-right:8px;
      vertical-align:middle;
    }

    .secure-big{
      display:block;
      color:#38d5ff;
      font-size:22px;
      font-weight:950;
      margin:0 0 3px;
      text-shadow:0 0 14px rgba(53,217,255,.42);
    }

    .copyright{
      position:fixed;
      left:50%;
      bottom:14px;
      transform:translateX(-50%);
      color:rgba(219,234,254,.72);
      font-size:11px;
      letter-spacing:.10em;
      text-align:center;
      white-space:nowrap;
      text-shadow:0 1px 8px rgba(0,0,0,.85);
      z-index:3;
    }

    @media(max-width:1100px){
      body{overflow:auto}
      .page{
        min-height:100dvh;
        height:auto;
        padding:16px 14px 72px;
      }
      .page::after{display:none}
      .login-card{
        width:min(560px,96vw);
        height:auto;
        min-height:auto;
        max-height:none;
        padding:28px 22px 24px;
        transform:none;
        border-radius:26px;
        border:1px solid rgba(97,210,255,.62);
      }
      .login-card::after{
        inset:10px;
        border-radius:20px;
        border:1px solid rgba(83,205,255,.12);
      }
      .top-actions,.side-card,.copyright{display:none}
    }

    @media(max-height:760px) and (min-width:1101px){
      .login-card{
        height:100dvh;
        min-height:620px;
        max-height:none;
        padding:18px 46px 16px;
        transform:none;
      }
      .brand-logo{max-height:135px;width:320px}
      .title{margin:4px 0 12px}
      .title h1{font-size:20px}
      .title p{font-size:12px}
      .input{height:50px}
      .login-btn{height:52px}
      .divider{margin:14px 0 10px}
      .bio{min-height:54px;padding:10px 13px}
      .secure-text{margin-top:12px}
    }
  </style>
</head>
<body>
  <div class="top-actions" aria-hidden="true">
    <span>☰ Menu</span>
    <span>◐</span>
    <span>🌙</span>
    <span>❔ Help</span>
    <span>📚 Manual</span>
  </div>

  <main class="page">
    <section class="login-card" aria-label="Login ERP RMI">
      <div class="brand">
        <img class="brand-logo" src="../assets/rmi-logo-gold-clean-transparent-final.png?v=final1" alt="Rizqullah Mediska Indonesia">
        <div class="brand-fallback">RMI</div>
      </div>

      <div class="title">
        <h1>Login To Access</h1>
        <p>ERP_RMI_SOFULL System</p>
      </div>

      <?php if ($error): ?>
        <div class="err"><?= htmlspecialchars((string)$error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" autocomplete="on">
        <?php if (function_exists('csrf_token')): ?>
          <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>

        <div class="field">
          <span class="ico" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.761 0 5-2.239 5-5s-2.239-5-5-5-5 2.239-5 5 2.239 5 5 5Zm0 2c-4.418 0-8 2.015-8 4.5V21h16v-2.5c0-2.485-3.582-4.5-8-4.5Z"/></svg>
          </span>
          <input class="input" id="l_username" name="username" type="text" autocomplete="username" required spellcheck="false" inputmode="text" placeholder="Username">
        </div>

        <div class="field">
          <span class="ico" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 24 24"><path d="M17 8V7a5 5 0 0 0-10 0v1H5v14h14V8h-2Zm-8 0V7a3 3 0 0 1 6 0v1H9Z"/></svg>
          </span>
          <input class="input" id="l_password" type="password" name="password" autocomplete="current-password" required placeholder="Password">
          <button type="button" class="pwd-toggle" id="l_password_toggle" aria-label="Tampilkan password" aria-pressed="false" title="Tampilkan / sembunyikan password">
            <svg class="pwd-icon-show" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="pwd-icon-hide" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" hidden><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="M2 2l20 20"/></svg>
          </button>
        </div>

        <div class="login-row">
          <label class="remember"><input type="checkbox" name="remember" value="1"> Remember me</label>
          <a class="forgot" href="#" onclick="return false;">Forgot Password?</a>
        </div>

        <button type="submit" class="login-btn">Login →</button>
      </form>

      <div class="divider">or</div>

      <div class="bio">
        <div class="bio-icon">▣</div>
        <div>
          <b>Login With Biometric</b>
          <span>Quick &amp; Secure Access</span>
        </div>
      </div>

      <div class="secure-text">Secure • Trusted • Innovative</div>
    </section>

    <aside class="side-card left">
      <b>🛡 Your Security<br>Our Priority</b>
      <p>Data is protected with enterprise-grade security and encrypted access.</p>
    </aside>

    <aside class="side-card right">
      <b><span class="status-dot"></span>System Status</b>
      <p><span class="secure-big">SECURE</span>All systems operational</p>
    </aside>

    <div class="copyright">© <?= date('Y') ?> RIZQULLAH MEDISKA INDONESIA. ALL RIGHTS RESERVED.</div>
  </main>

<script>
(function () {
  var inp = document.getElementById('l_password');
  var btn = document.getElementById('l_password_toggle');
  if (!inp || !btn) return;
  var iconShow = btn.querySelector('.pwd-icon-show');
  var iconHide = btn.querySelector('.pwd-icon-hide');
  btn.addEventListener('click', function () {
    var visible = inp.type === 'text';
    if (visible) {
      inp.type = 'password';
      btn.setAttribute('aria-pressed', 'false');
      btn.setAttribute('aria-label', 'Tampilkan password');
      if (iconShow) iconShow.removeAttribute('hidden');
      if (iconHide) iconHide.setAttribute('hidden', '');
    } else {
      inp.type = 'text';
      btn.setAttribute('aria-pressed', 'true');
      btn.setAttribute('aria-label', 'Sembunyikan password');
      if (iconShow) iconShow.setAttribute('hidden', '');
      if (iconHide) iconHide.removeAttribute('hidden');
    }
  });
})();
</script>
</body>
</html>
