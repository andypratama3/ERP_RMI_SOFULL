<?php
declare(strict_types=1);

if (!function_exists('tools_get_base_url')) {
    function tools_get_base_url(): string
    {
        $raw = trim((string)(getenv('TOOLS_BASE_URL') ?: ''));
        if ($raw === '') {
            return '';
        }
        return rtrim($raw, '/');
    }
}

if (!function_exists('tools_validate_base_url')) {
    function tools_validate_base_url(): array
    {
        $url = tools_get_base_url();
        if ($url === '') {
            return [
                'valid' => false,
                'base_url' => '',
                'error_code' => 'ERR_TOOLS_BASE_URL_INVALID',
                'message' => 'Set env TOOLS_BASE_URL, contoh: https://example.com/ERP_RMI_SOFULL',
            ];
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return [
                'valid' => false,
                'base_url' => $url,
                'error_code' => 'ERR_TOOLS_BASE_URL_INVALID',
                'message' => 'TOOLS_BASE_URL harus dimulai http:// atau https://',
            ];
        }
        return [
            'valid' => true,
            'base_url' => $url,
            'error_code' => '',
            'message' => 'OK',
        ];
    }
}
