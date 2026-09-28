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

$items = [];
foreach (rfc_list_files() as $f) {
    $p = rfc_parse_file($f);
    if (!$p['ok']) continue;
    $fm = (array)$p['frontmatter'];
    $items[] = [
        'id' => (string)($fm['RFC_ID'] ?? ''),
        'title' => (string)($fm['TITLE'] ?? ''),
        'type' => (string)($fm['TYPE'] ?? ''),
        'status' => (string)($fm['STATUS'] ?? ''),
        'env' => (string)($fm['TARGET_ENV'] ?? ''),
        'file' => rfc_mask($f),
    ];
}
usort($items, static fn(array $a, array $b): int => strcmp((string)$b['id'], (string)$a['id']));
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>RFC Admin</title><style>body{font-family:Arial,sans-serif;margin:16px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:6px}</style></head>
<body>
<h1>RFC Admin</h1>
<table>
  <tr><th>ID</th><th>Title</th><th>Type</th><th>Status</th><th>Env</th><th>Action</th></tr>
  <?php foreach ($items as $i): ?>
    <tr>
      <td><?= htmlspecialchars($i['id'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= htmlspecialchars($i['title'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= htmlspecialchars($i['type'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= htmlspecialchars($i['status'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><?= htmlspecialchars($i['env'], ENT_QUOTES, 'UTF-8') ?></td>
      <td><a href="view.php?rfc=<?= urlencode($i['id']) ?>">View</a></td>
    </tr>
  <?php endforeach; ?>
</table>
</body></html>

