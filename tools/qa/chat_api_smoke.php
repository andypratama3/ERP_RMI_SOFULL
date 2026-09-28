<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/../tools_ui_helpers.php';

$args = $_SERVER['argv'] ?? [];
$baseUrl = 'http://127.0.0.1:18081';
$user = '';
$pass = '';
foreach ($args as $arg) {
    if (str_starts_with((string)$arg, '--base-url=')) {
        $baseUrl = rtrim((string)substr((string)$arg, 11), '/');
    } elseif (str_starts_with((string)$arg, '--user=')) {
        $user = (string)substr((string)$arg, 7);
    } elseif (str_starts_with((string)$arg, '--pass=')) {
        $pass = (string)substr((string)$arg, 7);
    }
}
if ($user === '') $user = (string)(getenv('SMOKE_ADMIN_USER') ?: 'SmokeSYS_SYS');
if ($pass === '') $pass = (string)(getenv('SMOKE_ADMIN_PASS') ?: 'Temp1234!');

$cookieFile = ts_storage_logs_dir() . '/_chat_api_smoke.cookie.txt';
@unlink($cookieFile);

$steps = [];
$errors = [];

$curl = static function (string $url, string $cookieFile, ?array $post = null): string {
    $cmd = 'curl --max-time 20 -sS -L -c ' . escapeshellarg($cookieFile) . ' -b ' . escapeshellarg($cookieFile);
    if ($post !== null) {
        $cmd .= ' -X POST';
        foreach ($post as $k => $v) {
            $cmd .= ' --data-urlencode ' . escapeshellarg((string)$k . '=' . (string)$v);
        }
    }
    $cmd .= ' ' . escapeshellarg($url);
    $out = shell_exec($cmd);
    return is_string($out) ? $out : '';
};

$jsonOk = static function (string $body): bool {
    $j = json_decode($body, true);
    return is_array($j) && (($j['success'] ?? false) === true);
};

$record = static function (array &$steps, string $name, bool $ok, string $note = ''): void {
    $steps[] = [
        'name' => $name,
        'ok' => $ok,
        'note' => $note,
    ];
};

$loginBody = $curl($baseUrl . '/master/login.php', $cookieFile, [
    'username' => $user,
    'password' => $pass,
]);
$record($steps, 'login', !str_contains(strtolower($loginBody), 'kredensial tidak valid'), 'login posted');

$chatPage = $curl($baseUrl . '/chat/index.php', $cookieFile);
preg_match('/const CSRF = "([^"]+)";/', $chatPage, $m);
$csrf = (string)($m[1] ?? '');
$record($steps, 'load_chat_page', $csrf !== '', $csrf !== '' ? 'csrf found' : 'csrf missing');
if ($csrf === '') {
    $errors[] = 'csrf parse failed';
}

$chBody = $curl($baseUrl . '/api/v1/chat/channels.php', $cookieFile);
$ch = json_decode($chBody, true);
$channelId = (int)($ch['data']['channels'][0]['id'] ?? 0);
$uid = (int)($ch['data']['current_user']['id'] ?? 0);
$record($steps, 'channels_get', $jsonOk($chBody) && $channelId > 0 && $uid > 0, 'channel_id=' . $channelId);
if ($channelId <= 0 || $uid <= 0) {
    $errors[] = 'channels get failed';
}

if ($channelId > 0 && $uid > 0 && $csrf !== '') {
    $pPing = $curl($baseUrl . '/api/v1/chat/presence/ping.php', $cookieFile, ['csrf_token' => $csrf]);
    $record($steps, 'presence_ping', $jsonOk($pPing));

    $pGet = $curl($baseUrl . '/api/v1/chat/presence.php?user_ids=' . urlencode((string)$uid), $cookieFile);
    $record($steps, 'presence_get_batch', $jsonOk($pGet));

    $msgBody = $curl($baseUrl . '/api/v1/chat/messages.php?channel_id=' . urlencode((string)$channelId) . '&limit=5', $cookieFile);
    $msg = json_decode($msgBody, true);
    $messages = (array)($msg['data']['messages'] ?? []);
    $lastRead = 0;
    foreach ($messages as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > $lastRead) $lastRead = $id;
    }
    $record($steps, 'messages_get', $jsonOk($msgBody));

    $readBody = $curl($baseUrl . '/api/v1/chat/channels/read.php', $cookieFile, [
        'csrf_token' => $csrf,
        'channel_id' => (string)$channelId,
        'last_read_message_id' => (string)$lastRead,
    ]);
    $record($steps, 'channel_read', $jsonOk($readBody));

    $allReadBody = $curl($baseUrl . '/api/v1/chat/channels/mark_all_read.php', $cookieFile, [
        'csrf_token' => $csrf,
    ]);
    $record($steps, 'mark_all_read', $jsonOk($allReadBody));

    $muteBody = $curl($baseUrl . '/api/v1/chat/channels/mute.php', $cookieFile, [
        'csrf_token' => $csrf,
        'channel_id' => (string)$channelId,
        'duration' => '3600',
    ]);
    $record($steps, 'mute_1h', $jsonOk($muteBody));

    $unmuteBody = $curl($baseUrl . '/api/v1/chat/channels/unmute.php', $cookieFile, [
        'csrf_token' => $csrf,
        'channel_id' => (string)$channelId,
    ]);
    $record($steps, 'unmute', $jsonOk($unmuteBody));

    $idem = 'smoke-' . time();
    $send1 = $curl($baseUrl . '/api/v1/chat/messages.php', $cookieFile, [
        'csrf_token' => $csrf,
        'channel_id' => (string)$channelId,
        'message_text' => 'smoke idempotency message',
        'idempotency_key' => $idem,
    ]);
    $send2 = $curl($baseUrl . '/api/v1/chat/messages.php', $cookieFile, [
        'csrf_token' => $csrf,
        'channel_id' => (string)$channelId,
        'message_text' => 'smoke idempotency message',
        'idempotency_key' => $idem,
    ]);
    $j1 = json_decode($send1, true);
    $j2 = json_decode($send2, true);
    $mid1 = (int)($j1['data']['message_id'] ?? 0);
    $mid2 = (int)($j2['data']['message_id'] ?? 0);
    $record($steps, 'send_message_idempotency', $mid1 > 0 && $mid1 === $mid2, 'message_id=' . $mid1);

    $searchBody = $curl($baseUrl . '/api/v1/chat/search.php?q=smoke&channel_id=' . urlencode((string)$channelId) . '&limit=10&offset=0', $cookieFile);
    $record($steps, 'search', $jsonOk($searchBody));

    if ($mid1 > 0) {
        $ctxBody = $curl($baseUrl . '/api/v1/chat/messages/context.php?message_id=' . urlencode((string)$mid1) . '&before=2&after=2', $cookieFile);
        $record($steps, 'message_context', $jsonOk($ctxBody));
    } else {
        $record($steps, 'message_context', false, 'missing message_id');
    }

    $eventsBody = $curl($baseUrl . '/api/v1/chat/events.php?channel_id=' . urlencode((string)$channelId) . '&since_id=' . urlencode((string)$lastRead) . '&timeout=2', $cookieFile);
    $record($steps, 'events_long_poll', $jsonOk($eventsBody));

    $delNoReason = $curl($baseUrl . '/api/v1/chat/message_delete.php', $cookieFile, [
        'csrf_token' => $csrf,
        'message_id' => (string)$mid1,
    ]);
    $delJ = json_decode($delNoReason, true);
    $rejectReason = is_array($delJ) && (($delJ['success'] ?? true) === false);
    $record($steps, 'delete_without_reason_rejected', $rejectReason);

    $today = date('Y-m-d');
    $expCreate = $curl($baseUrl . '/api/v1/chat/exports.php', $cookieFile, [
        'csrf_token' => $csrf,
        'channel_id' => (string)$channelId,
        'start' => $today,
        'end' => $today,
        'format' => 'csv',
    ]);
    $expJ = json_decode($expCreate, true);
    $exportId = (int)($expJ['data']['id'] ?? 0);
    $record($steps, 'export_create', $jsonOk($expCreate) && $exportId > 0, 'export_id=' . $exportId);

    if ($exportId > 0) {
        $expDl = $curl($baseUrl . '/api/v1/chat/export_download.php?export_id=' . urlencode((string)$exportId), $cookieFile);
        $record($steps, 'export_download', $expDl !== '');
    } else {
        $record($steps, 'export_download', false, 'missing export_id');
    }
}

$ok = true;
foreach ($steps as $s) {
    if (!$s['ok']) {
        $ok = false;
        $errors[] = $s['name'] . '_failed';
    }
}

$result = [
    'state_version' => 1,
    'checked_at' => date(DateTimeInterface::ATOM),
    'base_url' => $baseUrl,
    'ok' => $ok,
    'steps' => $steps,
    'errors_masked' => array_map('tools_mask_sensitive', array_values(array_unique($errors))),
];

$out = ts_storage_logs_dir() . '/chat_api_smoke.last.json';
ts_write_json($out, $result);
@unlink($cookieFile);

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($ok ? 0 : 1);

