<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $tmpSession = __DIR__ . '/../.tmp_sessions';
    @mkdir($tmpSession, 0775, true);
    @ini_set('session.save_path', $tmpSession);
    @session_start();
}

require_once __DIR__ . '/../_shared/app_init.php';
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/../_shared/login_policy.php';
require_once __DIR__ . '/../_shared/health_readiness.php';
require_once __DIR__ . '/../_shared/backup_manifest.php';
require_once __DIR__ . '/../_shared/erp_audit.php';

