<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    $root = realpath(dirname(__DIR__, 2));
    define('APP_ROOT', $root !== false ? $root : dirname(__DIR__, 2));
}
