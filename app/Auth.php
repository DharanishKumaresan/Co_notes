<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Sessions. The cookie holds an opaque random id; everything else lives in the
 * sessions table so a logout or a purge is authoritative server-side.
 */
final class Auth
{
    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;

    private static function cookieName(): string
    {
        return Env::get('SESSION_COOKIE', 'conotes_sid') ?? 'conotes_sid';
    }

    private static function ttlSeconds(): int
    {
        return Env::int('SESSION_TTL_DAYS', 30) * 86400;
    }

    /**
     * The signed-in user, or null.
     *
     * @return array<string,mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;

        $sid = Http::cookie(self::cookieName());
        if ($sid === null || preg_match('/^[A-Za-z0-9_-]{43}$/', $sid) !== 1) {
            return self::$user = null;
        }

        $row = Db::one(
            'SELECT u.id, u.display_name, u.username, u.color, u.email,
                    u.avatar_url, u.avatar_path, u.default_theme, u.provider,
                    s.expires_at
               FROM sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.id = :sid AND s.expires_at > :now',
            [':sid' => $sid, ':now' => Db::now()]
        );

        if ($row === null) {
            return self::$user = null;
        }

        // Touch at most once a minute - this runs on every request, including
        // the 1s sync poll, and a write per poll would be silly.
        Db::run(
            'UPDATE sessions SET last_seen_at = :now
              WHERE id = :sid AND last_seen_at < DATE_SUB(:now2, INTERVAL 60 SECOND)',
            [':now' => Db::now(), ':sid' => $sid, ':now2' => Db::now()]
        );

        return self::$user = self::shape($row);
    }

    /**
     * The master profile, as the API returns it.
     *
     * `avatar` is the resolved photo the client should draw: an uploaded one if
     * there is one, otherwise whatever the OAuth provider gave us, otherwise
     * null and the client falls back to initials.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function shape(array $row): array
    {
        return [
            'id'           => (string) $row['id'],
            'displayName'  => (string) $row['display_name'],
            'username'     => $row['username'] !== null ? (string) $row['username'] : null,
            'color'        => (string) $row['color'],
            'email'        => $row['email'] !== null ? (string) $row['email'] : null,
            'avatar'       => Identity::avatar($row['avatar_path'] ?? null, $row['avatar_url'] ?? null),
            'hasPhoto'     => ($row['avatar_path'] ?? null) !== null,
            'defaultTheme' => (string) ($row['default_theme'] ?? Themes::DEFAULT),
            'provider'     => (string) $row['provider'],
        ];
    }

    /** Re-read the master profile straight from the database. */
    public static function reload(string $userId): array
    {
        $row = Db::one(
            'SELECT id, display_name, username, color, email, avatar_url,
                    avatar_path, default_theme, provider
               FROM users WHERE id = :id',
            [':id' => $userId]
        );

        if ($row === null) {
            throw ApiError::unauthorized();
        }

        return self::shape($row);
    }

    /**
     * The signed-in user, or a 401.
     *
     * @return array<string,mixed>
     */
    public static function require(): array
    {
        $u = self::user();
        if ($u === null) {
            throw ApiError::unauthorized();
        }
        return $u;
    }

    public static function startSession(string $userId): string
    {
        $sid = Ids::token();
        $now = Db::now();

        $ttl = self::ttlSeconds();

        Db::run(
            'INSERT INTO sessions (id, user_id, created_at, expires_at, last_seen_at, user_agent)
             VALUES (:id, :uid, :now, DATE_ADD(:now2, INTERVAL ' . $ttl . ' SECOND), :now3, :ua)',
            [
                ':id'   => $sid,
                ':uid'  => $userId,
                ':now'  => $now,
                ':now2' => $now,
                ':now3' => $now,
                ':ua'   => Http::userAgent(),
            ]
        );

        setcookie(self::cookieName(), $sid, [
            'expires'  => time() + self::ttlSeconds(),
            'path'     => '/',
            'secure'   => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $sid;
    }

    public static function endSession(): void
    {
        $sid = Http::cookie(self::cookieName());
        if ($sid !== null) {
            Db::run('DELETE FROM sessions WHERE id = :sid', [':sid' => $sid]);
        }
        setcookie(self::cookieName(), '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        self::$user = null;
        self::$resolved = true;
    }

    /**
     * Find or create the user behind an OAuth identity. Returns the user id.
     *
     * The display name is only written on first sight - if someone renames
     * themselves in Co notes we do not want the next login to stomp it.
     */
    public static function upsertUser(
        string $provider,
        string $providerUserId,
        string $displayName,
        ?string $email,
        ?string $avatarUrl
    ): string {
        $existing = Db::one(
            'SELECT id FROM users WHERE provider = :p AND provider_user_id = :pid',
            [':p' => $provider, ':pid' => $providerUserId]
        );

        $now = Db::now();

        if ($existing !== null) {
            $id = (string) $existing['id'];
            Db::run(
                'UPDATE users SET email = :email, avatar_url = :avatar, last_seen_at = :now
                  WHERE id = :id',
                [':email' => $email, ':avatar' => $avatarUrl, ':now' => $now, ':id' => $id]
            );
            return $id;
        }

        $name = Validate::displayName($displayName, 'Guest');
        $id   = Ids::ulid();

        Db::run(
            'INSERT INTO users
                (id, provider, provider_user_id, email, username, display_name,
                 color, avatar_url, default_theme, created_at, last_seen_at)
             VALUES (:id, :p, :pid, :email, :username, :name,
                     :color, :avatar, :theme, :now, :now2)',
            [
                ':id'       => $id,
                ':p'        => $provider,
                ':pid'      => $providerUserId,
                ':email'    => $email,
                ':username' => self::freeUsername($email, $name),
                ':name'     => $name,
                ':color'    => Validate::colorFor($name),
                ':avatar'   => $avatarUrl,
                ':theme'    => Themes::DEFAULT,
                ':now'      => $now,
                ':now2'     => $now,
            ]
        );

        return $id;
    }

    /**
     * Pick a handle nobody else has, from the email local part or the display
     * name, falling back to random. People can change it afterwards; this only
     * has to be reasonable and unique.
     */
    private static function freeUsername(?string $email, string $displayName): string
    {
        $seed = '';
        if (is_string($email) && str_contains($email, '@')) {
            $seed = explode('@', $email)[0];
        }
        if ($seed === '') {
            $seed = $displayName;
        }

        $seed = strtolower($seed);
        $seed = preg_replace('/[^a-z0-9_.]+/', '', $seed) ?? '';
        $seed = trim($seed, '._');
        $seed = substr($seed, 0, 24);

        if (strlen($seed) < Validate::MIN_USERNAME) {
            $seed = 'user' . bin2hex(random_bytes(2));
        }

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $candidate = $attempt === 0 ? $seed : $seed . bin2hex(random_bytes(2));

            $taken = Db::one('SELECT 1 FROM users WHERE username = :u', [':u' => $candidate]);
            if ($taken === null) {
                return $candidate;
            }
        }

        return 'user' . strtolower(substr(Ids::ulid(), -10));
    }

    /** Remove expired sessions and OAuth states. Cheap; runs opportunistically. */
    public static function sweep(): void
    {
        $now = Db::now();
        Db::run('DELETE FROM sessions WHERE expires_at < :now', [':now' => $now]);
        Db::run('DELETE FROM oauth_states WHERE expires_at < :now', [':now' => $now]);
    }
}
