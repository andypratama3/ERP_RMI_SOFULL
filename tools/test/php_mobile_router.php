<?php
declare(strict_types=1);

$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/');
$full = $docRoot . $uriPath;

if ($uriPath !== '/' && is_file($full)) {
    return false;
}

if (str_contains($uriPath, '/api/v1/mobile/')) {
    $candidate = $full . '.php';
    if (is_file($candidate)) {
        require $candidate;
        return true;
    }
}

$index = $docRoot . '/index.php';
if (is_file($index)) {
    require $index;
    return true;
}

http_response_code(404);
echo 'Not Found';
