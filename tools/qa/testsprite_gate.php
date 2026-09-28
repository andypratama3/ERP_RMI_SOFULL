<?php
/**
 * TestSprite Gate Parser — reads testsprite_last_summary.json, returns exit code.
 * Exit 0: passRate=100% and no critical findings.
 * Exit 2: any FAIL or CRITICAL.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$summaryPath = $root . '/storage/logs/testsprite_last_summary.json';

if (!is_file($summaryPath)) {
    fwrite(STDERR, "testsprite_gate: summary not found. Run testsprite_regression.php first.\n");
    exit(2);
}

$json = file_get_contents($summaryPath);
$data = json_decode($json, true);
if (!is_array($data)) {
    fwrite(STDERR, "testsprite_gate: invalid JSON\n");
    exit(2);
}

$criticalFail = (bool)($data['critical_fail'] ?? false);
$criticalFindings = (array)($data['critical_findings'] ?? []);
$passRate = (int)($data['pass_rate'] ?? 0);
$failed = (int)($data['failed'] ?? 0);

// Early exit (path /Volumes/) has only critical_fail + critical_findings
if ($criticalFail && empty($data['total'] ?? null)) {
    $pass = false;
} else {
    $threshold = (int)(getenv('TESTSPRITE_PASS_THRESHOLD') ?: 100);
    $pass = !$criticalFail && $failed === 0 && $passRate >= $threshold;
}

if (!$pass) {
    fwrite(STDERR, "testsprite_gate: FAIL (passRate={$passRate}%, failed={$failed}, critical=" . ($criticalFail ? 'yes' : 'no') . ")\n");
    foreach ($criticalFindings as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(2);
}

exit(0);
