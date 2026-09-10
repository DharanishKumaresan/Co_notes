<?php
declare(strict_types=1);

/**
 * Shared startup for every entry point (the API front controller and the CLI
 * tools). Loads config, registers the classes, and makes sure PHP never prints
 * a warning into the middle of a JSON response.
 */

namespace CoNotes;

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Co notes needs PHP 8.1 or newer. In hPanel: Advanced > PHP Configuration.');
}

// Errors are logged, never displayed. A stray notice in the output stream would
// corrupt every JSON response on the site.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CoNotes\\')) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen('CoNotes\\')));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// .env sits next to app/, one level up - outside the web root on Hostinger.
// The fallback covers a flat upload where everything ended up in public_html.
$envCandidates = [
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
];
foreach ($envCandidates as $candidate) {
    if (is_readable($candidate)) {
        Env::load($candidate);
        break;
    }
}

date_default_timezone_set('UTC');
