<?php
/**
 * CLI Capability — cek shell_exec/proc_open enabled.
 * Untuk halaman tools: jika disabled, tampilkan "jalankan via SSH" + baca artifact.
 */
declare(strict_types=1);

if (!function_exists('tools_can_shell')) {
    function tools_can_shell(): bool
    {
        $df = ini_get('disable_functions') ?: '';
        $list = array_map('trim', explode(',', $df));
        $disabled = array_filter($list, static fn(string $x): bool => $x !== '');
        if (in_array('shell_exec', $disabled, true) || in_array('proc_open', $disabled, true)) {
            return false;
        }
        if (function_exists('shell_exec')) {
            $r = @shell_exec('echo ok');
            if ($r === null && str_contains($df, 'shell_exec')) return false;
        }
        return true;
    }
}
