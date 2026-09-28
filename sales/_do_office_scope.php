<?php
declare(strict_types=1);
/**
 * _do_office_scope.php
 * Helper: deteksi apakah user saat ini adalah BRANCH staff,
 * dan tentukan office scope yang harus diterapkan ke query sales_do.
 *
 * Usage (di awal setiap task file, setelah require auth):
 *   require_once __DIR__ . '/_do_office_scope.php';
 *
 * Variabel yang tersedia setelah include:
 *   $DO_SCOPE_OFFICE   : string|null  — null = lihat semua; 'SMG' = scope ke SMG
 *   $DO_SCOPE_IS_BRANCH: bool         — true jika user adalah BRANCH staff
 *
 * Functions:
 *   do_scope_where(string $alias='d'): array{sql:string, params:array}
 *     Returns SQL clause + params untuk ditambahkan ke WHERE query sales_do.
 *     Contoh: ["AND d.office_code = ?", ["SMG"]]
 *
 *   do_scope_assert_row(array $doRow): void
 *     Melempar Exception jika user BRANCH mencoba akses DO kantor lain.
 */
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!isset($DO_SCOPE_OFFICE)) {
    $_dso_user   = function_exists('auth_user') ? auth_user() : [];
    $_dso_dept   = strtoupper(trim((string)($_dso_user['department'] ?: $_dso_user['level'] ?: '')));
    $_dso_role   = strtoupper(trim((string)($_dso_user['role'] ?? '')));
    $_dso_office = strtoupper(trim((string)($_dso_user['office_code'] ?? '')));

    $DO_SCOPE_IS_BRANCH = in_array($_dso_dept, ['BRANCH'], true)
                       && !in_array($_dso_role, ['SYS','ADMIN','SUPERADMIN'], true)
                       && $_dso_office !== '';

    $DO_SCOPE_OFFICE = $DO_SCOPE_IS_BRANCH ? $_dso_office : null;
}

if (!function_exists('do_scope_where')) {
    function do_scope_where(string $alias = 'd'): array {
        global $DO_SCOPE_OFFICE;
        if ($DO_SCOPE_OFFICE === null) return ['sql' => '', 'params' => []];
        $col = $alias !== '' ? "{$alias}.office_code" : 'office_code';
        return ['sql' => " AND {$col} = ?", 'params' => [$DO_SCOPE_OFFICE]];
    }
}

if (!function_exists('do_scope_assert_row')) {
    /**
     * Cek kepemilikan DO. Lempar Exception jika BRANCH mencoba akses DO kantor lain.
     * @param array $doRow — harus punya key 'office_code'
     */
    function do_scope_assert_row(array $doRow): void {
        global $DO_SCOPE_OFFICE;
        if ($DO_SCOPE_OFFICE === null) return;
        $rowOffice = strtoupper(trim((string)($doRow['office_code'] ?? '')));
        if ($rowOffice !== '' && $rowOffice !== $DO_SCOPE_OFFICE) {
            throw new RuntimeException(
                "Akses ditolak: DO ini milik kantor {$rowOffice}, bukan kantor Anda ({$DO_SCOPE_OFFICE})."
            );
        }
    }
}
