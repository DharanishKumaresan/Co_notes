<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Errors go to the PHP error log and nowhere else. Nothing here is ever
 * rendered into a response.
 */
final class Log
{
    public static function error(string $context, \Throwable $e): void
    {
        error_log(sprintf(
            '[conotes] %s: %s in %s:%d',
            $context,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    public static function warn(string $message): void
    {
        error_log('[conotes] ' . $message);
    }
}
