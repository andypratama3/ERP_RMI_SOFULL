<?php
declare(strict_types=1);

require_once __DIR__ . '/../ops_gov_common.php';
require_once __DIR__ . '/../../_shared/rmi_layout.php';

function sop_verify_collect_links(array $map): array
{
    $links = [];
    $default = (array)($map['default'] ?? []);
    foreach ((array)($default['links'] ?? []) as $l) {
        $u = (string)($l['url'] ?? '');
        if ($u !== '') $links[] = $u;
    }
    foreach ((array)($map['patterns'] ?? []) as $p) {
        foreach ((array)($p['links'] ?? []) as $l) {
            $u = (string)($l['url'] ?? '');
            if ($u !== '') $links[] = $u;
        }
    }
    $links = array_values(array_unique($links));
    sort($links);
    return $links;
}

function sop_verify_one(string $root, string $url): array
{
    if (!str_starts_with($url, '/')) {
        return ['url' => $url, 'status' => 'WARN', 'reason' => 'non_root_relative_url'];
    }
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return ['url' => $url, 'status' => 'WARN', 'reason' => 'external_url_not_checked'];
    }
    $cleanUrl = parse_url($url, PHP_URL_PATH);
    $cleanUrl = is_string($cleanUrl) && $cleanUrl !== '' ? $cleanUrl : $url;
    $path = $root . $cleanUrl;
    if (str_ends_with($url, '/')) {
        $path .= 'index.php';
    }
    if (is_file($path)) {
        return ['url' => $url, 'status' => 'OK', 'reason' => 'exists'];
    }
    // Legacy docs that are optional should not block gate.
    if (str_contains($cleanUrl, 'SOP_MASTER_DATA_PRINT.html')) {
        return ['url' => $url, 'status' => 'WARN', 'reason' => 'legacy_optional_missing'];
    }
    return ['url' => $url, 'status' => 'FAIL', 'reason' => 'missing'];
}

function sop_verify_run(): array
{
    $root = opsgov_root();
    $mapPath = $root . '/docs/help_sop_map.json';
    $map = opsgov_read_json($mapPath);
    if (!$map) {
        return ['ok' => false, 'message' => 'help_sop_map.json tidak valid'];
    }
    $urls = sop_verify_collect_links($map);
    $rows = [];
    $ok = 0; $warn = 0; $fail = 0;
    foreach ($urls as $u) {
        $r = sop_verify_one($root, (string)$u);
        $rows[] = $r;
        if ($r['status'] === 'OK') $ok++;
        if ($r['status'] === 'WARN') $warn++;
        if ($r['status'] === 'FAIL') $fail++;
    }
    $payload = [
        'state_version' => 1,
        'checked_at' => date(DateTimeInterface::ATOM),
        'summary' => ['total' => count($rows), 'ok' => $ok, 'warn' => $warn, 'fail' => $fail],
        'rows' => $rows,
        'fallback' => '/docs/governance/OPS_RUNBOOK_FINAL.md#general',
    ];
    opsgov_safe_write_json($root . '/storage/logs/sop_link_verify_last.json', $payload);
    $md = [
        '# SOP Link Verify',
        '',
        '- Checked at: ' . $payload['checked_at'],
        '- Total: ' . count($rows),
        '- OK: ' . $ok,
        '- WARN: ' . $warn,
        '- FAIL: ' . $fail,
        '- Fallback: `'.$payload['fallback'].'`',
        '',
        '## Rows',
    ];
    foreach ($rows as $r) {
        $md[] = '- [' . $r['status'] . '] `' . $r['url'] . '` => ' . $r['reason'];
    }
    @file_put_contents($root . '/storage/logs/sop_link_verify_last.md', implode("\n", $md) . "\n");
    ts_append_run_history('verify_sop_links', $fail > 0 ? 'WARN' : 'OK', [
        'module' => 'tools.dev',
        'action' => 'verify_sop_links',
        'result' => $fail > 0 ? 'WARN' : 'OK',
        'fail_count' => $fail,
        'warn_count' => $warn,
    ]);
    return ['ok' => true, 'data' => $payload];
}

if (PHP_SAPI === 'cli') {
    echo json_encode(sop_verify_run(), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit;
}

opsgov_require_admin();
$msg = '';
$type = 'info';
$last = opsgov_read_json(opsgov_root() . '/storage/logs/sop_link_verify_last.json');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
    $r = sop_verify_run();
    $msg = !empty($r['ok']) ? 'Verify SOP links selesai.' : (string)($r['message'] ?? 'Verify gagal');
    $type = !empty($r['ok']) ? 'success' : 'danger';
    $last = (array)($r['data'] ?? []);
}
$baseProject = rmi_layout_base_project();
rmi_header('Verify SOP Links', ['active' => 'tools', 'breadcrumbs' => [['label' => 'Tools', 'url' => $baseProject . '/tools/index.php'], 'Verify SOP Links']]);
?>
<div class="card p-3">
  <?php if ($msg !== ''): ?><div class="alert alert-<?= opsgov_h($type) ?>"><?= opsgov_h($msg) ?></div><?php endif; ?>
  <div class="small mb-2">Validasi tautan SOP/F1 agar tidak 404. Fallback default: <code>/docs/governance/OPS_RUNBOOK_FINAL.md#general</code>.</div>
  <form method="post" class="mb-3">
    <input type="hidden" name="csrf_token" value="<?= opsgov_h((string)csrf_token()) ?>">
    <button class="btn btn-sm btn-rmi">Run Verify</button>
  </form>
  <pre class="mb-0 small"><?= opsgov_h(json_encode($last, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>
<?php rmi_footer(); ?>
