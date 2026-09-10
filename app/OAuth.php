<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Google and GitHub OAuth 2.0, written against curl directly so the app has no
 * Composer dependency. Authorization Code flow, with PKCE where the provider
 * supports it and a one-shot `state` row in the database as the CSRF guard.
 */
final class OAuth
{
    private const PROVIDERS = ['google', 'github'];

    public static function isConfigured(string $provider): bool
    {
        return match ($provider) {
            'google' => (Env::get('GOOGLE_CLIENT_ID') ?? '') !== ''
                     && (Env::get('GOOGLE_CLIENT_SECRET') ?? '') !== '',
            'github' => (Env::get('GITHUB_CLIENT_ID') ?? '') !== ''
                     && (Env::get('GITHUB_CLIENT_SECRET') ?? '') !== '',
            default  => false,
        };
    }

    /** @return list<string> Providers with credentials filled in. */
    public static function available(): array
    {
        return array_values(array_filter(self::PROVIDERS, self::isConfigured(...)));
    }

    private static function assertProvider(string $provider): void
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw ApiError::notFound('Unknown sign-in provider.');
        }
        if (!self::isConfigured($provider)) {
            throw new ApiError(
                503,
                'provider_unconfigured',
                ucfirst($provider) . ' sign-in is not configured on this server.'
            );
        }
    }

    private static function redirectUri(string $provider): string
    {
        return Env::baseUrl() . '/api/auth/' . $provider . '/callback';
    }

    /**
     * Step 1: stash a state row, then send the browser to the provider.
     */
    public static function begin(string $provider): never
    {
        self::assertProvider($provider);

        $state    = Ids::token();
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        $invite = Http::query('invite');
        if ($invite !== null && $invite !== '') {
            $invite = Validate::inviteToken($invite);
        } else {
            $invite = null;
        }

        Db::run(
            'INSERT INTO oauth_states
                (state, provider, redirect_to, invite_token, code_verifier, created_at, expires_at)
             VALUES (:s, :p, :r, :i, :v, :now, DATE_ADD(:now2, INTERVAL 15 MINUTE))',
            [
                ':s'    => $state,
                ':p'    => $provider,
                ':r'    => Validate::safeRedirect(Http::query('next')),
                ':i'    => $invite,
                ':v'    => $verifier,
                ':now'  => Db::now(),
                ':now2' => Db::now(),
            ]
        );

        $url = match ($provider) {
            'google' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id'             => Env::require('GOOGLE_CLIENT_ID'),
                'redirect_uri'          => self::redirectUri('google'),
                'response_type'         => 'code',
                'scope'                 => 'openid email profile',
                'state'                 => $state,
                'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'code_challenge_method' => 'S256',
                'prompt'                => 'select_account',
            ]),
            'github' => 'https://github.com/login/oauth/authorize?' . http_build_query([
                'client_id'    => Env::require('GITHUB_CLIENT_ID'),
                'redirect_uri' => self::redirectUri('github'),
                'scope'        => 'read:user user:email',
                'state'        => $state,
            ]),
            default  => throw ApiError::notFound('Unknown sign-in provider.'),
        };

        Http::redirect($url);
    }

    /**
     * Step 2: verify state, exchange the code, upsert the user, start a session.
     * Always lands the browser back in the app - errors arrive as ?auth_error=.
     */
    public static function callback(string $provider): never
    {
        self::assertProvider($provider);

        $state = Http::query('state') ?? '';
        $code  = Http::query('code') ?? '';

        if (Http::query('error') !== null) {
            self::bounce('/', 'cancelled');
        }
        if ($state === '' || $code === '') {
            self::bounce('/', 'missing_code');
        }

        // One-shot: read the row, then delete it whatever happens next.
        $row = Db::one(
            'SELECT * FROM oauth_states WHERE state = :s AND provider = :p AND expires_at > :now',
            [':s' => $state, ':p' => $provider, ':now' => Db::now()]
        );
        Db::run('DELETE FROM oauth_states WHERE state = :s', [':s' => $state]);

        if ($row === null) {
            self::bounce('/', 'expired_state');
        }

        $next   = Validate::safeRedirect($row['redirect_to'] !== null ? (string) $row['redirect_to'] : null);
        $invite = $row['invite_token'] !== null ? (string) $row['invite_token'] : null;

        try {
            $identity = $provider === 'google'
                ? self::googleIdentity($code, (string) ($row['code_verifier'] ?? ''))
                : self::githubIdentity($code);

            $userId = Auth::upsertUser(
                $provider,
                $identity['id'],
                $identity['name'],
                $identity['email'],
                $identity['avatar']
            );

            Auth::startSession($userId);

            if ($invite !== null) {
                // Redeem the invite the sign-in was started from, then land on
                // that board rather than the default one.
                $slug = Boards::redeemInvite($invite, $userId);
                if ($slug !== null) {
                    $next = '/b/' . $slug;
                }
            }
        } catch (ApiError $e) {
            self::bounce($next, $e->errorCode);
        } catch (\Throwable $e) {
            Log::error('oauth_callback_failed', $e);
            self::bounce($next, 'signin_failed');
        }

        Http::redirect(Env::baseUrl() . $next);
    }

    private static function bounce(string $next, string $reason): never
    {
        $sep = str_contains($next, '?') ? '&' : '?';
        Http::redirect(Env::baseUrl() . $next . $sep . 'auth_error=' . rawurlencode($reason));
    }

    /**
     * @return array{id:string,name:string,email:?string,avatar:?string}
     */
    private static function googleIdentity(string $code, string $verifier): array
    {
        $token = self::post('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => Env::require('GOOGLE_CLIENT_ID'),
            'client_secret' => Env::require('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => self::redirectUri('google'),
            'grant_type'    => 'authorization_code',
            'code_verifier' => $verifier,
        ]);

        $access = $token['access_token'] ?? null;
        if (!is_string($access)) {
            throw new ApiError(502, 'token_exchange_failed', 'Google did not return an access token.');
        }

        $me = self::get('https://openidconnect.googleapis.com/v1/userinfo', [
            'Authorization: Bearer ' . $access,
        ]);

        $sub = $me['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new ApiError(502, 'profile_failed', 'Google did not return a user id.');
        }

        $email = isset($me['email']) && is_string($me['email']) ? $me['email'] : null;
        $name  = isset($me['name']) && is_string($me['name']) && $me['name'] !== ''
            ? $me['name']
            : ($email !== null ? explode('@', $email)[0] : 'Guest');

        return [
            'id'     => $sub,
            'name'   => $name,
            'email'  => $email,
            'avatar' => isset($me['picture']) && is_string($me['picture']) ? $me['picture'] : null,
        ];
    }

    /**
     * @return array{id:string,name:string,email:?string,avatar:?string}
     */
    private static function githubIdentity(string $code): array
    {
        $token = self::post('https://github.com/login/oauth/access_token', [
            'code'          => $code,
            'client_id'     => Env::require('GITHUB_CLIENT_ID'),
            'client_secret' => Env::require('GITHUB_CLIENT_SECRET'),
            'redirect_uri'  => self::redirectUri('github'),
        ]);

        $access = $token['access_token'] ?? null;
        if (!is_string($access)) {
            throw new ApiError(502, 'token_exchange_failed', 'GitHub did not return an access token.');
        }

        $headers = [
            'Authorization: Bearer ' . $access,
            'Accept: application/vnd.github+json',
        ];

        $me = self::get('https://api.github.com/user', $headers);

        $id = $me['id'] ?? null;
        if (!is_int($id) && !is_string($id)) {
            throw new ApiError(502, 'profile_failed', 'GitHub did not return a user id.');
        }

        $email = isset($me['email']) && is_string($me['email']) ? $me['email'] : null;
        if ($email === null) {
            // Primary email is private by default; ask for it explicitly.
            $emails = self::get('https://api.github.com/user/emails', $headers);
            foreach ($emails as $entry) {
                if (is_array($entry) && ($entry['primary'] ?? false) === true && is_string($entry['email'] ?? null)) {
                    $email = $entry['email'];
                    break;
                }
            }
        }

        $name = isset($me['name']) && is_string($me['name']) && $me['name'] !== ''
            ? $me['name']
            : (is_string($me['login'] ?? null) ? $me['login'] : 'Guest');

        return [
            'id'     => (string) $id,
            'name'   => $name,
            'email'  => $email,
            'avatar' => isset($me['avatar_url']) && is_string($me['avatar_url']) ? $me['avatar_url'] : null,
        ];
    }

    /**
     * @param array<string,string> $fields
     * @return array<mixed>
     */
    private static function post(string $url, array $fields): array
    {
        return self::request($url, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: Co notes',
            ],
        ]);
    }

    /**
     * @param list<string> $headers
     * @return array<mixed>
     */
    private static function get(string $url, array $headers): array
    {
        return self::request($url, [
            CURLOPT_HTTPHEADER => array_merge($headers, ['Accept: application/json', 'User-Agent: Co notes']),
        ]);
    }

    /**
     * @param array<int,mixed> $options
     * @return array<mixed>
     */
    private static function request(string $url, array $options): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new ApiError(502, 'upstream_failed', 'Could not reach the sign-in provider.');
        }

        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw    = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            throw new ApiError(502, 'upstream_failed', 'Sign-in provider did not respond: ' . $err);
        }
        if ($status >= 400) {
            throw new ApiError(502, 'upstream_failed', 'Sign-in provider returned HTTP ' . $status . '.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ApiError(502, 'upstream_failed', 'Sign-in provider returned a malformed response.');
        }

        if (isset($decoded['error'])) {
            $msg = is_string($decoded['error_description'] ?? null)
                ? $decoded['error_description']
                : (is_string($decoded['error']) ? $decoded['error'] : 'unknown error');
            throw new ApiError(502, 'token_exchange_failed', 'Sign-in failed: ' . $msg);
        }

        return $decoded;
    }
}
