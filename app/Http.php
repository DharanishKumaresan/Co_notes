<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Request reading and response writing. Keeps every superglobal touch in one
 * place so the handlers stay testable-ish and never echo raw input.
 */
final class Http
{
    /** @var array<string,mixed>|null */
    private static ?array $body = null;

    public static function method(): string
    {
        $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Some shared hosts strip PATCH/DELETE from HTML forms; honour the
        // override header for those, but only from a real POST.
        if ($m === 'POST') {
            $override = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '');
            if (in_array($override, ['PATCH', 'PUT', 'DELETE'], true)) {
                return $override;
            }
        }
        return $m;
    }

    /** Path within the API, e.g. "boards/my-board/notes". No leading slash. */
    public static function path(): string
    {
        // The front controller passes the route through ?_route= (mod_rewrite)
        // or we derive it from the URI.
        $route = $_GET['_route'] ?? null;
        if (is_string($route) && $route !== '') {
            return trim($route, '/');
        }

        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '/';

        $pos = strpos($path, '/api/');
        if ($pos !== false) {
            return trim(substr($path, $pos + 5), '/');
        }
        return trim($path, '/');
    }

    /**
     * Decoded JSON request body. Empty object when there is no body.
     *
     * @return array<string,mixed>
     */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return self::$body = [];
        }

        // Hard cap before decoding, so a huge body cannot exhaust memory here.
        if (strlen($raw) > Validate::MAX_BODY_BYTES) {
            throw ApiError::tooLarge('Request body is too large.');
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiError::badRequest('Request body is not valid JSON.', 'invalid_json');
        }

        if (!is_array($decoded)) {
            throw ApiError::badRequest('Request body must be a JSON object.', 'invalid_json');
        }

        /** @var array<string,mixed> $decoded */
        return self::$body = $decoded;
    }

    public static function query(string $key, ?string $default = null): ?string
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) ? $v : $default;
    }

    public static function cookie(string $name): ?string
    {
        $v = $_COOKIE[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public static function userAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return is_string($ua) ? substr($ua, 0, 255) : null;
    }

    public static function isHttps(): bool
    {
        if (str_starts_with(Env::baseUrl(), 'https://')) {
            return true;
        }
        if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') {
            return true;
        }
        return ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function noContent(): never
    {
        http_response_code(204);
        header('Cache-Control: no-store');
        exit;
    }

    public static function redirect(string $url): never
    {
        http_response_code(302);
        header('Location: ' . $url);
        header('Cache-Control: no-store');
        exit;
    }

    public static function fail(ApiError $e): never
    {
        $body = ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]];
        foreach ($e->extra as $k => $v) {
            $body[$k] = $v;
        }
        self::json($body, $e->status);
    }

    /**
     * Same-origin guard for state-changing requests. The session cookie is
     * SameSite=Lax, which already blocks cross-site POSTs from forms, but an
     * explicit Origin check costs nothing and covers fetch() edge cases.
     */
    public static function assertSameOrigin(): void
    {
        $method = self::method();
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin === '') {
            return; // Non-browser client (curl, server-to-server). Cookie auth still applies.
        }

        $base = Env::baseUrl();
        if ($base !== '' && rtrim($origin, '/') !== $base) {
            throw ApiError::forbidden('Cross-origin request refused.');
        }
    }
}
