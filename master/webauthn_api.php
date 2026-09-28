<?php
declare(strict_types=1);

/**
 * WebAuthn JSON API — registration & authentication.
 * Endpoints: ?action=register_options | register_verify | auth_options | auth_verify
 */
@ob_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../_shared/app_init.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db_pdo();
require_once __DIR__ . '/schema_mfa.php';
schema_ensure_mfa_columns($pdo);
schema_ensure_webauthn_credentials($pdo);

$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));
$input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST ?: [];
$verbose = isset($_GET['verbose']) || isset($input['verbose']);

function webauthn_json(array $data): void
{
    @ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function webauthn_err(string $msg, int $code = 400, array $extra = []): void
{
    global $verbose;
    $data = ['ok' => false, 'error' => $msg ?: 'Unknown error'];
    if (!empty($verbose) && !empty($extra)) {
        $data['debug'] = $extra;
    }
    webauthn_json($data);
}

// --- Register options (enroll) — requires login ---
if ($action === 'register_options') {
    require_login();
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $username = (string)($_SESSION['username'] ?? '');
    $fullName = (string)($_SESSION['full_name'] ?? $username);
    if ($uid <= 0) {
        webauthn_err('Login required');
    }
    $svc = new \App\Security\WebAuthnService();
    if (!$svc->isAvailable()) {
        webauthn_err('WebAuthn not available');
    }
    $result = $svc->getCreateArgs($uid, $username, $fullName);
    if ($result === null) {
        webauthn_err('Failed to generate registration options');
    }
    $_SESSION['_webauthn_register_challenge'] = $result['challenge'];
    webauthn_json(['ok' => true, 'createArgs' => $result['createArgs']]);
}

// --- Register verify (complete enroll) ---
if ($action === 'register_verify') {
    try {
        require_login();
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            webauthn_err('Login required');
        }
        $challenge = $_SESSION['_webauthn_register_challenge'] ?? '';
        if ($challenge === '') {
            webauthn_err('Session expired. Please try again.');
        }
        unset($_SESSION['_webauthn_register_challenge']);
        $clientDataJSON = $input['clientDataJSON'] ?? '';
        $attestationObject = $input['attestationObject'] ?? '';
        if ($clientDataJSON === '' || $attestationObject === '') {
            webauthn_err('Invalid registration data');
        }
        $svc = new \App\Security\WebAuthnService();
        if (!$svc->isAvailable()) {
            webauthn_err('WebAuthn not available');
        }
        $clientDataJSON = base64_decode(strtr($clientDataJSON, '-_', '+/'), true) ?: $clientDataJSON;
        $attestationObject = base64_decode(strtr($attestationObject, '-_', '+/'), true) ?: $attestationObject;
        $data = $svc->processCreate($clientDataJSON, $attestationObject, $challenge);
        if ($data === null || isset($data['error'])) {
            $err = $data['error'] ?? 'Registration verification failed';
            if (stripos($err, 'invalid origin') !== false) {
                $err .= ' — Coba akses via http://localhost/... (bukan 127.0.0.1), atau gunakan HTTPS.';
            }
            webauthn_err($err, 400, ['phase' => 'processCreate']);
        }
    } catch (\Throwable $e) {
        webauthn_err('Server error: ' . $e->getMessage(), 400, ['exception' => get_class($e), 'trace' => $e->getTraceAsString()]);
    }
    $st = $pdo->prepare("SELECT id FROM auth_webauthn_credentials WHERE credential_id = ? LIMIT 1");
    $st->execute([$data['credentialId']]);
    if ($st->fetch()) {
        webauthn_err('Credential already registered');
    }
    $st = $pdo->prepare(
        "INSERT INTO auth_webauthn_credentials (user_id, credential_id, public_key, sign_count, aaguid, friendly_name)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $st->execute([
        $uid,
        $data['credentialId'],
        $data['publicKey'],
        (int)($data['signCount'] ?? 0),
        $data['aaguid'] ?? null,
        trim((string)($input['friendlyName'] ?? 'Passkey')),
    ]);
    webauthn_json(['ok' => true, 'msg' => 'Passkey registered']);
}

// --- Auth options (login/MFA verify) — uses _mfa_pending session ---
if ($action === 'auth_options') {
    $pending = $_SESSION['_mfa_pending'] ?? null;
    if (!is_array($pending) || empty($pending['user'])) {
        webauthn_err('MFA session required');
    }
    $userId = (int)($pending['user']['id'] ?? 0);
    $st = $pdo->prepare("SELECT credential_id FROM auth_webauthn_credentials WHERE user_id = ?");
    $st->execute([$userId]);
    $credIds = $st->fetchAll(PDO::FETCH_COLUMN);
    if (empty($credIds)) {
        webauthn_err('No passkeys registered for this account');
    }
    $svc = new \App\Security\WebAuthnService();
    if (!$svc->isAvailable()) {
        webauthn_err('WebAuthn not available');
    }
    $result = $svc->getGetArgs($credIds);
    if ($result === null) {
        webauthn_err('Failed to generate auth options');
    }
    $_SESSION['_webauthn_auth_challenge'] = $result['challenge'] ?? '';
    if ($_SESSION['_webauthn_auth_challenge'] === '') {
        webauthn_err('Challenge missing');
    }
    webauthn_json(['ok' => true, 'getArgs' => $result['getArgs']]);
}

// --- Auth verify (complete MFA) ---
if ($action === 'auth_verify') {
    $pending = $_SESSION['_mfa_pending'] ?? null;
    if (!is_array($pending) || empty($pending['user'])) {
        webauthn_err('MFA session required');
    }
    $user = $pending['user'];
    $resp = $input['response'] ?? [];
    $credentialId = $input['credentialId'] ?? $input['rawId'] ?? $input['id'] ?? '';
    $clientDataJSON = $input['clientDataJSON'] ?? $resp['clientDataJSON'] ?? '';
    $authenticatorData = $input['authenticatorData'] ?? $resp['authenticatorData'] ?? '';
    $signature = $input['signature'] ?? $resp['signature'] ?? '';
    if ($credentialId === '' || $clientDataJSON === '' || $authenticatorData === '' || $signature === '') {
        webauthn_err('Invalid assertion data');
    }
    $credentialId = base64_decode(strtr($credentialId, '-_', '+/'), true) ?: $credentialId;
    $credentialIdB64 = base64_encode($credentialId);
    $st = $pdo->prepare("SELECT public_key, sign_count FROM auth_webauthn_credentials WHERE user_id = ? AND credential_id = ? LIMIT 1");
    $st->execute([(int)$user['id'], $credentialIdB64]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        webauthn_err('Credential not found');
    }
    $challenge = $_SESSION['_webauthn_auth_challenge'] ?? '';
    unset($_SESSION['_webauthn_auth_challenge']);
    if ($challenge === '') {
        webauthn_err('Session expired');
    }
    $clientDataJSON = base64_decode(strtr($clientDataJSON, '-_', '+/'), true) ?: $clientDataJSON;
    $authenticatorData = base64_decode(strtr($authenticatorData, '-_', '+/'), true) ?: $authenticatorData;
    $signature = base64_decode(strtr($signature, '-_', '+/'), true) ?: $signature;
    $svc = new \App\Security\WebAuthnService();
    if (!$svc->isAvailable()) {
        webauthn_err('WebAuthn not available');
    }
    $valid = $svc->processGet(
        $clientDataJSON,
        $authenticatorData,
        $signature,
        $row['public_key'],
        $challenge,
        (int)$row['sign_count']
    );
    if (!$valid) {
        webauthn_err('Verification failed');
    }
    $pdo->prepare("UPDATE auth_webauthn_credentials SET sign_count = sign_count + 1 WHERE user_id = ? AND credential_id = ?")
        ->execute([(int)$user['id'], $credentialIdB64]);
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
    $next = safe_next($_GET['next'] ?? ($pending['next'] ?? ''), $fallback_home ?? 'index.php');
    $bp = (isset($BASE_PROJECT) ? $BASE_PROJECT : '') ?: '';
    $path = function_exists('auth_post_login_landing_path')
        ? auth_post_login_landing_path((string)($user['department'] ?? ''))
        : auth_landing_path_for_dept((string)($user['department'] ?? ''));
    $target = $next !== ($fallback_home ?? '') ? $next : ($bp . $path);
    webauthn_json(['ok' => true, 'redirect' => $target]);
}

webauthn_err('Invalid action', 400);
