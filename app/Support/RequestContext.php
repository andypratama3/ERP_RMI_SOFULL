<?php
declare(strict_types=1);

namespace App\Support;

final class RequestContext
{
    public static function ensureRequestId(): string
    {
        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            return (string)$_SERVER['HTTP_X_REQUEST_ID'];
        }
        if (!empty($_SERVER['RMI_REQUEST_ID'])) {
            return (string)$_SERVER['RMI_REQUEST_ID'];
        }
        $id = bin2hex(random_bytes(12));
        $_SERVER['RMI_REQUEST_ID'] = $id;
        if (!headers_sent()) {
            header('X-Request-Id: ' . $id);
        }
        return $id;
    }
}
