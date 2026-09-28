<?php
declare(strict_types=1);

namespace App\Support;

final class AppLogger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $root = defined('RMI_ROOT') ? RMI_ROOT : dirname(__DIR__, 2);
        $dir = $root . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        $entry = [
            'ts' => date('c'),
            'level' => strtoupper($level),
            'request_id' => RequestContext::ensureRequestId(),
            'message' => $message,
            'context' => $context,
        ];
        @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
    }
}
