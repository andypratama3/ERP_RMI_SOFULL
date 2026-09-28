<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib/bootstrap.php';
require_once __DIR__ . '/../_lib/tools_ui_helpers.php';

tools_require_access('hardening/erp_hardening_triage_run.php');

function triage_uuid(): string
{
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
}

function triage_strip_comments(string $php): string
{
    $tokens = token_get_all($php);
    $out = '';
    foreach ($tokens as $token) {
        if (is_string($token)) {
            $out .= $token;
            continue;
        }
        [$type, $text] = $token;
        if ($type === T_COMMENT || $type === T_DOC_COMMENT) {
            $out .= str_repeat("\n", substr_count($text, "\n"));
            continue;
        }
        $out .= $text;
    }
    return $out;
}

function triage_path_ignored(string $relPath, array $ignore): bool
{
    foreach ($ignore as $pattern) {
        $p = trim((string)$pattern);
        if ($p === '' || str_starts_with($p, '#')) {
            continue;
        }
        $p = str_replace('\\', '/', $p);
        if (str_ends_with($p, '/')) {
            if (str_starts_with($relPath, $p)) {
                return true;
            }
            continue;
        }
        if ($relPath === $p) {
            return true;
        }
    }
    return false;
}

function triage_add_finding(array &$store, array $finding): void
{
    $evidenceKey = (string)($finding['evidence_key'] ?? '');
    $sig = hash('sha256', (string)$finding['rule_id'] . '|' . (string)$finding['file_path'] . '|' . $evidenceKey);
    if (isset($store[$sig])) {
        return;
    }
    $store[$sig] = $finding + ['_sig' => $sig];
}

$root = APP_ROOT;
$requestId = triage_uuid();
$generatedAt = date(DateTimeInterface::ATOM);
$rulesPath = APP_ROOT . '/tools/hardening/policies/triage_rules_v1.json';
$rulesCfg = tools_json_read_safe($rulesPath);
$rulesVersion = (string)($rulesCfg['rules_version'] ?? 'v1');
$ignoreFile = APP_ROOT . '/tools/hardening/.triageignore';
$ignore = [];
if (is_file($ignoreFile)) {
    $ignore = @file($ignoreFile, FILE_IGNORE_NEW_LINES) ?: [];
} else {
    $ignore = @file(APP_ROOT . '/tools/hardening/.triageignore.example', FILE_IGNORE_NEW_LINES) ?: [];
}

$scanDirs = ['tools'];
$findings = [];
$filesScanned = 0;
foreach ($scanDirs as $scanDir) {
    $absDir = $root . '/' . $scanDir;
    if (!is_dir($absDir)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if (triage_path_ignored($rel, $ignore)) {
            continue;
        }
        $filesScanned++;
        $src = (string)@file_get_contents($file->getPathname());
        if ($src === '') {
            continue;
        }
        $clean = triage_strip_comments($src);
        $lines = explode("\n", $clean);

        if (!str_contains($src, 'declare(strict_types=1);')) {
            triage_add_finding($findings, [
                'rule_id' => 'TRIAGE-ENG-0001',
                'file_path' => $rel,
                'evidence_key' => 'missing_strict_types',
                'severity' => 'P2',
                'category' => 'ENGINEERING',
                'title' => 'Missing declare(strict_types=1)',
                'line' => 1,
                'evidence' => 'declare(strict_types=1) not found',
                'autofix_allowed' => true,
                'manual_action_required' => false,
                'owner' => 'ENG',
            ]);
        }

        $hasAuthInclude = preg_match('/require_once\s+__DIR__\s*\.\s*[\'"]\/\.\.\/\.\.\/master\/auth\.php[\'"]/i', $clean) === 1
            || preg_match('/require_once\s+__DIR__\s*\.\s*[\'"]\/\.\.\/master\/auth\.php[\'"]/i', $clean) === 1;
        $isWebTools = str_contains($clean, 'rmi_header(') && $hasAuthInclude;
        if ($isWebTools && !preg_match('/require_login\s*\(/', $clean)) {
            triage_add_finding($findings, [
                'rule_id' => 'TRIAGE-SEC-0002',
                'file_path' => $rel,
                'evidence_key' => 'missing_require_login',
                'severity' => 'P1',
                'category' => 'SECURITY',
                'title' => 'Web tools page without require_login guard',
                'line' => 1,
                'evidence' => 'rmi_header found but require_login missing',
                'autofix_allowed' => false,
                'manual_action_required' => true,
                'owner' => 'SEC',
            ]);
        }

        if ($isWebTools && str_contains($clean, '$_POST') && !preg_match('/verify_csrf|csrf_token|rmi_csrf_verify|rmi_csrf_validate/i', $clean)) {
            $lineNo = 1;
            foreach ($lines as $idx => $line) {
                if (str_contains($line, '$_POST')) {
                    $lineNo = $idx + 1;
                    break;
                }
            }
            triage_add_finding($findings, [
                'rule_id' => 'TRIAGE-SEC-0001',
                'file_path' => $rel,
                'evidence_key' => 'post_without_csrf:' . $lineNo,
                'severity' => 'P0',
                'category' => 'SECURITY',
                'title' => 'POST handler without CSRF verification',
                'line' => $lineNo,
                'evidence' => '$_POST found without csrf verification pattern',
                'autofix_allowed' => false,
                'manual_action_required' => true,
                'owner' => 'SEC',
            ]);
        }
    }
}

$rank = ['P0' => 0, 'P1' => 1, 'P2' => 2];
$rows = array_values($findings);
usort($rows, static function (array $a, array $b) use ($rank): int {
    $ra = $rank[(string)($a['severity'] ?? 'P2')] ?? 9;
    $rb = $rank[(string)($b['severity'] ?? 'P2')] ?? 9;
    if ($ra !== $rb) return $ra <=> $rb;
    $ca = (string)($a['category'] ?? '');
    $cb = (string)($b['category'] ?? '');
    if ($ca !== $cb) return strcmp($ca, $cb);
    $fa = (string)($a['file_path'] ?? '');
    $fb = (string)($b['file_path'] ?? '');
    if ($fa !== $fb) return strcmp($fa, $fb);
    return strcmp((string)($a['rule_id'] ?? ''), (string)($b['rule_id'] ?? ''));
});

$p0 = 0;
$p1 = 0;
$p2 = 0;
$findingOut = [];
foreach ($rows as $idx => $f) {
    $sev = (string)($f['severity'] ?? 'P2');
    if ($sev === 'P0') $p0++;
    if ($sev === 'P1') $p1++;
    if ($sev === 'P2') $p2++;
    $findingOut[] = [
        'id' => 'TRIAGE-' . str_pad((string)($idx + 1), 4, '0', STR_PAD_LEFT),
        'severity' => $sev,
        'category' => (string)($f['category'] ?? 'ENGINEERING'),
        'title' => (string)($f['title'] ?? ''),
        'evidence_masked' => tools_mask_sensitive((string)($f['evidence'] ?? '')),
        'files' => [[
            'path_masked' => tools_mask_sensitive('[APP_ROOT]/' . (string)($f['file_path'] ?? '')),
            'line' => (int)($f['line'] ?? 1),
        ]],
        'autofix_allowed' => (bool)($f['autofix_allowed'] ?? false),
        'manual_action_required' => (bool)($f['manual_action_required'] ?? true),
        'owner' => (string)($f['owner'] ?? 'ENG'),
    ];
}

$score = max(0, 100 - ($p0 * 25) - ($p1 * 8) - ($p2 * 2));
$status = $score >= 85 ? 'HEALTHY' : ($score >= 70 ? 'ATTENTION' : 'CRITICAL');
$signatureSeed = json_encode([
    'files_scanned' => $filesScanned,
    'rules_version' => $rulesVersion,
    'generated_at' => $generatedAt,
    'findings_count' => count($findingOut),
], JSON_UNESCAPED_SLASHES) ?: '';

$payload = [
    'state_version' => 1,
    'request_id' => $requestId,
    'generated_at' => $generatedAt,
    'rules_version' => $rulesVersion,
    'signature' => hash('sha256', $signatureSeed),
    'summary' => [
        'score' => $score,
        'status' => $status,
        'p0' => $p0,
        'p1' => $p1,
        'p2' => $p2,
    ],
    'findings' => $findingOut,
    'notes_masked' => [
        'scan_root=[APP_ROOT]',
        'files_scanned=' . $filesScanned,
        'rules=' . $rulesVersion,
    ],
];

$lastPath = APP_ROOT . '/storage/logs/erp_hardening_triage_web.last.json';
$histPath = APP_ROOT . '/storage/logs/erp_hardening_triage_history.jsonl';
tools_json_write_atomic($lastPath, $payload);
@file_put_contents($histPath, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
echo json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($p0 > 0 ? 1 : 0);
