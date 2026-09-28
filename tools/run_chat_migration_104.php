<?php
/**
 * Jalankan migrasi 104_chat_channels_legacy_compat.sql
 * Menambah kolom type, is_private, dm_user_low, dm_user_high ke chat_channels (skema 096)
 */
declare(strict_types=1);

require_once __DIR__ . '/../master/auth.php';
require_login();
require_once __DIR__ . '/../_shared/db.php';

$base = function_exists('auth_base_project') ? auth_base_project() : '';
$role = strtoupper(trim((string)($_SESSION['level'] ?? $_SESSION['role'] ?? '')));
if (!in_array($role, ['ADMIN', 'SUPERADMIN'], true)) {
    rmi_redirect($base . '/tools/index.php');
}

$run = isset($_POST['run']) && $_POST['run'] === '1';
if ($run && function_exists('verify_csrf')) {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
}

$pdo = rmi_db_pdo();
$result = ['ok' => false, 'msg' => '', 'steps' => []];

if ($run) {
    try {
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='type'");
        $hasTypeNow = (int)$st->fetchColumn() > 0;
        $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='channel_type'");
        $hasChannelTypeNow = (int)$st->fetchColumn() > 0;
        if (!$hasChannelTypeNow) {
            throw new RuntimeException('Tabel chat_channels tidak punya channel_type. Jalankan migrasi 096 dulu.');
        }
        if ($hasTypeNow) {
            $result['ok'] = true;
            $result['msg'] = 'Kolom type sudah ada. Tidak perlu migrasi.';
        } else {
            $pdo->exec("ALTER TABLE chat_channels ADD COLUMN type VARCHAR(20) NULL AFTER id");
            $pdo->exec("UPDATE chat_channels SET type = CASE WHEN channel_type IN ('PUBLIC','PRIVATE') THEN 'CHANNEL' WHEN channel_type = 'DM' THEN 'DM' ELSE 'CHANNEL' END WHERE type IS NULL AND channel_type IS NOT NULL");
            $result['steps'][] = 'type: added';
            $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='is_private'");
            if ((int)$st->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE chat_channels ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0");
                $pdo->exec("UPDATE chat_channels SET is_private = 1 WHERE channel_type IN ('PRIVATE','DM')");
                $result['steps'][] = 'is_private: added';
            }
            $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='dm_user_low'");
            if ((int)$st->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE chat_channels ADD COLUMN dm_user_low BIGINT NULL");
                $result['steps'][] = 'dm_user_low: added';
            }
            $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels' AND column_name='dm_user_high'");
            if ((int)$st->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE chat_channels ADD COLUMN dm_user_high BIGINT NULL");
                $result['steps'][] = 'dm_user_high: added';
            }
            $pdo->exec("UPDATE chat_channels c SET dm_user_low = (SELECT MIN(user_id) FROM chat_channel_members WHERE channel_id = c.id), dm_user_high = (SELECT MAX(user_id) FROM chat_channel_members WHERE channel_id = c.id) WHERE (c.channel_type = 'DM' OR c.type = 'DM') AND (dm_user_low IS NULL OR dm_user_high IS NULL) AND (SELECT COUNT(*) FROM chat_channel_members WHERE channel_id = c.id) >= 2");
            $result['ok'] = true;
            $result['msg'] = 'Migrasi 104 berhasil. Kolom legacy ditambahkan.';
        }
    } catch (Throwable $e) {
        $result['msg'] = $e->getMessage();
        $result['steps'][] = 'Error: ' . $e->getFile() . ':' . $e->getLine();
    }
}

// Cek status
$hasType = false;
$hasChannelType = false;
try {
    $st = $pdo->query("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='chat_channels'");
    $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hasType = in_array('type', $cols, true);
    $hasChannelType = in_array('channel_type', $cols, true);
} catch (Throwable $e) {
    $result['steps'][] = 'Cek kolom gagal: ' . $e->getMessage();
}

$h = function_exists('h') ? 'h' : function($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Chat Migration 104</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <p class="mb-2"><a href="<?= $h($base . '/tools/index.php') ?>">← Tools</a></p>
  <h4>Chat Migration 104 - Legacy Compatibility</h4>
  <p class="text-secondary">Menambah kolom <code>type</code>, <code>is_private</code>, <code>dm_user_low</code>, <code>dm_user_high</code> ke tabel chat_channels agar Create Channel berfungsi dengan skema 096.</p>

  <div class="mb-3">
    <strong>Status skema:</strong>
    <ul class="mb-0">
      <li>Kolom <code>type</code>: <?= $hasType ? '✓ Ada' : '✗ Belum ada' ?></li>
      <li>Kolom <code>channel_type</code>: <?= $hasChannelType ? '✓ Ada' : '✗ Tidak ada' ?></li>
    </ul>
  </div>

  <?php if ($result['msg']): ?>
  <div class="alert alert-<?= $result['ok'] ? 'success' : 'danger' ?>">
    <?= htmlspecialchars($result['msg']) ?>
  </div>
  <?php endif; ?>

  <?php if ($hasChannelType && !$hasType): ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
    <input type="hidden" name="run" value="1">
    <button type="submit" class="btn btn-primary">Jalankan Migrasi 104</button>
  </form>
  <?php elseif ($hasType): ?>
  <p class="text-success">Skema sudah kompatibel. Create Channel seharusnya berfungsi.</p>
  <?php else: ?>
  <p class="text-warning">Tabel chat_channels tidak terdeteksi atau skema tidak standar. Jalankan migrasi chat (090, 096) terlebih dahulu.</p>
  <?php endif; ?>

  <p class="mt-3 small text-secondary">
    <a href="<?= htmlspecialchars($base . '/tools/chat_diag.php') ?>">→ Chat Diagnostic</a> untuk cek skema detail.
  </p>
</div>
</body>
</html>
