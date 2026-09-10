<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * The board calendar. Two kinds of thing land on it:
 *
 *   - calendar_events, which stand on their own ("site visit, Tue 3pm")
 *   - notes with a due_date, so a card can also be a deadline
 *
 * Both belong to the board, not to a member: everyone on a board sees the same
 * calendar, and any member may add to it. That matches how cards already work -
 * the board is the unit of sharing.
 *
 * Dates here are wall clock, not UTC. See migrations/004_calendar.sql for why.
 */
final class Calendar
{
    /** A range wider than this is refused rather than silently truncated. */
    private const MAX_RANGE_DAYS = 400;

    /**
     * Everything on the calendar between two dates, inclusive.
     *
     * @return array<string,mixed> { events, cards }
     */
    public static function range(string $boardId, string $from, string $to): array
    {
        $from = Validate::date($from, 'from');
        $to   = Validate::date($to, 'to');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $span = (strtotime($to) - strtotime($from)) / 86400;
        if ($span > self::MAX_RANGE_DAYS) {
            throw ApiError::badRequest('That date range is too wide.', 'range_too_wide');
        }

        return [
            'events' => self::eventsIn($boardId, $from, $to),
            'cards'  => self::cardsDueIn($boardId, $from, $to),
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function eventsIn(string $boardId, string $from, string $to): array
    {
        $rows = Db::all(
            'SELECT e.id, e.title, e.detail, e.event_date, e.start_time, e.version,
                    e.created_at, e.updated_at, e.updated_by, '
                    . Identity::columns() . '
               FROM calendar_events e '
               . Identity::joins('e.updated_by', 'e.board_id') . '
              WHERE e.board_id = :b AND e.deleted_at IS NULL
                AND e.event_date BETWEEN :from AND :to
              ORDER BY e.event_date ASC, e.start_time IS NULL DESC,
                       e.start_time ASC, e.created_at ASC',
            [':b' => $boardId, ':from' => $from, ':to' => $to]
        );

        return array_map([self::class, 'shape'], $rows);
    }

    /**
     * Cards carrying a due date in the range. Deliberately a thin projection -
     * the calendar shows enough to recognise a card and open it, not the card
     * itself.
     *
     * @return list<array<string,mixed>>
     */
    public static function cardsDueIn(string $boardId, string $from, string $to): array
    {
        $rows = Db::all(
            'SELECT n.id, n.title, n.pinned, n.due_date
               FROM notes n
              WHERE n.board_id = :b AND n.deleted_at IS NULL
                AND n.due_date BETWEEN :from AND :to
              ORDER BY n.due_date ASC, n.updated_at DESC',
            [':b' => $boardId, ':from' => $from, ':to' => $to]
        );

        return array_map(static fn (array $r): array => [
            'id'     => (string) $r['id'],
            'title'  => (string) $r['title'],
            'pinned' => (bool) $r['pinned'],
            'date'   => (string) $r['due_date'],
        ], $rows);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $eventId): ?array
    {
        $row = Db::one(
            'SELECT e.id, e.title, e.detail, e.event_date, e.start_time, e.version,
                    e.created_at, e.updated_at, e.updated_by, '
                    . Identity::columns() . '
               FROM calendar_events e '
               . Identity::joins('e.updated_by', 'e.board_id') . '
              WHERE e.id = :id AND e.deleted_at IS NULL',
            [':id' => $eventId]
        );

        return $row === null ? null : self::shape($row);
    }

    /**
     * Resolve the board an event belongs to and assert membership, the same way
     * a note does. A non-member gets 404, never 403.
     *
     * @return array<string,mixed>
     */
    public static function requireMemberForEvent(string $eventId, string $userId): array
    {
        Validate::id($eventId, 'event id');

        $row = Db::one(
            'SELECT b.id, b.slug, b.name, m.role
               FROM calendar_events e
               JOIN boards b ON b.id = e.board_id
               JOIN board_members m ON m.board_id = b.id AND m.user_id = :uid
              WHERE e.id = :eid AND e.deleted_at IS NULL AND b.deleted_at IS NULL',
            [':uid' => $userId, ':eid' => $eventId]
        );

        if ($row === null) {
            throw ApiError::notFound('That event does not exist, or you are not a member of its board.');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function create(string $boardId, string $userId, array $body): array
    {
        $title = trim(Validate::title($body));
        if ($title === '') {
            throw ApiError::badRequest('An event needs a title.', 'empty_title');
        }

        $date   = Validate::date($body['date'] ?? null, 'date');
        $time   = Validate::timeOrNull($body['time'] ?? null);
        $detail = Validate::content($body, 'detail');

        $id  = Ids::ulid();
        $now = Db::now();

        Db::begin();
        try {
            Db::run(
                'INSERT INTO calendar_events
                    (id, board_id, title, detail, event_date, start_time, version,
                     created_at, created_by, updated_at, updated_by)
                 VALUES (:id, :b, :t, :d, :date, :time, 1, :now, :u, :now2, :u2)',
                [
                    ':id'   => $id,
                    ':b'    => $boardId,
                    ':t'    => $title,
                    ':d'    => $detail,
                    ':date' => $date,
                    ':time' => $time,
                    ':now'  => $now,
                    ':u'    => $userId,
                    ':now2' => $now,
                    ':u2'   => $userId,
                ]
            );

            self::recordEdit($id, $boardId, $userId, 'created');
            Events::append($boardId, 'event.created', null, $userId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // After the commit, never inside it - a push service is somebody
        // else's server and must not be able to hold a row lock or roll back
        // the write. Same reasoning as the flag push in Notes::update().
        // A calendar entry is a deliberate act aimed at the other people on
        // the board, which is what makes it worth a notification at all.
        try {
            Push::notifyEventAdded($boardId, $userId);
        } catch (\Throwable $e) {
            Log::error('notify_event_failed', $e);
        }

        $event = self::find($id);
        if ($event === null) {
            throw new ApiError(500, 'create_failed', 'The event was not saved.');
        }
        return $event;
    }

    /**
     * Update an event, guarded by `version` the same way a note is.
     *
     * Events get the 409 but not the keep-mine/use-theirs sheet: an event is a
     * title, a date and a line of detail, so the client reloads and says so
     * rather than asking somebody to merge four words.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function update(string $eventId, string $boardId, string $userId, array $body): array
    {
        $expected = Validate::version($body);

        $sets   = [];
        $params = [];

        if (array_key_exists('title', $body)) {
            $title = trim(Validate::title($body));
            if ($title === '') {
                throw ApiError::badRequest('An event needs a title.', 'empty_title');
            }
            $sets[] = 'title = :t';
            $params[':t'] = $title;
        }
        if (array_key_exists('date', $body)) {
            $sets[] = 'event_date = :date';
            $params[':date'] = Validate::date($body['date'], 'date');
            // Moving an event re-arms its reminder: it has not been reminded
            // about the *new* date. Set unconditionally rather than only when
            // the date really changed - the client sends the whole event on
            // every save, and clearing a marker that was already null costs
            // nothing, where missing a real move would silence the reminder
            // for good. (Notes needs the `<=>` test because its due mark also
            // drives the flag; this one only drives the reminder.)
            $sets[] = 'reminded_at = NULL';
        }
        if (array_key_exists('time', $body)) {
            $sets[] = 'start_time = :time';
            $params[':time'] = Validate::timeOrNull($body['time']);
        }
        if (array_key_exists('detail', $body)) {
            $sets[] = 'detail = :d';
            $params[':d'] = Validate::content($body, 'detail');
        }

        if ($sets === []) {
            throw ApiError::badRequest('Nothing to update.', 'empty_patch');
        }

        $now = Db::now();

        Db::begin();
        try {
            $st = Db::run(
                'UPDATE calendar_events
                    SET ' . implode(', ', $sets) . ',
                        version = version + 1,
                        updated_at = :now,
                        updated_by = :uid
                  WHERE id = :id AND version = :expected AND deleted_at IS NULL',
                $params + [':now' => $now, ':uid' => $userId, ':id' => $eventId, ':expected' => $expected]
            );

            // Same reasoning as Notes::update(): version = version + 1 means a
            // matching row always changes, so zero rows is "someone else first".
            if ($st->rowCount() === 0) {
                Db::rollback();

                $fresh = self::find($eventId);
                if ($fresh === null) {
                    throw ApiError::notFound('That event has been deleted.');
                }
                throw ApiError::conflict($fresh);
            }

            self::recordEdit($eventId, $boardId, $userId, 'updated');
            Events::append($boardId, 'event.updated', null, $userId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $event = self::find($eventId);
        if ($event === null) {
            throw ApiError::notFound('That event has been deleted.');
        }
        return $event;
    }

    /**
     * Soft delete, like a card - the row survives the retention window so
     * tools/cleanup.php can purge it. There is deliberately no restore UI for
     * events: a mistaken delete is retyped in seconds, and a trash view for
     * four words would be more machinery than it is worth.
     */
    public static function softDelete(string $eventId, string $boardId, string $userId): void
    {
        Db::begin();
        try {
            $st = Db::run(
                'UPDATE calendar_events SET deleted_at = :now, deleted_by = :u
                  WHERE id = :id AND deleted_at IS NULL',
                [':now' => Db::now(), ':u' => $userId, ':id' => $eventId]
            );

            if ($st->rowCount() === 0) {
                Db::rollback();
                throw ApiError::notFound('That event has already been deleted.');
            }

            self::recordEdit($eventId, $boardId, $userId, 'deleted');
            Events::append($boardId, 'event.deleted', null, $userId);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    /** Append one row to the event's audit trail. See migrations/005 for why
     *  this is a separate table from note_edits rather than folded into it. */
    private static function recordEdit(string $eventId, string $boardId, string $userId, string $action): void
    {
        Db::run(
            'INSERT INTO calendar_event_edits (event_id, board_id, user_id, action, edited_at)
             VALUES (:e, :b, :u, :a, :now)',
            [':e' => $eventId, ':b' => $boardId, ':u' => $userId, ':a' => $action, ':now' => Db::now()]
        );
    }

    /**
     * Raw activity rows for Activity::forBoard() to merge with Notes'. The
     * title comes from a LEFT JOIN so a deleted event still names itself in
     * its own "deleted" row, right up until the retention window purges it.
     *
     * @return list<array<string,mixed>>
     */
    public static function activityRows(string $boardId, ?int $before, int $limit): array
    {
        $params = [':b' => $boardId];
        $where  = '';

        if ($before !== null && $before > 0) {
            $where = ' AND ce.id < :before';
            $params[':before'] = $before;
        }

        $rows = Db::all(
            'SELECT ce.id, ce.action, ce.edited_at, ce.event_id, ce.user_id,
                    e.title, e.deleted_at, '
                    . Identity::columns() . '
               FROM calendar_event_edits ce
               LEFT JOIN calendar_events e ON e.id = ce.event_id '
               . Identity::joins('ce.user_id', 'ce.board_id') . '
              WHERE ce.board_id = :b' . $where . '
              ORDER BY ce.id DESC
              LIMIT ' . $limit,
            $params
        );

        return array_map(static fn (array $r): array => [
            'kind'    => 'event',
            'srcId'   => (int) $r['id'],
            'action'  => (string) $r['action'],
            'at'      => (string) $r['edited_at'],
            'refId'   => (string) $r['event_id'],
            'title'   => $r['title'] !== null ? (string) $r['title'] : '',
            'deleted' => $r['deleted_at'] !== null,
            'user'    => Identity::fromRow($r, 'user_id')
                ?? ['id' => null, 'name' => 'Someone', 'color' => '#4B5240', 'username' => null, 'avatar' => null],
        ], $rows);
    }

    /**
     * Drop deleted events past the retention window. Called by the cleanup job
     * alongside Notes::purgeExpired().
     *
     * The INTERVAL is inlined, not bound - MySQL will not take a placeholder
     * there on several hosts. The value comes from config and is clamped first,
     * exactly as Notes::purgeExpired() does it.
     */
    public static function purgeExpired(): int
    {
        $days = max(1, Env::int('TRASH_RETENTION_DAYS', 30));
        $st = Db::run(
            'DELETE FROM calendar_events
              WHERE deleted_at IS NOT NULL
                AND deleted_at < DATE_SUB(:now, INTERVAL ' . $days . ' DAY)',
            [':now' => Db::now()]
        );
        return $st->rowCount();
    }

    /**
     * Row -> API shape. `date` and `time` are wall clock strings, never
     * timestamps, so nothing downstream is tempted to shift them by a zone.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function shape(array $r): array
    {
        $time = $r['start_time'] !== null ? substr((string) $r['start_time'], 0, 5) : null;

        return [
            'id'        => (string) $r['id'],
            'title'     => (string) $r['title'],
            'detail'    => $r['detail'] !== null ? (string) $r['detail'] : '',
            'date'      => (string) $r['event_date'],
            'time'      => $time,
            'allDay'    => $time === null,
            'version'   => (int) $r['version'],
            'createdAt' => Db::iso((string) $r['created_at']),
            'updatedAt' => Db::iso((string) $r['updated_at']),
            'updatedBy' => Identity::fromRow($r, 'updated_by'),
        ];
    }
}
