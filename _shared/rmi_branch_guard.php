<?php
declare(strict_types=1);
/**
 * rmi_branch_guard.php — Helper pemblok akses dept BRANCH ke halaman sensitif.
 *
 * Gunakan setelah require_login() dan require_any_permission():
 *
 *   require_once __DIR__ . '/../_shared/rmi_branch_guard.php';
 *   rmi_block_branch('Laporan AP hanya untuk Dept FIN atau Admin.');
 */

if (!function_exists('rmi_is_admin_session')) {
    function rmi_is_admin_session(): bool
    {
        $role  = strtoupper((string)($_SESSION['role']       ?? ''));
        $level = strtoupper((string)($_SESSION['level']      ?? ''));
        // SYS = privileged canonical. ADMIN/SUPERADMIN = backward-compat.
        return in_array($role,  ['SYS', 'ADMIN', 'SUPERADMIN'], true)
            || in_array($level, ['SYS', 'ADMIN', 'SUPERADMIN'], true);
    }
}

if (!function_exists('rmi_session_dept')) {
    function rmi_session_dept(): string
    {
        $dept = strtoupper(trim((string)($_SESSION['department'] ?? '')));
        if ($dept === '' && function_exists('auth_user')) {
            try {
                $u = (array)auth_user();
                $dept = strtoupper(trim((string)($u['department'] ?? $u['dept'] ?? '')));
            } catch (Throwable $e) {
                $dept = '';
            }
        }
        return $dept;
    }
}

/**
 * Blok akses dept BRANCH (bukan Admin/Superadmin/SYS).
 * Hentikan eksekusi dengan HTTP 403 jika user adalah BRANCH.
 *
 * @param string $msg  Pesan yang ditampilkan ke user
 */
if (!function_exists('rmi_block_branch')) {
    function rmi_block_branch(string $msg = 'Halaman ini tidak tersedia untuk Dept BRANCH.'): void
    {
        if (!rmi_is_admin_session() && rmi_session_dept() === 'BRANCH') {
            http_response_code(403);
            // Tampilkan pesan yang ramah jika layout sudah loaded, fallback ke plain text
            if (function_exists('rmi_header')) {
                rmi_header('Akses Ditolak');
                echo '<div class="alert alert-danger m-4">'
                   . '<strong>403 Akses Ditolak</strong><br>'
                   . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')
                   . '</div>';
                if (function_exists('rmi_footer')) rmi_footer();
            } else {
                echo '<h3>Akses ditolak</h3><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            exit;
        }
    }
}

/**
 * Office code yang secara bisnis diperlakukan sebagai DEPO eksternal,
 * tetapi akun tetap memakai department BRANCH agar tidak membuat department baru.
 * Jangan pakai username sebagai sumber keputusan akses.
 */
if (!function_exists('rmi_depo_branch_office_codes')) {
    function rmi_depo_branch_office_codes(): array
    {
        return ['KAL', 'JGY'];
    }
}

if (!function_exists('rmi_session_office')) {
    function rmi_session_office(): string
    {
        $office = strtoupper(trim((string)($_SESSION['office_code'] ?? '')));
        if ($office === '' && function_exists('auth_user')) {
            try {
                $u = (array)auth_user();
                $office = strtoupper(trim((string)($u['office_code'] ?? '')));
            } catch (Throwable $e) {
                $office = '';
            }
        }
        return $office;
    }
}

/**
 * TRUE hanya untuk akun partner Depo yang memakai BRANCH + office KAL/JGY.
 * Branch internal (BGR/BDG/BKS/TGR/SLO/SMG/dll) tidak ikut kondisi ini.
 */
if (!function_exists('rmi_is_depo_branch_session')) {
    function rmi_is_depo_branch_session(): bool
    {
        if (rmi_is_admin_session()) return false;
        return rmi_session_dept() === 'BRANCH'
            && in_array(rmi_session_office(), rmi_depo_branch_office_codes(), true);
    }
}

if (!function_exists('rmi_depo_branch_office')) {
    function rmi_depo_branch_office(): string
    {
        return rmi_is_depo_branch_session() ? rmi_session_office() : '';
    }
}

