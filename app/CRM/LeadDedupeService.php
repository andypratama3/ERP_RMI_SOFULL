<?php
declare(strict_types=1);

namespace App\CRM;

use PDO;

final class LeadDedupeService
{
    public function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9+]/', '', trim($phone));
        if ($digits === null) {
            return '';
        }
        $digits = ltrim($digits);
        if ($digits === '') {
            return '';
        }
        if (strpos($digits, '00') === 0) {
            $digits = '+' . substr($digits, 2);
        }
        if (strpos($digits, '0') === 0) {
            $digits = '+62' . substr($digits, 1);
        }
        if ($digits[0] !== '+' && strpos($digits, '62') === 0) {
            $digits = '+' . $digits;
        }
        return $digits;
    }

    public function findDuplicate(PDO $pdo, string $normalizedEmail, string $normalizedPhone): ?array
    {
        if ($normalizedEmail === '' && $normalizedPhone === '') {
            return null;
        }
        $sql = "SELECT id, lead_no, status, lead_name, company_name
                FROM crm_leads
                WHERE deleted_at IS NULL
                  AND ((normalized_email <> '' AND normalized_email = ?)
                    OR (normalized_phone <> '' AND normalized_phone = ?))
                ORDER BY id DESC
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$normalizedEmail, $normalizedPhone]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

