<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';

function maq_last_path(): string
{
    return APP_ROOT . '/storage/logs/manual_action_queue_last.json';
}

function maq_history_path(): string
{
    return APP_ROOT . '/storage/logs/manual_action_queue_history.jsonl';
}

function maq_generate_from_triage(): array
{
    $triage = tools_json_read_safe(APP_ROOT . '/storage/logs/erp_hardening_triage_web.last.json');
    $existing = tools_json_read_safe(maq_last_path());
    $rows = (array)($existing['rows'] ?? []);
    $index = [];
    foreach ($rows as $row) {
        $index[(string)($row['id'] ?? '')] = $row;
    }

    foreach ((array)($triage['findings'] ?? []) as $f) {
        if (empty($f['manual_action_required'])) {
            continue;
        }
        $id = (string)($f['id'] ?? '');
        if ($id === '') continue;
        if (!isset($index[$id])) {
            $index[$id] = [
                'id' => $id,
                'created_at' => date(DateTimeInterface::ATOM),
                'severity' => (string)($f['severity'] ?? 'P2'),
                'title' => (string)($f['title'] ?? ''),
                'owner' => (string)($f['owner'] ?? 'ENG'),
                'suggested_action' => 'Investigasi evidence dan lakukan perbaikan manual.',
                'status' => 'OPEN',
                'pic' => '',
            ];
        }
    }

    $final = array_values($index);
    usort($final, static function (array $a, array $b): int {
        $rank = ['P0' => 0, 'P1' => 1, 'P2' => 2];
        $ra = $rank[strtoupper((string)($a['severity'] ?? 'P2'))] ?? 2;
        $rb = $rank[strtoupper((string)($b['severity'] ?? 'P2'))] ?? 2;
        if ($ra !== $rb) return $ra <=> $rb;
        return strcmp((string)$a['id'], (string)$b['id']);
    });

    $payload = [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'rows' => $final,
    ];
    tools_json_write_atomic(maq_last_path(), $payload);
    @file_put_contents(maq_history_path(), json_encode(['event' => 'GENERATE', 'ts' => date(DateTimeInterface::ATOM), 'count' => count($final)], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    return $payload;
}

function maq_update_status(string $id, string $status, string $pic = ''): array
{
    $state = tools_json_read_safe(maq_last_path());
    $rows = (array)($state['rows'] ?? []);
    $status = strtoupper(trim($status));
    if (!in_array($status, ['OPEN', 'IN_PROGRESS', 'DONE'], true)) {
        $status = 'OPEN';
    }
    foreach ($rows as &$row) {
        if ((string)($row['id'] ?? '') !== $id) continue;
        $row['status'] = $status;
        if ($pic !== '') $row['pic'] = $pic;
        $row['updated_at'] = date(DateTimeInterface::ATOM);
        break;
    }
    unset($row);
    $state['rows'] = $rows;
    $state['generated_at'] = date(DateTimeInterface::ATOM);
    tools_json_write_atomic(maq_last_path(), $state);
    @file_put_contents(maq_history_path(), json_encode([
        'event' => 'STATUS_UPDATE',
        'ts' => date(DateTimeInterface::ATOM),
        'id' => $id,
        'status' => $status,
        'pic' => $pic,
    ], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    return $state;
}
