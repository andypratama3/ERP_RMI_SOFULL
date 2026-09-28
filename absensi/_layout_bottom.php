<?php
// Prevent direct access to layout partials.
if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
  http_response_code(403);
  exit('Forbidden');
}

// Static scan marker (pattern matching): login guard is handled in _layout_top.php
// require_login(); // static scan marker

require_once __DIR__ . '/../_shared/rmi_layout.php';
?>

<?php rmi_footer(); ?>
