<?php
/**
 * Chat context channels (DO / PO / AP) — deterministic private channel + seed SYSTEM message.
 * Mutations only via chat_context_ensure_channel(); use chat_context_find_channel_id() for read-only GET.
 */
declare(strict_types=1);

if (!function_exists('chat_context_build_index_url')) {
    function chat_context_build_index_url(string $baseProject, array $query = []): string
    {
        $base = rtrim(str_replace('\\', '/', $baseProject), '/');
        $path = ($base === '' ? '' : $base) . '/chat/index.php';
        if ($query === []) {
            return $path;
        }
        return $path . '?' . http_build_query($query);
    }
}

if (!function_exists('chat_context_detect_channel_schema')) {
    /**
     * @return array{has_type:bool, has_channel_type:bool, uid_col:string}
     */
    function chat_context_detect_channel_schema(PDO $pdo): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='type'");
        $hasType = (int)$st->fetchColumn() > 0;
        $st2 = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='channel_type'");
        $hasChannelType = (int)$st2->fetchColumn() > 0;
        $st3 = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_messages' AND column_name='user_id'");
        $uidCol = (int)$st3->fetchColumn() > 0 ? 'user_id' : 'sender_user_id';
        $cache = ['has_type' => $hasType, 'has_channel_type' => $hasChannelType, 'uid_col' => $uidCol];

        return $cache;
    }
}

if (!function_exists('chat_context_ctx_name')) {
    function chat_context_ctx_name(string $entityType, int $entityId): string
    {
        return 'ctx-' . strtolower($entityType) . '-' . $entityId;
    }
}

if (!function_exists('chat_context_doc_link')) {
    function chat_context_doc_link(string $entityType, int $entityId): string
    {
        return match (strtoupper($entityType)) {
            'DO' => 'sales/sales_do_view.php?id=' . $entityId,
            'PO' => 'purchases/purchases_po_view.php?id=' . $entityId,
            default => 'purchases/purchases_invoice_ap_edit.php?id=' . $entityId,
        };
    }
}

if (!function_exists('chat_context_has_master_employees')) {
    function chat_context_has_master_employees(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $st = $pdo->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'master_employees' LIMIT 1");
            $cache = (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            $cache = false;
        }

        return $cache;
    }
}

if (!function_exists('chat_context_resolve_pic')) {
    /**
     * PIC untuk mention di pesan konteks: username dari login; nama tampilan prioritas
     * master_employees.employee_name (via holder_employee_code), lalu master_system_login.full_name.
     *
     * @return array{username:string, full_name:string, employee_name:string, display_name:string}
     */
    function chat_context_resolve_pic(PDO $pdo, string $entityType, int $entityId): array
    {
        $empty = ['username' => '', 'full_name' => '', 'employee_name' => '', 'display_name' => ''];
        $entityType = strtoupper($entityType);
        $joinEmp = chat_context_has_master_employees($pdo);
        $empJoin = $joinEmp
            ? 'LEFT JOIN master_employees e ON e.employee_code = m.holder_employee_code AND LOWER(COALESCE(e.status,\'active\'))=\'active\''
            : '';
        $empSel = $joinEmp ? 'e.employee_name AS employee_name' : 'NULL AS employee_name';

        $docOffice = '';
        if ($entityType === 'DO') {
            $st = $pdo->prepare('SELECT office_code, status_act, status_fin, status_scm, status_wqs FROM sales_do WHERE id=? LIMIT 1');
            $st->execute([$entityId]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if ($doc === false) {
                return $empty;
            }
            $docOffice = strtoupper(trim((string)($doc['office_code'] ?? '')));
            $dept = 'ACT';
            if (strtolower((string)($doc['status_fin'] ?? '')) === 'pending') {
                $dept = 'FIN';
            }
            if (strtolower((string)($doc['status_scm'] ?? '')) === 'pending') {
                $dept = 'SCM';
            }
            if (strtolower((string)($doc['status_wqs'] ?? '')) === 'pending') {
                $dept = 'WQS';
            }
            $picStmt = $pdo->prepare("
                SELECT m.username, m.full_name, {$empSel}
                FROM master_system_login m
                {$empJoin}
                WHERE LOWER(COALESCE(m.status,'active'))='active'
                  AND UPPER(COALESCE(m.department,''))=?
                  AND (?='' OR UPPER(COALESCE(m.office_code,''))=?)
                ORDER BY CASE UPPER(COALESCE(m.level,'')) WHEN 'MANAGER' THEN 1 WHEN 'STAFF' THEN 2 ELSE 9 END, m.id ASC
                LIMIT 1
            ");
            $picStmt->execute([$dept, $docOffice, $docOffice]);
        } elseif ($entityType === 'PO') {
            $st = $pdo->prepare('SELECT office_code FROM purchases_po WHERE id=? LIMIT 1');
            $st->execute([$entityId]);
            $poRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($poRow === false) {
                return $empty;
            }
            $docOffice = strtoupper(trim((string)($poRow['office_code'] ?? '')));
            $picStmt = $pdo->prepare("
                SELECT m.username, m.full_name, {$empSel}
                FROM master_system_login m
                {$empJoin}
                WHERE LOWER(COALESCE(m.status,'active'))='active'
                  AND UPPER(COALESCE(m.department,'')) IN ('PQP','SCM')
                  AND (?='' OR UPPER(COALESCE(m.office_code,''))=?)
                ORDER BY CASE UPPER(COALESCE(m.level,'')) WHEN 'MANAGER' THEN 1 WHEN 'STAFF' THEN 2 ELSE 9 END, m.id ASC
                LIMIT 1
            ");
            $picStmt->execute([$docOffice, $docOffice]);
        } else {
            $st = $pdo->prepare('SELECT office_code FROM purchases_invoice_ap WHERE id=? LIMIT 1');
            $st->execute([$entityId]);
            $apRow = $st->fetch(PDO::FETCH_ASSOC);
            if ($apRow === false) {
                return $empty;
            }
            $docOffice = strtoupper(trim((string)($apRow['office_code'] ?? '')));
            $picStmt = $pdo->prepare("
                SELECT m.username, m.full_name, {$empSel}
                FROM master_system_login m
                {$empJoin}
                WHERE LOWER(COALESCE(m.status,'active'))='active'
                  AND UPPER(COALESCE(m.department,''))='FIN'
                  AND (?='' OR UPPER(COALESCE(m.office_code,''))=?)
                ORDER BY CASE UPPER(COALESCE(m.level,'')) WHEN 'MANAGER' THEN 1 WHEN 'STAFF' THEN 2 ELSE 9 END, m.id ASC
                LIMIT 1
            ");
            $picStmt->execute([$docOffice, $docOffice]);
        }
        $row = $picStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $username = trim((string)($row['username'] ?? ''));
        $fullName = trim((string)($row['full_name'] ?? ''));
        $empName = trim((string)($row['employee_name'] ?? ''));
        $display = $empName !== '' ? $empName : $fullName;

        return [
            'username' => $username,
            'full_name' => $fullName,
            'employee_name' => $empName,
            'display_name' => $display,
        ];
    }
}

if (!function_exists('chat_context_document_exists')) {
    function chat_context_document_exists(PDO $pdo, string $entityType, int $entityId): bool
    {
        $entityType = strtoupper($entityType);
        if ($entityType === 'DO') {
            $chk = $pdo->prepare('SELECT 1 FROM sales_do WHERE id=? LIMIT 1');
            $chk->execute([$entityId]);

            return (bool)$chk->fetchColumn();
        }
        if ($entityType === 'PO') {
            $chk = $pdo->prepare('SELECT 1 FROM purchases_po WHERE id=? LIMIT 1');
            $chk->execute([$entityId]);

            return (bool)$chk->fetchColumn();
        }
        if ($entityType === 'AP') {
            $chk = $pdo->prepare('SELECT 1 FROM purchases_invoice_ap WHERE id=? LIMIT 1');
            $chk->execute([$entityId]);

            return (bool)$chk->fetchColumn();
        }

        return false;
    }
}

if (!function_exists('chat_context_offer_label')) {
    function chat_context_offer_label(string $entityType, int $entityId): string
    {
        return strtoupper($entityType) . ' #' . $entityId;
    }
}

if (!function_exists('chat_context_find_channel_id')) {
    function chat_context_find_channel_id(PDO $pdo, string $entityType, int $entityId): int
    {
        $ctxName = chat_context_ctx_name($entityType, $entityId);
        $sch = chat_context_detect_channel_schema($pdo);
        if (!$sch['has_type'] && !$sch['has_channel_type']) {
            return 0;
        }
        $key = strtolower($ctxName);
        if ($sch['has_type']) {
            $sc = $pdo->prepare("SELECT id FROM chat_channels WHERE type='CHANNEL' AND LOWER(COALESCE(name,''))=? LIMIT 1");
            $sc->execute([$key]);

            return (int)$sc->fetchColumn();
        }
        $sc = $pdo->prepare("SELECT id FROM chat_channels WHERE channel_type IN ('PUBLIC','PRIVATE') AND LOWER(COALESCE(name,''))=? LIMIT 1");
        $sc->execute([$key]);

        return (int)$sc->fetchColumn();
    }
}

if (!function_exists('chat_context_config_get')) {
    /**
     * Baca chat_config (dengan cache per-request). Fail-soft jika tabel/kolom belum ada.
     */
    function chat_context_config_get(PDO $pdo, string $key, string $default = ''): string
    {
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $st = $pdo->prepare('SELECT config_value FROM chat_config WHERE config_key = ? LIMIT 1');
            $st->execute([$key]);
            $v = trim((string)$st->fetchColumn());

            return $cache[$key] = ($v !== '' ? $v : $default);
        } catch (Throwable $e) {
            return $cache[$key] = $default;
        }
    }
}

if (!function_exists('chat_context_sanitize_display_name')) {
    function chat_context_sanitize_display_name(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($name) > 120 ? mb_substr($name, 0, 120) . '…' : $name;
        }

        return strlen($name) > 120 ? substr($name, 0, 120) . '…' : $name;
    }
}

if (!function_exists('chat_context_format_pic_mention')) {
    /**
     * @param string $format parentheses | comma | dash (hanya dipakai jika $includeDisplayName dan $fullName tidak kosong)
     */
    function chat_context_format_pic_mention(string $username, string $fullName, bool $includeDisplayName, string $format = 'parentheses'): string
    {
        if ($username === '') {
            return '';
        }
        $fullName = chat_context_sanitize_display_name($fullName);
        $useName = $includeDisplayName && $fullName !== '';
        if (!$useName) {
            return '@' . $username . ' ';
        }
        $format = strtolower(trim($format));
        if (!in_array($format, ['parentheses', 'comma', 'dash'], true)) {
            $format = 'parentheses';
        }

        return match ($format) {
            'comma' => '@' . $username . ', ' . $fullName . ' ',
            'dash' => '@' . $username . ' — ' . $fullName . ' ',
            default => '@' . $username . ' (' . $fullName . ') ',
        };
    }
}

if (!function_exists('chat_context_ensure_channel')) {
    /**
     * Create channel/member/seed message if needed. Idempotent for existing channel.
     *
     * @return array{ok:bool, channel_id:int, user_message:string}
     */
    function chat_context_ensure_channel(PDO $pdo, int $userId, string $entityType, int $entityId): array
    {
        $entityType = strtoupper($entityType);
        if (!in_array($entityType, ['DO', 'PO', 'AP'], true) || $entityId <= 0) {
            return ['ok' => false, 'channel_id' => 0, 'user_message' => 'Jenis dokumen atau ID tidak valid.'];
        }

        $sch = chat_context_detect_channel_schema($pdo);
        if (!$sch['has_type'] && !$sch['has_channel_type']) {
            return ['ok' => false, 'channel_id' => 0, 'user_message' => 'Skema chat belum siap (kolom channel). Hubungi administrator.'];
        }

        if (!chat_context_document_exists($pdo, $entityType, $entityId)) {
            $msg = $entityType === 'DO' ? 'Delivery Order tidak ditemukan.' : ($entityType === 'PO' ? 'Purchase Order tidak ditemukan.' : 'Invoice AP tidak ditemukan.');

            return ['ok' => false, 'channel_id' => 0, 'user_message' => $msg];
        }

        $ctxName = chat_context_ctx_name($entityType, $entityId);
        $link = chat_context_doc_link($entityType, $entityId);
        $pic = chat_context_resolve_pic($pdo, $entityType, $entityId);

        $channelId = chat_context_find_channel_id($pdo, $entityType, $entityId);

        if ($channelId <= 0) {
            if ($sch['has_type']) {
                $ins = $pdo->prepare("INSERT INTO chat_channels (type,name,created_by,created_at,is_private) VALUES ('CHANNEL',?,?,NOW(),1)");
                $ins->execute([$ctxName, $userId]);
            } else {
                $ins = $pdo->prepare("INSERT INTO chat_channels (channel_type,name,created_by,created_at) VALUES ('PRIVATE',?,?,NOW())");
                $ins->execute([$ctxName, (string)$userId]);
            }
            $channelId = (int)$pdo->lastInsertId();
        }

        if ($channelId <= 0) {
            return ['ok' => false, 'channel_id' => 0, 'user_message' => 'Gagal membuat channel. Coba lagi atau hubungi administrator.'];
        }

        $cm = $pdo->prepare('INSERT INTO chat_channel_members (channel_id,user_id,joined_at,last_read_message_id) VALUES (?,?,NOW(),0) ON DUPLICATE KEY UPDATE joined_at=joined_at');
        $cm->execute([$channelId, $userId]);

        $chk = $pdo->prepare("SELECT id FROM chat_messages WHERE channel_id=? AND sender_username='SYSTEM' ORDER BY id ASC LIMIT 1");
        $chk->execute([$channelId]);
        if ((int)$chk->fetchColumn() <= 0) {
            $includeName = chat_context_config_get($pdo, 'context_include_employee_name', '1') === '1';
            $nameFormat = chat_context_config_get($pdo, 'context_employee_name_format', 'parentheses');
            $mention = chat_context_format_pic_mention($pic['username'], $pic['display_name'], $includeName, $nameFormat);
            $msg = $mention . 'Context opened: ' . $entityType . ':' . $entityId . ' [' . $link . ']';
            $uidCol = $sch['uid_col'];
            $im = $pdo->prepare("INSERT INTO chat_messages (channel_id,{$uidCol},sender_username,message_text,created_at,is_deleted) VALUES (?,0,'SYSTEM',?,NOW(),0)");
            $im->execute([$channelId, $msg]);
        }

        return ['ok' => true, 'channel_id' => $channelId, 'user_message' => ''];
    }
}

if (!function_exists('chat_context_log_error')) {
    function chat_context_log_error(Throwable $e, string $entityType, int $entityId, string $phase): void
    {
        $msg = '[chat_context] ' . $phase . ' type=' . $entityType . ' id=' . $entityId . ' err=' . $e->getMessage();
        if (function_exists('rmi_log_module_error')) {
            rmi_log_module_error('chat_context', $e, ['entity_type' => $entityType, 'entity_id' => $entityId, 'phase' => $phase]);
        } else {
            error_log($msg);
        }
    }
}
