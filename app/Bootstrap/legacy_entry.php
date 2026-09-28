<?php
declare(strict_types=1);

require_once __DIR__ . '/app_root.php';

$legacyCandidates = [
    APP_ROOT . '/_shared/bootstrap.php',
    APP_ROOT . '/master/auth.php',
];

foreach ($legacyCandidates as $file) {
    if (is_file($file)) {
        require_once $file;
    }
}
