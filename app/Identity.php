<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * How a person appears *on a particular board*.
 *
 * Someone has one master profile and, optionally, a different name, colour and
 * photo on each board they belong to. Everything the API renders a person into
 * - a card's last editor, a card's creator, an activity line, the member
 * list - resolves through here, so the override applies everywhere or nowhere.
 *
 * Nothing is copied at join time. The board profile stores NULL for "inherit",
 * so editing the master profile updates every board that has not overridden it.
 */
final class Identity
{
    /**
     * SELECT fragment. Expects a `users` alias and a `board_members` alias in
     * scope; both may be LEFT JOINed and absent.
     *
     * @param string $prefix column-name prefix, so several people can be
     *                       selected in one row without colliding
     */
    public static function columns(string $u = 'u', string $bm = 'bm', string $prefix = 'ident'): string
    {
        return "COALESCE({$bm}.display_name, {$u}.display_name) AS {$prefix}_name,
                COALESCE({$bm}.color, {$u}.color)               AS {$prefix}_color,
                COALESCE({$bm}.avatar_path, {$u}.avatar_path)   AS {$prefix}_avatar_path,
                {$u}.avatar_url                                 AS {$prefix}_avatar_url,
                {$u}.username                                   AS {$prefix}_username";
    }

    /**
     * JOIN fragment binding a user column and a board column to those aliases.
     *
     * @param string $userExpr  SQL expression for the user id  (e.g. "n.updated_by")
     * @param string $boardExpr SQL expression for the board id (e.g. "n.board_id")
     */
    public static function joins(string $userExpr, string $boardExpr, string $u = 'u', string $bm = 'bm'): string
    {
        return "LEFT JOIN users {$u}         ON {$u}.id = {$userExpr}
                LEFT JOIN board_members {$bm} ON {$bm}.board_id = {$boardExpr}
                                             AND {$bm}.user_id  = {$userExpr}";
    }

    /**
     * Turn the selected columns into the person object the API returns.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null null when the row has no person at all
     */
    public static function fromRow(array $row, string $idKey, string $prefix = 'ident'): ?array
    {
        $id = $row[$idKey] ?? null;
        if ($id === null) {
            return null;
        }

        return [
            'id'       => (string) $id,
            'name'     => (string) ($row["{$prefix}_name"] ?? 'Someone'),
            'color'    => (string) ($row["{$prefix}_color"] ?? '#4B5240'),
            'username' => isset($row["{$prefix}_username"]) && $row["{$prefix}_username"] !== null
                ? (string) $row["{$prefix}_username"]
                : null,
            'avatar'   => self::avatar(
                $row["{$prefix}_avatar_path"] ?? null,
                $row["{$prefix}_avatar_url"] ?? null
            ),
        ];
    }

    /**
     * An uploaded photo wins over the one the OAuth provider gave us; if there
     * is neither, the client falls back to initials on a coloured disc, which
     * is what it has always drawn.
     */
    public static function avatar(mixed $path, mixed $providerUrl): ?string
    {
        if (is_string($path) && $path !== '') {
            return Uploads::publicUrl($path);
        }
        if (is_string($providerUrl) && $providerUrl !== '') {
            return $providerUrl;
        }
        return null;
    }
}
