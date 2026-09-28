<?php
declare(strict_types=1);

/**
 * Konvensi quartet CRUD — helper untuk kode baru (VIEW/CREATE/EDIT/DELETE).
 * Lihat docs/RBAC_CRUD_STANDARD.md dan docs/RBAC_CATALOG_BY_MODULE.md
 */

/** @var list<string> */
const RMI_RBAC_CRUD_SUFFIXES = ['VIEW', 'CREATE', 'EDIT', 'DELETE'];

/**
 * Bentuk empat permission dari prefix tanpa sufiks aksi.
 * Contoh: rmi_rbac_crud_codes('HRL.REQ_CUTI') → HRL.REQ_CUTI_VIEW, …
 *
 * @return array{VIEW:string,CREATE:string,EDIT:string,DELETE:string}
 */
function rmi_rbac_crud_codes(string $basePrefix): array
{
    $b = strtoupper(trim($basePrefix));
    $b = rtrim($b, '_');

    return [
        'VIEW' => $b . '_VIEW',
        'CREATE' => $b . '_CREATE',
        'EDIT' => $b . '_EDIT',
        'DELETE' => $b . '_DELETE',
    ];
}

/**
 * Daftar flat untuk require_any_permission (minimal salah satu VIEW untuk akses modul).
 *
 * @return list<string>
 */
function rmi_rbac_crud_codes_list(string $basePrefix): array
{
    return array_values(rmi_rbac_crud_codes($basePrefix));
}
