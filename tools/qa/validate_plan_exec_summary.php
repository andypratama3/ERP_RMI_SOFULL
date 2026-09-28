<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/_lib/pipeline_lib.php';
require_once __DIR__ . '/_lib/plan_exec_summary_lib.php';

$args = $_SERVER['argv'] ?? [];
$planId = '';
$masterRunId = '';
foreach (array_slice($args, 1) as $arg) {
    if (str_starts_with((string)$arg, '--plan=')) {
        $planId = trim((string)substr((string)$arg, 7));
    }
    if (str_starts_with((string)$arg, '--run-id=')) {
        $masterRunId = trim((string)substr((string)$arg, 9));
    }
}

$pipe = ppl_pipeline_dir();
$planIdSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $planId);
$runIdSafe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $masterRunId);

$errors = [];
$path = $planId !== '' && $masterRunId !== ''
    ? $pipe . '/plan_exec_summary_' . $planIdSafe . '_' . $runIdSafe . '.json'
    : $pipe . '/plan_exec_summary_last.json';

if (!is_file($path)) {
    $errors[] = 'file_missing:' . ppl_mask($path);
} else {
    $raw = (string)@file_get_contents($path);
    if (trim($raw) === '') {
        $errors[] = 'file_empty';
    } else {
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                $errors[] = 'schema_not_object';
            } else {
                $goNoGo = (string)($data['decision']['go_no_go'] ?? '');
                if (!in_array($goNoGo, ['GO', 'NO-GO', 'UNKNOWN'], true)) {
                    $errors[] = 'invalid_go_no_go:' . $goNoGo;
                }
                $level = (string)($data['decision']['level'] ?? '');
                if (!in_array($level, ['HEALTHY', 'ATTENTION', 'CRITICAL', 'UNKNOWN'], true)) {
                    $errors[] = 'invalid_level:' . $level;
                }
                $archStatus = (string)($data['architecture']['status'] ?? '');
                if (!in_array($archStatus, ['OK', 'ATTENTION', 'CRITICAL', 'DATA_MISSING'], true)) {
                    $errors[] = 'invalid_architecture_status:' . $archStatus;
                }
                $denyPatterns = ['/Users/', 'C:\\', 'BEGIN PRIVATE KEY', 'Authorization:', 'DB_PASS'];
                $contentToCheck = $raw;
                $mdPath = str_replace('.json', '.md', $path);
                if (is_file($mdPath)) $contentToCheck .= "\n" . (string)@file_get_contents($mdPath);
                $htmlPath = str_replace('.json', '.html', $path);
                if (is_file($htmlPath)) $contentToCheck .= "\n" . (string)@file_get_contents($htmlPath);
                foreach ($denyPatterns as $p) {
                    if (str_contains($contentToCheck, $p)) {
                        $errors[] = 'deny_pattern_found:' . ppl_mask($p);
                    }
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'json_parse_error:' . ppl_mask($e->getMessage());
        }
    }
}

$ok = $errors === [];
$payload = [
    'state_version' => 1,
    'generated_at' => date(DateTimeInterface::ATOM),
    'ok' => $ok,
    'path' => ppl_mask($path),
    'errors' => $errors,
];

ppl_write_json($pipe . '/plan_exec_summary_validate_last.json', $payload);
$md = "# Plan Exec Summary Validation\n\n- ok: " . ($ok ? 'true' : 'false') . "\n- path: " . ppl_mask($path) . "\n";
foreach ($errors as $e) {
    $md .= "- error: " . ppl_mask($e) . "\n";
}
@file_put_contents($pipe . '/plan_exec_summary_validate_last.md', $md);

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
