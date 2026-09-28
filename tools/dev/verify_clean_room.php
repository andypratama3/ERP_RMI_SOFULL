<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$rootNorm = str_replace('\\', '/', $root);
$forbiddenNeedles = [
    'rmi_latest_update_dev',
    'legacy_project_reference',
];
$violations = [];

foreach ($forbiddenNeedles as $needle) {
    if (str_contains(strtolower($rootNorm), $needle)) {
        $violations[] = 'root_path_contains_forbidden_token:' . $needle;
    }
}

$forbiddenPaths = [
    $root . '/legacy_project',
    $root . '/project_lama',
];
foreach ($forbiddenPaths as $path) {
    if (file_exists($path)) {
        $violations[] = 'forbidden_path_exists:' . str_replace($root, '[APP_ROOT]', str_replace('\\', '/', $path));
    }
}

$ok = ($violations === []);
$payload = [
    'state_version' => 1,
    'checked_at' => date(DateTimeInterface::ATOM),
    'ok' => $ok,
    'violations' => $violations,
];

echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
