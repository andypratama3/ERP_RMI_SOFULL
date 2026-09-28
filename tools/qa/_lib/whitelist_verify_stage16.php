<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
$contractFile = $root . '/tools/qa/contract_whitelist_stage16.json';
$endpointsFile = $root . '/tools/qa/http_endpoints_stage16.json';
if (!is_file($contractFile) || !is_file($endpointsFile)) {
    fwrite(STDERR, "FAIL: Missing contract_whitelist_stage16.json or http_endpoints_stage16.json. Fix: php tools/qa/generate_whitelists.php --verify-only --stage=16. If endpoints missing: Commit tools/qa/http_endpoints_stage16.json\n");
    exit(1);
}
$j = @json_decode((string)file_get_contents($contractFile), true);
if (!is_array($j)) {
    fwrite(STDERR, "FAIL: contract_whitelist_stage16.json invalid JSON\n");
    exit(1);
}
$j = @json_decode((string)file_get_contents($endpointsFile), true);
if (!is_array($j)) {
    fwrite(STDERR, "FAIL: http_endpoints_stage16.json invalid JSON\n");
    exit(1);
}
exit(0);
