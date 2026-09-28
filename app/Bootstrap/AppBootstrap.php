<?php
declare(strict_types=1);

namespace App\Bootstrap;

use App\Security\SecurityHeaders;
use App\Support\RequestContext;

final class AppBootstrap
{
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        RequestContext::ensureRequestId();
        SecurityHeaders::apply();
    }
}
