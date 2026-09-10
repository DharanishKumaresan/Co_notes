<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Boards and membership. Membership is the permission model: if there is no
 * row in board_members, the board does not exist as far as the caller is
 * concerned (404, not 403, so board slugs cannot be enumerated).
 */
final class Boards
{
    /**
     * Resolve a slug and assert the caller is a member.
     *
     * @return array<string,mixed> board row plus the caller's role
     */
    public static function requireMember(string $slug, string $userId): array
    {
        Validate::slug($slug);

        $row = Db::one(
            'SELECT b.id, b.slug, b.name, b.theme, b.created_at, b.created_by,
                    m.role, m.display_name, m.color, m.avatar_path
               FROM boards b
               JOIN board_members m ON m.board_id = b.id AND m.user_id = :uid
              WHERE b.slug = :slug AND b.deleted_at IS NULL',
            [':uid' => $userId, ':slug' => $slug]
        );

        if ($row === null) {
            // Deliberately indistinguishable from a board that is not there.
            throw ApiError::notFound('That board does not exist, or you are not a member of it.');
        }

        return $row;
    }

    /** @return array<string,mixed> board row plus role, resolved from a note id */
    public static function requireMemberForNote(string $noteId, string $userId): array
    {
        Validate::id($noteId, 'note id');

        $row = Db::one(
            'SELECT b.id, b.slug, b.name, m.role
               FROM notes n
               JOIN boards b ON b.id = n.board_id
               JOIN board_members m ON m.board_id = b.id AND m.user_id = :uid
              WHERE n.id = :nid AND b.deleted_at IS NULL',
            [':uid' => $userId, ':nid' => $noteId]
        );

        if ($row === null) {
            throw ApiError::notFound('That card does not exist, or you are not a member of its board.');
        }

        return $row;
    }

    /**
     * Boards the user belongs to, most recently active first.
     *
     * @return list<array<string,mixed>>
     */
    public static function forUser(string $userId): array
    {
        $rows = Db::all(
            'SELECT b.id, b.slug, b.name, b.theme, m.role, m.joined_at,
                    (SELECT COUNT(*) FROM notes n
                      WHERE n.board_id = b.id AND n.deleted_at IS NULL) AS note_count,
                    (SELECT COUNT(*) FROM board_members bm WHERE bm.board_id = b.id) AS member_count,
                    (SELECT MAX(n2.updated_at) FROM notes n2
                      WHERE n2.board_id = b.id AND n2.deleted_at IS NULL) AS last_activity
               FROM boards b
               JOIN board_members m ON m.board_id = b.id
              WHERE m.user_id = :uid AND b.deleted_at IS NULL
              ORDER BY last_activity IS NULL, last_activity DESC, b.created_at DESC',
            [':uid' => $userId]
        );

        return array_map(static fn (array $r): array => [
            'id'           => (string) $r['id'],
            'slug'         => (string) $r['slug'],
            'name'         => (string) $r['name'],
            'role'         => (string) $r['role'],
            'theme'        => (string) $r['theme'],
            'noteCount'    => (int) $r['note_count'],
            'memberCount'  => (int) $r['member_count'],
            'lastActivity' => Db::iso($r['last_activity'] !== null ? (string) $r['last_activity'] : null),
        ], $rows);
    }

    /**
     * Create a board with the caller as owner.
     *
     * @return array<string,mixed>
     */
    public static function create(string $name, string $userId): array
    {
        $name = Validate::boardName($name);
        $id   = Ids::ulid();
        $now  = Db::now();

        // A new board opens in whatever theme its creator prefers. That is the
        // last say users.default_theme has over it: from here the theme belongs
        // to the board, and anyone on it can change it for everyone.
        $seed  = Db::scalar('SELECT default_theme FROM users WHERE id = :u', [':u' => $userId]);
        $theme = (is_string($seed) && Themes::isValid($seed)) ? $seed : Themes::DEFAULT;

        // Slug carries a random suffix, so a collision means the CSPRNG
        // repeated - retry rather than fail the request.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $slug = Ids::slugify($name);
            try {
                Db::run(
                    'INSERT INTO boards (id, slug, name, theme, created_by, created_at)
                     VALUES (:id, :slug, :name, :theme, :uid, :now)',
                    [':id' => $id, ':slug' => $slug, ':name' => $name, ':theme' => $theme,
                     ':uid' => $userId, ':now' => $now]
                );
                Db::run(
                    'INSERT INTO board_members (board_id, user_id, role, joined_at)
                     VALUES (:b, :u, :role, :now)',
                    [':b' => $id, ':u' => $userId, ':role' => 'owner', ':now' => $now]
                );
                Events::append($id, 'member.joined', null, $userId);

                return ['id' => $id, 'slug' => $slug, 'name' => $name,
                        'theme' => $theme, 'role' => 'owner'];
            } catch (\PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        throw new ApiError(500, 'slug_collision', 'Could not allocate a board address. Try again.');
    }

    /**
     * People on the board. Membership, not activity: this is everyone with
     * access, including those who have never touched a card.
     *
     * @return list<array<string,mixed>>
     */
    public static function members(string $boardId): array
    {
        $rows = Db::all(
            'SELECT u.id AS user_id, m.role, m.joined_at, ' . Identity::columns('u', 'm') . '
               FROM board_members m
               JOIN users u ON u.id = m.user_id
              WHERE m.board_id = :b
              ORDER BY m.joined_at ASC',
            [':b' => $boardId]
        );

        return array_map(static function (array $r): array {
            $person = Identity::fromRow($r, 'user_id') ?? [];
            $person['role']     = (string) $r['role'];
            $person['joinedAt'] = Db::iso((string) $r['joined_at']);
            return $person;
        }, $rows);
    }

    /**
     * The caller's own profile on one board: what is overridden, and what the
     * effective values are once the master profile is folded in.
     *
     * @param array<string,mixed> $master the signed-in user's master profile
     * @return array<string,mixed>
     */
    public static function profile(string $boardId, string $userId, array $master): array
    {
        $row = Db::one(
            'SELECT role, display_name, color, avatar_path
               FROM board_members WHERE board_id = :b AND user_id = :u',
            [':b' => $boardId, ':u' => $userId]
        );

        if ($row === null) {
            throw ApiError::forbidden();
        }

        $overrideName   = $row['display_name'] !== null ? (string) $row['display_name'] : null;
        $overrideAvatar = $row['avatar_path'] !== null ? (string) $row['avatar_path'] : null;

        return [
            'role' => (string) $row['role'],

            // What this member has deliberately set for this board. null means
            // "inherit", and the UI shows the master value as a placeholder.
            'overrides' => [
                'displayName' => $overrideName,
                'hasPhoto'    => $overrideAvatar !== null,
            ],

            // What actually gets drawn.
            'effective' => [
                'name'   => $overrideName ?? (string) $master['displayName'],
                'color'  => $row['color'] !== null ? (string) $row['color'] : (string) $master['color'],
                'avatar' => Identity::avatar($overrideAvatar, null) ?? $master['avatar'],
            ],
        ];
    }

    /**
     * Update the caller's profile on one board.
     *
     * A null `displayName` clears the override rather than being ignored -
     * going back to the master profile has to be expressible.
     *
     * The theme is not here. It belongs to the board, not to the member; see
     * setTheme().
     *
     * @param array<string,mixed> $body
     */
    public static function updateProfile(string $boardId, string $userId, array $body): void
    {
        $sets   = [];
        $params = [':b' => $boardId, ':u' => $userId];

        if (array_key_exists('displayName', $body)) {
            $name = Validate::optionalDisplayName($body['displayName']);
            $sets[] = 'display_name = :name';
            $sets[] = 'color = :color';
            $params[':name']  = $name;
            $params[':color'] = $name === null ? null : Validate::colorFor($name);
        }

        if ($sets === []) {
            throw ApiError::badRequest('Nothing to update.', 'empty_patch');
        }

        Db::run(
            'UPDATE board_members SET ' . implode(', ', $sets) . '
              WHERE board_id = :b AND user_id = :u',
            $params
        );
    }

    /**
     * Set the board's theme. Any member can: this board is shared, everyone on
     * it can already edit and delete any card, and a theme is a smaller thing
     * than that. The caller has been through requireMember() to get here.
     *
     * Returns the theme actually stored, so the caller does not have to guess
     * what normalisation did.
     */
    public static function setTheme(string $boardId, mixed $theme): string
    {
        $theme = (string) Themes::normalise($theme);

        Db::run(
            'UPDATE boards SET theme = :t WHERE id = :b',
            [':t' => $theme, ':b' => $boardId]
        );

        return $theme;
    }

    /**
     * Rename a board. Owners only, unlike the theme: a theme is a look, and
     * anyone on the board can change it, but the name is how the board
     * announces itself to every member and in every invite.
     *
     * Only the name moves. The slug is the board's address - members have it in
     * their URL bar and in invite links already sent - so it is deliberately
     * left alone, and a renamed board stays exactly as reachable as it was.
     *
     * Returns the name actually stored, so the caller does not have to guess
     * what trimming and truncation did.
     */
    public static function rename(string $boardId, string $userId, string $role, mixed $name): string
    {
        if ($role !== 'owner') {
            throw ApiError::forbidden('Only the board owner can rename the board.');
        }

        $name = Validate::boardName($name);

        Db::run(
            'UPDATE boards SET name = :n WHERE id = :b',
            [':n' => $name, ':b' => $boardId]
        );

        return $name;
    }

    /** The board's current name. */
    public static function name(string $boardId): string
    {
        $v = Db::scalar('SELECT name FROM boards WHERE id = :b', [':b' => $boardId]);
        return is_string($v) ? $v : '';
    }

    /** The board's current theme. */
    public static function theme(string $boardId): string
    {
        $v = Db::scalar('SELECT theme FROM boards WHERE id = :b', [':b' => $boardId]);
        return (is_string($v) && Themes::isValid($v)) ? $v : Themes::DEFAULT;
    }

    /** Replace this member's board photo, discarding whatever it replaced. */
    public static function setProfilePhoto(string $boardId, string $userId, ?string $filename): void
    {
        $previous = Db::scalar(
            'SELECT avatar_path FROM board_members WHERE board_id = :b AND user_id = :u',
            [':b' => $boardId, ':u' => $userId]
        );

        Db::run(
            'UPDATE board_members SET avatar_path = :p WHERE board_id = :b AND user_id = :u',
            [':p' => $filename, ':b' => $boardId, ':u' => $userId]
        );

        if (is_string($previous) && $previous !== $filename) {
            Uploads::forget($previous);
        }
    }

    /**
     * Mint an invite link. Owners only - otherwise any member could widen
     * access to a board they were merely invited to.
     *
     * @return array<string,mixed>
     */
    public static function createInvite(string $boardId, string $userId, string $role, int $days = 7): array
    {
        if ($role !== 'owner') {
            throw ApiError::forbidden('Only the board owner can create invite links.');
        }

        $token = Ids::inviteToken();

        $days = max(1, min(365, $days));

        Db::run(
            'INSERT INTO board_invites (token, board_id, created_by, created_at, expires_at, max_uses, uses)
             VALUES (:t, :b, :u, :now, DATE_ADD(:now2, INTERVAL ' . $days . ' DAY), 100, 0)',
            [':t' => $token, ':b' => $boardId, ':u' => $userId, ':now' => Db::now(), ':now2' => Db::now()]
        );

        return [
            'token'     => $token,
            'url'       => Env::baseUrl() . '/join/' . $token,
            'expiresAt' => Db::iso(gmdate('Y-m-d H:i:s', time() + $days * 86400)),
        ];
    }

    /**
     * Look at an invite without redeeming it, so the join screen can name the
     * board before the user commits.
     *
     * @return array<string,mixed>
     */
    public static function peekInvite(string $token): array
    {
        Validate::inviteToken($token);

        $row = Db::one(
            'SELECT i.token, i.uses, i.max_uses, i.expires_at, i.revoked_at,
                    b.id AS board_id, b.slug, b.name,
                    (SELECT COUNT(*) FROM board_members bm WHERE bm.board_id = b.id) AS member_count
               FROM board_invites i
               JOIN boards b ON b.id = i.board_id
              WHERE i.token = :t AND b.deleted_at IS NULL',
            [':t' => $token]
        );

        if ($row === null) {
            throw ApiError::notFound('That invite link is not valid.');
        }
        if ($row['revoked_at'] !== null) {
            throw new ApiError(410, 'invite_revoked', 'That invite link has been revoked.');
        }
        if ((string) $row['expires_at'] < Db::now()) {
            throw new ApiError(410, 'invite_expired', 'That invite link has expired.');
        }
        if ((int) $row['uses'] >= (int) $row['max_uses']) {
            throw new ApiError(410, 'invite_exhausted', 'That invite link has been used too many times.');
        }

        return [
            'board' => [
                'slug'        => (string) $row['slug'],
                'name'        => (string) $row['name'],
                'memberCount' => (int) $row['member_count'],
            ],
        ];
    }

    /**
     * Redeem an invite: add the user as a member. Idempotent - redeeming twice
     * does not consume a second use or duplicate the membership row.
     *
     * @return string|null board slug, or null if the invite is no longer valid
     */
    public static function redeemInvite(string $token, string $userId): ?string
    {
        try {
            $peek = self::peekInvite($token);
        } catch (ApiError) {
            return null;
        }

        $slug  = $peek['board']['slug'];
        $board = Db::one('SELECT id FROM boards WHERE slug = :s', [':s' => $slug]);
        if ($board === null) {
            return null;
        }
        $boardId = (string) $board['id'];

        $already = Db::one(
            'SELECT 1 FROM board_members WHERE board_id = :b AND user_id = :u',
            [':b' => $boardId, ':u' => $userId]
        );
        if ($already !== null) {
            return $slug;
        }

        Db::begin();
        try {
            Db::run(
                'INSERT INTO board_members (board_id, user_id, role, joined_at)
                 VALUES (:b, :u, :role, :now)',
                [':b' => $boardId, ':u' => $userId, ':role' => 'member', ':now' => Db::now()]
            );
            Db::run(
                'UPDATE board_invites SET uses = uses + 1
                  WHERE token = :t AND uses < max_uses',
                [':t' => $token]
            );
            Events::append($boardId, 'member.joined', null, $userId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            Log::error('redeem_invite_failed', $e);
            return null;
        }

        return $slug;
    }

    /** Leave a board. The last owner cannot leave - the board would be orphaned. */
    public static function leave(string $boardId, string $userId, string $role): void
    {
        if ($role === 'owner') {
            $owners = (int) Db::scalar(
                'SELECT COUNT(*) FROM board_members WHERE board_id = :b AND role = :r',
                [':b' => $boardId, ':r' => 'owner']
            );
            if ($owners <= 1) {
                throw ApiError::badRequest(
                    'You are the only owner. Make someone else an owner first, or reset the board.',
                    'last_owner'
                );
            }
        }

        Db::run(
            'DELETE FROM board_members WHERE board_id = :b AND user_id = :u',
            [':b' => $boardId, ':u' => $userId]
        );
    }

    /**
     * Delete a whole board. Owners only, and the heaviest thing anyone can do
     * in this app - it takes every other member's cards, calendar and history
     * with it, not just the owner's.
     *
     * Soft, like every other delete here. The row stays until cleanup.php
     * purges it past the retention window, at which point the ON DELETE
     * CASCADE constraints remove the children. Until then the board is simply
     * unreachable: requireMember(), forUser() and peekInvite() all exclude it,
     * so members get a 404 on their next poll and land on another board.
     *
     * There is deliberately no restore UI, the same call made for calendar
     * events - a board delete is typed-to-confirm and rare, and a trash view
     * for it would be more machinery than it earns. Inside the window it is
     * still recoverable by clearing deleted_at directly.
     */
    public static function delete(string $boardId, string $userId, string $role): void
    {
        if ($role !== 'owner') {
            throw ApiError::forbidden('Only the board owner can delete the board.');
        }

        $st = Db::run(
            'UPDATE boards SET deleted_at = :now, deleted_by = :u
              WHERE id = :b AND deleted_at IS NULL',
            [':now' => Db::now(), ':u' => $userId, ':b' => $boardId]
        );

        if ($st->rowCount() === 0) {
            throw ApiError::notFound('That board has already been deleted.');
        }
    }

    /**
     * Purge boards deleted past the retention window. Called by the cleanup job.
     *
     * The cascades do the real work: one DELETE on boards takes board_members,
     * board_invites, notes, note_edits, board_events and calendar_events with
     * it. The INTERVAL is inlined rather than bound, as everywhere else here -
     * several hosts will not take a placeholder there.
     */
    public static function purgeDeleted(): int
    {
        $days = max(1, Env::int('TRASH_RETENTION_DAYS', 30));
        $st = Db::run(
            'DELETE FROM boards
              WHERE deleted_at IS NOT NULL
                AND deleted_at < DATE_SUB(:now, INTERVAL ' . $days . ' DAY)',
            [':now' => Db::now()]
        );
        return $st->rowCount();
    }

    /**
     * Reset: soft-delete every live card on the board. Recoverable for the
     * retention window, and every card lands in the trash with an audit row -
     * which is the point of choosing soft delete.
     */
    public static function reset(string $boardId, string $userId, string $role): int
    {
        if ($role !== 'owner') {
            throw ApiError::forbidden('Only the board owner can reset the board.');
        }

        $now = Db::now();

        Db::begin();
        try {
            $ids = Db::all(
                'SELECT id FROM notes WHERE board_id = :b AND deleted_at IS NULL',
                [':b' => $boardId]
            );

            if ($ids !== []) {
                Db::run(
                    'UPDATE notes SET deleted_at = :now, deleted_by = :u
                      WHERE board_id = :b AND deleted_at IS NULL',
                    [':now' => $now, ':u' => $userId, ':b' => $boardId]
                );

                foreach ($ids as $r) {
                    Notes::recordEdit((string) $r['id'], $boardId, $userId, 'deleted');
                }
            }

            Events::append($boardId, 'board.reset', null, $userId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return count($ids);
    }
}
