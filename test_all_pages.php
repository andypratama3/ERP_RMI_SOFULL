<?php
/**
 * Comprehensive ERP Page Tester
 * Tests all pages with authenticated session
 */

// Start session and simulate login
session_start();

// Simulate admin login session
$_SESSION['user_id'] = 2;
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Administrator';
$_SESSION['role'] = 'ADMIN';
$_SESSION['level'] = 'ADMIN';
$_SESSION['department'] = 'SYS';
$_SESSION['office_code'] = 'SYS';
$_SESSION['user'] = [
    'id' => 2,
    'username' => 'admin',
    'full_name' => 'Administrator',
    'role' => 'ADMIN',
    'level' => 'ADMIN',
    'department' => 'SYS',
    'office_code' => 'SYS',
    'src' => 'master_system_login',
];

// List of pages to test
$pages = [
    // DASHBOARDS
    ['num' => 1, 'url' => '/dashboards/index.php', 'priority' => false],
    ['num' => 2, 'url' => '/dashboards/dashboard_center.php', 'priority' => false],
    ['num' => 3, 'url' => '/dashboards/finance/dashboard_detail.php', 'priority' => true],
    ['num' => 4, 'url' => '/dashboards/finance/ar_ap_cash_dashboard.php', 'priority' => false],
    ['num' => 5, 'url' => '/sales/sales_dashboard.php', 'priority' => false],
    ['num' => 6, 'url' => '/dashboards/owner/exec_summary.php', 'priority' => false],
    ['num' => 7, 'url' => '/dashboards/warehouse/wqs_dashboard.php', 'priority' => false],
    ['num' => 8, 'url' => '/dashboards/quality/qc_complaint_dashboard.php', 'priority' => false],
    
    // CHAT
    ['num' => 9, 'url' => '/chat/index.php', 'priority' => true],
    ['num' => 10, 'url' => '/chat/admin_channels.php', 'priority' => false],
    ['num' => 11, 'url' => '/chat/admin_settings.php', 'priority' => false],
    
    // MODUL UTAMA
    ['num' => 12, 'url' => '/sales/sales_dashboard.php', 'priority' => false],
    ['num' => 13, 'url' => '/purchases/purchases_dashboard.php', 'priority' => false],
    ['num' => 14, 'url' => '/stock/wqs_stock.php', 'priority' => false],
    ['num' => 15, 'url' => '/kpi/kpi_center.php', 'priority' => false],
    ['num' => 16, 'url' => '/absensi/index.php', 'priority' => false],
    ['num' => 17, 'url' => '/payroll/index.php', 'priority' => false],
    ['num' => 18, 'url' => '/hrl/hrl_docs.php', 'priority' => false],
    ['num' => 19, 'url' => '/mpr/index.php', 'priority' => false],
    ['num' => 20, 'url' => '/Fixed_Asset/index.php', 'priority' => false],
    ['num' => 21, 'url' => '/master/index.php', 'priority' => false],
    ['num' => 22, 'url' => '/rbac/index.php', 'priority' => false],
];

echo "=== COMPREHENSIVE ERP PAGE TEST ===\n";
echo "Session User: " . $_SESSION['username'] . " (Role: " . $_SESSION['role'] . ")\n";
echo "Total Pages: " . count($pages) . "\n\n";

$results = [];

foreach ($pages as $page) {
    $num = $page['num'];
    $url = $page['url'];
    $priority = $page['priority'] ? ' ← PRIORITAS' : '';
    
    echo "[$num] $url$priority\n";
    
    $fullPath = __DIR__ . $url;
    $status = 'UNKNOWN';
    $phpError = 'TIDAK ADA';
    $layout = 'UNKNOWN';
    $notes = '';
    $httpCode = 200;
    
    // Check if file exists
    if (!file_exists($fullPath)) {
        $status = 'NOT_FOUND';
        $layout = 'FILE NOT EXISTS';
        $notes = "File tidak ditemukan: $fullPath";
        $httpCode = 404;
    } else {
        // Try to capture output
        ob_start();
        try {
            // Simulate HTTP request environment
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = $url;
            $_SERVER['SCRIPT_NAME'] = $url;
            $_SERVER['PHP_SELF'] = $url;
            
            include $fullPath;
            
            $output = ob_get_clean();
            $size = strlen($output);
            
            if ($size < 50) {
                $status = 'BLANK';
                $layout = 'BLANK';
                $notes = "Output sangat kecil ($size bytes): " . substr($output, 0, 50);
            } else {
                $status = 'OK';
                
                // Check for PHP errors in output
                if (preg_match('/(Fatal error|Parse error|Warning:|Notice:|Deprecated:)/i', $output, $matches)) {
                    $phpError = 'ADA';
                    preg_match('/(Fatal error|Parse error|Warning:|Notice:)[^\n]+/i', $output, $errorMatch);
                    $notes = "PHP Error: " . ($errorMatch[0] ?? 'Unknown error');
                }
                
                // Check layout
                if (strpos($output, '<html') !== false || strpos($output, '<body') !== false) {
                    $layout = 'RAPI';
                    
                    // Check for common elements
                    if (strpos($output, 'rmi-topbar') !== false) {
                        $notes .= " | Has topbar";
                    }
                    if (strpos($output, 'offcanvas') !== false) {
                        $notes .= " | Has offcanvas menu";
                    }
                    if (strpos($output, 'Dashboard') !== false || strpos($output, 'dashboard') !== false) {
                        $notes .= " | Dashboard content";
                    }
                } else {
                    $layout = 'BERANTAKAN';
                    $notes .= " | No proper HTML structure";
                }
            }
            
        } catch (Throwable $e) {
            ob_end_clean();
            $status = 'ERROR';
            $phpError = 'ADA';
            $layout = 'ERROR';
            $notes = "Exception: " . $e->getMessage();
            $httpCode = 500;
        }
    }
    
    echo "Status: $status (HTTP $httpCode)\n";
    echo "PHP Error: $phpError\n";
    echo "Layout: $layout\n";
    if ($notes) {
        echo "Catatan: $notes\n";
    }
    echo "\n";
    
    $results[] = [
        'num' => $num,
        'url' => $url,
        'status' => $status,
        'http_code' => $httpCode,
        'php_error' => $phpError,
        'layout' => $layout,
        'notes' => $notes,
    ];
}

echo "=== SUMMARY ===\n";
$ok = count(array_filter($results, fn($r) => $r['status'] === 'OK'));
$error = count(array_filter($results, fn($r) => $r['status'] === 'ERROR'));
$blank = count(array_filter($results, fn($r) => $r['status'] === 'BLANK'));
$notFound = count(array_filter($results, fn($r) => $r['status'] === 'NOT_FOUND'));

echo "OK: $ok\n";
echo "ERROR: $error\n";
echo "BLANK: $blank\n";
echo "NOT FOUND: $notFound\n";
echo "Total: " . count($results) . "\n";
