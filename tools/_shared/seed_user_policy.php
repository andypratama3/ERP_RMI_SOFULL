<?php
/**
 * seed_user_policy.php — CLI seed/reset password may only touch test-only users.
 *
 * Allowed:
 *   - Username prefix (case-insensitive): Smoke, qa_, uat_
 *   - Explicit RBAC matrix smoke personas (non-Smoke* names used in matrix HTTP checks)
 */
declare(strict_types=1);

if (!function_exists('rmi_tools_seed_username_allowed')) {
    function rmi_tools_seed_username_allowed(string $username): bool {
        static $matrix = [
            'MgrFIN_BGR',
            'StaffFIN_BGR',
            'MgrFIN_BDG',
            'StaffWQS_BGR',
            'StaffCRM_BGR',
            'MgrITC_BGR',
        ];
        $u = trim($username);
        if ($u === '') {
            return false;
        }
        if (preg_match('/^(Smoke|qa_|uat_)/i', $u) === 1) {
            return true;
        }
        return in_array($u, $matrix, true);
    }
}
