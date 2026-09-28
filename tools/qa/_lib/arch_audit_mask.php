<?php
declare(strict_types=1);

if (!function_exists('aa_root')) {
    function aa_root(): string
    {
        return realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    }
}

if (!function_exists('aa_mask_path')) {
    function aa_mask_path(string $path): string
    {
        $root = aa_root();
        $masked = str_replace($root, '[APP_ROOT]', $path);
        return $masked;
    }
}

if (!function_exists('aa_mask_snippet')) {
    function aa_mask_snippet(string $text): string
    {
        $patterns = [
            '/\/Users\/[^\s]+/i' => '[APP_ROOT]',
            '/C:\\\\[^\s]+/i' => '[APP_ROOT]',
            '/BEGIN PRIVATE KEY[\s\S]*?END PRIVATE KEY/i' => '[REDACTED]',
            '/Authorization:\s*[^\s]+/i' => '[REDACTED]',
            '/DB_PASS(?:WORD)?\s*=\s*[^\s&]+/i' => 'DB_PASS=[REDACTED]',
            '/password\s*=\s*["\']?[^\s"\']+/i' => 'password=[REDACTED]',
            '/token\s*=\s*["\']?[^\s"\']+/i' => 'token=[REDACTED]',
            '/api_key\s*=\s*["\']?[^\s"\']+/i' => 'api_key=[REDACTED]',
        ];
        $out = $text;
        foreach ($patterns as $pat => $repl) {
            $out = preg_replace($pat, $repl, $out) ?? $out;
        }
        return substr($out, 0, 200);
    }
}

if (!function_exists('aa_deny_patterns')) {
    /** @return string[] Patterns that MUST NOT appear in output */
    function aa_deny_patterns(): array
    {
        return [
            '/Users/',
            'C:\\',
            'BEGIN PRIVATE KEY',
            'Authorization:',  // raw header value must not appear
            'DB_PASS',
        ];
    }
}

if (!function_exists('aa_validate_output')) {
    function aa_validate_output(string $text): array
    {
        $violations = [];
        foreach (aa_deny_patterns() as $pat) {
            if (stripos($text, $pat) !== false) {
                $violations[] = 'deny_pattern:' . $pat;
            }
        }
        return $violations;
    }
}
