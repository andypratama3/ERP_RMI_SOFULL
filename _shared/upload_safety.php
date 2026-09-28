<?php
/**
 * _shared/upload_safety.php
 *
 * Central helpers for safer file uploads.
 *
 * Goal (minimal-risk retrofit):
 * - Sanitize all uploaded filenames (avoid path traversal / weird chars)
 * - Block obviously-dangerous executable extensions (.php, .phtml, .phar, ...)
 *
 * IMPORTANT:
 * - This does NOT magically make uploads fully safe. For production-hardening you still want:
 *   allowlisted extensions + MIME verification + storage outside webroot + download-through-PHP.
 */

if (!function_exists('safe_filename')) {
    /**
     * Convert a user-supplied filename into a safer basename.
     */
    function safe_filename(string $name): string
    {
        $name = trim($name);
        // Drop any directory components
        $name = basename(str_replace("\\0", '', $name));

        // Replace spaces and control chars
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = str_replace([' ', "\t", "\r", "\n"], '_', $name);

        // Keep only a conservative set
        $name = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $name);
        $name = preg_replace('/_+/', '_', $name);
        $name = trim($name, '._-');

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'upload_' . date('Ymd_His');
        }

        // Limit length (avoid filesystem issues)
        if (strlen($name) > 180) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $base = substr($name, 0, 180);
            $base = rtrim($base, '._-');
            if ($ext !== '' && strlen($ext) < 12 && !str_ends_with($base, '.' . $ext)) {
                $base .= '.' . $ext;
            }
            $name = $base;
        }

        // Block obviously executable extensions (defense-in-depth)
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $blocked = [
            'php','phtml','phar','php3','php4','php5','php7','php8',
            'cgi','pl','asp','aspx','jsp','sh','bat','cmd','com','exe'
        ];
        if ($ext !== '' && in_array($ext, $blocked, true)) {
            // Keep original as text-ish to prevent execution
            $name .= '.txt';
        }

        return $name;
    }
}

if (!function_exists('rmi_sanitize_uploads')) {
    /**
     * Sanitize $_FILES array in-place (supports single + multi upload structures).
     */
    function rmi_sanitize_uploads(array &$files): void
    {
        foreach ($files as $key => &$file) {
            if (!is_array($file)) {
                continue;
            }
            if (!array_key_exists('name', $file)) {
                continue;
            }
            $file['name'] = rmi_sanitize_name_field($file['name']);
        }
        unset($file);
    }

    /**
     * @param mixed $nameField
     * @return mixed
     */
    function rmi_sanitize_name_field($nameField)
    {
        if (is_array($nameField)) {
            $out = [];
            foreach ($nameField as $k => $v) {
                $out[$k] = rmi_sanitize_name_field($v);
            }
            return $out;
        }

        return safe_filename((string)$nameField);
    }
}
