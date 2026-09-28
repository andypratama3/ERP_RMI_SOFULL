<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$baseUrl = getenv('ROUTE_CHECK_BASE_URL') ?: '';
$routes = [
    '/tools/index.php',
    '/tools/health.php',
    '/tools/backup_manager.php',
    '/master/login.php',
    '/master/master_system_login.php',
    '/sales/sales_dashboard.php',
    '/purchases/index.php',
    '/stock/index.php',
    '/tools/backup_schedule.php',
    '/tools/smoke_schedule.php',
];

echo "verify_routes\n";
echo "base_url=" . ($baseUrl !== '' ? $baseUrl : '(file-existence-only)') . "\n";

$fail = 0;
foreach ($routes as $route) {
    $filePath = $root . $route;
    $exists = is_file($filePath);
    $status = $exists ? 'PASS' : 'FAIL';
    if (!$exists) {
        $fail++;
    }
    $http = '-';
    if ($baseUrl !== '') {
        $ch = curl_init(rtrim($baseUrl, '/') . $route);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $http = (string)$code;
        if ($code === 404 || $code === 0) {
            $status = 'FAIL';
            $fail++;
        }
    }
    echo "{$status} route={$route} exists=" . ($exists ? 'yes' : 'no') . " http={$http}\n";
}

exit($fail === 0 ? 0 : 1);
