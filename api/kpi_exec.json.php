<?php
/**
 * api/kpi_exec.json.php
 * Optional endpoint untuk kebutuhan chart (AJAX) dari dashboard owner.
 *
 * Saat ini: stub (placeholder).
 * Nanti Phase 2 bisa diisi output JSON: revenue trend, AR aging, inventory trend, dll.
 */

require_once __DIR__ . '/../dashboards/_dashboard_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// RBAC minimal: login
if (function_exists('require_login')) {
  @require_login();
}

echo json_encode([
  'ok' => true,
  'message' => 'kpi_exec endpoint placeholder. Phase 2: implement JSON charts.',
  'data' => [
    'status' => 'placeholder',
    'charts' => [],
  ],
  'request_id' => 'req-' . date('YmdHis') . '-' . substr(sha1((string)microtime(true)), 0, 10),
], JSON_PRETTY_PRINT);
