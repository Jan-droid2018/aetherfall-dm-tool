<?php
declare(strict_types=1);

namespace Aetherfall\Support;

final class Logger
{
    public static function error(\Throwable $error): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $line = sprintf("[%s] %s\n%s\n\n", date('c'), $error->getMessage(), $error->getTraceAsString());
        file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }
}

