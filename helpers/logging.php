<?php

if (!function_exists('logger')) {
    function logger(): \Core\Logger
    {
        static $logger = null;
        if ($logger === null) {
            $logger = \Core\Container::getInstance()->make(\Core\Logger::class);
        }
        return $logger;
    }
}
