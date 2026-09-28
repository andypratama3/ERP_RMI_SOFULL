<?php
declare(strict_types=1);

// Backward-compatible locked path wrapper.
require_once __DIR__ . '/../../master/auth.php';
require_once __DIR__ . '/../tools_access_helpers.php';
tools_require_access('ops/restore_now.php');
require_login();
if (function_exists('require_any_permission')) {
    require_once __DIR__ . '/../../_shared/rbac.php';
    require_any_permission(['TOOLS.BACKUP_MANAGE', 'TOOLS.VIEW']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}
require __DIR__ . '/../restore_now.php';
