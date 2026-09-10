<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Minimal .env reader. No dependencies, because shared hosting rarely has
 * Composer available and this needs nothing Composer would give us.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $val = trim(substr($line, $eq + 1));

            // Strip one layer of matching quotes.
            $len = strlen($val);
            if ($len >= 2) {
                $first = $val[0];
                $last  = $val[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $val = substr($val, 1, -1);
                }
            }

            self::$vars[$key] = $val;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$vars) && self::$vars[$key] !== '') {
            return self::$vars[$key];
        }
        $fromServer = getenv($key);
        if (is_string($fromServer) && $fromServer !== '') {
            return $fromServer;
        }
        return $default;
    }

    public static function require(string $key): string
    {
        $v = self::get($key);
        if ($v === null || $v === '') {
            throw new \RuntimeException("Missing required config: {$key}");
        }
        return $v;
    }

    public static function int(string $key, int $default): int
    {
        $v = self::get($key);
        return ($v === null || !is_numeric($v)) ? $default : (int) $v;
    }

    public static function isDev(): bool
    {
        return self::get('APP_ENV', 'production') === 'dev';
    }

    /** Public base URL with any trailing slash removed. */
    public static function baseUrl(): string
    {
        return rtrim(self::get('APP_BASE_URL', '') ?? '', '/');
    }
}
