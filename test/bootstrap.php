<?php

declare(strict_types=1);
use Swoole\Runtime;

if (! defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

if (extension_loaded('swoole') && defined('SWOOLE_HOOK_ALL')) {
    Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
}
