<?php
declare(strict_types=1);
/**
 * Panduan per halaman: gate RBAC (parent route + DOCS.PANDUAN_VIEW), render Markdown.
 */
const RMI_PANDUAN_VIEW_PERM = 'DOCS.PANDUAN_VIEW';

/**
 * Relatif project, contoh stock/wqs_pr.php (tanpa slash depan).
 */
function rmi_panduan_erp_rel_from_wrapper(string $wrapperFile): string
{
    $wrapperFile = str_replace('\\', '/', $wrapperFile);
    $base = basename($wrapperFile, '.php');
    if (!str_starts_with($base, 'panduan_')) {
        throw new InvalidArgumentException('Bukan file panduan_: ' . $base);
    }
    $parentStem = substr($base, strlen('panduan_'));
    $rootFs = defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    $rootFs = str_replace('\\', '/', rtrim($rootFs, '/'));
    $dir = str_replace('\\', '/', dirname($wrapperFile));
    $relDir = trim(str_replace($rootFs, '', $dir), '/');
    return ($relDir === '' ? '' : $relDir . '/') . $parentStem . '.php';
}

/**
 * Route HTTP seperti /stock/wqs_pr.php → stock/wqs_pr.php
 */
function rmi_panduan_route_to_erp_rel(string $route): string
{
    $r = ltrim(str_replace('\\', '/', $route), '/');
    return $r;
}

/**
 * @return array<string,mixed>|null
 */
function rmi_panduan_registry_row_for_erp(string $erpRel): ?array
{
    if (!function_exists('rmi_page_registry_all_rows')) {
        require_once __DIR__ . '/rmi_page_registry_guard.php';
    }
    foreach (rmi_page_registry_all_rows() as $item) {
        $u = (string)($item['url'] ?? '');
        if ($u === $erpRel) {
            return $item['row'];
        }
    }
    return null;
}

/**
 * @return list<string>
 */
function rmi_panduan_route_any_from_row(?array $row): array
{
    $codes = [];
    if ($row !== null) {
        $routeAny = $row['route_any'] ?? null;
        if (is_array($routeAny) && $routeAny !== []) {
            foreach ($routeAny as $c) {
                $c = strtoupper(trim((string)$c));
                if ($c !== '') {
                    $codes[] = $c;
                }
            }
        } else {
            $perms = $row['perms'] ?? [];
            if (is_array($perms)) {
                foreach (['access', 'view'] as $k) {
                    $v = $perms[$k] ?? null;
                    if ($v !== null && $v !== '') {
                        $codes[] = strtoupper(trim((string)$v));
                    }
                }
            }
        }
    }
    $codes[] = RMI_PANDUAN_VIEW_PERM;
    $codes[] = 'DOCS.VIEW';
    return array_values(array_unique(array_filter($codes)));
}

function rmi_panduan_require_for_erp(string $erpRel): void
{
    if (!function_exists('require_any_permission')) {
        return;
    }
    $row = rmi_panduan_registry_row_for_erp($erpRel);
    $codes = rmi_panduan_route_any_from_row($row);
    require_any_permission($codes);
}

function rmi_panduan_md_full_path(string $erpRel): string
{
    $stem = substr($erpRel, 0, -4);
    $dir = dirname($stem);
    $base = basename($stem);
    $root = defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    $sub = ($dir === '.' ? '' : $dir . '/') . 'panduan_' . $base . '.md';
    return $root . '/docs/panduan/' . $sub;
}

function rmi_panduan_active_nav(string $erpRel): string
{
    $seg = explode('/', str_replace('\\', '/', $erpRel), 2)[0];
    static $map = [
        'sales' => 'sales',
        'stock' => 'stock',
        'purchases' => 'purchases',
        'master' => 'master',
        'dashboards' => 'dashboard',
        'absensi' => 'absensi',
        'payroll' => 'payroll',
        'hrl' => 'hrl',
        'hrl_process' => 'hrl_process',
        'hrl_reg_alkes' => 'hrl_reg_alkes',
        'mpr' => 'mpr',
        'kpi' => 'kpi',
        'rbac' => 'rbac',
        'tools' => 'tools',
        'chat' => 'chat',
        'Fixed_Asset' => 'fixed_asset',
        'docs' => '',
    ];
    return $map[$seg] ?? '';
}

/**
 * Render markdown minimal (heading, bold, list, code, link) agar panduan
 * mudah dibaca. Bukan parser penuh — cukup untuk konten panduan internal.
 */
function rmi_panduan_render_md(string $md): string
{
    $lines = explode("\n", $md);
    $html = '';
    $inList = false;
    foreach ($lines as $line) {
        $t = rtrim($line);
        if (preg_match('/^(#{1,3})\s+(.*)$/', $t, $m)) {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $lvl = strlen($m[1]);
            $html .= '<h' . ($lvl + 3) . ' class="text-light mt-3">' . rmi_h($m[2]) . '</h' . ($lvl + 3) . '>';
        } elseif (preg_match('/^(\-|\*)\s+(.*)$/', $t, $m)) {
            if (!$inList) { $html .= '<ul class="text-light">'; $inList = true; }
            $item = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', rmi_h($m[2]));
            $html .= '<li>' . $item . '</li>';
        } elseif (trim($t) === '') {
            if ($inList) { $html .= '</ul>'; $inList = false; }
        } else {
            if ($inList) { $html .= '</ul>'; $inList = false; }
            $esc = rmi_h($t);
            $esc = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $esc);
            $esc = preg_replace('/`(.+?)`/', '<code>$1</code>', $esc);
            $html .= '<p class="text-light mb-2" style="font-size:14px;line-height:1.6">' . $esc . '</p>';
        }
    }
    if ($inList) { $html .= '</ul>'; }
    return $html;
}

/**
 * Jalankan halaman panduan dari path file wrapper panduan_*.php
 */
function rmi_panduan_run_page(string $wrapperFile): void
{
    require_once __DIR__ . '/assets.php';
    require_once dirname(__DIR__) . '/master/auth.php';
    require_login();

    require_once __DIR__ . '/rbac.php';
    require_once __DIR__ . '/helpers.php';

    $erpRel = rmi_panduan_erp_rel_from_wrapper($wrapperFile);
    rmi_panduan_require_for_erp($erpRel);

    $mdPath = rmi_panduan_md_full_path($erpRel);
    $body = (is_readable($mdPath) ? (string)file_get_contents($mdPath) : '');

    require_once __DIR__ . '/rmi_layout.php';
    $baseProject = rmi_layout_base_project();
    $parentUrl = $baseProject . '/' . $erpRel;
    $titleBase = basename($erpRel, '.php');
    $title = 'Panduan — ' . str_replace('_', ' ', $titleBase);

    $active = rmi_panduan_active_nav($erpRel);

    rmi_header($title, $active, [
        'subtitle' => 'Penjelasan untuk ' . $erpRel,
        'breadcrumbs' => [
            ['label' => '← Kembali', 'url' => $parentUrl],
            'Panduan',
        ],
        'actions' => [
            ['label' => 'Buka Fitur →', 'url' => $parentUrl, 'class' => 'btn btn-sm btn-success'],
            ['label' => 'Help Center', 'url' => $baseProject . '/docs/help_center.php', 'class' => 'btn btn-sm btn-outline-light'],
        ],
    ]);
    ?>
<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
  <span>Ini halaman panduan untuk <code><?= rmi_h($erpRel) ?></code>. Selesai membaca, langsung praktik di fiturnya.</span>
  <a class="btn btn-sm btn-success" href="<?= rmi_h($parentUrl) ?>">Buka Fitur →</a>
</div>
<div class="card border-secondary bg-dark bg-opacity-25">
  <div class="card-body">
<?php if ($body === ''): ?>
    <p class="text-secondary mb-0">Belum ada konten. SYS dapat mengisi <code><?= rmi_h(str_replace(dirname(__DIR__) . '/', '', $mdPath)) ?></code>.</p>
<?php else: ?>
    <?= rmi_panduan_render_md($body) ?>
<?php endif; ?>
  </div>
</div>
<div class="mt-3 d-flex gap-2">
  <a class="btn btn-success" href="<?= rmi_h($parentUrl) ?>">Buka Fitur →</a>
  <a class="btn btn-outline-light" href="<?= rmi_h($baseProject . '/docs/help_center.php') ?>">Help Center</a>
</div>
<?php
    rmi_footer();
}

/**
 * Tombol Panduan untuk rmi_header: null jika tidak ada wrapper di disk.
 *
 * @return array{label:string,url:string,class:string}|null
 */
function rmi_panduan_header_action(string $baseProject): ?array
{
    if (!function_exists('auth_rbac_route')) {
        return null;
    }
    $route = auth_rbac_route();
    if ($route === '' || auth_rbac_is_public($route)) {
        return null;
    }
    $rel = rmi_panduan_route_to_erp_rel($route);
    if ($rel === '' || !str_ends_with($rel, '.php')) {
        return null;
    }
    $base = basename($rel, '.php');
    if (str_starts_with($base, 'panduan_')) {
        return null;
    }
    $dir = dirname($rel);
    $panduanPhp = ($dir === '.' ? '' : $dir . '/') . 'panduan_' . $base . '.php';
    $root = defined('RMI_ROOT') ? RMI_ROOT : (realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    $full = $root . '/' . $panduanPhp;
    if (!is_file($full)) {
        return null;
    }
    return [
        'label' => 'Panduan',
        'url' => $baseProject . '/' . $panduanPhp,
        'class' => 'btn btn-sm btn-outline-info',
    ];
}
