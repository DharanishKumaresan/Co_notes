<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Server-side input validation. The frontend has its own caps, but nothing
 * here trusts them - every limit is re-checked on this side.
 */
final class Validate
{
    public const MAX_TITLE_CHARS   = 120;
    public const MAX_CONTENT_BYTES = 100 * 1024;   // 100KB
    public const MAX_NAME_CHARS    = 28;
    public const MAX_BOARD_NAME    = 80;
    public const MAX_BODY_BYTES    = 128 * 1024;   // content + JSON overhead
    public const MIN_USERNAME      = 3;
    public const MAX_USERNAME      = 32;

    /** Colours a display name can hash to. Mirrors PALETTE in app.js exactly. */
    public const PALETTE = [
        '#5E7A2E', '#2E7D74', '#B0603A', '#7A5AA8',
        '#A8862E', '#3F6FA8', '#A8425F', '#4A7A4E',
    ];

    /**
     * Deterministic colour for a name. Must stay byte-identical to colorFor()
     * in app.js, or the same person renders in two different colours.
     */
    public static function colorFor(string $name): string
    {
        $h = 0;
        $len = strlen($name);
        for ($i = 0; $i < $len; $i++) {
            // >>> 0 in JS - emulate with a 32-bit unsigned wrap.
            $h = ($h * 31 + ord($name[$i])) & 0xFFFFFFFF;
        }
        return self::PALETTE[$h % count(self::PALETTE)];
    }

    /**
     * Strip control characters that have no business in a note. Keeps tab and
     * newline; removes NUL and the rest of the C0/C1 range.
     */
    public static function clean(string $s): string
    {
        $s = str_replace("\r\n", "\n", $s);
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    }

    /** @param array<string,mixed> $body */
    public static function title(array $body, string $key = 'title'): string
    {
        $v = $body[$key] ?? '';
        if (!is_string($v)) {
            throw ApiError::badRequest('Title must be a string.', 'invalid_title');
        }
        $v = self::clean($v);
        if (mb_strlen($v) > self::MAX_TITLE_CHARS) {
            throw ApiError::badRequest(
                'Title is longer than ' . self::MAX_TITLE_CHARS . ' characters.',
                'title_too_long'
            );
        }
        return $v;
    }

    /** @param array<string,mixed> $body */
    public static function content(array $body, string $key = 'content'): string
    {
        $v = $body[$key] ?? '';
        if (!is_string($v)) {
            throw ApiError::badRequest('Content must be a string.', 'invalid_content');
        }
        $v = self::clean($v);
        if (strlen($v) > self::MAX_CONTENT_BYTES) {
            throw ApiError::tooLarge('Card content is larger than 100KB.');
        }
        return $v;
    }

    /** @param array<string,mixed> $body */
    public static function bool(array $body, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $body)) {
            return $default;
        }
        $v = $body[$key];
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            return in_array(strtolower($v), ['1', 'true', 'yes'], true);
        }
        throw ApiError::badRequest("Field {$key} must be a boolean.", 'invalid_field');
    }

    public static function displayName(mixed $v, string $fallback = 'Guest'): string
    {
        if (!is_string($v)) {
            return $fallback;
        }
        $v = trim(self::clean($v));
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        if ($v === '') {
            return $fallback;
        }
        return mb_substr($v, 0, self::MAX_NAME_CHARS);
    }

    /**
     * The master profile handle. Lowercase, unambiguous, and stable enough to
     * tell two people with the same display name apart.
     */
    public static function username(mixed $v): string
    {
        if (!is_string($v)) {
            throw ApiError::badRequest('Username must be text.', 'invalid_username');
        }

        $v = strtolower(trim(self::clean($v)));
        $v = ltrim($v, '@');

        if ($v === '') {
            throw ApiError::badRequest('Username cannot be empty.', 'invalid_username');
        }
        if (strlen($v) < self::MIN_USERNAME || strlen($v) > self::MAX_USERNAME) {
            throw ApiError::badRequest(
                'Username must be between ' . self::MIN_USERNAME . ' and ' . self::MAX_USERNAME . ' characters.',
                'invalid_username'
            );
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9_.]*[a-z0-9])?$/', $v) !== 1) {
            throw ApiError::badRequest(
                'Usernames can use letters, numbers, dots and underscores, and must start and end with a letter or number.',
                'invalid_username'
            );
        }

        return $v;
    }

    /**
     * A per-board display name. Empty means "clear the override and go back to
     * my master profile name", which is a meaningful choice, so it maps to null
     * rather than being rejected.
     */
    public static function optionalDisplayName(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw ApiError::badRequest('Name must be text.', 'invalid_name');
        }

        $v = trim(self::clean($v));
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;

        if ($v === '') {
            return null;
        }

        return mb_substr($v, 0, self::MAX_NAME_CHARS);
    }

    public static function boardName(mixed $v): string
    {
        if (!is_string($v)) {
            throw ApiError::badRequest('Board name must be a string.', 'invalid_board_name');
        }
        $v = trim(self::clean($v));
        if ($v === '') {
            throw ApiError::badRequest('Board name cannot be empty.', 'invalid_board_name');
        }
        return mb_substr($v, 0, self::MAX_BOARD_NAME);
    }

    /** Ids we generate are ULIDs; reject anything that is not shaped like one. */
    public static function id(string $v, string $what = 'id'): string
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $v) !== 1) {
            throw ApiError::badRequest("Malformed {$what}.", 'invalid_id');
        }
        return $v;
    }

    public static function slug(string $v): string
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $v) !== 1) {
            throw ApiError::badRequest('Malformed board slug.', 'invalid_slug');
        }
        return $v;
    }

    public static function inviteToken(string $v): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/', $v) !== 1) {
            throw ApiError::badRequest('Malformed invite token.', 'invalid_invite');
        }
        return $v;
    }

    /** @param array<string,mixed> $body */
    /**
     * A wall-clock calendar date, YYYY-MM-DD.
     *
     * checkdate() is the point of this: '2025-02-30' matches the pattern and is
     * still not a day. Returned re-formatted, so '2025-2-3' cannot reach SQL in
     * a shape MySQL might read differently.
     */
    public static function date(mixed $v, string $what = 'date'): string
    {
        if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            throw ApiError::badRequest('That ' . $what . ' is not a valid date.', 'invalid_date');
        }

        [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if (!checkdate($mo, $d, $y) || $y < 1970 || $y > 2999) {
            throw ApiError::badRequest('That ' . $what . ' is not a valid date.', 'invalid_date');
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** Like date(), but null and '' mean "no date", which is a value here. */
    public static function dateOrNull(mixed $v, string $what = 'date'): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return self::date($v, $what);
    }

    /**
     * A wall-clock time of day, HH:MM. null or '' means an all-day entry, which
     * is a real value here rather than a missing one.
     */
    public static function timeOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || !preg_match('/^(\d{2}):(\d{2})$/', $v, $m)) {
            throw ApiError::badRequest('That time is not valid.', 'invalid_time');
        }

        [$h, $min] = [(int) $m[1], (int) $m[2]];
        if ($h > 23 || $min > 59) {
            throw ApiError::badRequest('That time is not valid.', 'invalid_time');
        }

        return sprintf('%02d:%02d:00', $h, $min);
    }

    public static function version(array $body): int
    {
        $v = $body['version'] ?? null;
        if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
            throw ApiError::badRequest(
                'A version is required so concurrent edits can be detected.',
                'missing_version'
            );
        }
        return (int) $v;
    }

    /**
     * Where to send the browser after OAuth. Only same-site paths are allowed -
     * an open redirect here would be a phishing primitive.
     */
    public static function safeRedirect(?string $v): string
    {
        if ($v === null || $v === '' || !str_starts_with($v, '/') || str_starts_with($v, '//')) {
            return '/';
        }
        return substr($v, 0, 512);
    }
}
