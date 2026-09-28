<?php
/**
 * tools/view_source.php
 * Menampilkan source code file (untuk link "file sumber" dari dashboard).
 * Hanya file dalam project yang diizinkan.
 */
declare(strict_types=1);

require_once __DIR__ . '/../_shared/bootstrap.php';
require_once __DIR__ . '/../master/auth.php';
require_login();

$file = trim((string)($_GET['file'] ?? ''));
$line = isset($_GET['line']) ? (int)$_GET['line'] : 0;

$root = realpath(__DIR__ . '/..');
$allowedDirs = ['app', 'dashboards', 'master', 'sales', 'purchases', 'stock', 'kpi', '_shared', 'sql'];
$ok = false;
$fullPath = '';
$err = '';

if ($file !== '') {
    $file = str_replace('\\', '/', $file);
    if (preg_match('/\.\./', $file) || $file[0] === '/') {
        $err = 'Path tidak valid.';
    } else {
        $parts = explode('/', trim($file, '/'));
        $first = $parts[0] ?? '';
        if (in_array($first, $allowedDirs, true)) {
            $fullPath = $root . '/' . $file;
            if (file_exists($fullPath) && is_file($fullPath)) {
                $real = realpath($fullPath);
                if ($real && strpos($real, $root) === 0) {
                    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                    if (in_array($ext, ['php', 'sql', 'md', 'js', 'css'], true)) {
                        $ok = true;
                    } else {
                        $err = 'Hanya file .php, .sql, .md, .js, .css yang dapat ditampilkan.';
                    }
                } else {
                    $err = 'File di luar project.';
                }
            } else {
                $err = 'File tidak ditemukan.';
            }
        } else {
            $err = 'Path harus dimulai dari: ' . implode(', ', $allowedDirs);
        }
    }
}

$dashboardUrl = (function_exists('rmi_layout_base_project') && ($bp = rmi_layout_base_project()) !== '') ? (rtrim($bp, '/') . '/dashboards/finance/dashboard_detail.php') : '../dashboards/finance/dashboard_detail.php';

if (!$ok) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>View Source</title>';
    echo '<style>body{font-family:system-ui;margin:24px;background:#1e293b;color:#e2e8f0;}';
    echo '.err{background:rgba(239,68,68,.2);border:1px solid #f87171;padding:12px;border-radius:8px;color:#fca5a5;}';
    echo 'a{color:#67e8f9;}</style></head><body>';
    echo '<div class="err">' . htmlspecialchars($err ?: 'Parameter file diperlukan.') . '<br><small>Klik ikon ⓘ di samping angka pada Dashboard Detail untuk membuka file sumber.</small></div>';
    echo '<p><a href="' . htmlspecialchars($dashboardUrl) . '">← Kembali ke Dashboard</a></p>';
    echo '</body></html>';
    exit;
}

$content = file_get_contents($fullPath);
$lines = explode("\n", $content);
$totalLines = count($lines);

require_once __DIR__ . '/../_shared/rmi_layout.php';
rmi_header('Source: ' . $file, 'tools', [
    'breadcrumbs' => [
        ['label' => 'Tools', 'url' => 'index.php'],
        ['label' => 'View Source', 'url' => ''],
    ],
]);
?>
<style>
.src-viewer{background:#0f172a;border:1px solid rgba(6,182,212,.3);border-radius:8px;overflow:auto;max-height:80vh;font-family:ui-monospace,monospace;font-size:13px;line-height:1.5}
.src-viewer table{width:100%;border-collapse:collapse}
.src-viewer td{vertical-align:top;padding:0 8px}
.src-viewer .ln{color:#64748b;text-align:right;user-select:none;min-width:40px;border-right:1px solid rgba(6,182,212,.2)}
.src-viewer .hl{background:rgba(6,182,212,.2)}
.src-viewer .code{color:#e2e8f0;white-space:pre-wrap;word-break:break-all}
.src-viewer .keyword{color:#c084fc}
.src-viewer .string{color:#86efac}
.src-viewer .comment{color:#64748b}
.src-meta{background:rgba(6,182,212,.1);padding:10px 16px;border-radius:8px;margin-bottom:12px;font-size:13px}
.src-meta code{background:rgba(6,182,212,.2);padding:2px 6px;border-radius:4px}
</style>
<div class="container-fluid py-3">
  <div class="src-meta">
    <strong>File:</strong> <code><?= htmlspecialchars($file) ?></code>
    <?php if ($line > 0): ?>
      | <strong>Baris:</strong> <?= (int)$line ?>
    <?php endif; ?>
    | <a href="<?= h($dashboardUrl) ?>">← Kembali ke Dashboard</a>
  </div>
  <div class="src-viewer">
    <table>
      <tbody>
      <?php foreach ($lines as $i => $ln): ?>
        <?php $num = $i + 1; $hl = ($line > 0 && $num === $line); ?>
        <tr id="L<?= $num ?>" class="<?= $hl ? 'hl' : '' ?>">
          <td class="ln"><?= $num ?></td>
          <td class="code"><?= htmlspecialchars($ln) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($line > 0 && $line <= $totalLines): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var el = document.getElementById('L<?= (int)$line ?>');
  if (el) el.scrollIntoView({ block: 'center', behavior: 'smooth' });
});
</script>
<?php endif; ?>
<?php rmi_footer(); ?>
