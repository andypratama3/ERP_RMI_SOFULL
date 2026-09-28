<?php
declare(strict_types=1);

namespace App\Security;

use PDO;

final class MFAPolicyService
{
    public function isMfaRequired(PDO $pdo, string $role, string $department): bool
    {
        $role = strtoupper(trim($role));
        $department = strtoupper(trim($department));
        if ($role === '' && $department === '') {
            return false;
        }

        // Priority:
        // 1) exact role+dept
        // 2) role only
        // 3) dept only
        // 4) global
        $candidates = [
            [$role, $department],
            [$role, '*'],
            ['*', $department],
            ['*', '*'],
        ];

        foreach ($candidates as [$r, $d]) {
            $st = $pdo->prepare(
                "SELECT require_mfa
                 FROM auth_mfa_policies
                 WHERE role_code = ? AND dept_code = ? AND is_active = 1
                 LIMIT 1"
            );
            $st->execute([$r, $d]);
            $v = $st->fetchColumn();
            if ($v !== false) {
                return ((int)$v) === 1;
            }
        }
        return false;
    }
}
