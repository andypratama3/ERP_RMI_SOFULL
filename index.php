<?php
/**
 * Root entry — guest redirect ke login (302).
 * ERP: GET / untuk guest harus redirect, bukan 200.
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
require_once __DIR__ . '/master/auth.php';

$hasUser = isset($_SESSION['user']) || isset($_SESSION['username']);
if ($hasUser) {
    $base = auth_base_project();
    rmi_redirect($base . '/dashboards/index.php');
}

// API clients (Accept: application/json) expect 401 JSON, not redirect.
// Mobile Quality Gate: auth/me without token must return ERR_UNAUTHENTICATED 401.
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
$isApiPath = (strpos($uri, '/api/v1/mobile/') !== false) || (strpos($uri, '/api/v1/') !== false);
if ($isApiPath && (strpos($accept, 'application/json') !== false)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'code' => 'ERR_UNAUTHENTICATED',
        'message' => 'Missing bearer token.',
        'request_id' => trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '')),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$base = auth_base_project();
$redirectTo = $base . '/master/login.php';
$next = $_SERVER['REQUEST_URI'] ?? '';
$sep = (strpos($redirectTo, '?') !== false) ? '&' : '?';
rmi_redirect($redirectTo . $sep . 'next=' . urlencode($next));
