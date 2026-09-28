<?php
declare(strict_types=1);

require_once __DIR__ . '/arch_audit_mask.php';
require_once __DIR__ . '/arch_audit_patterns.php';
require_once __DIR__ . '/pipeline_lib.php';

/** @return string[] */
function aa_collect_files(string $root, int $maxFiles): array
{
    $includeDirs = ['app', 'src', 'modules', 'api', 'tools', 'config', 'docs', 'sql'];
    $excludeDirs = ['storage', 'vendor', 'node_modules', 'public/assets/vendor', 'dist', 'build'];
    $extensions = ['php', 'json', 'yaml', 'yml', 'md', 'sh', 'conf', 'properties', 'env.example'];
    $all = [];
    foreach ($includeDirs as $dir) {
        $path = $root . '/' . $dir;
        if (!is_dir($path)) continue;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = substr($f->getPathname(), strlen($root) + 1);
            $skip = false;
            foreach ($excludeDirs as $ex) {
                if (str_starts_with($rel, $ex . '/') || $rel === $ex) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, $extensions, true) && !in_array($f->getFilename(), ['Dockerfile', 'docker-compose.yml', '.env.example'], true)) continue;
            $all[] = $rel;
        }
    }
    $all = array_unique($all);
    sort($all);
    $total = count($all);
    if ($total > $maxFiles) {
        $all = array_slice($all, 0, $maxFiles);
    }
    return $all;
}

/** @return array{files: string[], total_before_truncate: int, truncated: bool} */
function aa_build_file_list(string $root, int $maxFiles): array
{
    $files = aa_collect_files($root, $maxFiles);
    $total = count(aa_collect_files($root, PHP_INT_MAX));
    return [
        'files' => $files,
        'total_before_truncate' => $total,
        'truncated' => $total > $maxFiles,
    ];
}

/** @return array{type: string, file_rel_path: string, matched_pattern: string, note: string, strength: string}[] */
function aa_scan_file(string $relPath, string $root, array $patterns): array
{
    $full = $root . '/' . $relPath;
    if (!is_file($full) || !is_readable($full)) return [];
    $content = (string)@file_get_contents($full);
    $evidences = [];
    foreach ($patterns as $category => $rules) {
        $strength = 'weak';
        if (str_contains($category, '_STRONG')) $strength = 'strong';
        elseif (str_contains($category, '_MED')) $strength = 'medium';
        foreach ($rules as $patternId => $regex) {
            if (@preg_match($regex, $content) === 1) {
                $evidences[] = [
                    'type' => $category,
                    'file_rel_path' => $relPath,
                    'matched_pattern' => $patternId,
                    'note' => aa_mask_snippet(substr($content, 0, 500)),
                    'strength' => $strength,
                ];
            }
        }
    }
    return $evidences;
}

/** @return array{broker: array, data_gov: array, iam: array, monitoring: array, backup_dr: array} */
function aa_run_scan(string $root, array $fileList, array $patterns): array
{
    $domains = [
        'broker' => ['strong' => 0, 'medium' => 0, 'weak' => 0, 'fallback' => 0, 'evidences' => []],
        'data_gov' => ['strong' => 0, 'medium' => 0, 'weak' => 0, 'change_gov' => 0, 'evidences' => []],
        'iam' => ['strong' => 0, 'medium' => 0, 'weak' => 0, 'evidences' => []],
        'monitoring' => ['strong' => 0, 'medium' => 0, 'weak' => 0, 'evidences' => []],
        'backup_dr' => ['strong' => 0, 'medium' => 0, 'weak' => 0, 'evidences' => []],
    ];
    $brokerCats = ['BROKER_STRONG', 'BROKER_MED', 'BROKER_WEAK', 'BROKER_FALLBACK'];
    $dataGovCats = ['DATA_GOV_STRONG', 'DATA_GOV_MED', 'DATA_GOV_WEAK', 'CHANGE_GOV'];
    $iamCats = ['IAM_STRONG', 'IAM_MED', 'IAM_WEAK'];
    $monCats = ['MONITORING_STRONG', 'MONITORING_MED', 'MONITORING_WEAK'];
    $backupCats = ['BACKUP_STRONG', 'BACKUP_MED', 'BACKUP_WEAK'];
    $brokerPats = array_intersect_key($patterns, array_flip($brokerCats));
    $dataGovPats = array_intersect_key($patterns, array_flip($dataGovCats));
    $iamPats = array_intersect_key($patterns, array_flip($iamCats));
    $monPats = array_intersect_key($patterns, array_flip($monCats));
    $backupPats = array_intersect_key($patterns, array_flip($backupCats));
    foreach ($fileList as $rel) {
        foreach (aa_scan_file($rel, $root, $brokerPats) as $e) {
            $domains['broker']['evidences'][] = $e;
            if ($e['type'] === 'BROKER_FALLBACK') $domains['broker']['fallback']++;
            elseif ($e['strength'] === 'strong') $domains['broker']['strong']++;
            elseif ($e['strength'] === 'medium') $domains['broker']['medium']++;
            else $domains['broker']['weak']++;
        }
        foreach (aa_scan_file($rel, $root, $dataGovPats) as $e) {
            $domains['data_gov']['evidences'][] = $e;
            if ($e['type'] === 'CHANGE_GOV') $domains['data_gov']['change_gov']++;
            elseif ($e['strength'] === 'strong') $domains['data_gov']['strong']++;
            elseif ($e['strength'] === 'medium') $domains['data_gov']['medium']++;
            else $domains['data_gov']['weak']++;
        }
        foreach (aa_scan_file($rel, $root, $iamPats) as $e) {
            $domains['iam']['evidences'][] = $e;
            if ($e['strength'] === 'strong') $domains['iam']['strong']++;
            elseif ($e['strength'] === 'medium') $domains['iam']['medium']++;
            else $domains['iam']['weak']++;
        }
        foreach (aa_scan_file($rel, $root, $monPats) as $e) {
            $domains['monitoring']['evidences'][] = $e;
            if ($e['strength'] === 'strong') $domains['monitoring']['strong']++;
            elseif ($e['strength'] === 'medium') $domains['monitoring']['medium']++;
            else $domains['monitoring']['weak']++;
        }
        foreach (aa_scan_file($rel, $root, $backupPats) as $e) {
            $domains['backup_dr']['evidences'][] = $e;
            if ($e['strength'] === 'strong') $domains['backup_dr']['strong']++;
            elseif ($e['strength'] === 'medium') $domains['backup_dr']['medium']++;
            else $domains['backup_dr']['weak']++;
        }
    }
    return $domains;
}

function aa_score_domain(array $d, bool $truncated, string $domainKey): array
{
    $strong = $d['strong'] ?? 0;
    $medium = $d['medium'] ?? 0;
    $weak = $d['weak'] ?? 0;
    $confidence = min(100, $strong * 30 + $medium * 15 + $weak * 5);
    if ($truncated && $confidence < 20) {
        return ['status' => 'UNKNOWN', 'confidence' => $confidence, 'reason' => 'truncated_scan'];
    }
    if ($confidence >= 60 && ($strong >= 1 || $medium >= 2)) {
        return ['status' => 'PRESENT', 'confidence' => $confidence, 'reason' => 'sufficient'];
    }
    if ($confidence >= 20) {
        return ['status' => 'PARTIAL', 'confidence' => $confidence, 'reason' => 'partial'];
    }
    return ['status' => 'NOT_FOUND', 'confidence' => $confidence, 'reason' => 'insufficient'];
}

/** @return array{status: string, confidence: int, evidence: array, risk_note: string, recommendations: string[], broker_present?: bool, streaming_present?: bool, fallback_queue_present?: bool, change_gov_status?: string, data_quality_status?: string, iam_maturity?: string, backup_present?: string, restore_safe?: string, dr_drill_evidence?: string} */
function aa_domain_report(string $domain, array $raw, array $scored, bool $truncated): array
{
    $evidences = $raw['evidences'] ?? [];
    $out = [
        'status' => $scored['status'],
        'confidence' => (int)$scored['confidence'],
        'evidence' => array_map(static function ($e) {
            return [
                'type' => $e['type'],
                'file' => $e['file_rel_path'],
                'pattern' => $e['matched_pattern'],
                'strength' => $e['strength'],
            ];
        }, array_slice($evidences, 0, 20)),
        'risk_note' => '',
        'recommendations' => [],
    ];
    switch ($domain) {
        case 'broker':
            $brokerPresent = ($raw['strong'] ?? 0) >= 1 || ($raw['medium'] ?? 0) >= 2;
            $fallback = ($raw['fallback'] ?? 0) >= 1;
            $out['broker_present'] = $brokerPresent;
            $out['streaming_present'] = $brokerPresent;
            $out['fallback_queue_present'] = $fallback;
            $out['risk_note'] = $brokerPresent ? 'Message broker detected.' : ($fallback ? 'Only fallback queue (DB/cron) detected.' : 'No message broker or queue detected.');
            $out['recommendations'] = $brokerPresent ? [] : ['Consider RabbitMQ/Kafka for async workloads.'];
            break;
        case 'data_gov':
            $changeGov = ($raw['change_gov'] ?? 0) >= 1;
            $dq = ($raw['strong'] ?? 0) >= 1 || ($raw['medium'] ?? 0) >= 2;
            $out['change_gov_status'] = $changeGov ? 'PRESENT' : 'NOT_FOUND';
            $out['data_quality_status'] = $dq ? 'PRESENT' : ($scored['status'] === 'PARTIAL' ? 'PARTIAL' : 'NOT_FOUND');
            $out['risk_note'] = !$dq ? 'Data quality engine not detected.' : '';
            $out['recommendations'] = !$dq ? ['Add data validation pipeline and deduplication.'] : [];
            break;
        case 'iam':
            $strong = $raw['strong'] ?? 0;
            $out['iam_maturity'] = $strong >= 3 ? 'L4' : ($strong >= 2 ? 'L3' : ($strong >= 1 || ($raw['medium'] ?? 0) >= 1 ? 'L2' : 'L1'));
            $out['risk_note'] = $out['iam_maturity'] === 'L1' ? 'Basic login only; consider RBAC.' : '';
            $out['recommendations'] = $out['iam_maturity'] === 'L1' ? ['Implement RBAC and permission checks.'] : [];
            break;
        case 'monitoring':
            $out['risk_note'] = $scored['status'] === 'NOT_FOUND' ? 'No monitoring detected.' : '';
            $out['recommendations'] = $scored['status'] === 'NOT_FOUND' ? ['Add health endpoint and smoke tests.'] : [];
            break;
        case 'backup_dr':
            $strong = $raw['strong'] ?? 0;
            $out['backup_present'] = $strong >= 1 ? 'yes' : (($raw['medium'] ?? 0) >= 1 ? 'partial' : 'no');
            $out['restore_safe'] = (str_contains(implode(' ', array_column($evidences, 'pattern')), 'dry') || str_contains(implode(' ', array_column($evidences, 'file_rel_path')), 'restore')) ? 'has_confirm_guard' : 'unknown';
            $out['dr_drill_evidence'] = $strong >= 2 ? 'yes' : (($raw['medium'] ?? 0) >= 1 ? 'partial' : 'no');
            $out['risk_note'] = $out['backup_present'] === 'no' ? 'No backup tool detected.' : '';
            $out['recommendations'] = $out['backup_present'] === 'no' ? ['Add backup script and retention policy.'] : [];
            break;
    }
    return $out;
}

/** @return array{state_version: int, generated_at: string, run_id: string, scan_coverage: array, inventory: array, domains: array} */
function aa_build_report(string $root, string $runId, array $fileListResult, array $domainsRaw, bool $strict): array
{
    $truncated = $fileListResult['truncated'] ?? false;
    $files = $fileListResult['files'] ?? [];
    $total = $fileListResult['total_before_truncate'] ?? count($files);
    $inventory = aa_inventory($root);
    $domainsOut = [];
    $domainKeys = ['broker', 'data_gov', 'iam', 'monitoring', 'backup_dr'];
    foreach ($domainKeys as $key) {
        $raw = $domainsRaw[$key] ?? ['strong' => 0, 'medium' => 0, 'weak' => 0, 'evidences' => []];
        $scored = aa_score_domain($raw, $truncated, $key);
        $domainsOut[$key] = aa_domain_report($key, $raw, $scored, $truncated);
    }
    return [
        'state_version' => 1,
        'generated_at' => date(DateTimeInterface::ATOM),
        'run_id' => $runId,
        'scan_coverage' => [
            'files_scanned' => count($files),
            'total_eligible' => $total,
            'truncated' => $truncated,
            'excluded_dirs' => ['storage', 'vendor', 'node_modules', 'public/assets/vendor', 'dist', 'build'],
        ],
        'inventory' => $inventory,
        'domains' => $domainsOut,
    ];
}

/** @return array<string, array{exists: bool, size: int}> */
function aa_inventory(string $root): array
{
    $items = [
        'composer.json' => $root . '/composer.json',
        'composer.lock' => $root . '/composer.lock',
        'package.json' => $root . '/package.json',
        'pnpm-lock.yaml' => $root . '/pnpm-lock.yaml',
        'yarn.lock' => $root . '/yarn.lock',
        'docker-compose.yml' => $root . '/docker-compose.yml',
        'Dockerfile' => $root . '/Dockerfile',
        'github_workflows' => $root . '/.github/workflows',
        'config' => $root . '/config',
        'sql_migrations' => $root . '/sql/migrations',
        'tools' => $root . '/tools',
        'docs' => $root . '/docs',
    ];
    $out = [];
    foreach ($items as $name => $path) {
        $exists = is_file($path) || is_dir($path);
        $size = $exists ? (is_dir($path) ? 0 : (int)@filesize($path)) : 0;
        $out[$name] = ['exists' => $exists, 'size' => $size];
    }
    return $out;
}

function aa_precheck(string $root): array
{
    $ok = is_dir($root . '/tools');
    $markers = ['app', 'modules', 'src', 'composer.json', 'package.json'];
    $hasMarker = false;
    foreach ($markers as $m) {
        if (is_dir($root . '/' . $m) || is_file($root . '/' . $m)) {
            $hasMarker = true;
            break;
        }
    }
    return [
        'valid' => $ok && $hasMarker,
        'reason' => !$ok ? 'tools/ directory missing' : (!$hasMarker ? 'no app/modules/src/composer.json/package.json' : ''),
    ];
}
