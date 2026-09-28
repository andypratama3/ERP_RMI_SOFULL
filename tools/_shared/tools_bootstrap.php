<?php
/**
 * tools_bootstrap.php — Load first from CLI tools (URL canon + optional early guards).
 *
 * Usage (top of tool scripts):
 *   require_once __DIR__ . '/../_shared/tools_bootstrap.php';
 */
declare(strict_types=1);

$__rmi_tools_shared = __DIR__;
require_once $__rmi_tools_shared . '/url.php';
require_once $__rmi_tools_shared . '/seed_user_policy.php';
// Library only (no auto-run). Run base_path_guard_run() from cutover / qa scripts.
require_once $__rmi_tools_shared . '/base_path_guard.php';
