<?php
declare(strict_types=1);

// Backward-compatible locked path wrapper.
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_access_helpers.php';
tools_require_access('ops/backup_schedule.php');
require_login();
require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
require __DIR__ . '/../backup_schedule.php';
