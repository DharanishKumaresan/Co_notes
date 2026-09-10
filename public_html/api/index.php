<?php
declare(strict_types=1);

/**
 * API front controller. Everything under /api/ lands here via .htaccess.
 */

use CoNotes\ApiError;
use CoNotes\Http;
use CoNotes\Log;

// app/ normally sits a level above public_html. The second path covers a flat
// upload where the whole repo went into the web root.
$bootstrap = null;
foreach ([__DIR__ . '/../../app/bootstrap.php', __DIR__ . '/../app/bootstrap.php'] as $candidate) {
    if (is_file($candidate)) {
        $bootstrap = $candidate;
        break;
    }
}

if ($bootstrap === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => [
        'code'    => 'misconfigured',
        'message' => 'Server files are not where the app expects them.',
    ]]);
    exit;
}

require_once $bootstrap;

try {
    CoNotes\Router::dispatch();
} catch (ApiError $e) {
    Http::fail($e);
} catch (PDOException $e) {
    Log::error('db_error', $e);
    Http::fail(new ApiError(
        503,
        'database_unavailable',
        CoNotes\Env::isDev() ? $e->getMessage() : 'The database is not reachable right now.'
    ));
} catch (Throwable $e) {
    Log::error('unhandled', $e);
    Http::fail(new ApiError(
        500,
        'internal_error',
        CoNotes\Env::isDev() ? $e->getMessage() : 'Something went wrong on our side.'
    ));
}
