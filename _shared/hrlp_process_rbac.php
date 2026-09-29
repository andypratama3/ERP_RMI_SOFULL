<?php
declare(strict_types=1);
require_once __DIR__ . '/rmi_icons.php';

/**
 * RBAC HRL Process — izin per tipe pengajuan (CUTI, IZIN, …).
 *
 * - Permission granular: HRL.REQ_<TIPE>_{VIEW|CREATE|EDIT|DELETE}
 * - Kompatibilitas lama: HRL.PROCESS_* (semua tipe) — gunakan HRL.REQ_<TIPE>_CREATE untuk granular.
 */

/**
 * Metadata tipe (ikon/warna) — satu sumber kebenaran untuk tower & dokumen.
 *
 * @return array<string, array{label:string, icon:string, color:string}>
 */
function hrlp_request_types_meta(): array
{
    return [
        'CUTI'                => ['label' => 'Cuti', 'icon' => rmi_icon('sun'), 'color' => '#10b981'],
        'IZIN'                => ['label' => 'Izin', 'icon' => rmi_icon('clipboard'), 'color' => '#f59e0b'],
        'LEMBUR'              => ['label' => 'Lembur', 'icon' => rmi_icon('calendar'), 'color' => '#3b82f6'],
        'PERJADIN'            => ['label' => 'Perjadin (Form)', 'icon' => rmi_icon('outbox'), 'color' => '#8b5cf6'],
        'PERMINTAAN_KARYAWAN' => ['label' => 'Permintaan Karyawan', 'icon' => rmi_icon('box'), 'color' => '#ec4899'],
        'KENAIKAN_GAJI'       => ['label' => 'Kenaikan Gaji', 'icon' => rmi_icon('money'), 'color' => '#14b8a6'],
        'REKRUTMEN'           => ['label' => 'Rekrutmen', 'icon' => rmi_icon('users'), 'color' => '#f97316'],
    ];
}

/** @return list<string> */
function hrlp_request_type_codes(): array
{
    return array_keys(hrlp_request_types_meta());
}

function hrlp_req_type_perm(string $reqType, string $action): string
{
    return 'HRL.REQ_' . strtoupper(trim($reqType)) . '_' . strtoupper(trim($action));
}

/** True jika punya salah satu izin proses HRL “lebar” (sebelum pecah per tipe). */
function hrlp_has_legacy_hrl_process_perm(): bool
{
    if (!function_exists('can')) {
        return false;
    }
    return can('HRL.PROCESS_VIEW')
        || can('HRL.PROCESS_EDIT')
        || can('HRL.PROCESS_CREATE')
        || can('HRL.PROCESS_DELETE');
}

/**
 * Bisa membuka modul HRL Process (menu / bootstrap): legacy PROCESS_* atau minimal satu REQ_*_VIEW.
 */
function hrlp_can_enter_module(): bool
{
    if (function_exists('rbac_is_privileged_session') && rbac_is_privileged_session()) {
        return true;
    }
    if (!function_exists('can')) {
        return true;
    }
    if (hrlp_has_legacy_hrl_process_perm()) {
        return true;
    }
    foreach (hrlp_request_type_codes() as $t) {
        if (can(hrlp_req_type_perm($t, 'VIEW'))) {
            return true;
        }
    }
    return false;
}

/**
 * @param string $action VIEW|CREATE|EDIT|DELETE
 */
function hrlp_can_req_type(string $reqType, string $action): bool
{
    $reqType = strtoupper(trim($reqType));
    $action = strtoupper(trim($action));
    if ($reqType === '' || !in_array($reqType, hrlp_request_type_codes(), true)) {
        return false;
    }
    if (function_exists('rbac_is_privileged_session') && rbac_is_privileged_session()) {
        return true;
    }
    if (hrlp_has_legacy_hrl_process_perm()) {
        return true;
    }
    if (!function_exists('can')) {
        return false;
    }

    return can(hrlp_req_type_perm($reqType, $action));
}

/**
 * Daftar permission untuk sidebar / require_any_permission (salah satu cukup).
 *
 * @return list<string>
 */
function hrlp_process_menu_permissions(): array
{
    $out = [
        'HRL.PROCESS_VIEW',
        'HRL.PROCESS_EDIT',
        'HRL.PROCESS_CREATE',
        'HRL.PROCESS_DELETE',
    ];
    foreach (hrlp_request_type_codes() as $t) {
        $out[] = hrlp_req_type_perm($t, 'VIEW');
    }
    return $out;
}

/**
 * Permission untuk item sidebar per tipe tower (deep link).
 * User dengan legacy PROCESS_* atau salah satu REQ_<TIPE>_* untuk tipe tersebut melihat menu.
 *
 * @return list<string>
 */
function hrlp_req_type_nav_permissions(string $reqType): array
{
    $reqType = strtoupper(trim($reqType));
    if ($reqType === '' || !in_array($reqType, hrlp_request_type_codes(), true)) {
        return hrlp_process_menu_permissions();
    }
    $out = [
        'HRL.PROCESS_VIEW',
        'HRL.PROCESS_EDIT',
        'HRL.PROCESS_CREATE',
        'HRL.PROCESS_DELETE',
    ];
    foreach (['VIEW', 'CREATE', 'EDIT', 'DELETE'] as $act) {
        $out[] = hrlp_req_type_perm($reqType, $act);
    }

    return array_values(array_unique($out));
}
