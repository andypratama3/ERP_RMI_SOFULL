<?php
declare(strict_types=1);

/**
 * rmi_sys_gate.php — satu pintu session-level untuk aksi "kebijakan / mutasi berbahaya".
 *
 * Prinsip (ERP_RMI_SOFULL):
 * - RBAC (*.VIEW, dll.) = siapa boleh buka halaman / menu.
 * - Level SYS di session = siapa boleh mengubah kebijakan / master KPI / config sensitif.
 * - Jangan mengandalkan permission generik semacam KPI.EDIT sebagai satu-satunya gate mutasi.
 *
 * Session: $_SESSION['level'] atau $_SESSION['role'] (canonical SYS | MANAGER | STAFF).
 */

if (!function_exists('rmi_session_canonical_level')) {
    function rmi_session_canonical_level(): string {
        $level = strtoupper(trim((string)($_SESSION['level'] ?? '')));
        if ($level !== '') {
            return $level;
        }
        $role = strtoupper(trim((string)($_SESSION['role'] ?? '')));
        if ($role !== '') {
            return $role;
        }
        return 'GUEST';
    }
}

if (!function_exists('rmi_is_sys_session')) {
    function rmi_is_sys_session(): bool {
        return rmi_session_canonical_level() === 'SYS';
    }
}

if (!function_exists('rmi_require_sys_session_or_json')) {
    /**
     * Untuk endpoint JSON: 403 + {"ok":false} jika bukan SYS.
     */
    function rmi_require_sys_session_or_json(string $error = 'Akses ditolak. Hanya SYS.'): void {
        if (rmi_is_sys_session()) {
            return;
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('rmi_require_sys_session_or_html')) {
    /**
     * Pesan HTML minimal jika bukan SYS (halaman legacy tanpa layout).
     */
    function rmi_require_sys_session_or_html(string $title = '403 — Akses Ditolak', string $bodyHtml = ''): void {
        if (rmi_is_sys_session()) {
            return;
        }
        http_response_code(403);
        $default = '<p>Hanya user level <strong>SYS</strong> yang dapat melakukan aksi ini.</p>'
            . '<p class="muted">Akses halaman tetap diatur lewat RBAC (mis. <code>*.VIEW</code>).</p>';
        echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</title></head><body style="font-family:sans-serif;padding:24px">'
            . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . ($bodyHtml !== '' ? $bodyHtml : $default)
            . '</body></html>';
        exit;
    }
}
