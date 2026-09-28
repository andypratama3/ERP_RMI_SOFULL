<?php
declare(strict_types=1);
/**
 * Diagram Suite Generator — SVG per module + per file + RMI logo stamp.
 * RUN FROM NAS: cd /volume4/web/ERP_RMI_SOFULL && php tools/diagrams/generate_diagrams.php --mode=all --strict --write-last
 */
require_once __DIR__ . '/../_shared/tools_path_redact.php';
$APP_ROOT = '/volume4/web/ERP_RMI_SOFULL';
$requestId = 'diagrams_' . date('YmdHis');
$generatedAt = date('c');

// Preflight A1: path MUST be NAS
$cwd = getcwd() ?: '';
if ($cwd !== $APP_ROOT) {
    $assumptions = [
        'request_id' => $requestId,
        'generated_at' => $generatedAt,
        'items' => [[
            'severity' => 'CRITICAL',
            'code' => 'APP_ROOT_MISMATCH',
            'message' => "pwd=$cwd, expected=$APP_ROOT",
            'hint' => 'SSH to NAS, cd /volume4/web/ERP_RMI_SOFULL, then re-run.'
        ]]
    ];
    tools_safe_json_file_put_contents(__DIR__ . '/../../storage/logs/assumptions_last.json', $assumptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    tools_safe_json_file_put_contents(__DIR__ . '/../../storage/logs/diagrams_generate_last.json', [
        'request_id' => $requestId, 'generated_at' => $generatedAt, 'ok' => false,
        'reason' => 'APP_ROOT_MISMATCH', 'counts' => ['module_count' => 0, 'file_count' => 0], 'warnings' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, "CRITICAL: Run from NAS only. pwd=$cwd\n");
    exit(1);
}

// Logo check
$logoPath = $APP_ROOT . '/docs/brand/rmi_logo.png';
if (!is_file($logoPath)) {
    $assumptions = [
        'request_id' => $requestId, 'generated_at' => $generatedAt,
        'items' => [['severity' => 'CRITICAL', 'code' => 'LOGO_MISSING', 'message' => 'docs/brand/rmi_logo.png not found', 'hint' => 'Place logo PNG at docs/brand/rmi_logo.png']]
    ];
    tools_safe_json_file_put_contents(__DIR__ . '/../../storage/logs/assumptions_last.json', $assumptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, "CRITICAL: Place logo at docs/brand/rmi_logo.png\n");
    exit(1);
}

// Graphviz check
exec('dot -V 2>&1', $dv, $dc);
if ($dc !== 0) {
    $assumptions = [
        'request_id' => $requestId, 'generated_at' => $generatedAt,
        'items' => [['severity' => 'CRITICAL', 'code' => 'GRAPHVIZ_MISSING', 'message' => 'dot not found', 'hint' => 'Install graphviz (dot) or use docker']]
    ];
    tools_safe_json_file_put_contents(__DIR__ . '/../../storage/logs/assumptions_last.json', $assumptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, "CRITICAL: Install graphviz (dot)\n");
    exit(1);
}

require_once __DIR__ . '/lib/sanitize.php';
require_once __DIR__ . '/lib/scan.php';
require_once __DIR__ . '/lib/parse_php.php';
require_once __DIR__ . '/lib/graph_build.php';
require_once __DIR__ . '/lib/render_dot.php';
require_once __DIR__ . '/lib/svg_stamp.php';
require_once __DIR__ . '/lib/write_evidence.php';

$diagramsDir = $APP_ROOT . '/docs/diagrams';
$logoSha = hash_file('sha256', $logoPath);
file_put_contents($diagramsDir . '/assets/logo.sha256', $logoSha);
copy($logoPath, $diagramsDir . '/assets/rmi_logo.png');

$modules = diagrams_scan_modules($APP_ROOT);
$moduleSvg = [];
foreach ($modules as $mod) {
    $dot = "digraph G {\n  rankdir=TB;\n  label=\"$mod\";\n  \"$mod\" [shape=box];\n}\n";
    $name = diagrams_sanitize_filename($mod);
    $svgPath = $diagramsDir . '/modules/' . $name . '.svg';
    $dotPath = $diagramsDir . '/dot/' . $name . '.dot';
    @file_put_contents($dotPath, $dot);
    if (diagrams_render_dot_to_svg($dot, $svgPath)) {
        $svg = file_get_contents($svgPath);
        $svg = diagrams_stamp_svg($svg, $mod, $requestId, $generatedAt, $logoPath);
        file_put_contents($svgPath, $svg);
        $moduleSvg[] = ['name' => $mod, 'svg' => 'docs/diagrams/modules/' . $name . '.svg'];
    }
}

$files = diagrams_scan_php_files($APP_ROOT);
$fileSvg = [];
$limit = min(count($files), 200);
for ($i = 0; $i < $limit; $i++) {
    $rel = $files[$i];
    $full = $APP_ROOT . '/' . $rel;
    if (!is_file($full)) continue;
    $content = @file_get_contents($full) ?: '';
    $includes = diagrams_parse_includes($content);
    $tables = diagrams_parse_tables($content);
    $guards = diagrams_parse_guards($content);
    $nodes = [['id' => 'file', 'label' => basename($rel)]];
    $edges = [];
    foreach (array_slice($includes, 0, 5) as $inc) {
        $nodes[] = ['id' => $inc, 'label' => basename($inc)];
        $edges[] = ['from' => 'file', 'to' => $inc];
    }
    $dot = diagrams_build_dot($rel, $nodes, $edges, 'file');
    $name = diagrams_sanitize_filename(str_replace('/', '_', $rel));
    $svgPath = $diagramsDir . '/files/' . $name . '.svg';
    if (diagrams_render_dot_to_svg($dot, $svgPath)) {
        $svg = file_get_contents($svgPath);
        $svg = diagrams_stamp_svg($svg, $rel, $requestId, $generatedAt, $logoPath);
        file_put_contents($svgPath, $svg);
        $fileSvg[] = ['path' => $rel, 'svg' => 'docs/diagrams/files/' . $name . '.svg'];
    }
}

$manifest = [
    'generated_at' => $generatedAt,
    'request_id' => $requestId,
    'app_root' => $APP_ROOT,
    'logo_sha256' => $logoSha,
    'modules' => $moduleSvg,
    'files' => $fileSvg,
    'stats' => ['module_count' => count($moduleSvg), 'file_count' => count($fileSvg), 'warnings' => 0]
];
diagrams_write_evidence($diagramsDir . '/manifest.json', $manifest);

$indexMd = "# Diagram Suite\n\nGenerated: $generatedAt | Request: $requestId\n\n## Module Diagrams\n\n";
foreach ($moduleSvg as $m) {
    $indexMd .= "- [{$m['name']}]({$m['svg']})\n";
}
$indexMd .= "\n## File Diagrams\n\n";
foreach ($fileSvg as $f) {
    $indexMd .= "- [{$f['path']}]({$f['svg']})\n";
}
file_put_contents($diagramsDir . '/INDEX.md', $indexMd);

diagrams_write_evidence($APP_ROOT . '/storage/logs/diagrams_generate_last.json', [
    'request_id' => $requestId, 'generated_at' => $generatedAt, 'ok' => true,
    'counts' => ['module_count' => count($moduleSvg), 'file_count' => count($fileSvg)], 'durations' => [], 'warnings' => []
]);

echo "OK: " . count($moduleSvg) . " modules, " . count($fileSvg) . " files\n";
