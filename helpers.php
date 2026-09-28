<?php
/**
 * Root shim — jangan menambahkan fungsi di sini.
 *
 * Satu sumber implementasi: `_shared/helpers.php` (rmi_h, rmi_redirect, CSRF, dll.).
 * File ini hanya:
 * - guard akses langsung ke /helpers.php (web)
 * - require ke _shared agar path lama / skrip luar tetap jalan
 *
 * Lihat: docs/internal/CANONICAL_PATHS_AND_DUPLICATES.md
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    $sf = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($sf && realpath(__FILE__) === $sf) {
        require_once __DIR__ . '/master/auth.php';
        if (function_exists('require_login')) {
            require_login();
        }
        http_response_code(404);
        exit;
    }
}

require_once __DIR__ . '/_shared/helpers.php';
