<?php
declare(strict_types=1);

// Pastikan APP_ROOT terdefinisi (untuk tools yang load bootstrap sebelum auth)
$legacyEntry = __DIR__ . '/../../app/Bootstrap/legacy_entry.php';
if (is_file($legacyEntry)) {
    require_once $legacyEntry;
}

// Fallback: jika legacy_entry tidak ada (mis. struktur deploy berbeda)
if (!defined('APP_ROOT')) {
    define('APP_ROOT', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
}

require_once __DIR__ . '/../_shared/app_root_guard.php';
tools_assert_expected_app_root();

$toolsHelperFiles = [
    APP_ROOT . '/tools/tools_ui_helpers.php',
    APP_ROOT . '/tools/tools_state_lib.php',
    APP_ROOT . '/tools/tools_access_helpers.php',
];

foreach ($toolsHelperFiles as $helperFile) {
    if (is_file($helperFile)) {
        require_once $helperFile;
    }
}
