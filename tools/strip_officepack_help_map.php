<?php
/**
 * One-shot: hapus referensi officepack dari docs/help_sop_map.json
 */
declare(strict_types=1);

$path = dirname(__DIR__) . '/docs/help_sop_map.json';
$raw = file_get_contents($path);
if ($raw === false) {
  fwrite(STDERR, "Cannot read $path\n");
  exit(1);
}
$d = json_decode($raw, true);
if (!is_array($d)) {
  fwrite(STDERR, "Invalid JSON\n");
  exit(1);
}

$strip = static function (array &$node): void {
  foreach (['diagram', 'sop', 'manual', 'quick_start', 'sop_keluhan', 'sop_recall'] as $k) {
    if (!isset($node[$k])) {
      continue;
    }
    $v = $node[$k];
    if (is_string($v) && (str_contains($v, 'officepack_view') || str_contains($v, 'officepack/'))) {
      unset($node[$k]);
    }
  }
  if (isset($node['links']) && is_array($node['links'])) {
    $node['links'] = array_values(array_filter($node['links'], static function ($l) {
      if (!is_array($l) || empty($l['url'])) {
        return true;
      }
      $u = (string)$l['url'];

      return !str_contains($u, 'officepack_view') && !str_contains($u, 'officepack/');
    }));
  }
};

if (isset($d['default']) && is_array($d['default'])) {
  $strip($d['default']);
  $d['default']['links'] ??= [];
  if (is_array($d['default']['links'])) {
    $hasHc = false;
    foreach ($d['default']['links'] as $l) {
      if (is_array($l) && isset($l['url']) && str_contains((string)$l['url'], 'help_center')) {
        $hasHc = true;
        break;
      }
    }
    if (!$hasHc) {
      $d['default']['links'][] = ['label' => 'Help Center', 'url' => '/docs/help_center.php'];
    }
  }
}
if (isset($d['map']) && is_array($d['map'])) {
  foreach ($d['map'] as &$m) {
    if (is_array($m)) {
      $strip($m);
    }
  }
  unset($m);
}

$enc = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($enc === false) {
  exit(1);
}
file_put_contents($path, $enc . "\n");
echo "Updated $path\n";
