<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * The whole API surface. One switch over method + path segments - a router
 * library would be more machinery than this needs.
 */
final class Router
{
    public static function dispatch(): void
    {
        $method = Http::method();
        $path   = Http::path();

        if ($method === 'OPTIONS') {
            Http::noContent();
        }

        Http::assertSameOrigin();

        $seg = $path === '' ? [] : explode('/', $path);
        $n   = count($seg);

        // Opportunistic housekeeping, roughly one request in two hundred.
        if (random_int(1, 200) === 1) {
            try {
                Auth::sweep();
            } catch (\Throwable $e) {
                Log::error('sweep_failed', $e);
            }
        }

        // --- health ---------------------------------------------------------
        if ($n === 1 && $seg[0] === 'health' && $method === 'GET') {
            Db::scalar('SELECT 1');
            Http::json(['ok' => true, 'time' => Db::iso(Db::now())]);
        }

        // --- auth -----------------------------------------------------------
        if ($n >= 1 && $seg[0] === 'auth') {
            if ($n === 2 && $seg[1] === 'providers' && $method === 'GET') {
                Http::json(['providers' => OAuth::available()]);
            }
            if ($n === 2 && $method === 'GET') {
                OAuth::begin($seg[1]);
            }
            if ($n === 3 && $seg[2] === 'callback' && $method === 'GET') {
                OAuth::callback($seg[1]);
            }
            throw ApiError::notFound('Unknown auth route.');
        }

        // --- session and master profile -------------------------------------
        if ($n >= 1 && $seg[0] === 'session') {
            if ($n === 1 && ($method === 'GET' || $method === 'POST')) {
                $user = Auth::user();
                Http::json([
                    'user'      => $user,
                    'providers' => $user === null ? OAuth::available() : [],
                    'themes'    => Themes::catalogue(),
                    // Null when the server has no VAPID pair; the client hides
                    // the notifications setting entirely rather than offering
                    // something that cannot work.
                    'pushKey'   => Push::configured() ? Push::publicKey() : null,
                ]);
            }

            if ($n === 1 && $method === 'PATCH') {
                Http::json(['user' => self::updateMasterProfile()]);
            }

            // POST /session/photo  (multipart) - replace the master photo
            if ($n === 2 && $seg[1] === 'photo' && $method === 'POST') {
                $meUser = Auth::require();
                $file   = Uploads::acceptAvatar();

                $previous = Db::scalar('SELECT avatar_path FROM users WHERE id = :id', [':id' => $meUser['id']]);
                Db::run('UPDATE users SET avatar_path = :p WHERE id = :id',
                    [':p' => $file, ':id' => $meUser['id']]);

                if (is_string($previous)) {
                    Uploads::forget($previous);
                }

                Http::json(['user' => Auth::reload((string) $meUser['id'])]);
            }

            // DELETE /session/photo - fall back to the provider picture
            if ($n === 2 && $seg[1] === 'photo' && $method === 'DELETE') {
                $meUser   = Auth::require();
                $previous = Db::scalar('SELECT avatar_path FROM users WHERE id = :id', [':id' => $meUser['id']]);

                Db::run('UPDATE users SET avatar_path = NULL WHERE id = :id', [':id' => $meUser['id']]);
                if (is_string($previous)) {
                    Uploads::forget($previous);
                }

                Http::json(['user' => Auth::reload((string) $meUser['id'])]);
            }

            if ($n === 1 && $method === 'DELETE') {
                Auth::endSession();
                Http::noContent();
            }

            throw ApiError::notFound('Unknown session route.');
        }

        // --- push subscriptions ---------------------------------------------
        // A browser, not a person: the same user on a phone and a laptop is two
        // subscriptions and both should buzz. See app/Push.php.
        if ($n >= 1 && $seg[0] === 'push') {
            $pushUser = Auth::require();
            $pushId   = (string) $pushUser['id'];

            if ($n === 2 && $seg[1] === 'subscribe' && $method === 'POST') {
                Push::subscribe($pushId, Http::body());
                Http::json(['ok' => true]);
            }

            if ($n === 2 && $seg[1] === 'unsubscribe' && $method === 'POST') {
                Push::unsubscribe($pushId, Http::body());
                Http::json(['ok' => true]);
            }

            // The service worker asks this after a payload-less push: "what am
            // I supposed to be telling them?" Reading it moves the watermark,
            // so the same flag is not announced twice.
            if ($n === 2 && $seg[1] === 'pending' && $method === 'GET') {
                $endpoint = (string) ($_GET['endpoint'] ?? '');
                Http::json(Push::pending($pushId, $endpoint));
            }

            throw ApiError::notFound('Unknown push route.');
        }

        // --- invites (peek is public-ish, accept needs a session) -----------
        if ($n >= 2 && $seg[0] === 'invites') {
            $token = Validate::inviteToken($seg[1]);

            if ($n === 2 && $method === 'GET') {
                $peek = Boards::peekInvite($token);
                $peek['signedIn'] = Auth::user() !== null;
                Http::json($peek);
            }

            if ($n === 3 && $seg[2] === 'accept' && $method === 'POST') {
                $me   = Auth::require();
                $slug = Boards::redeemInvite($token, (string) $me['id']);
                if ($slug === null) {
                    throw new ApiError(410, 'invite_invalid', 'That invite link is no longer valid.');
                }
                Http::json(['slug' => $slug]);
            }

            throw ApiError::notFound('Unknown invite route.');
        }

        // --- boards ---------------------------------------------------------
        if ($n >= 1 && $seg[0] === 'boards') {
            $me     = Auth::require();
            $userId = (string) $me['id'];

            if ($n === 1 && $method === 'GET') {
                Http::json(['boards' => self::boardsWithBootstrap($userId)]);
            }

            if ($n === 1 && $method === 'POST') {
                $body  = Http::body();
                $board = Boards::create(Validate::boardName($body['name'] ?? 'New board'), $userId);
                Http::json(['board' => $board], 201);
            }

            if ($n >= 2) {
                $board   = Boards::requireMember($seg[1], $userId);
                $boardId = (string) $board['id'];
                $role    = (string) $board['role'];

                // GET /boards/:slug
                if ($n === 2 && $method === 'GET') {
                    // Opening the board is the moment to notice a date has
                    // arrived; the poll below catches it for someone already
                    // sitting here.
                    Notes::flagDue($boardId);

                    Http::json([
                        'board' => [
                            'id'    => $boardId,
                            'slug'  => (string) $board['slug'],
                            'name'  => (string) $board['name'],
                            'theme' => (string) $board['theme'],
                            'role'  => $role,
                        ],
                        'profile' => Boards::profile($boardId, $userId, $me),
                        'notes'   => Notes::listForBoard($boardId),
                        'members' => Boards::members($boardId),
                        'cursor'  => Events::cursor($boardId),
                    ]);
                }

                // GET|PATCH /boards/:slug/profile - this member's board profile
                if ($n === 3 && $seg[2] === 'profile' && $method === 'GET') {
                    Http::json(['profile' => Boards::profile($boardId, $userId, $me)]);
                }

                if ($n === 3 && $seg[2] === 'profile' && $method === 'PATCH') {
                    Boards::updateProfile($boardId, $userId, Http::body());
                    Events::append($boardId, 'member.updated', null, $userId);
                    Http::json([
                        'profile' => Boards::profile($boardId, $userId, $me),
                        'members' => Boards::members($boardId),
                        'cursor'  => Events::cursor($boardId),
                    ]);
                }

                // POST|DELETE /boards/:slug/profile/photo
                if ($n === 4 && $seg[2] === 'profile' && $seg[3] === 'photo') {
                    if ($method === 'POST') {
                        Boards::setProfilePhoto($boardId, $userId, Uploads::acceptAvatar());
                    } elseif ($method === 'DELETE') {
                        Boards::setProfilePhoto($boardId, $userId, null);
                    } else {
                        throw ApiError::notFound('Unknown profile route.');
                    }

                    Events::append($boardId, 'member.updated', null, $userId);
                    Http::json([
                        'profile' => Boards::profile($boardId, $userId, $me),
                        'members' => Boards::members($boardId),
                        'cursor'  => Events::cursor($boardId),
                    ]);
                }

                // PATCH /boards/:slug - rename the board. Owners only; the slug
                // does not move, so links and invites already sent keep working.
                if ($n === 2 && $method === 'PATCH') {
                    $body = Http::body();
                    if (!array_key_exists('name', $body)) {
                        throw ApiError::badRequest('Nothing to update.', 'empty_patch');
                    }

                    $name = Boards::rename($boardId, $userId, $role, $body['name']);
                    Events::append($boardId, 'board.renamed', null, $userId);

                    Http::json([
                        'board' => [
                            'id'    => $boardId,
                            'slug'  => (string) $board['slug'],
                            'name'  => $name,
                            'theme' => (string) $board['theme'],
                            'role'  => $role,
                        ],
                        'cursor' => Events::cursor($boardId),
                    ]);
                }

                // PATCH /boards/:slug/theme - the board's theme, shared by
                // everyone on it. Any member may change it; the event tells the
                // others to repaint.
                if ($n === 3 && $seg[2] === 'theme' && $method === 'PATCH') {
                    $body = Http::body();
                    if (!array_key_exists('theme', $body)) {
                        throw ApiError::badRequest('Nothing to update.', 'empty_patch');
                    }

                    $theme = Boards::setTheme($boardId, $body['theme']);
                    Events::append($boardId, 'board.theme', null, $userId);

                    Http::json(['theme' => $theme, 'cursor' => Events::cursor($boardId)]);
                }

                // POST /boards/:slug/notes
                if ($n === 3 && $seg[2] === 'notes' && $method === 'POST') {
                    $note = Notes::create($boardId, $userId, Http::body());
                    Http::json(['note' => $note, 'cursor' => Events::cursor($boardId)], 201);
                }

                // GET|POST /boards/:slug/calendar - the board's shared
                // calendar. Any member may read it or add to it, exactly like
                // cards; the board is the unit of sharing.
                if ($n === 3 && $seg[2] === 'calendar' && $method === 'GET') {
                    $from = Http::query('from');
                    $to   = Http::query('to');
                    if (!is_string($from) || !is_string($to)) {
                        throw ApiError::badRequest('A from and to date are required.', 'missing_range');
                    }
                    Http::json(Calendar::range($boardId, $from, $to));
                }

                if ($n === 3 && $seg[2] === 'calendar' && $method === 'POST') {
                    $event = Calendar::create($boardId, $userId, Http::body());
                    Http::json(['event' => $event, 'cursor' => Events::cursor($boardId)], 201);
                }

                // GET /boards/:slug/changes?since=N
                if ($n === 3 && $seg[2] === 'changes' && $method === 'GET') {
                    // Due dates land on someone who has had the board open all
                    // night. Same opportunistic idiom as Auth::sweep() above:
                    // the poll is 1s, so this is roughly every half minute per
                    // client, and it writes nothing on the runs where nothing
                    // has come due.
                    if (random_int(1, 30) === 1) {
                        try {
                            Notes::flagDue($boardId);
                        } catch (\Throwable $e) {
                            Log::error('flag_due_failed', $e);
                        }
                    }

                    Http::json(self::changes($boardId, Http::query('since')));
                }

                // GET /boards/:slug/activity?before=&limit=N - cards and calendar
                // changes merged into one feed. `before` is an opaque cursor:
                // whatever Activity::forBoard() handed back last time, or
                // absent for the first page.
                if ($n === 3 && $seg[2] === 'activity' && $method === 'GET') {
                    $before = Http::query('before');
                    $limit  = Http::query('limit');
                    Http::json(Activity::forBoard(
                        $boardId,
                        is_string($before) && $before !== '' ? $before : null,
                        is_numeric($limit) ? (int) $limit : 30
                    ));
                }

                // GET /boards/:slug/trash
                if ($n === 3 && $seg[2] === 'trash' && $method === 'GET') {
                    Http::json(['notes' => Notes::trash($boardId)]);
                }

                // GET|POST /boards/:slug/members
                if ($n === 3 && $seg[2] === 'members' && $method === 'GET') {
                    Http::json(['members' => Boards::members($boardId)]);
                }

                // POST /boards/:slug/invites
                if ($n === 3 && $seg[2] === 'invites' && $method === 'POST') {
                    Http::json(['invite' => Boards::createInvite($boardId, $userId, $role)], 201);
                }

                // POST /boards/:slug/leave
                if ($n === 3 && $seg[2] === 'leave' && $method === 'POST') {
                    Boards::leave($boardId, $userId, $role);
                    Http::noContent();
                }

                // DELETE /boards/:slug - the whole board. Owners only; the
                // heaviest action in the app, so the client types the board
                // name to confirm before it ever gets here.
                if ($n === 2 && $method === 'DELETE') {
                    Boards::delete($boardId, $userId, $role);
                    Http::noContent();
                }

                // POST /boards/:slug/reset
                if ($n === 3 && $seg[2] === 'reset' && $method === 'POST') {
                    $count = Boards::reset($boardId, $userId, $role);
                    Http::json(['deleted' => $count, 'cursor' => Events::cursor($boardId)]);
                }
            }

            throw ApiError::notFound('Unknown board route.');
        }

        // --- calendar events ------------------------------------------------
        if ($n >= 2 && $seg[0] === 'calendar') {
            $me      = Auth::require();
            $userId  = (string) $me['id'];
            $eventId = Validate::id($seg[1], 'event id');

            $board   = Calendar::requireMemberForEvent($eventId, $userId);
            $boardId = (string) $board['id'];

            if ($n === 2 && $method === 'GET') {
                $event = Calendar::find($eventId);
                if ($event === null) {
                    throw ApiError::notFound('That event has been deleted.');
                }
                Http::json(['event' => $event]);
            }

            if ($n === 2 && $method === 'PATCH') {
                $event = Calendar::update($eventId, $boardId, $userId, Http::body());
                Http::json(['event' => $event, 'cursor' => Events::cursor($boardId)]);
            }

            if ($n === 2 && $method === 'DELETE') {
                Calendar::softDelete($eventId, $boardId, $userId);
                Http::json(['ok' => true, 'cursor' => Events::cursor($boardId)]);
            }

            throw ApiError::notFound('Unknown calendar route.');
        }

        // --- notes ----------------------------------------------------------
        if ($n >= 2 && $seg[0] === 'notes') {
            $me     = Auth::require();
            $userId = (string) $me['id'];
            $noteId = Validate::id($seg[1], 'note id');

            $board   = Boards::requireMemberForNote($noteId, $userId);
            $boardId = (string) $board['id'];

            if ($n === 2 && $method === 'PATCH') {
                $note = Notes::update($noteId, $boardId, $userId, Http::body());
                Http::json(['note' => $note, 'cursor' => Events::cursor($boardId)]);
            }

            if ($n === 2 && $method === 'DELETE') {
                Notes::softDelete($noteId, $boardId, $userId);
                Http::json(['ok' => true, 'cursor' => Events::cursor($boardId)]);
            }

            if ($n === 2 && $method === 'GET') {
                $note = Notes::find($noteId);
                if ($note === null) {
                    throw ApiError::notFound('That card has been deleted.');
                }
                Http::json(['note' => $note]);
            }

            if ($n === 3 && $seg[2] === 'restore' && $method === 'POST') {
                $note = Notes::restore($noteId, $boardId, $userId);
                Http::json(['note' => $note, 'cursor' => Events::cursor($boardId)]);
            }

            throw ApiError::notFound('Unknown note route.');
        }

        throw ApiError::notFound('No such endpoint.');
    }

    /**
     * Master profile edit: display name, handle, default theme. Every field is
     * optional; only what is present in the body changes.
     *
     * @return array<string,mixed> the updated master profile
     */
    private static function updateMasterProfile(): array
    {
        $meUser = Auth::require();
        $body   = Http::body();

        $sets   = [];
        $params = [':id' => $meUser['id']];

        if (array_key_exists('displayName', $body)) {
            $name = Validate::displayName($body['displayName'], (string) $meUser['displayName']);
            $sets[] = 'display_name = :name';
            $sets[] = 'color = :color';
            $params[':name']  = $name;
            $params[':color'] = Validate::colorFor($name);
        }

        if (array_key_exists('username', $body)) {
            $params[':username'] = Validate::username($body['username']);
            $sets[] = 'username = :username';
        }

        if (array_key_exists('defaultTheme', $body)) {
            $params[':theme'] = Themes::normalise($body['defaultTheme']);
            $sets[] = 'default_theme = :theme';
        }

        if ($sets === []) {
            throw ApiError::badRequest('Nothing to update.', 'empty_patch');
        }

        try {
            Db::run('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        } catch (\PDOException $e) {
            // The only unique constraint that a user can collide with here.
            if ($e->getCode() === '23000') {
                throw new ApiError(409, 'username_taken', 'That username is already taken.');
            }
            throw $e;
        }

        return Auth::reload((string) $meUser['id']);
    }

    /**
     * Boards for a user, creating a first one if they have none. A brand new
     * account landing on an empty screen with no way forward would be a dead
     * end - every user owns at least one board.
     *
     * @return list<array<string,mixed>>
     */
    private static function boardsWithBootstrap(string $userId): array
    {
        $boards = Boards::forUser($userId);
        if ($boards !== []) {
            return $boards;
        }

        Boards::create('My board', $userId);
        return Boards::forUser($userId);
    }

    /**
     * The sync endpoint. Stands in for `WS /api/boards/:slug/live`.
     *
     * Returns only what changed since the client's cursor: full note bodies for
     * created/updated ids, bare ids for deletions. When nothing has happened the
     * response is a few dozen bytes, which is what makes a 1s poll affordable.
     *
     * @return array<string,mixed>
     */
    private static function changes(string $boardId, ?string $sinceRaw): array
    {
        $cursor = Events::cursor($boardId);

        // No cursor, or a cursor from a pruned window: tell the client to
        // resync the whole board rather than hand it a partial picture.
        if ($sinceRaw === null || !is_numeric($sinceRaw)) {
            return ['cursor' => $cursor, 'resync' => true, 'changed' => [], 'deleted' => []];
        }

        $since = (int) $sinceRaw;
        if ($since >= $cursor) {
            return ['cursor' => $cursor, 'changed' => [], 'deleted' => [],
                    'members' => null, 'theme' => null, 'name' => null, 'calendar' => false];
        }

        // A client that slept past the event retention window would silently
        // miss whatever was pruned. Send it back for a full load instead.
        if (Events::hasGapBefore($boardId, $since)) {
            return ['cursor' => $cursor, 'resync' => true, 'changed' => [], 'deleted' => []];
        }

        $limit  = 200;
        $events = Events::since($boardId, $since, $limit);

        // The batch was capped. Only advance the cursor as far as we actually
        // processed, or the next poll would skip everything past the cap.
        if (count($events) === $limit) {
            $cursor = (int) $events[count($events) - 1]['id'];
        }

        $touched     = [];
        $deleted     = [];
        $needResync  = false;
        $membersMoved = false;
        $themeMoved   = false;
        $nameMoved    = false;
        $calendarMoved = false;

        foreach ($events as $e) {
            $type   = (string) $e['type'];
            $noteId = $e['note_id'] !== null ? (string) $e['note_id'] : null;

            if ($type === 'board.reset') {
                $needResync = true;
                continue;
            }
            if ($type === 'member.joined') {
                $membersMoved = true;
                continue;
            }
            if ($type === 'event.created' || $type === 'event.updated' || $type === 'event.deleted') {
                // A flag, not the events themselves. The calendar is paged by
                // month on the client, so it knows which range to re-read and
                // the delta stays a few bytes however many events a board has.
                $calendarMoved = true;
                continue;
            }
            if ($type === 'board.theme') {
                // Unlike member.updated this needs no resync: a theme touches
                // one attribute on <html> and no note data at all, so the new
                // value is the entire delta.
                $themeMoved = true;
                continue;
            }
            if ($type === 'board.renamed') {
                // Same reasoning as board.theme: one board attribute, no note
                // data behind it, so the new value is the whole delta.
                $nameMoved = true;
                continue;
            }
            if ($type === 'member.updated') {
                // A profile change restyles that person's name and photo on
                // every card they have touched, not just the member list, so
                // the cheapest correct answer is a full reload. Profile edits
                // are rare enough that this costs nothing in practice.
                $needResync = true;
                continue;
            }
            if ($noteId === null) {
                continue;
            }

            if ($type === 'note.deleted') {
                $deleted[$noteId] = true;
                unset($touched[$noteId]);
            } else {
                $touched[$noteId] = true;
                unset($deleted[$noteId]);
            }
        }

        if ($needResync) {
            return ['cursor' => $cursor, 'resync' => true, 'changed' => [], 'deleted' => []];
        }

        $changed = [];
        foreach (array_keys($touched) as $noteId) {
            $note = Notes::find($noteId);
            if ($note !== null) {
                $changed[] = $note;
            } else {
                // Created and deleted between two polls.
                $deleted[$noteId] = true;
            }
        }

        return [
            'cursor'  => $cursor,
            'changed' => $changed,
            'deleted' => array_keys($deleted),
            'members' => $membersMoved ? Boards::members($boardId) : null,
            'theme'   => $themeMoved ? Boards::theme($boardId) : null,
            'name'    => $nameMoved ? Boards::name($boardId) : null,
            'calendar' => $calendarMoved,
        ];
    }
}
