<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
$contractFile = $root . '/tools/qa/contract_whitelist_mobile.json';
$endpointsFile = $root . '/tools/qa/http_endpoints_mobile.json';

if (!is_file($contractFile) || !is_file($endpointsFile)) {
    fwrite(STDERR, "FAIL: Missing contract_whitelist_mobile.json or http_endpoints_mobile.json.\n");
    fwrite(STDERR, "Fix: Create these files explicitly and commit to repo.\n");
    fwrite(STDERR, "  - tools/qa/contract_whitelist_mobile.json\n");
    fwrite(STDERR, "  - tools/qa/http_endpoints_mobile.json\n");
    fwrite(STDERR, "Add endpoints for:\n");
    fwrite(STDERR, "  - /api/v1/mobile/auth/*\n");
    fwrite(STDERR, "  - /api/v1/mobile/dashboard/*\n");
    fwrite(STDERR, "  - /api/v1/mobile/tasks/*\n");
    fwrite(STDERR, "Add locked policy checks:\n");
    fwrite(STDERR, "  - /api/v1/mobile/master/* => expect 403\n");
    fwrite(STDERR, "Set MOBILE_QA_USER, MOBILE_QA_PASS for master policy smoke.\n");
    exit(1);
}

$j = @json_decode((string)file_get_contents($contractFile), true);
if (!is_array($j)) {
    fwrite(STDERR, "FAIL: contract_whitelist_mobile.json invalid JSON\n");
    exit(1);
}

$j = @json_decode((string)file_get_contents($endpointsFile), true);
if (!is_array($j)) {
    fwrite(STDERR, "FAIL: http_endpoints_mobile.json invalid JSON\n");
    exit(1);
}

exit(0);
