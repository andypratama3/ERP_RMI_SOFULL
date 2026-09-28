<?php
declare(strict_types=1);

/**
 * Generate docs/RBAC_CATALOG_BY_MODULE.md from config/rbac_permissions.php
 *
 * Usage (NAS / lokal):
 *   php tools/rbac/build_rbac_catalog_md.php
 *   php tools/rbac/build_rbac_catalog_md.php --out=/path/to/custom.md
 */

$root = dirname(__DIR__, 2);
$configPath = $root . '/config/rbac_permissions.php';
$defaultOut = $root . '/docs/RBAC_CATALOG_BY_MODULE.md';

$outPath = $defaultOut;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outPath = substr($arg, 6);
    }
}

if (!is_file($configPath)) {
    fwrite(STDERR, "Missing: {$configPath}\n");
    exit(1);
}

/** @var list<array{0:string,1:string,2:string,3:string}> $rows */
$rows = require $configPath;
if (!is_array($rows)) {
    fwrite(STDERR, "Config must return array\n");
    exit(1);
}

/**
 * Klasifikasi orientasi CRUD (target konsistensi Accurate-like: VIEW/CREATE/EDIT/DELETE).
 */
function rmi_catalog_classify(string $code): string
{
    $u = strtoupper(trim($code));
    if ($u === '') {
        return 'OTHER';
    }
    if (str_contains($u, '.CRUD') || str_ends_with($u, '_CRUD')) {
        return 'CRUD (alias · setara VIEW+CREATE+EDIT+DELETE)';
    }
    if (str_ends_with($u, '_DELETE') || str_ends_with($u, '.DELETE')) {
        return 'DELETE';
    }
    if (str_ends_with($u, '_CREATE') || str_ends_with($u, '.CREATE')) {
        return 'CREATE';
    }
    if (str_ends_with($u, '_EDIT') || str_ends_with($u, '.EDIT')) {
        return 'EDIT';
    }
    if (str_ends_with($u, '_VIEW') || str_ends_with($u, '.VIEW')) {
        return 'VIEW';
    }
    if (str_ends_with($u, '_READ') || str_ends_with($u, '.READ') || str_contains($u, '_READ')) {
        return 'VIEW (READ alias)';
    }
    if (preg_match('/(APPROVE|POST|SUBMIT|LOCK|PAID|FINALIZE|VOID|CANCEL)/', $u)) {
        return 'WORKFLOW (map ke EDIT saat migrasi)';
    }
    if (preg_match('/(EXPORT|IMPORT|PRINT|UPLOAD|DOWNLOAD)/', $u)) {
        return 'EXPORT/IMPORT (map: VIEW atau EDIT)';
    }
    if (preg_match('/(AUDIT|RECAP|REPORT|MONITOR|LOG)/', $u)) {
        return 'AUDIT / REPORT (map: VIEW)';
    }
    if (preg_match('/(MANAGE|SETTINGS|ADMIN|CONFIG|KEYS|BYPASS)/', $u)) {
        return 'ADMIN / MANAGE (map: EDIT atau SYS)';
    }
    if (preg_match('/(CHECKIN|CLOCK_|REQUEST[^_]|PIN)/', $u)) {
        return 'OPERASIONAL (spesifik modul)';
    }

    return 'OTHER / legacy';
}

$byMod = [];
foreach ($rows as $row) {
    if (!is_array($row) || count($row) < 4) {
        continue;
    }
    $mod = strtoupper(trim((string) $row[2]));
    if ($mod === '') {
        $mod = 'UNKNOWN';
    }
    $byMod[$mod][] = [
        'code' => (string) $row[0],
        'name' => (string) $row[1],
        'desc' => (string) $row[3],
        'class' => rmi_catalog_classify((string) $row[0]),
    ];
}
ksort($byMod);

$total = 0;
foreach ($byMod as $list) {
    $total += count($list);
}

$date = gmdate('Y-m-d H:i:s') . ' UTC';
$buf = <<<MD
# Katalog RBAC per modul (generated)

**Sumber data:** `config/rbac_permissions.php`  
**Dibuat:** {$date}  
**Total baris:** {$total} permission  

> Regenerate: `php tools/rbac/build_rbac_catalog_md.php`

---

## Ringkas: pola CRUD (selaras HRL Process / Accurate-like)

| Kelas | Arti singkat |
|-------|----------------|
| **VIEW** | Baca / buka halaman / list / detail / print read-only |
| **CREATE** | Tambah baris / dokumen baru / draft |
| **EDIT** | Ubah data / submit workflow / approve / posting non-final |
| **DELETE** | Hapus / void / soft-delete (risiko tinggi) |
| **CRUD (alias)** | Satu kode men-cover beberapa aksi — disarankan diganti quartet bertahap |
| **WORKFLOW / OTHER** | Kode khusus; saat normalisasi dipetakan ke **EDIT** (approval) atau **VIEW** |

Dokumen aturan lengkap: [`RBAC_CRUD_STANDARD.md`](./RBAC_CRUD_STANDARD.md) · Panduan permission: [`RBAC_PERMISSION_GUIDE.md`](./RBAC_PERMISSION_GUIDE.md)

---

## Daftar isi (modul)

MD;

foreach (array_keys($byMod) as $m) {
    $anchor = preg_replace('/[^A-Z0-9]+/', '-', $m);
    $buf .= '- [' . $m . '](#module-' . strtolower((string) $anchor) . ') — ' . count($byMod[$m]) . " permission\n";
}

$buf .= "\n---\n\n";

foreach ($byMod as $mod => $list) {
    $anchor = 'module-' . strtolower((string) preg_replace('/[^A-Z0-9]+/', '-', $mod));
    usort($list, static fn($a, $b) => strcmp($a['class'], $b['class']) ?: strcmp($a['code'], $b['code']));
    $buf .= "## `{$mod}` {#{$anchor}}\n\n";
    $buf .= "| Kelas CRUD | `perm_code` | Nama (UI) | Deskripsi |\n";
    $buf .= "|---|---|---|---|\n";
    foreach ($list as $r) {
        $cls = $r['class'];
        $code = str_replace('|', '\\|', $r['code']);
        $name = str_replace('|', '\\|', $r['name']);
        $desc = str_replace(["\r", "\n", '|'], ['', ' ', '\\|'], $r['desc']);
        $buf .= '| ' . $cls . ' | `' . $code . '` | ' . $name . ' | ' . $desc . " |\n";
    }
    $buf .= "\n";
}

if (@file_put_contents($outPath, $buf) === false) {
    fwrite(STDERR, "Write failed: {$outPath}\n");
    exit(1);
}

echo "Wrote {$outPath} ({$total} permissions, " . count($byMod) . " modules)\n";
