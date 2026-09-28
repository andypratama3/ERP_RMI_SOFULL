<?php
declare(strict_types=1);

/**
 * Konfigurasi dokumen Struktur Organisasi (JSON).
 * - Default: _shared/org_structure.default.json
 * - Override runtime: storage/config/org_structure.json (NAS, writable)
 */
if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('org_structure_project_root')) {
    function org_structure_project_root(): string {
        return realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }
}

if (!function_exists('org_structure_default_path')) {
    function org_structure_default_path(): string {
        return org_structure_project_root() . '/_shared/org_structure.default.json';
    }
}

if (!function_exists('org_structure_config_path')) {
    function org_structure_config_path(): string {
        return org_structure_project_root() . '/storage/config/org_structure.json';
    }
}

if (!function_exists('org_structure_backup_dir')) {
    function org_structure_backup_dir(): string {
        return org_structure_project_root() . '/storage/backups/org_structure';
    }
}

if (!function_exists('org_structure_default_data')) {
    /**
     * @return array<string,mixed>
     */
    function org_structure_default_data(): array {
        $p = org_structure_default_path();
        if (!is_readable($p)) {
            return ['version' => 1, 'doc' => [], 'vision' => [], 'divisions' => [], 'notes' => []];
        }
        $raw = file_get_contents($p);
        if ($raw === false) {
            return ['version' => 1, 'doc' => [], 'vision' => [], 'divisions' => [], 'notes' => []];
        }
        $j = json_decode($raw, true);
        return is_array($j) ? $j : ['version' => 1, 'doc' => [], 'vision' => [], 'divisions' => [], 'notes' => []];
    }
}

if (!function_exists('org_structure_merge')) {
    /**
     * Gabungkan file user: array pengganti penuh untuk list (vision, divisions, …).
     *
     * @param array<string,mixed> $def
     * @param array<string,mixed> $ov
     * @return array<string,mixed>
     */
    function org_structure_merge(array $def, array $ov): array {
        $out = $def;
        if (isset($ov['doc']) && is_array($ov['doc'])) {
            $out['doc'] = array_merge($def['doc'] ?? [], $ov['doc']);
        }
        if (isset($ov['director']) && is_array($ov['director'])) {
            $out['director'] = array_merge($def['director'] ?? [], $ov['director']);
        }
        if (isset($ov['branch_block']) && is_array($ov['branch_block'])) {
            $bb = array_merge($def['branch_block'] ?? [], $ov['branch_block']);
            if (isset($ov['branch_block']['branches']) && is_array($ov['branch_block']['branches'])) {
                $bb['branches'] = $ov['branch_block']['branches'];
            }
            $out['branch_block'] = $bb;
        }
        foreach (['vision', 'divisions', 'notes', 'legend', 'signatures'] as $lk) {
            if (isset($ov[$lk]) && is_array($ov[$lk])) {
                $out[$lk] = $ov[$lk];
            }
        }
        if (isset($ov['version'])) {
            $out['version'] = $ov['version'];
        }
        if (array_key_exists('footer', $ov)) {
            $out['footer'] = (string)$ov['footer'];
        }
        return $out;
    }
}

if (!function_exists('org_structure_validate')) {
    /**
     * @param array<string,mixed> $d
     */
    function org_structure_validate(array $d): ?string {
        $v = $d['version'] ?? null;
        if (!is_int($v) && !is_numeric($v)) {
            return 'Field "version" wajib angka.';
        }
        if ((int)$v !== 1) {
            return 'Hanya schema version 1 yang didukung.';
        }
        if (empty($d['divisions']) || !is_array($d['divisions'])) {
            return 'Array "divisions" wajib diisi.';
        }
        if (count($d['divisions']) > 12) {
            return 'Maksimal 12 divisi.';
        }
        $allowedClass = [
            'div-fin', 'div-scm', 'div-sales', 'div-hrl', 'div-itc', 'div-branch', 'div-mfg',
        ];
        foreach ($d['divisions'] as $i => $div) {
            if (!is_array($div)) {
                return 'Divisi #' . ($i + 1) . ' format tidak valid.';
            }
            $bc = trim((string)($div['box_class'] ?? ''));
            if ($bc === '' || !in_array($bc, $allowedClass, true)) {
                return 'Divisi #' . ($i + 1) . ': box_class tidak valid (gunakan div-fin, div-scm, …).';
            }
            $title = (string)($div['title'] ?? '');
            if (mb_strlen($title) > 200) {
                return 'Divisi #' . ($i + 1) . ': judul terlalu panjang.';
            }
            $units = $div['units'] ?? null;
            if (!is_array($units) || $units === []) {
                return 'Divisi #' . ($i + 1) . ': "units" wajib array non-kosong.';
            }
            if (count($units) > 30) {
                return 'Divisi #' . ($i + 1) . ': terlalu banyak unit.';
            }
            foreach ($units as $u) {
                if (!is_string($u) || mb_strlen($u) > 400) {
                    return 'Divisi #' . ($i + 1) . ': tiap unit harus string (maks 400 char).';
                }
            }
        }
        $bb = $d['branch_block'] ?? null;
        if (is_array($bb)) {
            $br = $bb['branches'] ?? [];
            if (!is_array($br)) {
                return 'branch_block.branches harus array.';
            }
            if (count($br) > 24) {
                return 'Maksimal 24 cabang.';
            }
            foreach ($br as $j => $b) {
                if (!is_array($b)) {
                    return 'Cabang #' . ($j + 1) . ' format tidak valid.';
                }
                $code = preg_replace('/\s+/', '', (string)($b['code'] ?? ''));
                if ($code === '' || strlen($code) > 12 || !preg_match('/^[A-Za-z0-9_-]+$/', $code)) {
                    return 'Cabang #' . ($j + 1) . ': kode tidak valid.';
                }
                if (mb_strlen((string)($b['city'] ?? '')) > 200) {
                    return 'Cabang #' . ($j + 1) . ': nama kota terlalu panjang.';
                }
            }
        }
        foreach (['doc', 'director'] as $blk) {
            if (isset($d[$blk]) && is_array($d[$blk])) {
                foreach ($d[$blk] as $val) {
                    if (is_string($val) && mb_strlen($val) > 2000) {
                        return 'Teks di "' . $blk . '" terlalu panjang.';
                    }
                }
            }
        }
        return null;
    }
}

if (!function_exists('org_structure_load')) {
    /**
     * @return array<string,mixed>
     */
    function org_structure_load(): array {
        $def = org_structure_default_data();
        $path = org_structure_config_path();
        if (!is_readable($path)) {
            return $def;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $def;
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return $def;
        }
        return org_structure_merge($def, $j);
    }
}

if (!function_exists('org_structure_ensure_dirs')) {
    function org_structure_ensure_dirs(): void {
        $c = dirname(org_structure_config_path());
        if (!is_dir($c)) {
            @mkdir($c, 0775, true);
        }
        $b = org_structure_backup_dir();
        if (!is_dir($b)) {
            @mkdir($b, 0775, true);
        }
    }
}

if (!function_exists('org_structure_save')) {
    /**
     * Simpan JSON (validasi + backup + atomic write + audit DB).
     *
     * @param array<string,mixed> $data
     */
    function org_structure_save(array $data, PDO $pdo): array {
        $def = org_structure_default_data();
        $merged = org_structure_merge($def, $data);
        $err = org_structure_validate($merged);
        if ($err !== null) {
            return ['ok' => false, 'error' => $err];
        }
        org_structure_ensure_dirs();
        $path = org_structure_config_path();
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'error' => 'Gagal encode JSON.'];
        }
        if (strlen($json) > 512000) {
            return ['ok' => false, 'error' => 'Ukuran dokumen melebihi batas aman (512KB).'];
        }

        $backupDir = org_structure_backup_dir();
        if (is_file($path) && is_readable($path)) {
            $bak = $backupDir . '/org_structure_' . date('Ymd_His') . '.json';
            @copy($path, $bak);
            $rotate = glob($backupDir . '/org_structure_*.json') ?: [];
            rsort($rotate);
            foreach (array_slice($rotate, 30) as $old) {
                @unlink($old);
            }
        }

        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json) === false) {
            return ['ok' => false, 'error' => 'Gagal menulis file sementara.'];
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'Gagal menempatkan file konfigurasi (cek permission storage/config).'];
        }
        @chmod($path, 0664);

        if (function_exists('master_audit')) {
            master_audit($pdo, 'docs', 'org_structure_json', 'UPDATE', null, 'ORG_STRUCTURE', 'Struktur organisasi (JSON) diperbarui', [
                'bytes'     => strlen($json),
                'version'   => $merged['version'] ?? null,
                'path'      => $path,
            ]);
        }

        return ['ok' => true, 'error' => null];
    }
}
