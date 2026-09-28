<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$args = $_SERVER['argv'] ?? [];
$baseUrl = '';
$managerUser = (string)(getenv('SMOKE_MANAGER_USER') ?: '');
$managerPass = (string)(getenv('SMOKE_MANAGER_PASS') ?: '');
$staffUser = (string)(getenv('SMOKE_STAFF_USER') ?: '');
$staffPass = (string)(getenv('SMOKE_STAFF_PASS') ?: '');

foreach ($args as $arg) {
    if (str_starts_with((string)$arg, '--base-url=')) {
        $baseUrl = rtrim((string)substr((string)$arg, 11), '/');
    } elseif (str_starts_with((string)$arg, '--manager-user=')) {
        $managerUser = (string)substr((string)$arg, 15);
    } elseif (str_starts_with((string)$arg, '--manager-pass=')) {
        $managerPass = (string)substr((string)$arg, 15);
    } elseif (str_starts_with((string)$arg, '--staff-user=')) {
        $staffUser = (string)substr((string)$arg, 13);
    } elseif (str_starts_with((string)$arg, '--staff-pass=')) {
        $staffPass = (string)substr((string)$arg, 13);
    }
}

echo "== Dashboard Role Smoke ==\n";

if ($baseUrl === '') {
    echo "SKIP: missing --base-url\n";
    exit(0);
}

$routes = [
    '/sales/sales_dashboard.php',
    '/purchases/purchases_dashboard.php',
    '/dashboards/warehouse/wqs_dashboard.php',
    '/dashboards/finance/ar_ap_cash_dashboard.php',
    '/dashboards/regulatory/license_docs_dashboard.php',
    '/dashboards/quality/qc_complaint_dashboard.php',
    '/mpr/mpr_dashboard.php',
    '/kpi/kpi_dashboard_daily.php',
    '/kpi/kpi_dashboard_monthly.php',
];

$checks = [];
$ok = true;
$blocked = false;
$tmpDir = sys_get_temp_dir();

$runRole = static function (string $roleName, string $user, string $pass) use ($baseUrl, $routes, &$ok, &$blocked, &$checks, $tmpDir): void {
    if ($user === '' || $pass === '') {
        $blocked = true;
        $checks[] = [
            'role' => $roleName,
            'status' => 'SKIP',
            'reason' => 'missing credentials',
        ];
        return;
    }

    $cookie = $tmpDir . '/dash_role_' . strtolower($roleName) . '_' . getmypid() . '.txt';
    $loginUrl = $baseUrl . '/master/login.php';
    $payload = http_build_query(['username' => $user, 'password' => $pass]);
    $cmdLogin = 'curl -sS -L -c ' . escapeshellarg($cookie) . ' -b ' . escapeshellarg($cookie) .
        ' -X POST -d ' . escapeshellarg($payload) . ' ' . escapeshellarg($loginUrl);
    @exec($cmdLogin . ' >/dev/null 2>&1');

    $allowedRoutesByPersona = [
        'MANAGER' => [
            '/sales/sales_dashboard.php',
            '/kpi/kpi_dashboard_daily.php',
            '/kpi/kpi_dashboard_monthly.php',
        ],
        'STAFF' => [
            '/sales/sales_dashboard.php',
            '/kpi/kpi_dashboard_daily.php',
            '/kpi/kpi_dashboard_monthly.php',
        ],
    ];
    $allowedRoutes = $allowedRoutesByPersona[$roleName] ?? [];

    foreach ($routes as $route) {
        $url = $baseUrl . $route;
        $cmd = 'curl -sS -L -c ' . escapeshellarg($cookie) . ' -b ' . escapeshellarg($cookie) .
            ' -w "__HTTP_CODE__:%{http_code}" ' . escapeshellarg($url);
        $resp = (string)(shell_exec($cmd) ?? '');
        $httpCode = 0;
        $body = $resp;
        $marker = '__HTTP_CODE__:';
        $markerPos = strrpos($resp, $marker);
        if ($markerPos !== false) {
            $body = substr($resp, 0, $markerPos);
            $httpCode = (int)substr($resp, $markerPos + strlen($marker));
        }
        $hasFatal = str_contains($body, 'Fatal error') || str_contains($body, 'Unhandled Exception') || str_contains($body, 'SQLSTATE');
        $looksDenied = stripos($body, 'kredensial tidak valid') !== false
            || stripos($body, 'login erp') !== false
            || stripos($body, 'akses ditolak') !== false;
        $hasControlWidget = stripos($body, 'Manager Controling Staff') !== false
            || stripos($body, 'Team Staff') !== false
            || stripos($body, 'Attendance Today') !== false
            || stripos($body, 'Backlog Open') !== false;
        $expected = in_array($route, $allowedRoutes, true) ? 'allow' : 'deny';
        if ($expected === 'allow') {
            $passRoute = in_array($httpCode, [200], true) && $body !== '' && !$hasFatal && !$looksDenied;
        } else {
            // expected deny: scoped user must be blocked from unrelated departments
            $passRoute = in_array($httpCode, [403], true);
        }
        if (!$passRoute) {
            $ok = false;
        }
        $checks[] = [
            'role' => $roleName,
            'route' => $route,
            'expected' => $expected,
            'ok' => $passRoute,
            'http_code' => $httpCode,
            'widget_hint' => $hasControlWidget,
        ];
    }

    @unlink($cookie);
};

$runRole('MANAGER', $managerUser, $managerPass);
$runRole('STAFF', $staffUser, $staffPass);

$stats = [
    'pass' => 0,
    'fail' => 0,
    'skip' => 0,
];
foreach ($checks as $row) {
    if (($row['status'] ?? '') === 'SKIP') {
        $stats['skip']++;
        continue;
    }
    if (($row['ok'] ?? false) === true) {
        $stats['pass']++;
    } else {
        $stats['fail']++;
    }
}
if ($blocked || $stats['fail'] > 0 || $stats['pass'] === 0) {
    $ok = false;
}

$result = [
    'run_at' => date(DateTimeInterface::ATOM),
    'base_url' => $baseUrl,
    'ok' => $ok,
    'blocked' => $blocked,
    'stats' => $stats,
    'checks' => $checks,
];

$outFile = __DIR__ . '/dashboard_role_smoke.last.json';
@file_put_contents($outFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
echo 'artifact: ' . $outFile . PHP_EOL;
exit($ok ? 0 : 1);

