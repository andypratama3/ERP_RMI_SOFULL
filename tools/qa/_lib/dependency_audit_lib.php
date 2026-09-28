<?php
declare(strict_types=1);

if (!function_exists('daud_root')) {
    function daud_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('daud_mask')) {
    function daud_mask(string $value): string
    {
        if (function_exists('tools_mask_sensitive')) {
            $v = tools_mask_sensitive($value);
        } else {
            $v = preg_replace('/\/Users\/[^\s]+/', '[APP_ROOT]', $value);
        }
        return function_exists('ts_mask') ? ts_mask($v) : $v;
    }
}

if (!function_exists('daud_composer_available')) {
    function daud_composer_available(): bool
    {
        $out = [];
        $code = 1;
        @exec('composer --version 2>/dev/null', $out, $code);
        return (int)$code === 0;
    }
}

if (!function_exists('daud_run_composer_audit')) {
    /**
     * @return array{ok:bool,output_masked:string,vulns:array}
     */
    function daud_run_composer_audit(string $root): array
    {
        $vulns = [];
        $output = [];
        $code = 1;
        $cwd = getcwd();
        @chdir($root);
        @exec('composer audit --no-interaction --format=json 2>&1', $output, $code);
        if ($cwd !== false) @chdir($cwd);
        $raw = implode("\n", $output);
        $decoded = @json_decode($raw, true);
        if (is_array($decoded)) {
            $advs = $decoded['advisories'] ?? [];
            if (is_array($advs)) {
                foreach ($advs as $pkg => $adv) {
                    $adv = is_array($adv) ? $adv : [];
                    $vulns[] = [
                        'package' => daud_mask((string)($adv['package'] ?? (is_string($pkg) ? $pkg : 'unknown'))),
                        'advisory' => daud_mask((string)($adv['advisoryId'] ?? '')),
                        'severity' => daud_mask((string)($adv['severity'] ?? 'unknown')),
                    ];
                }
            }
        }
        if ($vulns === [] && (int)$code !== 0 && $raw !== '') {
            $vulns[] = [
                'package' => 'N/A',
                'advisory' => 'AUDIT_FAILED',
                'severity' => 'unknown',
                'message' => 'composer audit found issues. Run: composer audit --no-interaction',
            ];
        }
        $outputMasked = daud_mask(implode(' | ', array_slice($output, -5)));
        return [
            'ok' => ((int)$code === 0),
            'output_masked' => $outputMasked,
            'vulns' => $vulns,
        ];
    }
}
