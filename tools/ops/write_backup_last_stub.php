<?php
declare(strict_types=1);

/**
 * CLI: Write backup_last.json from ts_latest_backup_meta or stub.
 * Clears "backup_last (MISSING)" in Data Sources when no backup tool writes it.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    exit("CLI only\n");
}

require_once __DIR__ . '/../_lib/tools_paths.php';
require_once __DIR__ . '/../tools_state_lib.php';
require_once __DIR__ . '/_lib/ops_helpers.php';

tools_assert_app_root_locked_cli();

$meta = ts_latest_backup_meta();
$ageHours = null;
$source = 'stub';

if (is_array($meta) && array_key_exists('age_hours', $meta) && $meta['age_hours'] !== null && $meta['age_hours'] !== '') {
    $ageHours = (int)round((float)$meta['age_hours']);
    $source = 'ts_latest_backup_meta';
}

if ($ageHours === null) {
    $ageHours = 0;
}

$dir = ts_storage_state_dir();
$path = $dir . '/backup_last.json';

$payload = [
    'backup_age_hours' => $ageHours,
    'source' => $source,
    'generated_at' => date(DateTimeInterface::ATOM),
];

ts_write_json($path, $payload);

echo "OK: backup_last.json written, backup_age_hours={$ageHours}, source={$source}\n";
exit(0);
