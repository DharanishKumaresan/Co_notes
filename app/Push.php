<?php
declare(strict_types=1);

namespace CoNotes;

/**
 * Web Push, for one thing only: somebody flagged a card on a board you are on.
 *
 * Why only that. A shared board changes constantly and the app already follows
 * it - the delta poll is 1s while the tab is open. A notification is for the
 * case the poll cannot cover: the app is closed and something happened that
 * someone deliberately marked as needing attention. Flagging is the only
 * gesture in this app that means exactly that, which is why it is the only one
 * that buzzes. Every card edit would be noise, and the poll already has it.
 *
 * The pushes carry **no payload**. That is a deliberate simplification, and it
 * is what makes this possible without Composer: an encrypted payload needs
 * ECDH against the browser's key, HKDF and AES-128-GCM, which is a lot of
 * hand-rolled crypto to get subtly wrong. A payload-less push is just a signed
 * "wake up" - the service worker then calls GET /api/push/pending and finds out
 * what actually happened. So the only crypto here is the VAPID JWT, which is
 * one openssl_sign() plus some DER wrangling.
 *
 * The cost of that choice: the browser must be online when the notification is
 * shown, because the text comes from a fetch. That is acceptable - a card
 * flagged on a shared board is not worth reading offline, and the fetch is the
 * same endpoint the app already talks to.
 */
final class Push
{
    /** How long a signed VAPID token is good for. Twelve hours is the usual. */
    private const JWT_TTL = 43200;

    /** How long a push service should hold the message for an offline browser. */
    private const TTL = 86400;

    /** Nothing older than this is ever notified about, even on a fresh browser. */
    private const CATCHUP_MINUTES = 60;

    /** One buzz per card; more than this in a window and the rest can wait. */
    private const MAX_ITEMS = 5;

    public static function configured(): bool
    {
        return extension_loaded('openssl')
            && Env::get('VAPID_PUBLIC_KEY', '') !== ''
            && Env::get('VAPID_PRIVATE_KEY', '') !== '';
    }

    public static function publicKey(): string
    {
        return (string) Env::get('VAPID_PUBLIC_KEY', '');
    }

    /* ------------------------------------------------------------ storage */

    /**
     * Remember a browser. Re-subscribing the same browser hands back the same
     * endpoint, so this is an upsert rather than a second row.
     */
    public static function subscribe(string $userId, array $body): void
    {
        $endpoint = trim((string) ($body['endpoint'] ?? ''));
        $p256dh   = trim((string) ($body['p256dh'] ?? ''));
        $auth     = trim((string) ($body['auth'] ?? ''));

        if ($endpoint === '' || !preg_match('#^https://#', $endpoint)) {
            throw ApiError::badRequest('A push endpoint is required.', 'bad_subscription');
        }
        if (strlen($endpoint) > 2048 || strlen($p256dh) > 255 || strlen($auth) > 255) {
            throw ApiError::badRequest('That subscription is not valid.', 'bad_subscription');
        }

        Db::run(
            'INSERT INTO push_subscriptions
                 (id, user_id, endpoint, p256dh, auth, created_at, last_notified_at)
             VALUES (:id, :uid, :ep, :p, :a, :now, :now2)
             ON DUPLICATE KEY UPDATE
                 user_id = VALUES(user_id),
                 p256dh  = VALUES(p256dh),
                 auth    = VALUES(auth),
                 failed_at = NULL',
            [
                ':id'   => Ids::ulid(),
                ':uid'  => $userId,
                ':ep'   => $endpoint,
                ':p'    => $p256dh,
                ':a'    => $auth,
                ':now'  => Db::now(),
                // A new browser starts caught up: enabling notifications should
                // not immediately replay an hour of other people's flags.
                ':now2' => Db::now(),
            ]
        );
    }

    public static function unsubscribe(string $userId, array $body): void
    {
        $endpoint = trim((string) ($body['endpoint'] ?? ''));
        if ($endpoint === '') {
            return;
        }
        Db::run(
            'DELETE FROM push_subscriptions WHERE user_id = :uid AND endpoint = :ep',
            [':uid' => $userId, ':ep' => $endpoint]
        );
    }

    /** Does this user have at least one browser subscribed? */
    public static function countFor(string $userId): int
    {
        return (int) Db::scalar(
            'SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :uid',
            [':uid' => $userId]
        );
    }

    /* ------------------------------------------------------------ sending */

    /**
     * Somebody flagged a card. Wake every other member's browsers.
     *
     * Called from Notes::update() after the transaction commits - never inside
     * it. A push service being slow or down must not roll back somebody's save,
     * and must not hold a row lock while we wait on the network.
     */
    public static function notifyFlagged(string $boardId, string $actorId): void
    {
        self::wake($boardId, $actorId);
    }

    /* ---------------------------------------------------------- reminders */

    /**
     * How far ahead "coming up" reaches. Two days, so something due tomorrow
     * still catches you the evening before rather than the morning of.
     */
    private const REMIND_DAYS = 2;

    /**
     * Somebody put an event on the board calendar. Same shape as a flag: one
     * deliberate act by a person, so everyone else hears about it.
     */
    public static function notifyEventAdded(string $boardId, string $actorId): void
    {
        self::wake($boardId, $actorId);
    }

    /**
     * Mark everything dated within the window as reminded, and wake the
     * browsers of everyone on those boards.
     *
     * The marking is what makes this safe to run on a tight cron: a row is
     * only ever picked up once, because the same UPDATE that selects it also
     * stamps it. Run it hourly, or every five minutes - it will not repeat.
     *
     * Unlike a flag or a new event, a reminder goes to **everybody on the
     * board including whoever set the date**. A reminder is not news about
     * another person, it is the thing itself coming up, and the person who
     * wrote "call the tiler Thursday" is exactly who needs telling on
     * Wednesday.
     *
     * @return array{notes:int,events:int} how many were newly marked
     */
    public static function sweepReminders(): array
    {
        $now = Db::now();
        $d   = self::REMIND_DAYS;

        // INTERVAL inlined from a constant, as everywhere else in this app.
        $notes = Db::run(
            'UPDATE notes
                SET reminded_at = :now
              WHERE reminded_at IS NULL
                AND deleted_at IS NULL
                AND due_date IS NOT NULL
                AND due_date <= DATE_ADD(CURDATE(), INTERVAL ' . $d . ' DAY)',
            [':now' => $now]
        )->rowCount();

        $events = Db::run(
            'UPDATE calendar_events
                SET reminded_at = :now
              WHERE reminded_at IS NULL
                AND deleted_at IS NULL
                AND event_date <= DATE_ADD(CURDATE(), INTERVAL ' . $d . ' DAY)',
            [':now' => $now]
        )->rowCount();

        if ($notes === 0 && $events === 0) {
            return ['notes' => 0, 'events' => 0];
        }

        // Which boards actually gained something, so a quiet board's browsers
        // are not woken to be told nothing.
        $boards = Db::all(
            'SELECT DISTINCT board_id FROM notes
              WHERE reminded_at = :now AND deleted_at IS NULL
             UNION
             SELECT DISTINCT board_id FROM calendar_events
              WHERE reminded_at = :now2 AND deleted_at IS NULL',
            [':now' => $now, ':now2' => $now]
        );

        foreach ($boards as $row) {
            self::wake((string) $row['board_id'], null);
        }

        return ['notes' => $notes, 'events' => $events];
    }

    /**
     * Wake every subscribed browser on a board, optionally skipping one
     * person's own.
     *
     * `$except` is the actor for "somebody did something" pushes, and null for
     * a reminder, which is for everyone.
     */
    private static function wake(string $boardId, ?string $except): void
    {
        if (!self::configured()) {
            return;
        }

        $sql = 'SELECT s.id, s.endpoint
                  FROM push_subscriptions s
                  JOIN board_members m ON m.user_id = s.user_id
                 WHERE m.board_id = :b';
        $args = [':b' => $boardId];

        if ($except !== null) {
            $sql .= ' AND s.user_id <> :actor';
            $args[':actor'] = $except;
        }

        foreach (Db::all($sql, $args) as $sub) {
            try {
                self::send((string) $sub['id'], (string) $sub['endpoint']);
            } catch (\Throwable $e) {
                // One dead browser must not stop the others being told.
                Log::error('push_send_failed', $e);
            }
        }
    }

    /**
     * One payload-less push. 201 is the success every push service returns;
     * 404 and 410 mean the browser is gone for good, so the row goes with it.
     */
    private static function send(string $subId, string $endpoint): void
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return;
        }
        $audience = $parts['scheme'] . '://' . $parts['host'];

        $jwt = self::vapidToken($audience);
        if ($jwt === null) {
            return;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => [
                'Authorization: vapid t=' . $jwt . ', k=' . self::publicKey(),
                'TTL: ' . self::TTL,
                'Urgency: normal',
                'Content-Length: 0',
            ],
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false && $err !== '') {
            Log::warn('push_transport_failed ' . $audience . ' - ' . $err);
            return;
        }

        if ($status === 404 || $status === 410) {
            Db::run('DELETE FROM push_subscriptions WHERE id = :id', [':id' => $subId]);
            return;
        }
        if ($status < 200 || $status >= 300) {
            Db::run(
                'UPDATE push_subscriptions SET failed_at = :now WHERE id = :id',
                [':now' => Db::now(), ':id' => $subId]
            );
            Log::warn('push_rejected ' . $audience . ' - HTTP ' . $status);
        }
    }

    /* --------------------------------------------------------------- VAPID */

    /**
     * The signed identity token a push service wants on every send.
     *
     * A JWT with alg ES256: base64url(header).base64url(claims).base64url(sig),
     * signed with the P-256 private key from .env.
     */
    private static function vapidToken(string $audience): ?string
    {
        $pem = self::privateKeyPem();
        if ($pem === null) {
            return null;
        }

        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $claims = [
            'aud' => $audience,
            'exp' => time() + self::JWT_TTL,
            'sub' => self::subject(),
        ];

        $input = self::b64u(json_encode($header, JSON_UNESCAPED_SLASHES))
               . '.' . self::b64u(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            Log::warn('vapid_key_unreadable - ' . (openssl_error_string() ?: 'unknown'));
            return null;
        }

        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            Log::warn('vapid_sign_failed - ' . (openssl_error_string() ?: 'unknown'));
            return null;
        }

        $raw = self::derToRawSignature($der);
        if ($raw === null) {
            return null;
        }

        return $input . '.' . self::b64u($raw);
    }

    /**
     * Who to contact about this server, for the JWT's `sub` claim.
     *
     * This is not decoration. Apple's push service rejects a token whose `sub`
     * is missing or is not a mailto: or https: URL - 403 BadJwtToken, every
     * send, so notifications simply never arrive on iPhones while working fine
     * on Android, which is a miserable thing to debug. Google is happy with
     * almost anything, so a bad value here breaks exactly one platform.
     *
     * So an unusable VAPID_SUBJECT falls back to the site's own URL, which the
     * spec allows and Apple accepts, rather than to something cosmetic.
     */
    private static function subject(): string
    {
        $subject = trim((string) Env::get('VAPID_SUBJECT', ''));

        $usable = $subject !== ''
            && (str_starts_with($subject, 'https://')
                || (str_starts_with($subject, 'mailto:')
                    && filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false));

        if ($usable) {
            return $subject;
        }

        $base = rtrim((string) Env::baseUrl(), '/');
        if (str_starts_with($base, 'https://')) {
            return $base;
        }

        // Local development over http. Apple is never talked to from here, and
        // Google does not mind, so this only has to be well-formed.
        return 'mailto:admin@example.com';
    }

    /** Whether VAPID_SUBJECT is set to something a push service will accept. */
    public static function subjectIsExplicit(): bool
    {
        $subject = trim((string) Env::get('VAPID_SUBJECT', ''));
        if ($subject === '') {
            return false;
        }
        return str_starts_with($subject, 'https://')
            || (str_starts_with($subject, 'mailto:')
                && filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL) !== false);
    }

    /**
     * Rebuild a PEM from the two base64url values in .env.
     *
     * openssl will not take a bare 32-byte scalar, so the SEC1 structure from
     * RFC 5915 is assembled by hand around it:
     *
     *   SEQUENCE { INTEGER 1, OCTET STRING priv, [0] OID prime256v1,
     *              [1] BIT STRING 0x04||X||Y }
     */
    private static function privateKeyPem(): ?string
    {
        $priv = self::b64uDecode((string) Env::get('VAPID_PRIVATE_KEY', ''));
        $pub  = self::b64uDecode(self::publicKey());

        if (strlen($priv) !== 32 || strlen($pub) !== 65 || $pub[0] !== "\x04") {
            Log::warn('vapid_keys_malformed - private=' . strlen($priv) . 'B public=' . strlen($pub) . 'B');
            return null;
        }

        // 1.2.840.10045.3.1.7 - prime256v1 / P-256.
        $oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

        $der = self::derSeq(
            "\x02\x01\x01"                                   // INTEGER 1
            . self::derTlv("\x04", $priv)                    // OCTET STRING
            . self::derTlv("\xa0", $oid)                     // [0] parameters
            . self::derTlv("\xa1", self::derTlv("\x03", "\x00" . $pub))  // [1] BIT STRING
        );

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }

    /**
     * openssl signs to DER - SEQUENCE { INTEGER r, INTEGER s } - and JWS wants
     * the raw pair, each left-padded to 32 bytes. The INTEGERs carry a leading
     * 0x00 whenever the high bit is set, which has to come back off.
     */
    private static function derToRawSignature(string $der): ?string
    {
        $i = 0;
        if (($der[$i++] ?? '') !== "\x30") {
            return null;
        }
        $seqLen = ord($der[$i++] ?? "\x00");
        if ($seqLen > 0x80) {                    // long form length
            $i += $seqLen - 0x80;
        }

        $read = static function (string $der, int &$i): ?string {
            if (($der[$i++] ?? '') !== "\x02") {
                return null;
            }
            $len = ord($der[$i++] ?? "\x00");
            $val = substr($der, $i, $len);
            $i  += $len;
            return $val;
        };

        $r = $read($der, $i);
        $s = $read($der, $i);
        if ($r === null || $s === null) {
            return null;
        }

        $trim = static fn (string $v): string =>
            str_pad(ltrim($v, "\x00"), 32, "\0", STR_PAD_LEFT);

        return $trim($r) . $trim($s);
    }

    /** DER tag-length-value, with the two length forms this needs. */
    private static function derTlv(string $tag, string $value): string
    {
        $len = strlen($value);
        if ($len < 0x80) {
            return $tag . chr($len) . $value;
        }
        $bytes = ltrim(pack('N', $len), "\x00");
        return $tag . chr(0x80 | strlen($bytes)) . $bytes . $value;
    }

    private static function derSeq(string $value): string
    {
        return self::derTlv("\x30", $value);
    }

    private static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad !== 0) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return (string) base64_decode($s, true);
    }

    /* -------------------------------------------------------------- pending */

    /**
     * What this browser still owes its owner a notification about.
     *
     * The push carried nothing, so the service worker asks this. Everything
     * flagged since the subscription was last caught up, on boards this person
     * is on, by somebody else. Reading it moves the watermark, so a second
     * push for the same flag comes back empty rather than buzzing twice - and
     * the client tags notifications by card id as a second line of defence.
     *
     * @return array<string,mixed>
     */
    public static function pending(string $userId, string $endpoint): array
    {
        $sub = Db::one(
            'SELECT id, last_notified_at FROM push_subscriptions
              WHERE user_id = :uid AND endpoint = :ep',
            [':uid' => $userId, ':ep' => $endpoint]
        );
        if ($sub === null) {
            return ['items' => []];
        }

        $now   = Db::now();
        $since = $sub['last_notified_at'] ?? '1970-01-01 00:00:00';

        $items = array_merge(
            self::pendingFlags($userId, $since, $now),
            self::pendingEvents($userId, $since, $now),
            self::pendingReminders($userId, $since)
        );

        // Newest first, and never more than a handful - a browser that has
        // been asleep should not wake up to a wall of notifications.
        usort($items, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        $items = array_slice($items, 0, self::MAX_ITEMS);

        // Reading moves the watermark, so a redelivered push comes back empty
        // rather than buzzing twice.
        Db::run(
            'UPDATE push_subscriptions SET last_notified_at = :now WHERE id = :id',
            [':now' => $now, ':id' => (string) $sub['id']]
        );

        return ['items' => $items];
    }

    /** Cards somebody else flagged. */
    private static function pendingFlags(string $userId, string $since, string $now): array
    {
        $rows = Db::all(
            'SELECT n.id, n.title, b.name AS board_name, b.slug AS board_slug,
                    e.edited_at, e.user_id AS actor_id, ' . Identity::columns() . '
               FROM note_edits e
               JOIN notes  n ON n.id = e.note_id
               JOIN boards b ON b.id = e.board_id
               JOIN board_members m ON m.board_id = e.board_id AND m.user_id = :uid
               ' . Identity::joins('e.user_id', 'e.board_id') . '
              WHERE e.action = \'flagged\'
                AND e.user_id IS NOT NULL
                AND e.user_id <> :uid2
                AND n.deleted_at IS NULL
                AND n.pinned = 1
                AND b.deleted_at IS NULL
                AND e.edited_at > :since
                AND e.edited_at > DATE_SUB(:now, INTERVAL ' . self::CATCHUP_MINUTES . ' MINUTE)
              ORDER BY e.edited_at DESC
              LIMIT ' . self::MAX_ITEMS,
            [':uid' => $userId, ':uid2' => $userId, ':since' => $since, ':now' => $now]
        );

        $out = [];
        foreach ($rows as $r) {
            $who = Identity::fromRow($r, 'actor_id');
            $out[] = [
                'kind'      => 'flagged',
                'id'        => (string) $r['id'],
                'title'     => self::titleOr($r['title'] ?? '', 'an untitled card'),
                'boardName' => (string) $r['board_name'],
                'boardSlug' => (string) $r['board_slug'],
                'by'        => (string) ($who['name'] ?? 'Someone'),
                'at'        => Db::iso((string) $r['edited_at']),
            ];
        }
        return $out;
    }

    /** Events somebody else put on the board calendar. */
    private static function pendingEvents(string $userId, string $since, string $now): array
    {
        $rows = Db::all(
            'SELECT ev.id, ev.title, ev.event_date, b.name AS board_name, b.slug AS board_slug,
                    ce.edited_at, ce.user_id AS actor_id, ' . Identity::columns() . '
               FROM calendar_event_edits ce
               JOIN calendar_events ev ON ev.id = ce.event_id
               JOIN boards b ON b.id = ce.board_id
               JOIN board_members m ON m.board_id = ce.board_id AND m.user_id = :uid
               ' . Identity::joins('ce.user_id', 'ce.board_id') . '
              WHERE ce.action = \'created\'
                AND ce.user_id IS NOT NULL
                AND ce.user_id <> :uid2
                AND ev.deleted_at IS NULL
                AND b.deleted_at IS NULL
                AND ce.edited_at > :since
                AND ce.edited_at > DATE_SUB(:now, INTERVAL ' . self::CATCHUP_MINUTES . ' MINUTE)
              ORDER BY ce.edited_at DESC
              LIMIT ' . self::MAX_ITEMS,
            [':uid' => $userId, ':uid2' => $userId, ':since' => $since, ':now' => $now]
        );

        $out = [];
        foreach ($rows as $r) {
            $who = Identity::fromRow($r, 'actor_id');
            $out[] = [
                'kind'      => 'event',
                'id'        => (string) $r['id'],
                'title'     => self::titleOr($r['title'] ?? '', 'an untitled event'),
                'date'      => (string) $r['event_date'],
                'boardName' => (string) $r['board_name'],
                'boardSlug' => (string) $r['board_slug'],
                'by'        => (string) ($who['name'] ?? 'Someone'),
                'at'        => Db::iso((string) $r['edited_at']),
            ];
        }
        return $out;
    }

    /**
     * Dated things coming up.
     *
     * No CATCHUP window here, unlike the two above. Those are news about a
     * person and go stale; a date does not. A card due tomorrow is still due
     * tomorrow whether the browser has been asleep for ten minutes or ten
     * hours, so the only thing that decides is the watermark.
     *
     * And no actor filter: a reminder goes to whoever is on the board,
     * including the person who set the date. It is the thing itself coming
     * up, not news about somebody else.
     */
    private static function pendingReminders(string $userId, string $since): array
    {
        $notes = Db::all(
            'SELECT n.id, n.title, n.due_date, n.reminded_at,
                    b.name AS board_name, b.slug AS board_slug
               FROM notes n
               JOIN boards b ON b.id = n.board_id
               JOIN board_members m ON m.board_id = n.board_id AND m.user_id = :uid
              WHERE n.reminded_at IS NOT NULL
                AND n.reminded_at > :since
                AND n.deleted_at IS NULL
                AND b.deleted_at IS NULL
              ORDER BY n.due_date ASC
              LIMIT ' . self::MAX_ITEMS,
            [':uid' => $userId, ':since' => $since]
        );

        $events = Db::all(
            'SELECT ev.id, ev.title, ev.event_date, ev.start_time, ev.reminded_at,
                    b.name AS board_name, b.slug AS board_slug
               FROM calendar_events ev
               JOIN boards b ON b.id = ev.board_id
               JOIN board_members m ON m.board_id = ev.board_id AND m.user_id = :uid
              WHERE ev.reminded_at IS NOT NULL
                AND ev.reminded_at > :since
                AND ev.deleted_at IS NULL
                AND b.deleted_at IS NULL
              ORDER BY ev.event_date ASC
              LIMIT ' . self::MAX_ITEMS,
            [':uid' => $userId, ':since' => $since]
        );

        $out = [];
        foreach ($notes as $r) {
            $out[] = [
                'kind'      => 'due',
                'id'        => (string) $r['id'],
                'title'     => self::titleOr($r['title'] ?? '', 'an untitled card'),
                'date'      => (string) $r['due_date'],
                'boardName' => (string) $r['board_name'],
                'boardSlug' => (string) $r['board_slug'],
                'by'        => null,
                'at'        => Db::iso((string) $r['reminded_at']),
            ];
        }
        foreach ($events as $r) {
            $out[] = [
                'kind'      => 'soon',
                'id'        => (string) $r['id'],
                'title'     => self::titleOr($r['title'] ?? '', 'an untitled event'),
                'date'      => (string) $r['event_date'],
                'time'      => $r['start_time'] !== null ? substr((string) $r['start_time'], 0, 5) : null,
                'boardName' => (string) $r['board_name'],
                'boardSlug' => (string) $r['board_slug'],
                'by'        => null,
                'at'        => Db::iso((string) $r['reminded_at']),
            ];
        }
        return $out;
    }

    private static function titleOr(mixed $title, string $fallback): string
    {
        $t = trim((string) $title);
        return $t !== '' ? $t : $fallback;
    }
}
