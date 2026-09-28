<?php
declare(strict_types=1);

function rmi_login_denial_reason(?array $user): ?string
{
    if (!$user) return 'invalid';
    $statusRaw = strtoupper(trim((string)($user['status'] ?? '')));
    $isInactive = ($statusRaw !== '' && $statusRaw !== 'ACTIVE');
    $isDeleted = !empty($user['deleted_at']);
    if ($isDeleted) return 'deleted';
    if ($isInactive) return 'inactive';
    return null;
}

/**
 * Cek apakah username termasuk akun built-in admin/superadmin
 * yang perlu dibatasi aksesnya berdasarkan IP.
 * Hanya berlaku untuk: admin, superadmin (case-insensitive).
 * TIDAK berlaku untuk SYS user lain (RizqullahMediskaSYS, SmokeSYS_SYS, dll).
 */
function rmi_is_builtin_admin_username(string $username): bool
{
    return in_array(strtolower(trim($username)), ['admin', 'superadmin'], true);
}

/**
 * Cek apakah IP client diizinkan untuk login sebagai akun built-in admin/superadmin.
 * Whitelist dibaca dari env ADMIN_BUILTIN_ALLOWED_IPS (pisah koma).
 * Support: IP tunggal (10.10.60.21) atau CIDR subnet (10.10.60.0/24).
 * Jika env kosong/tidak diset → restriksi TIDAK aktif (semua IP diizinkan).
 */
function rmi_builtin_admin_ip_allowed(string $ip): bool
{
    $list = '';
    if (function_exists('rmi_env')) {
        $list = (string)(rmi_env('ADMIN_BUILTIN_ALLOWED_IPS') ?: '');
    }
    if ($list === '') {
        $list = (string)(getenv('ADMIN_BUILTIN_ALLOWED_IPS') ?: ($_ENV['ADMIN_BUILTIN_ALLOWED_IPS'] ?? ''));
    }

    // Jika tidak dikonfigurasi → tidak aktif, izinkan semua
    if (trim($list) === '') return true;

    $allowed = array_filter(array_map('trim', explode(',', $list)));
    foreach ($allowed as $entry) {
        if (strpos($entry, '/') !== false) {
            // CIDR notation (misal: 10.10.60.0/24)
            if (rmi_ip_in_cidr($ip, $entry)) return true;
        } else {
            // IP tunggal — exact match
            if ($ip === $entry) return true;
        }
    }
    return false;
}

/**
 * Cek apakah sebuah IP masuk dalam range CIDR.
 * Mendukung IPv4 saja (IPv6 CIDR tidak umum di skenario ini).
 */
function rmi_ip_in_cidr(string $ip, string $cidr): bool
{
    if (strpos($ip, ':') !== false) return false; // IPv6, skip CIDR check
    [$subnet, $bits] = explode('/', $cidr, 2);
    $bits = (int)$bits;
    if ($bits < 0 || $bits > 32) return false;
    $ipLong     = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) return false;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

