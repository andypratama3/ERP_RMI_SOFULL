<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class ChatMentionService
{
    /**
     * @return array<int,string>
     */
    public function parseMentionUsernames(string $text): array
    {
        if ($text === '') {
            return [];
        }
        preg_match_all('/(^|\s)@([a-zA-Z0-9._-]{2,50})/u', $text, $m);
        $names = [];
        foreach ($m[2] ?? [] as $u) {
            $u = strtolower(trim((string)$u));
            $u = rtrim($u, ".,;:!?");
            if ($u !== '') {
                $names[$u] = true;
            }
        }
        return array_keys($names);
    }

    /**
     * @param array<int,string> $usernames
     * @return array<int,array{id:int,username:string,full_name:string}>
     */
    public function resolveUsersByUsername(PDO $pdo, array $usernames, int $limit = 50): array
    {
        $usernames = array_values(array_unique(array_map(static fn($v) => strtolower(trim((string)$v)), $usernames)));
        $usernames = array_values(array_filter($usernames, static fn($v) => $v !== ''));
        if (!$usernames) {
            return [];
        }
        $limit = max(1, min(100, $limit));

        $holders = implode(',', array_fill(0, count($usernames), '?'));
        $rows = [];
        try {
            $sql = "SELECT id, username, COALESCE(full_name, username) AS full_name
                    FROM master_system_login
                    WHERE LOWER(username) IN ($holders)
                    LIMIT $limit";
            $st = $pdo->prepare($sql);
            $st->execute($usernames);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $rows = [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)($r['id'] ?? 0),
                'username' => (string)($r['username'] ?? ''),
                'full_name' => (string)($r['full_name'] ?? ''),
            ];
        }
        return $out;
    }
}

