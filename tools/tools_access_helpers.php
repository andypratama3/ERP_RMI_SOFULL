<?php
declare(strict_types=1);

require_once __DIR__ . '/tools_access_matrix.php';

if (!function_exists('tools_current_actor_username')) {
    function tools_current_actor_username(): string
    {
        $u = trim((string)($_SESSION['username'] ?? ''));
        if ($u !== '') {
            return $u;
        }
        $envUser = trim((string)(getenv('USER') ?: ''));
        return $envUser !== '' ? $envUser : 'SYSTEM';
    }
}

if (!function_exists('tools_current_role')) {
    function tools_current_role(): string
    {
        $role = trim((string)($_SESSION['role'] ?? $_SESSION['level'] ?? ''));
        return strtoupper($role);
    }
}

if (!function_exists('tools_matrix_entry')) {
    function tools_matrix_entry(string $toolId): array
    {
        $m = tools_access_matrix();
        $entry = (array)($m[$toolId] ?? []);
        if (!$entry) {
            // Safe default: unknown tools remain admin-only.
            $entry = [
                'title' => $toolId,
                'tags' => ['WEB', 'READ'],
                'web_roles_allowed' => ['ADMIN', 'SUPERADMIN'],
                'cli_roles_allowed' => ['ADMIN', 'SUPERADMIN'],
                'owner' => 'Platform Team',
                'description' => 'Auto-guarded fallback entry',
            ];
        }
        return $entry;
    }
}

if (!function_exists('tools_is_allowed_for_roles')) {
    function tools_is_allowed_for_roles(array $allowed, ?string $role = null): bool
    {
        if (!$allowed) {
            return true;
        }
        $r = strtoupper(trim((string)($role ?? tools_current_role())));
        return in_array($r, array_map('strtoupper', $allowed), true);
    }
}

if (!function_exists('tools_can_access_web_tool')) {
    function tools_can_access_web_tool(string $toolId): bool
    {
        $entry = tools_matrix_entry($toolId);
        $allowed = (array)($entry['web_roles_allowed'] ?? []);
        return tools_is_allowed_for_roles($allowed);
    }
}

if (!function_exists('tools_require_access')) {
    function tools_require_access(string $toolId): void
    {
        $entry = tools_matrix_entry($toolId);
        $webAllowed = (array)($entry['web_roles_allowed'] ?? []);
        $tags = array_map('strtoupper', (array)($entry['tags'] ?? []));

        if (PHP_SAPI !== 'cli') {
            if (function_exists('require_login')) {
                require_login();
            }
            if (function_exists('require_role') && $webAllowed) {
                require_role($webAllowed);
            }
            return;
        }

        $appEnv = strtolower((string)(getenv('APP_ENV') ?: 'local'));
        $argv = $_SERVER['argv'] ?? [];
        $isMutating = in_array('MUTATING', $tags, true);
        $flagOk = in_array('--i-understand', $argv, true);
        if ($isMutating && in_array($appEnv, ['prod', 'production'], true) && !$flagOk) {
            fwrite(STDERR, "Blocked in production. Use --i-understand.\n");
            exit(1);
        }
    }
}
