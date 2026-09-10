<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * The board_events table is the live-sync spine. Every mutation appends a row;
 * clients poll `GET /api/boards/:slug/changes?since=<id>` and only pull the
 * notes named by the events they have not seen.
 *
 * This is the shape a WebSocket would push. If the app ever moves to a host
 * that can hold a socket open, the transport changes and this table does not.
 */
final class Events
{
    public static function append(string $boardId, string $type, ?string $noteId, ?string $actorId): void
    {
        Db::run(
            'INSERT INTO board_events (board_id, type, note_id, actor_id, created_at)
             VALUES (:b, :t, :n, :a, :now)',
            [
                ':b'   => $boardId,
                ':t'   => $type,
                ':n'   => $noteId,
                ':a'   => $actorId,
                ':now' => Db::now(),
            ]
        );
    }

    /** Highest event id on a board - the cursor a fresh client starts from. */
    public static function cursor(string $boardId): int
    {
        $v = Db::scalar(
            'SELECT COALESCE(MAX(id), 0) FROM board_events WHERE board_id = :b',
            [':b' => $boardId]
        );
        return (int) $v;
    }

    /**
     * Events after `$since`, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public static function since(string $boardId, int $since, int $limit = 200): array
    {
        return Db::all(
            'SELECT id, type, note_id, actor_id, created_at
               FROM board_events
              WHERE board_id = :b AND id > :since
              ORDER BY id ASC
              LIMIT ' . $limit,
            [':b' => $boardId, ':since' => $since]
        );
    }

    /**
     * True when events the client has not seen have already been pruned - i.e.
     * its cursor sits before the oldest row we still hold. The delta it would
     * get back is incomplete, so it needs a full reload instead.
     */
    public static function hasGapBefore(string $boardId, int $since): bool
    {
        $oldest = Db::scalar(
            'SELECT MIN(id) FROM board_events WHERE board_id = :b',
            [':b' => $boardId]
        );

        if ($oldest === null) {
            return false;   // nothing retained, nothing to miss
        }

        // `since` is the last id the client applied, so the next row it expects
        // is since + 1. Anything older than that being gone is fine.
        return $since + 1 < (int) $oldest;
    }

    /** Drop events older than the retention window. Called by the cleanup job. */
    public static function prune(): int
    {
        $days = max(1, Env::int('EVENT_RETENTION_DAYS', 7));
        $st = Db::run(
            'DELETE FROM board_events WHERE created_at < DATE_SUB(:now, INTERVAL ' . $days . ' DAY)',
            [':now' => Db::now()]
        );
        return $st->rowCount();
    }
}
