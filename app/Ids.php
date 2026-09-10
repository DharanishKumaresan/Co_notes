<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Identifier generation.
 *
 * Note/board/user ids are ULIDs: 26 chars, Crockford base32, lexicographically
 * sortable by creation time. Session and invite tokens are plain CSPRNG bytes
 * in base64url - they are secrets, so they must not encode a timestamp.
 */
final class Ids
{
    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function ulid(): string
    {
        $time = (int) (microtime(true) * 1000);

        $timeChars = '';
        for ($i = 9; $i >= 0; $i--) {
            $timeChars = self::CROCKFORD[$time % 32] . $timeChars;
            $time = intdiv($time, 32);
        }

        $randChars = '';
        $bytes = random_bytes(16);
        for ($i = 0; $i < 16; $i++) {
            $randChars .= self::CROCKFORD[ord($bytes[$i]) % 32];
        }

        return $timeChars . $randChars;
    }

    /** 256 bits of randomness, base64url, 43 chars. Used for sessions and OAuth state. */
    public static function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** 128 bits, base64url, 22 chars. Used for invite links. */
    public static function inviteToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }

    /**
     * Turn a board name into a URL slug, with a short random suffix so two
     * boards called "Notes" do not collide.
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'board';
        }
        $slug = substr($slug, 0, 40);

        return $slug . '-' . bin2hex(random_bytes(3));
    }
}
