<?php
/**
 * Chat Diagnostic - cek skema DB dan test create channel
 * Akses: /tools/chat_diag.php (perlu login)
 */
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/../_shared/db.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = rmi_db_pdo();

echo "=== CHAT DIAGNOSTIC ===\n\n";

// 1. Cek kolom chat_channels
echo "1. CHAT_CHANNELS columns:\n";
$st = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS 
  WHERE table_schema=DATABASE() AND table_name='chat_channels' ORDER BY ORDINAL_POSITION");
$cols = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($cols as $c) {
    echo "   - " . $c['COLUMN_NAME'] . " (" . $c['DATA_TYPE'] . ")\n";
}
$hasType = in_array('type', array_column($cols, 'COLUMN_NAME'), true);
$hasChannelType = in_array('channel_type', array_column($cols, 'COLUMN_NAME'), true);
echo "   Has 'type': " . ($hasType ? 'YES' : 'NO') . "\n";
echo "   Has 'channel_type': " . ($hasChannelType ? 'YES' : 'NO') . "\n\n";

// 2. Cek kolom chat_channel_members
echo "2. CHAT_CHANNEL_MEMBERS columns:\n";
$st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS 
  WHERE table_schema=DATABASE() AND table_name='chat_channel_members' ORDER BY ORDINAL_POSITION");
$memCols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
echo "   " . implode(', ', $memCols) . "\n";
$hasUsername = in_array('username', $memCols, true);
echo "   Has 'username': " . ($hasUsername ? 'YES' : 'NO') . "\n\n";

// 3. Cek tabel mentions
echo "3. MENTIONS table:\n";
$st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('chat_mentions','chat_message_mentions')");
$cnt = (int)$st->fetchColumn();
$st = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('chat_mentions','chat_message_mentions')");
$tables = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
echo "   Found: " . implode(', ', $tables) . "\n\n";

// 4. Test create channel (dry run - rollback)
echo "4. TEST CREATE CHANNEL (rollback):\n";
$uid = (int)($_SESSION['user_id'] ?? 0);
$role = strtoupper(trim((string)($_SESSION['level'] ?? $_SESSION['role'] ?? '')));
$isAdmin = in_array($role, ['ADMIN', 'SUPERADMIN'], true);
echo "   user_id: $uid, role: $role, isAdmin: " . ($isAdmin ? 'YES' : 'NO') . "\n";

if (!$isAdmin) {
    echo "   SKIP: Hanya ADMIN/SUPERADMIN yang bisa create channel.\n";
} else {
    try {
        $pdo->beginTransaction();
        $testName = 'diag_test_' . time();
        if ($hasChannelType && !$hasType) {
            $ins = $pdo->prepare("INSERT INTO chat_channels (channel_type,name,created_by,created_at) VALUES ('PUBLIC',?,?,NOW())");
            $ins->execute([$testName, (string)$uid]);
        } else {
            $ins = $pdo->prepare("INSERT INTO chat_channels (type,name,created_by,created_at,is_private) VALUES ('CHANNEL',?,?,NOW(),0)");
            $ins->execute([$testName, $uid > 0 ? $uid : null]);
        }
        $cid = (int)$pdo->lastInsertId();
        echo "   INSERT chat_channels: OK (id=$cid)\n";
        $username = 'user' . $uid;
        if ($hasUsername) {
            $u = $pdo->prepare("SELECT username FROM master_system_login WHERE id=? LIMIT 1");
            $u->execute([$uid]);
            $un = $u->fetchColumn();
            if ($un) $username = trim((string)$un);
            $ins2 = $pdo->prepare("INSERT INTO chat_channel_members (channel_id, user_id, username, joined_at, last_read_message_id) VALUES (?,?,?,NOW(),0)");
            $ins2->execute([$cid, $uid, $username]);
        } else {
            $ins2 = $pdo->prepare("INSERT INTO chat_channel_members (channel_id, user_id, joined_at, last_read_message_id) VALUES (?,?,NOW(),0)");
            $ins2->execute([$cid, $uid]);
        }
        echo "   INSERT chat_channel_members: OK\n";
        $pdo->rollBack();
        echo "   ROLLBACK: OK (test berhasil, tidak ada data tersimpan)\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "   ERROR: " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}

echo "\n5. REKOMENDASI:\n";
if ($hasChannelType && !$hasType) {
    echo "   Skema 096 terdeteksi (channel_type tanpa type).\n";
    echo "   Jalankan migrasi: mysql -u USER -p DATABASE < sql/migrations/104_chat_channels_legacy_compat.sql\n";
    echo "   Atau via phpMyAdmin: import file 104_chat_channels_legacy_compat.sql\n";
} else {
    echo "   Skema sudah kompatibel.\n";
}

echo "\n=== DONE ===\n";
