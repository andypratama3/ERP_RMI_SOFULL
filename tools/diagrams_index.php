<?php
declare(strict_types=1);
/**
 * Diagram Suite — simple HTML index of module/file diagrams.
 * Admin guard. No logic changes.
 */
require_once __DIR__ . '/../master/auth.php';
require_once __DIR__ . '/_lib/bootstrap.php';
require_login();
if (function_exists('require_any_permission')) {
    require_once __DIR__ . '/../_shared/rbac.php';
    require_any_permission(['TOOLS.VIEW']);
} else {
    require_role(['SYS', 'ADMIN', 'SUPERADMIN']);
}

$base = function_exists('rmi_layout_base_project') ? rtrim(rmi_layout_base_project(), '/') : '';
$manifestPath = __DIR__ . '/../docs/diagrams/manifest.json';
$manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
$modules = $manifest['modules'] ?? [];
$files = $manifest['files'] ?? [];
$generatedAt = $manifest['generated_at'] ?? '-';
?>
<!DOCTYPE html>
<html lang="id">
<head><meta charset="utf-8"><title>Diagram Suite — ERP_RMI_SOFULL</title></head>
<body style="font-family:sans-serif;padding:1rem">
<h1>Diagram Suite</h1>
<p>Generated: <?= htmlspecialchars((string)$generatedAt) ?></p>
<h2>Module Diagrams</h2>
<ul>
<?php foreach ($modules as $m): ?>
<li><a href="<?= htmlspecialchars($base . '/' . $m['svg']) ?>"><?= htmlspecialchars($m['name']) ?></a></li>
<?php endforeach; ?>
<?php if (empty($modules)): ?><li>Run generator from NAS to populate.</li><?php endif; ?>
</ul>
<h2>File Diagrams</h2>
<ul>
<?php foreach (array_slice($files, 0, 50) as $f): ?>
<li><a href="<?= htmlspecialchars($base . '/' . $f['svg']) ?>"><?= htmlspecialchars($f['path']) ?></a></li>
<?php endforeach; ?>
<?php if (count($files) > 50): ?><li><em>... and <?= count($files) - 50 ?> more</em></li><?php endif; ?>
<?php if (empty($files)): ?><li>Run generator from NAS to populate.</li><?php endif; ?>
</ul>
<p><a href="<?= htmlspecialchars($base . '/tools/index.php') ?>">← Back to Tools</a></p>
</body>
</html>
