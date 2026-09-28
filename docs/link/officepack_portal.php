<?php
/**
 * @deprecated Portal Office Pack diganti Help Center + docs/panduan/
 */
declare(strict_types=1);

require_once __DIR__ . '/../../master/auth.php';

require_login();

$bp = '';
if (function_exists('auth_base_project')) {
  $bp = rtrim((string)auth_base_project(), '/');
}
header('Location: ' . $bp . '/docs/help_center.php', true, 302);
exit;
