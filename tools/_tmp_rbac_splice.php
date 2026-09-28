<?php
declare(strict_types=1);
$path = __DIR__ . '/../rbac/index.php';
$text = file_get_contents($path);
$m1 = "        <?php if (\$rbac_matrix_layout === 'category'): ?>";
$m2 = "        <?php elseif (\$rbac_matrix_layout === 'user'): ?>";
$i1 = strpos($text, $m1);
$i2 = strpos($text, $m2);
if ($i1 === false || $i2 === false) {
    fwrite(STDERR, "markers not found i1=" . var_export($i1, true) . " i2=" . var_export($i2, true) . "\n");
    exit(1);
}
$lineEnd = strpos($text, "\n", $i2);
if ($lineEnd === false) {
    exit(1);
}
$replacement = "        <?php if (\$rbac_matrix_layout === 'user'): ?>\n";
$new = substr($text, 0, $i1) . $replacement . substr($text, $lineEnd + 1);
file_put_contents($path, $new);
echo "spliced OK\n";
