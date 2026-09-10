<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server, standing in for public_html/.htaccess.
 *
 * The built-in server does not read .htaccess, so without this every /api/
 * request 404s and the SPA routes (/b/<slug>, /join/<token>) do not resolve.
 * This is a development convenience only - it is never uploaded, and Apache on
 * the host does the same job from .htaccess.
 *
 *   php -S localhost:8000 -t public_html tools/serve.php
 *
 * Started for you by .claude/launch.json as the "php-api" configuration.
 */

$root = dirname(__DIR__) . '/public_html';
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

// Refuse the paths .htaccess refuses, in case the whole repo ever sits in the
// web root. Belt and braces, same as the deny-all .htaccess files.
if (preg_match('#^/(\.env|app/|tools/|migrations/)#', $path)) {
    http_response_code(403);
    exit;
}

// /api/<route> -> the front controller. Http::path() derives the route from
// REQUEST_URI when no ?_route= is present, so nothing needs rewriting here.
if ($path === '/api' || str_starts_with($path, '/api/')) {
    require $root . '/api/index.php';
    return true;
}

// Anything that exists on disk (assets, uploads) the server can serve itself.
$file = realpath($root . $path);
if ($path !== '/' && $file !== false && is_file($file) && str_starts_with($file, realpath($root) ?: $root)) {
    return false;
}

// Everything else is a client-side route: hand back the shell.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
readfile($root . '/index.html');
return true;
