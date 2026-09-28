<?php
/**
 * check_company_sitemap.php — Cek sitemap.xml company website.
 * Output: storage/logs/company_website_checks.last.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$ROOT = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$WEBSITE_ROOT = realpath($ROOT . '/../website/rmi_website') ?: realpath(dirname($ROOT) . '/website/rmi_website') ?: '';

$result = [
    'state_version' => 'company_website_checks_v1',
    'generated_at' => date('c'),
    'sitemap_exists' => false,
    'sitemap_valid' => false,
    'url_count' => 0,
    'robots_exists' => false,
    'errors' => [],
];

if ($WEBSITE_ROOT === '') {
    $result['errors'][] = 'WEBSITE_ROOT_NOT_FOUND';
    $logDir = $ROOT . '/storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    file_put_contents($logDir . '/company_website_checks.last.json', json_encode($result, JSON_PRETTY_PRINT));
    echo json_encode($result, JSON_PRETTY_PRINT);
    exit(1);
}

$sitemapPath = $WEBSITE_ROOT . '/sitemap.xml';
$robotsPath = $WEBSITE_ROOT . '/robots.txt';

$result['robots_exists'] = is_file($robotsPath);
$result['sitemap_exists'] = is_file($sitemapPath);

if ($result['sitemap_exists']) {
    $xml = @file_get_contents($sitemapPath);
    if ($xml !== false) {
        $prev = libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($doc !== false) {
            $result['sitemap_valid'] = true;
            $urlCount = 0;
            $doc->registerXPathNamespace('sm', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $urls = $doc->xpath('//sm:url');
            if (is_array($urls) && count($urls) > 0) {
                $urlCount = count($urls);
            } else {
                $urlCount = preg_match_all('#<loc>#', $xml) ?: 0;
            }
            $result['url_count'] = $urlCount;
            if ($result['url_count'] < 1) {
                $result['errors'][] = 'SITEMAP_MIN_1_URL';
            }
        } else {
            $result['errors'][] = 'SITEMAP_XML_PARSE_FAIL';
        }
    } else {
        $result['errors'][] = 'SITEMAP_READ_FAIL';
    }
} else {
    $result['errors'][] = 'SITEMAP_NOT_FOUND';
}

$ok = empty($result['errors']) && $result['sitemap_exists'] && $result['sitemap_valid'] && $result['url_count'] >= 1;

$logDir = $ROOT . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
file_put_contents($logDir . '/company_website_checks.last.json', json_encode($result, JSON_PRETTY_PRINT));

echo json_encode($result, JSON_PRETTY_PRINT);
exit($ok ? 0 : 1);
