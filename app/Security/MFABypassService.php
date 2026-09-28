<?php
declare(strict_types=1);

namespace App\Security;

use PDO;

final class MFABypassService
{
    public function hasActiveBypass(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $st = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM auth_mfa_bypass_tickets
                 WHERE user_id = ?
                   AND is_active = 1
                   AND status = 'APPROVED'
                   AND expires_at > NOW()"
            );
            $st->execute([$userId]);
            return ((int)$st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            // backward compatibility before status column exists
            $st = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM auth_mfa_bypass_tickets
                 WHERE user_id = ?
                   AND is_active = 1
                   AND expires_at > NOW()"
            );
            $st->execute([$userId]);
            return ((int)$st->fetchColumn()) > 0;
        }
    }
}
