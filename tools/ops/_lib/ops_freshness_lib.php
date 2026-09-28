<?php
declare(strict_types=1);

require_once __DIR__ . '/ops_helpers.php';

if (!function_exists('ofr_root')) {
    function ofr_root(): string
    {
        return ops_root();
    }
}

if (!function_exists('ofr_sources')) {
    /** @return array<string, array{path:string,name:string}> */
    function ofr_sources(): array
    {
        $root = ofr_root();
        return [
            'contract_check' => ['path' => $root . '/storage/logs/contract_check_last.json', 'name' => 'contract_check_last.json'],
            'smoke_http' => ['path' => $root . '/storage/logs/smoke_http_last.json', 'name' => 'smoke_http_last.json'],
            'readiness' => ['path' => $root . '/storage/logs/readiness_report_last.json', 'name' => 'readiness_report_last.json'],
            'release_verify_all' => ['path' => $root . '/storage/state/release_verify_all_last.json', 'name' => 'release_verify_all_last.json'],
            'alerts_last' => ['path' => $root . '/storage/state/alerts_last.json', 'name' => 'alerts_last.json'],
            'ops_banner' => ['path' => $root . '/storage/state/ops_banner.json', 'name' => 'ops_banner.json'],
            'backup_last' => ['path' => $root . '/storage/state/backup_last.json', 'name' => 'backup_last.json'],
        ];
    }
}

if (!function_exists('ofr_thresholds')) {
    /** @return array{contract_smoke_readiness_fail_h:float,backup_warn_h:float,backup_fail_h:float} */
    function ofr_thresholds(string $env): array
    {
        $env = strtolower($env);
        if ($env === 'production') {
            return [
                'contract_smoke_readiness_fail_h' => 6.0,
                'backup_warn_h' => 24.0,
                'backup_fail_h' => 24.0,
            ];
        }
        return [
            'contract_smoke_readiness_fail_h' => 24.0,
            'backup_warn_h' => 24.0,
            'backup_fail_h' => 48.0,
        ];
    }
}

if (!function_exists('ofr_check_source')) {
    /**
     * @return array{name:string,path_masked:string,age_hours:float,status:string,exists:bool}
     */
    function ofr_check_source(string $key, array $cfg, float $ageHours, array $thresholds): array
    {
        $path = (string)($cfg['path'] ?? '');
        $name = (string)($cfg['name'] ?? $key);
        $exists = is_file($path);
        $status = 'OK';
        if (!$exists) {
            $status = 'FAIL';
        } elseif ($key === 'backup_last') {
            if ($ageHours > $thresholds['backup_fail_h']) {
                $status = 'FAIL';
            } elseif ($ageHours > $thresholds['backup_warn_h']) {
                $status = 'WARN';
            }
        } else {
            if ($ageHours > $thresholds['contract_smoke_readiness_fail_h']) {
                $status = 'FAIL';
            }
        }
        return [
            'name' => $name,
            'path_masked' => ops_mask($path),
            'age_hours' => round($ageHours, 2),
            'status' => $status,
            'exists' => $exists,
        ];
    }
}
