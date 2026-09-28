<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../_shared/bootstrap.php';
require_once __DIR__ . '/../../../_shared/rbac.php';
require_once __DIR__ . '/../../../tools/rfc/_lib/rfc_lib.php';

require_login();
if (function_exists('require_any_permission')) {
    require_any_permission(['TOOLS.VIEW', 'SYSTEM.CONFIG_MANAGE']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$rfc = strtoupper(trim((string)($_GET['rfc'] ?? '')));
$file = rfc_find_file_by_id($rfc);
$parsed = $file !== '' ? rfc_parse_file($file) : ['ok' => false, 'error' => 'missing', 'frontmatter' => [], 'body' => ''];
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>RFC View</title><style>body{font-family:Arial,sans-serif;margin:16px}pre{border:1px solid #ddd;padding:8px;white-space:pre-wrap}</style></head>
<body>
<p><a href="index.php">Back</a></p>
<h1>RFC View</h1>
<?php if (!$parsed['ok']): ?>
  <p>RFC not found or invalid.</p>
<?php else: ?>
  <p>ID: <b><?= htmlspecialchars((string)($parsed['frontmatter']['RFC_ID'] ?? ''), ENT_QUOTES, 'UTF-8') ?></b> | Status: <?= htmlspecialchars((string)($parsed['frontmatter']['STATUS'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
  <p><a href="approve.php?rfc=<?= urlencode($rfc) ?>">Approve</a></p>
  <pre><?= htmlspecialchars((string)@file_get_contents($file), ENT_QUOTES, 'UTF-8') ?></pre>
<?php endif; ?>
</body></html>

