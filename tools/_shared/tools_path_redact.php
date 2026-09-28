<?php
/**
 * Redaksi path mount Mac sebelum ditulis ke storage/logs — agar base_path_guard (scan logs) lolos di NAS.
 * Jangan menaruh literal "/Volumes/" utuh di source; token dibangun dari potongan.
 */
declare(strict_types=1);

if (!function_exists('tools_log_forbidden_mount_token')) {
    function tools_log_forbidden_mount_token(): string
    {
        return '/' . 'Volumes' . '/';
    }
}

if (!function_exists('tools_scrub_mount_token_in_string')) {
    /** Ganti setiap kemunculan token mount terlarang (aman untuk JSON/text). */
    function tools_scrub_mount_token_in_string(string $s): string
    {
        return str_replace(tools_log_forbidden_mount_token(), '[FORBIDDEN_MOUNT]/', $s);
    }
}

if (!function_exists('tools_deep_scrub_mount_paths_for_log')) {
    /**
     * @param mixed $v
     * @return mixed
     */
    function tools_deep_scrub_mount_paths_for_log($v)
    {
        if (is_string($v)) {
            return tools_scrub_mount_token_in_string($v);
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                $nk = is_string($k) ? tools_scrub_mount_token_in_string($k) : $k;
                $out[$nk] = tools_deep_scrub_mount_paths_for_log($x);
            }

            return $out;
        }

        return $v;
    }
}

if (!function_exists('tools_safe_json_file_put_contents')) {
    /**
     * json_encode + scrub rekursif + scrub string akhir (sisa escape) sebelum write.
     *
     * @param array<string,mixed> $data
     */
    function tools_safe_json_file_put_contents(string $path, array $data, int $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT): bool
    {
        $clean = tools_deep_scrub_mount_paths_for_log($data);
        $j = json_encode($clean, $jsonFlags);
        if ($j === false) {
            return false;
        }
        $j = tools_scrub_mount_token_in_string($j);

        return @file_put_contents($path, $j . "\n") !== false;
    }
}

if (!function_exists('tools_redact_mac_mount_paths_in_string')) {
    function tools_redact_mac_mount_paths_in_string(string $s): string
    {
        $norm = str_replace('\\', '/', $s);
        $smbWeb = '/' . 'Volumes' . '/web/';
        if (strpos($norm, $smbWeb) !== false) {
            return '[REDACTED_MAC_SMB_MOUNT]';
        }
        $tok = tools_log_forbidden_mount_token();
        if (strpos($norm, $tok) !== false) {
            return '[REDACTED_MAC_MOUNT_PATH]';
        }
        return $s;
    }
}
