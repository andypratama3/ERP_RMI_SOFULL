<?php
// File sementara — HAPUS setelah selesai cek!
// Akses: https://erp.rizqullahmediska.com/ERP_RMI_SOFULL/tools/phpinfo_check.php

if (php_sapi_name() === 'cli') {
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "Extension dir: " . ini_get('extension_dir') . "\n";
    echo "PDO drivers: " . implode(', ', PDO::getAvailableDrivers()) . "\n";
    echo "Loaded extensions:\n";
    foreach (get_loaded_extensions() as $ext) {
        if (stripos($ext, 'pdo') !== false || stripos($ext, 'mysql') !== false) {
            echo "  - $ext\n";
        }
    }
} else {
    phpinfo();
}
