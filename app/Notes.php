<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Notes: per-note writes with optimistic versioning and soft delete.
 */
final class Notes
{
    /**
     * Consecutive edits by the same person to the same note inside this window
     * collapse into one audit row. Without it, a 650ms typing debounce would
     * write an activity entry every few keystrokes.
     */
    private const EDIT_COALESCE_SECONDS = 120;

    /**
     * Live notes on a board, newest edit first.
     *
     * @return list<array<string,mixed>>
     */
    public static function listForBoard(string $boardId): array
    {
        $rows = Db::all(
            'SELECT n.id, n.title, n.content, n.pinned, n.due_date, n.version,
                    n.created_at, n.updated_at, n.updated_by, n.created_by, '
                    . Identity::columns() . ', '
                    . Identity::columns('cu', 'cbm', 'creator') . '
               FROM notes n '
               . Identity::joins('n.updated_by', 'n.board_id') . ' '
               . Identity::joins('n.created_by', 'n.board_id', 'cu', 'cbm') . '
              WHERE n.board_id = :b AND n.deleted_at IS NULL
              ORDER BY n.updated_at DESC',
            [':b' => $boardId]
        );

        return self::hydrate($rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $noteId, bool $includeDeleted = false): ?array
    {
        $sql = 'SELECT n.id, n.title, n.content, n.pinned, n.due_date, n.version,
                       n.created_at, n.updated_at, n.deleted_at, n.updated_by, n.created_by, '
                       . Identity::columns() . ', '
                       . Identity::columns('cu', 'cbm', 'creator') . '
                  FROM notes n '
                  . Identity::joins('n.updated_by', 'n.board_id') . ' '
                  . Identity::joins('n.created_by', 'n.board_id', 'cu', 'cbm') . '
                 WHERE n.id = :id';
        if (!$includeDeleted) {
            $sql .= ' AND n.deleted_at IS NULL';
        }

        $row = Db::one($sql, [':id' => $noteId]);
        if ($row === null) {
            return null;
        }

        $out = self::hydrate([$row]);
        return $out[0] ?? null;
    }

    /**
     * Turn database rows into the note shape the API returns.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function hydrate(array $rows): array
    {
        return array_map(static function (array $r): array {
            return [
                'id'        => (string) $r['id'],
                'title'     => (string) $r['title'],
                'content'   => (string) $r['content'],
                'pinned'    => (bool) $r['pinned'],
                'dueDate'   => isset($r['due_date']) && $r['due_date'] !== null
                    ? (string) $r['due_date']
                    : null,
                'version'   => (int) $r['version'],
                'createdAt' => Db::iso((string) $r['created_at']),
                'updatedAt' => Db::iso((string) $r['updated_at']),
                'deletedAt' => isset($r['deleted_at']) && $r['deleted_at'] !== null
                    ? Db::iso((string) $r['deleted_at'])
                    : null,
                'updatedBy'    => Identity::fromRow($r, 'updated_by'),
                // Null on the trash query, which does not select created_by:
                // a deleted card is listed by who deleted it, not who made it.
                'createdBy'    => Identity::fromRow($r, 'created_by', 'creator'),
            ];
        }, $rows);
    }

    /**
     * Flag every card on a board whose due date has arrived.
     *
     * The date is the server's, not the viewer's: `due_date` is a wall-clock
     * DATE with no zone (see the calendar note in CLAUDE.md), and a board is
     * shared, so two members in different zones must not disagree about
     * whether a card is flagged. `<=` rather than `=` so a card that came due
     * while nobody had the board open is still flagged when someone opens it.
     *
     * It fires **once per card**, not once per poll. The marker is a
     * `note_edits` row with action 'due' and no user - no new column, and it
     * earns its place by showing up in Activity as "came due" - and
     * `Notes::update()` clears it when the due date changes, so a card given a
     * new date can come due again. Without that marker the flag would be
     * derived state: unflag an overdue card and the next poll would put it
     * straight back, and you could never turn it off.
     *
     * `version` moves because a client holding the old one would otherwise
     * write the flag straight back off; `updated_at` deliberately does not,
     * because nobody edited the card and it should neither jump the board
     * order nor claim someone touched it.
     *
     * @return list<string> the ids actually flagged
     */
    public static function flagDue(string $boardId): array
    {
        $due = Db::all(
            "SELECT n.id
               FROM notes n
              WHERE n.board_id = :b
                AND n.deleted_at IS NULL
                AND n.pinned = 0
                AND n.due_date IS NOT NULL
                AND n.due_date <= CURDATE()
                AND NOT EXISTS (
                      SELECT 1 FROM note_edits e
                       WHERE e.note_id = n.id AND e.action = 'due')",
            [':b' => $boardId]
        );
        if ($due === []) {
            return [];
        }

        $ids = array_map(static fn (array $r): string => (string) $r['id'], $due);
        $now = Db::now();

        Db::begin();
        try {
            foreach ($ids as $id) {
                Db::run(
                    'UPDATE notes SET pinned = 1, version = version + 1
                      WHERE id = :id AND pinned = 0 AND deleted_at IS NULL',
                    [':id' => $id]
                );
                Db::run(
                    "INSERT INTO note_edits (note_id, board_id, user_id, action, edited_at)
                     VALUES (:n, :b, NULL, 'due', :now)",
                    [':n' => $id, ':b' => $boardId, ':now' => $now]
                );
                // No actor: nobody did this, the date did.
                Events::append($boardId, 'note.updated', $id, null);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        return $ids;
    }

    /**
     * Forget that a card has already come due, so a *new* date can flag it
     * once more.
     *
     * The test is "the date moved", not "the client mentioned a date": every
     * save sends the whole card, dueDate included, so keying off the field's
     * presence would clear the mark on every keystroke burst and the flag
     * would become unremovable again.
     *
     * The join reads the row's current date, so this must run *before* the
     * UPDATE overwrites it, and inside the same transaction so a version
     * conflict rolls it back too. `<=>` is null-safe: no date to no date is
     * not a move.
     */
    private static function clearDueMarkIfMoved(string $noteId, ?string $newDue): void
    {
        Db::run(
            "DELETE e FROM note_edits e
               JOIN notes n ON n.id = e.note_id
              WHERE e.note_id = :n AND e.action = 'due' AND NOT (n.due_date <=> :due)",
            [':n' => $noteId, ':due' => $newDue]
        );

        // The reminder marker moves with the date for the same reason the due
        // mark does: a card pushed to next week has not been reminded about
        // next week yet. `<=>` so a date being cleared or set from null counts
        // as a move; plain `<>` is never true against NULL.
        Db::run(
            'UPDATE notes SET reminded_at = NULL
              WHERE id = :n AND NOT (due_date <=> :due)',
            [':n' => $noteId, ':due' => $newDue]
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed> the created note
     */
    public static function create(string $boardId, string $userId, array $body): array
    {
        $title   = Validate::title($body);
        $content = Validate::content($body);
        $pinned  = Validate::bool($body, 'pinned', false);
        $due     = array_key_exists('dueDate', $body) ? Validate::dateOrNull($body['dueDate']) : null;

        $id  = Ids::ulid();
        $now = Db::now();

        Db::begin();
        try {
            Db::run(
                'INSERT INTO notes
                    (id, board_id, title, content, pinned, due_date, version,
                     created_at, created_by, updated_at, updated_by)
                 VALUES (:id, :b, :t, :c, :p, :due, 1, :now, :u, :now2, :u2)',
                [
                    ':id'  => $id,
                    ':b'   => $boardId,
                    ':t'   => $title,
                    ':c'   => $content,
                    ':p'   => $pinned ? 1 : 0,
                    ':due' => $due,
                    ':now' => $now,
                    ':u'   => $userId,
                    ':now2' => $now,
                    ':u2'  => $userId,
                ]
            );

            self::recordEdit($id, $boardId, $userId, 'created', true);
            Events::append($boardId, 'note.created', $id, $userId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $note = self::find($id);
        if ($note === null) {
            throw new ApiError(500, 'create_failed', 'The card was not saved.');
        }
        return $note;
    }

    /**
     * Update a note, guarded by the client's `version`.
     *
     * On mismatch this throws a 409 carrying the current server state, so the
     * client can show the conflict rather than overwrite someone else's work.
     * Fields absent from the body are left alone.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed> the updated note
     */
    public static function update(string $noteId, string $boardId, string $userId, array $body): array
    {
        $expected = Validate::version($body);

        // No pre-read: the UPDATE below is guarded on both the version and
        // deleted_at, and the zero-row branch tells the two cases apart. This
        // runs on every keystroke burst, so the extra round trip is worth
        // avoiding.
        $sets   = [];
        $params = [];
        $action = 'edited';

        if (array_key_exists('title', $body)) {
            $sets[':title'] = 'title = :title';
            $params[':title'] = Validate::title($body);
        }
        if (array_key_exists('content', $body)) {
            $sets[':content'] = 'content = :content';
            $params[':content'] = Validate::content($body);
        }
        if (array_key_exists('dueDate', $body)) {
            // null clears the date; the calendar has to be able to let go of a card.
            $sets[':due'] = 'due_date = :due';
            $params[':due'] = Validate::dateOrNull($body['dueDate']);
        }

        if (array_key_exists('pinned', $body)) {
            $pinned = Validate::bool($body, 'pinned', false);
            $sets[':pinned'] = 'pinned = :pinned';
            $params[':pinned'] = $pinned ? 1 : 0;

            // A flag toggle on its own reads better in Activity than "edited".
            if (!array_key_exists('title', $body) && !array_key_exists('content', $body)) {
                $action = $pinned ? 'flagged' : 'unflagged';
            }
        }

        if ($sets === []) {
            throw ApiError::badRequest('Nothing to update.', 'empty_patch');
        }

        $now = Db::now();
        $assignments = implode(', ', $sets);

        Db::begin();
        try {
            if (array_key_exists(':due', $params)) {
                self::clearDueMarkIfMoved($noteId, $params[':due']);
            }

            $st = Db::run(
                "UPDATE notes
                    SET {$assignments},
                        version = version + 1,
                        updated_at = :now,
                        updated_by = :uid
                  WHERE id = :id AND version = :expected AND deleted_at IS NULL",
                $params + [':now' => $now, ':uid' => $userId, ':id' => $noteId, ':expected' => $expected]
            );

            if ($st->rowCount() === 0) {
                Db::rollback();

                $fresh = self::find($noteId);
                if ($fresh === null) {
                    throw ApiError::notFound('That card has been deleted.');
                }
                // The row exists but the version moved: somebody else saved first.
                throw ApiError::conflict($fresh);
            }

            self::recordEdit($noteId, $boardId, $userId, $action);
            Events::append($boardId, 'note.updated', $noteId, $userId);
            Db::commit();
        } catch (ApiError $e) {
            Db::rollback();
            throw $e;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // Flagging is the one edit that means "somebody look at this", so it is
        // the one edit that reaches people who do not have the board open.
        //
        // After the commit, never inside it: a push service is a network call
        // to somebody else's server, and it must not be able to hold a row lock
        // or roll back a save. Failures are swallowed on purpose - a card that
        // saved but did not notify is a far better outcome than a save that
        // 500s because Google was slow.
        if ($action === 'flagged') {
            try {
                Push::notifyFlagged($boardId, $userId);
            } catch (\Throwable $e) {
                Log::error('notify_flagged_failed', $e);
            }
        }

        $note = self::find($noteId);
        if ($note === null) {
            throw new ApiError(500, 'update_failed', 'The card could not be read back.');
        }
        return $note;
    }

    /** Soft delete. The row stays put and stays restorable. */
    public static function softDelete(string $noteId, string $boardId, string $userId): void
    {
        Db::begin();
        try {
            $st = Db::run(
                'UPDATE notes SET deleted_at = :now, deleted_by = :uid, version = version + 1
                  WHERE id = :id AND deleted_at IS NULL',
                [':now' => Db::now(), ':uid' => $userId, ':id' => $noteId]
            );

            if ($st->rowCount() === 0) {
                Db::rollback();
                throw ApiError::notFound('That card has already been deleted.');
            }

            self::recordEdit($noteId, $boardId, $userId, 'deleted', true);
            Events::append($boardId, 'note.deleted', $noteId, $userId);
            Db::commit();
        } catch (ApiError $e) {
            Db::rollback();
            throw $e;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** @return array<string,mixed> the restored note */
    public static function restore(string $noteId, string $boardId, string $userId): array
    {
        Db::begin();
        try {
            $st = Db::run(
                'UPDATE notes
                    SET deleted_at = NULL, deleted_by = NULL,
                        version = version + 1, updated_at = :now, updated_by = :uid
                  WHERE id = :id AND deleted_at IS NOT NULL',
                [':now' => Db::now(), ':uid' => $userId, ':id' => $noteId]
            );

            if ($st->rowCount() === 0) {
                Db::rollback();
                throw ApiError::notFound('That card is not in the trash.');
            }

            self::recordEdit($noteId, $boardId, $userId, 'restored', true);
            Events::append($boardId, 'note.restored', $noteId, $userId);
            Db::commit();
        } catch (ApiError $e) {
            Db::rollback();
            throw $e;
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $note = self::find($noteId);
        if ($note === null) {
            throw new ApiError(500, 'restore_failed', 'The card could not be read back.');
        }
        return $note;
    }

    /**
     * Deleted cards still inside the retention window.
     *
     * @return list<array<string,mixed>>
     */
    public static function trash(string $boardId): array
    {
        $days = max(1, Env::int('TRASH_RETENTION_DAYS', 30));

        $rows = Db::all(
            'SELECT n.id, n.title, n.content, n.pinned, n.version,
                    n.created_at, n.updated_at, n.deleted_at,
                    n.deleted_by AS updated_by, '
                    . Identity::columns() . '
               FROM notes n '
               . Identity::joins('n.deleted_by', 'n.board_id') . '
              WHERE n.board_id = :b
                AND n.deleted_at IS NOT NULL
                AND n.deleted_at > DATE_SUB(:now, INTERVAL ' . $days . ' DAY)
              ORDER BY n.deleted_at DESC
              LIMIT 100',
            [':b' => $boardId, ':now' => Db::now()]
        );

        $notes = self::hydrate($rows);

        // The trash screen counts down to the purge, so send the deadline too.
        foreach ($notes as $i => $n) {
            $deletedAt = $n['deletedAt'];
            $notes[$i]['purgesAt'] = is_string($deletedAt)
                ? Db::iso(gmdate('Y-m-d H:i:s', strtotime($deletedAt) + $days * 86400))
                : null;
        }

        return $notes;
    }

    /**
     * The Activity feed, straight off note_edits.
     *
     * The prototype derived this from notes.updated_at, which could only ever
     * show one entry per card. This shows every edit by every person.
     *
     * @return array{items:list<array<string,mixed>>,nextCursor:?int}
     */
    /**
     * Raw activity rows, newest first. Activity::forBoard() merges these with
     * Calendar::activityRows() into one feed - this method knows nothing about
     * that merge, only about note_edits.
     *
     * @return list<array<string,mixed>>
     */
    public static function activityRows(string $boardId, ?int $before, int $limit): array
    {
        $params = [':b' => $boardId];
        $where  = '';

        if ($before !== null && $before > 0) {
            $where = ' AND e.id < :before';
            $params[':before'] = $before;
        }

        $rows = Db::all(
            "SELECT e.id, e.action, e.edited_at, e.note_id, e.user_id,
                    n.title, n.deleted_at, "
                    . Identity::columns() . "
               FROM note_edits e
               LEFT JOIN notes n ON n.id = e.note_id "
               . Identity::joins('e.user_id', 'e.board_id') . "
              WHERE e.board_id = :b{$where}
              ORDER BY e.id DESC
              LIMIT " . $limit,
            $params
        );

        return array_map(static fn (array $r): array => [
            'kind'    => 'note',
            'srcId'   => (int) $r['id'],
            'action'  => (string) $r['action'],
            'at'      => (string) $r['edited_at'],
            'refId'   => (string) $r['note_id'],
            'title'   => $r['title'] !== null ? (string) $r['title'] : '',
            'deleted' => $r['deleted_at'] !== null,
            'user'    => Identity::fromRow($r, 'user_id')
                ?? ['id' => null, 'name' => 'Someone', 'color' => '#4B5240', 'username' => null, 'avatar' => null],
        ], $rows);
    }

    /**
     * Append to the audit trail, coalescing a run of edits by one person on one
     * card into a single row.
     */
    public static function recordEdit(
        string $noteId,
        string $boardId,
        string $userId,
        string $action,
        bool $force = false
    ): void {
        if (!$force && $action === 'edited') {
            $recent = Db::one(
                'SELECT id FROM note_edits
                  WHERE note_id = :n AND user_id = :u AND action = :a
                    AND edited_at > DATE_SUB(:now, INTERVAL ' . self::EDIT_COALESCE_SECONDS . ' SECOND)
                  ORDER BY id DESC LIMIT 1',
                [':n' => $noteId, ':u' => $userId, ':a' => $action, ':now' => Db::now()]
            );

            if ($recent !== null) {
                // Slide the existing row forward instead of adding another.
                Db::run(
                    'UPDATE note_edits SET edited_at = :now WHERE id = :id',
                    [':now' => Db::now(), ':id' => $recent['id']]
                );
                return;
            }
        }

        Db::run(
            'INSERT INTO note_edits (note_id, board_id, user_id, action, edited_at)
             VALUES (:n, :b, :u, :a, :now)',
            [':n' => $noteId, ':b' => $boardId, ':u' => $userId, ':a' => $action, ':now' => Db::now()]
        );
    }

    /**
     * Hard-delete soft-deleted notes past the retention window.
     * Called by tools/cleanup.php, not by request handling.
     */
    public static function purgeExpired(): int
    {
        $days = max(1, Env::int('TRASH_RETENTION_DAYS', 30));
        $st = Db::run(
            'DELETE FROM notes
              WHERE deleted_at IS NOT NULL
                AND deleted_at < DATE_SUB(:now, INTERVAL ' . $days . ' DAY)',
            [':now' => Db::now()]
        );
        return $st->rowCount();
    }
}
