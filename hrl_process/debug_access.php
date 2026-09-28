<?php
// /hrl_process/debug_access.php
require_once __DIR__ . '/_inc/bootstrap.php';
require_login();

header('Content-Type: text/plain; charset=utf-8');
echo "HRL Process Open Access Debug\n";
echo "=============================\n";
echo "user login: YES\n";
echo "can HRL.REQ_CUTI_VIEW: " . (function_exists('can') && can('HRL.REQ_CUTI_VIEW') ? 'YES' : 'NO') . "\n";
echo "can HRL.REQ_CUTI_CREATE: " . (function_exists('can') && can('HRL.REQ_CUTI_CREATE') ? 'YES' : 'NO') . "\n";
echo "hrlp_can_req_type CUTI CREATE: " . (function_exists('hrlp_can_req_type') && hrlp_can_req_type('CUTI', 'CREATE') ? 'YES' : 'NO') . "\n";
