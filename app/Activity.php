<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * The board's Activity feed: card edits and calendar changes, merged into one
 * timeline. Two source tables feed it - note_edits and calendar_event_edits -
 * each with its own independent auto-increment id, so a single integer cursor
 * cannot page across both. See decodeCursor()/encodeCursor() for how that is
 * handled.
 */
final class Activity
{
    /**
     * @return array{items: list<array<string,mixed>>, nextCursor: array<string,int|null>|null}
     */
    public static function forBoard(string $boardId, ?string $beforeToken, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        [$noteBefore, $eventBefore] = self::decodeCursor($beforeToken);

        // Overfetch by one from each source, same trick Notes::activityRows()
        // used to play alone: if a source hands back more than $limit rows,
        // there is more beyond what fits on this page.
        $notes  = Notes::activityRows($boardId, $noteBefore, $limit + 1);
        $events = Calendar::activityRows($boardId, $eventBefore, $limit + 1);

        $merged = array_merge($notes, $events);

        // `at` is a raw 'YYYY-MM-DD HH:MM:SS' string from both sources, which
        // sorts correctly as a string. Within one source, id order and time
        // order agree (edited_at is set at insert time), so this global sort
        // can only ever cut a *suffix* off each source's own sequence - never
        // leave a gap in the middle. That is what makes the per-source cursor
        // below safe.
        usort($merged, static function (array $a, array $b): int {
            return $b['at'] <=> $a['at'] ?: $b['srcId'] <=> $a['srcId'];
        });

        $hasMore = count($merged) > $limit;
        $page    = array_slice($merged, 0, $limit);

        // The page is newest-first; walking it in reverse and taking the first
        // hit per kind gives the oldest (smallest) srcId of that kind still on
        // the page - the correct point to resume from. A kind absent from the
        // page keeps whatever cursor it came in with.
        $noteCursor  = $noteBefore;
        $eventCursor = $eventBefore;
        $sawNote  = false;
        $sawEvent = false;
        foreach (array_reverse($page) as $row) {
            if ($row['kind'] === 'note' && !$sawNote) {
                $noteCursor = $row['srcId'];
                $sawNote = true;
            } elseif ($row['kind'] === 'event' && !$sawEvent) {
                $eventCursor = $row['srcId'];
                $sawEvent = true;
            }
        }

        $items = array_map(static fn (array $r): array => [
            'kind'    => $r['kind'],
            'action'  => $r['action'],
            'at'      => Db::iso($r['at']),
            'refId'   => $r['refId'],
            'title'   => $r['title'],
            'deleted' => $r['deleted'],
            'user'    => $r['user'],
        ], $page);

        return [
            'items'      => $items,
            'nextCursor' => $hasMore ? ['n' => $noteCursor, 'e' => $eventCursor] : null,
        ];
    }

    /**
     * The cursor is an opaque token as far as the client is concerned - it is
     * only ever round-tripped, never inspected. Encoded here as small JSON so
     * two independent id sequences can travel in one query parameter.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private static function decodeCursor(?string $token): array
    {
        if ($token === null || $token === '') {
            return [null, null];
        }

        $decoded = json_decode($token, true);
        if (!is_array($decoded)) {
            return [null, null];
        }

        $n = $decoded['n'] ?? null;
        $e = $decoded['e'] ?? null;

        return [
            is_int($n) || (is_string($n) && ctype_digit($n)) ? (int) $n : null,
            is_int($e) || (is_string($e) && ctype_digit($e)) ? (int) $e : null,
        ];
    }
}
