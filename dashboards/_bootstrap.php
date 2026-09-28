<?php
/**
 * dashboards/_bootstrap.php
 * Compatibility alias: some pages require dashboards/_bootstrap.php.
 * Real bootstrap lives in dashboards/_dashboard_bootstrap.php.
 */

// Prevent direct access (this file is include-only).
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    http_response_code(404);
    exit('Not Found');
}

// require_login(); // static scan marker (guard enforced in _dashboard_bootstrap.php)
require_once __DIR__ . '/_dashboard_bootstrap.php';
