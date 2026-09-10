# AGENTS.md — Co notes

Context file for Codex. Read this before touching anything.

## What this project is

Co notes is a **shared notes board**. Everyone on a board sees the same set of
cards and can read, edit, flag, and delete any of them. There are no private
notes and no per-card permissions — the board is the unit of sharing.

The backend exists now. `README.md` covers deployment and the API; this file
covers how to work on the code.

---

## Where things stand

**Read this first.** The whole app is written — schema, API, auth, profiles,
themes, frontend — and none of the PHP has ever been executed.

| | Status |
|---|---|
| Frontend (`app.css`, `app.js`) | Driven end to end in a browser against the mock API. Cards, editing, 409 conflicts, delta sync, offline, activity, trash, boards, profiles, photo upload, all six themes. |
| PHP (`app/`, `tools/`) | Written and reviewed. **Never run.** No PHP existed on the machine it was written on, so there is no `php -l` pass behind it — only a brace/paren balance check. |
| Database | `migrations/001_init.sql` through `006_board_delete.sql`, applied to a real MariaDB locally and to MySQL on Hostinger. |
| OAuth | Google and GitHub apps are **not registered yet**; no client ids exist. |
| Deployment | Nothing has been uploaded to Hostinger. |

So: expect the first real run to surface ordinary PHP mistakes — a typo, a
column name, a MySQL strictness difference. That is the known risk, and
`tools/doctor.php` exists to catch the environmental half of it fast.

### First run on a new machine

1. `cp .env.example .env`, then fill in `APP_BASE_URL` and the `DB_*` values.
   `APP_BASE_URL` must match the address used in the browser exactly — scheme
   and `www.` included — because writes check it against the `Origin` header.
2. Create the schema: import `migrations/001_init.sql` through
   `005_calendar_activity.sql`, in filename order, through phpMyAdmin, or run
   `php tools/migrate.php` if there is a shell. Order matters: 003 drops
   `board_members.theme`, 004 alters `notes`, and 005 depends on the table 004
   left behind - none can run instead of what came before it.
3. `php tools/doctor.php` — checks PHP version, `pdo_mysql`, `curl`, `mbstring`,
   `gd`, the database, every table, the migration-002 through -004 columns,
   the upload directory, and OAuth config. It prints the exact redirect URIs to paste into
   Google and GitHub.
4. Register the OAuth apps against those URIs and put the ids and secrets in
   `.env`. Either provider alone is enough.
5. If PHP is available locally, set `APP_ENV=dev` so API errors come back with
   real messages instead of generic ones. Turn it off before anyone else uses it.

If PHP still is not available, the frontend can be worked on in full through the
mock harness below — that is how everything so far was verified.

---

## Stack

PHP 8.1+ and MySQL/MariaDB, no Composer, no build step. That is a consequence of
the deployment target — **Hostinger shared hosting with phpMyAdmin** — not a
preference. It also rules out WebSocket, so live updates run over a cursor-based
delta poll (`GET /api/boards/:slug/changes?since=`) at 1s.

If the app ever moves to a host with long-lived processes, the transport is
isolated in `tick()` in `public_html/assets/app.js`. Replace that function; the
rest of the client does not know how events arrive.

## Layout

```
public_html/          the deployable web root — upload its CONTENTS to the host
  index.html            the shell (.side wraps the nav; see decision 13)
  assets/app.css        prototype stylesheet + one marked block of additions
  assets/app.js         the client: API calls, sync, conflicts, profiles, themes
  api/index.php         front controller; everything under /api/ lands here
  uploads/avatars/      profile photos, PHP execution disabled via .htaccess
app/                  server code, lives OUTSIDE the web root
  Router.php            the entire API surface, one switch over path segments
  Auth.php OAuth.php    sessions and Google/GitHub sign-in
  Boards.php Notes.php  the domain
  Calendar.php           board events, and the cards that carry a due date
  Activity.php           merges note_edits and calendar_event_edits into one feed
  Identity.php          how a person appears on a given board (see Schema)
  Uploads.php           photo validation, GD re-encoding, storage
  Themes.php            the theme catalogue the server validates against
  Db Http Validate ApiError Env Ids Events Log
migrations/           forward-only SQL: 001_init, 002_profiles, 003_board_theme,
                        004_calendar, 005_calendar_activity, 006_board_delete
tools/                migrate, cleanup (cron), doctor (self-check), preview/
co-notes-lime.html      the original single-file prototype, reference only, not served
```

Nothing is minified or generated except `tools/preview/co-notes-preview.html`.
`public_html/` is the deployable artifact exactly as it sits.

## Decisions already made

Do not reopen these without asking:

1. **Auth** — Google and GitHub OAuth 2.0. Sessions are server-side rows; the
   cookie carries only an opaque id, httpOnly, SameSite=Lax.
2. **Board access** — multi-board with explicit membership. A board you are not
   a member of returns 404, not 403, so slugs cannot be enumerated. Invite links
   grant membership; only owners can mint them.
3. **Delete** — soft, with a 30-day restore window. Board reset soft-deletes
   every card at once. An owner can also delete a whole board
   (`boards.deleted_at`, migration 006): it vanishes for every member at once,
   and `cleanup.php` hard-deletes it past the window, at which point the
   ON DELETE CASCADE constraints take the cards, calendar, members and history
   with it. There is deliberately no restore UI for a board - the same call
   made for calendar events - but inside the window it is recoverable by
   clearing `deleted_at` by hand. `tools/cleanup.php` purges past the window.
4. **Concurrency** — optimistic per-note `version`. `PATCH` with a stale version
   returns 409 with the current server state; the client shows *Keep mine* /
   *Use theirs*. No CRDTs, no OT — that is a different project, and would need
   to be its own decision.
5. **Hosting** — shared hosting, hence polling. See above.
6. **Profiles** — one master profile per user (unique `@username`, email from
   the provider, display name, photo, default theme), plus an optional profile
   per board that overrides name and photo for that board only. The theme is not
   part of it; see 7.
7. **Themes** — six accent themes, all on the same matte dark ground. **A theme
   is a colour *pair*, not one colour**: `--acc` is the primary and `--acc-2`
   the second stop, and `--acc-grad` is the 135° gradient between them. Small
   marks - a status dot, a tick, a tinted pill - stay flat `--acc`, because a
   gradient across 8px is only a muddier flat colour; the pair shows on the
   surfaces big enough to carry it (buttons, the active tab, the ambient `#bg`
   wash, the profile hero's bloom). Both stops are light, so `--on-acc` reads
   on either end - that is the constraint a new pair has to satisfy. `--glow-b`
   is derived from `--acc-2`, so the second glow follows automatically. The
   picker's discs are painted from `THEME_SWATCH` in `app.js`, which holds the
   same pairs and must be kept in step with the CSS blocks. A theme is
   *a property of the board*, shared by everyone on it: any member can change it
   and every other member sees it, live. This reverses the original decision
   (themes used to be personal to a member on a board); `board_members.theme` is
   gone and `boards.theme` replaces it, per `migrations/003_board_theme.sql`.
   `users.default_theme` survives as the theme a board starts in when that user
   creates it.
   **Light and dark is a separate axis, and the opposite kind of setting**: a
   theme is the board's and is shared, light/dark is the viewer's and is device
   -local (`localStorage`, key `conotes-mode`, through `store.*`). No column, no
   event, no request - two people on one board can sit on different grounds.
   It rides the same mechanism as a theme: one attribute on `<html>`,
   `data-mode="light"`, with dark being the *absence* of the attribute so the
   signed-off dark UI cannot move. See `app.js` under "light mode" and the
   light block at the bottom of `app.css`.
8. **Card formatting** — a small markdown subset held as plain text in
   `notes.content`: `**bold**`, `__underline__`, `*italic*` / `_italic_`, and
   `- [ ]` / `- [x]` task lines. Text rather than HTML on purpose: the board is
   shared, so HTML would mean sanitising one member's input before showing it to
   everyone else. It also means the server needed no change at all -
   `Validate::clean()` only strips control characters, so every marker survives
   the round trip. Ticking a box rewrites that one line and saves through the
   ordinary version-checked path, so two people ticking at once get the same
   409 they would get for any other edit.
   **There is no formatting toolbar right now.** It was removed deliberately,
   to be reworked - so the markers above are currently written by hand, and
   nothing in the UI inserts them. What did *not* go with it, and must not be
   removed by accident while reworking:
     - `renderRich()` / `inlineMd()` still render every marker, so cards
       written before the bar existed still read as bold, italic and lists
       rather than showing their markers raw;
     - `TASK_RE` and `toggleTask()` still tick checkboxes from the reading
       view, which is decision 8's whole reason for the read-by-default card
       and has nothing to do with the bar;
     - `Validate::clean()` still passes every marker through untouched, so the
       server needs no change whichever way the rework goes.
   The bar that was removed had toggling buttons (B/I/U/checklist plus a C that
   cleared ticked lines) and the one hard-won piece of knowledge from it is
   worth keeping in mind: `*` and `**` share a character, so any future
   implementation has to read a *run* of marker characters whole - a run of two
   belongs to bold alone, a run of one to italic alone, a run of three to both
   (`***x***`). Reading one `*` out of `**` silently breaks bold; refusing
   every run of three turns bold-italic into five asterisks a side. Both
   directions bit the previous implementation.

9. **Calendar** — every board has one, shared like everything else on a board:
   any member can add to it and everyone sees the same thing. Two kinds of item
   land on it - `calendar_events` (standalone, "site visit Tue 3pm") and notes
   carrying a `due_date`. Events use the same optimistic `version` as notes and
   return the same 409, but the client reloads and says so instead of showing
   the keep-mine/use-theirs sheet: an event is four words, and there is nothing
   worth merging. Events soft-delete and are purged by `cleanup.php`, but there
   is deliberately no trash UI for them. Every event write also appends to
   `calendar_event_edits`, so event changes show up in Activity alongside card
   edits - see the `note_edits` entry above and `app/Activity.php`.
10. **Search** — client-side, over the cards already loaded for the open board.
   The API hands the client every live card up front, so a search endpoint would
   be a second source of truth for something already in memory; it also keeps
   working offline. It composes with the filter chips rather than replacing
   them, and the chip counts describe what search has left. Consequence worth
   knowing: it covers the open board's live cards only - not the trash, not
   other boards. If boards ever get big enough that the client cannot hold them,
   this is the thing that has to move server-side, along with the delta sync.
11. **Photos** — uploaded, re-encoded through GD, stored under
   `public_html/uploads/avatars/` with execution disabled. Never store a file
   that has not been through `Uploads::acceptAvatar()`.
12. **Board names** — an owner can rename their board (`PATCH /boards/:slug`),
   and the slug deliberately does not move with it: members have it in their URL
   bar and in invite links already sent, so a rename changes the label only.
   Owners only, unlike the theme — a theme is a look any member may change, the
   name is how the board announces itself to everyone on it, so it sits with
   reset and delete. It travels like `board.theme` and not like
   `member.updated`; see the events note below.
13. **Desktop** — the app was phone-first and stays phone-first: nothing below
   1024px has moved, and between 880 and 1023px it is still the phone UI inside
   its rounded frame. Past 1024px the *same components* are re-laid-out rather
   than restyled, in one block at the bottom of `app.css`. `#screen` becomes a
   two-column grid - a 272px rail, then the board. The tab bar unpacks into
   that rail: same five buttons, same order, same states, laid out downward with
   every label showing instead of only the open one's, and *New card* pulled to
   the top with `order:-1` because on a desktop it is a command rather than a
   thumb target. The boards list stops being a modal - `renderSideBoards()`
   draws it into the rail and marks the open board - though the modal is still
   there behind the title and is still what creates a board. The card becomes a
   centred dialog: a full screen is right where there is no "beside", and wrong
   when it covers a 1400px window to edit four lines. Everything inside the card
   is untouched (decision 8, and the card model below). Two CSS columns become
   three, and four past 1440px; a one-column board stays a reading column rather
   than one card stretched across the window. No token, no type ramp, no theme
   and no spacing value changed - only where the boxes sit.
14. **Notifications** — Web Push, for exactly one trigger: somebody **flagged**
   a card on a board you are on. Not every edit. The delta poll already shows
   you everything while the tab is open, so a notification is only for the case
   the poll cannot reach - the app closed - and flagging is the one gesture in
   this app that means "look at this". Everything else would be noise.
   The pushes carry **no payload**, and that is what makes it possible without
   Composer: an encrypted payload needs ECDH + HKDF + AES-128-GCM against the
   browser's key, which is a lot of hand-rolled crypto to get subtly wrong. A
   payload-less push is a signed "wake up"; `sw.js` then calls
   `GET /api/push/pending` and finds out what happened. So the only crypto in
   `app/Push.php` is the VAPID JWT - one `openssl_sign()` plus DER wrangling to
   rebuild the key and reshape the signature. The cost is that the browser must
   be online to show the notification, which is fine for a shared board.
   A subscription is **a browser, not a person** - the same user on a phone and
   a laptop is two rows and both buzz - and like light/dark it is per device,
   so turning it on here does not turn it on anywhere else. Requires
   `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` in `.env` (`php tools/make-vapid.php`)
   and `openssl`; with either missing the client hides the setting entirely
   rather than offering something that cannot work.

## Schema

```
users         (id, provider, provider_user_id, email, username, display_name,
               color, avatar_url, avatar_path, default_theme, ...)
sessions      (id, user_id, expires_at, ...)
oauth_states  (state, provider, redirect_to, invite_token, code_verifier, ...)
boards        (id, slug, name, theme, created_by, created_at,
               deleted_at, deleted_by)
calendar_events (id, board_id, title, detail, event_date, start_time, version,
               created_at/by, updated_at/by, deleted_at/by)
calendar_event_edits (id, event_id, board_id, user_id, action, edited_at)
board_members (board_id, user_id, role, joined_at,
               display_name, color, avatar_path)          -- all nullable
board_invites (token, board_id, expires_at, max_uses, uses, revoked_at, ...)
notes         (id, board_id, title, content, pinned, due_date, version,
               created_at/by, updated_at/by, deleted_at/by)
note_edits    (id, note_id, board_id, user_id, action, edited_at)
board_events  (id, board_id, type, note_id, actor_id, created_at)
push_subscriptions (id, user_id, endpoint, p256dh, auth, created_at,
               last_notified_at, failed_at)
```

Three of these carry weight:

- **`board_members`** holds the per-board profile as well as the membership.
  Its three profile columns are NULL for "inherit from `users`". The theme is
  not among them - it lives on `boards` and is shared. Nothing is
  copied down at join time, so editing the master profile updates every board
  that has not overridden it. `app/Identity.php` owns the COALESCE that resolves
  the two, and *every* query that renders a person goes through it — a card's
  creator and its last editor, the activity feed, the member list. Two people in
  one row is what the `prefix` and alias arguments are for: `Notes` joins
  `updated_by` under `u`/`bm` and `created_by` under `cu`/`cbm`, and reads them
  back with `Identity::fromRow($r, 'created_by', 'creator')`. Add a new
  one and it must use `Identity::columns()` and `Identity::joins()` too, or that
  surface will silently show master names while the rest shows board names.

- **`note_edits`** is a card's audit trail, and `contributors` is derived from
  it. The Activity feed is not - it is a merge of this table and
  `calendar_event_edits`, done at read time by `app/Activity.php`; see that
  file, and `calendar_event_edits` below, before assuming Activity is note_edits
  alone. Never store a contributors array on a note again —
  it drifted and grew without bound. Consecutive edits by one person on one card
  inside two minutes coalesce into one row.
- **`board_events`** is the sync spine. Every mutation appends a row; its
  auto-increment id is the cursor clients poll against. This is the row a
  WebSocket would push. Types: `note.created|updated|deleted|restored`,
  `board.reset`, `board.theme`, `board.renamed`,
  `event.created|updated|deleted`, `member.joined`, `member.updated`. The comment
  in `001_init.sql` predates `board.theme`; migrations are forward-only, so it
  was left alone rather than edited after the fact.

## Invariants

- The unit of write is a **note**, never the board. Nothing may reintroduce a
  whole-board write.
- Every mutation appends to both `note_edits` and `board_events`, inside the
  same transaction as the write.
- The server validates everything. Title 120 chars, content 100KB, display name
  28 chars, username 3-32 chars, photos 3MB, request body 128KB. Client caps are
  a convenience, not a control.
- Card content is **plain text**, and the formatting markers in it are text
  too. `renderRich()` escapes first and applies markers second - never the
  other way round, or a card becomes an XSS vector for everyone on the board.
  Nothing may store HTML in `notes.content`.
- Every accent value in `app.css` is a token — `var(--acc*)` or
  `rgba(var(--acc-rgb), a)` / `rgba(var(--on-acc-rgb), a)`. A literal accent
  colour anywhere in the stylesheet is a bug: it will not follow the theme.
- Timestamps are ISO 8601 UTC over the wire, formatted client-side only.
- Errors are `{ error: { code, message } }`. Stack traces are logged, never
  returned. `APP_ENV=dev` relaxes this for local work only.
- No secrets in the repo. `.env` is ignored; `.env.example` is committed.
- Migrations are forward-only and checked in.
- A push is sent **after** the transaction commits, never inside it. A push
  service is somebody else's server; it must not be able to hold a row lock or
  roll back a save. Failures are logged and swallowed - a card that saved but
  did not notify beats a save that 500s because Google was slow.

## Frontend rules

The design is signed off. `app.css` is the prototype stylesheet verbatim, plus
one clearly-marked block of additions that reuse the existing tokens.

**Allowed:**
- Changes behind the API seam in `app.js` (everything network-facing is in the
  `api()` helper and the small group of functions under "the seam").
- New states on existing components.

**Not allowed without asking:**
- A framework or a build step. It is plain files on purpose.
- Changing spacing, type, or layout *on the phone*. Themes may change the accent
  and the ground; nothing else moves. The desktop layout past 1024px is the one
  exception that has been signed off (decision 13), and it is a re-layout of the
  same components, in its own block at the end of `app.css`: it may not reach
  back and change anything the phone uses. A theme is board-wide, so a change here is visible
  to everyone on the board, not just the person who made it. Lime
  (`--acc: #C8F04A`, `--acc-2: #5FE3A1`, `--on-acc: #15190B`) is the default.
  It is no longer pixel-identical to the signed-off prototype and is not meant
  to be: accents became gradients deliberately (decision 7), so every
  accent-filled surface now carries the pair. Everything *else* about the
  prototype still holds - same geometry, same type, same spacing - and that is
  what must not drift. Light mode may flip the ground and the ink and nothing
  else; if a rule there moves a box, it is wrong.
- Changing the card interaction model further. It has moved twice, both times
  deliberately:
  1. the card *reads* by default and edits on tap, because checklists are
     useless if you cannot tick one without entering an editor. A rendered
     layer was added in front of the textarea, not swapped for it.
  2. the card is a **full screen**, not a partial sheet: `top:0`, no radius, no
     grabber, and a back button at the top left where Done used to be a button
     at the top right. (Past 1024px it is a centred dialog instead - see
     decision 13. That moves the box and nothing in it.) Everything that is not writing - flag, date, delete -
     moved behind the three dots at the top right (`.menu`), so the screen is
     the card. What the menu has set is shown as read-only tags under the
     title, because a state you can only see by opening a menu is a state
     people forget is there. The formatting bar and the "last edited by" line
     sit in a fixed `.sheet-foot` at the bottom of the screen rather than in
     the scrolling body: both describe the card as a whole, and the formatting
     bar used to scroll off the screen while you were still typing.
  3. **Back is two steps** - out of the editor to the rendered card, then out
     of the card. Without it the reading view, and with it the tickable
     checkboxes, would only be reachable by closing the card and opening it
     again. This mattered when "Done formatting" was removed from the bar and
     matters more now the bar is gone entirely: nothing else returns you to the
     rendered card. The scrim still closes the whole thing in one tap.
  The slide-to-delete rail is gone with it. Delete is a menu item and asks once
  in a modal - one tap needs a confirmation where a deliberate drag did not,
  and the card is restorable from the trash for 30 days either way.
  `#e-title`, `#e-content`, the formatting bar and the conflict block are all
  untouched.

## Testing

There is no test suite. There is a mock harness, and it is the only thing
standing between a frontend change and production — use it.

`tools/preview/` inlines the real `app.css` and `app.js` into one file and stubs
the API in memory, so the whole frontend runs from disk with no PHP, no MySQL
and no OAuth:

```
sh tools/preview/build.sh
```

```
powershell -ExecutionPolicy Bypass -File tools\preview\build.ps1
```

Both produce the same `tools/preview/co-notes-preview.html`; use whichever suits
the machine. It is generated, and git-ignored — rebuild it after every change to
`app.js`, `app.css`, `index.html` or the mock, or you will be looking at a stale
copy and wondering why your edit did nothing.

Open it in a browser and drive the paths that are hard to reach by clicking:

```js
__mock.forceConflict()   // next save returns 409, so the conflict UI appears
__mock.remoteEdit()      // someone else edits a card; arrives via delta sync
__mock.disguiseSam()     // someone else takes a board-specific name and colour
__mock.remoteTheme()     // someone else restyles the board; everyone repaints
__mock.remoteRename()    // the owner renames the board; every client relabels
__mock.remoteEvent()     // someone else drops an event on the board calendar
__mock.goOffline()       // network failures, the offline dot, retry backoff
__mock.goOnline()
__mock.signOut()         // the sign-in screen
```

Photo uploads work there too: the chosen file becomes a data: URL, so picking
one really does change the avatars on screen.

**Keep `mock-api.js` in step with the real API.** If you change a response shape
in PHP and not in the mock, the harness will keep passing while production
breaks — the mock is the contract as well as the fixture.

For a real deployment, `php tools/doctor.php` checks PHP version, extensions
(including `gd`, needed for photos), the database, every table, the
migration-002 through -004 columns, the upload directory, and OAuth config.

### If no PHP is available

That was the case throughout the build, and it is workable: the frontend is
fully exercisable through the harness. What it cannot do is prove the PHP
correct. If you have PHP on the new machine, the single highest-value thing to
run before anything else is:

```
find app tools public_html/api -name '*.php' -exec php -l {} \;
```

Nothing in this repo has ever had a real parser look at it.

## Things that will bite you

Hard-won during the build; not obvious from reading the code.

- **MySQL, not Postgres.** `INTERVAL` takes an inlined integer, never a bound
  parameter — several hosts choke on the latter. Every such value comes from
  config or a constant, and is clamped before interpolation. Do not "tidy" these
  into placeholders.
- **`ONLY_FULL_GROUP_BY`.** `Notes::contributorsFor()` groups by the identity
  column *aliases*. MySQL allows that; it is deliberate, and dropping the
  aliases from the `GROUP BY` will break on a default MySQL 8 install.
- **`rowCount()` is the version check.** `Notes::update()` treats zero affected
  rows as "someone else saved first". It is safe because `version = version + 1`
  guarantees the row changes on success — so it works whether the driver reports
  matched or changed rows. Keep that increment unconditional.
- **`member.updated` forces a full resync on every client.** A profile change
  restyles that person's name and photo on every card they have touched, so a
  delta cannot express it. Fine at this scale; if boards ever get large, narrow
  it rather than leaving it.
- **The Activity cursor is a small JSON object, not an integer.** Every other
  cursor in this app (`Events::cursor`, delta sync) is one auto-increment id
  from one table. Activity draws from two - `note_edits` and
  `calendar_event_edits` - each with its own independent sequence, so a single
  integer cannot resume both. `Activity::forBoard()` hands back `{n, e}` and the
  client only ever round-trips it as an opaque string; nothing on the client
  reads its shape. Do not "simplify" this back to one integer without solving
  that problem again.
- **Calendar dates are wall clock, not UTC.** `event_date` is a DATE and
  `start_time` a TIME, neither carrying a zone, and they travel as
  `'YYYY-MM-DD'` / `'HH:MM'` strings. This is the one place in the app that is
  not UTC-over-the-wire and it is deliberate: a shared board calendar wants
  "14 March, 3pm" to read the same for everyone, and converting per viewer would
  have two people on one board disagreeing about which day something is on. Do
  not "fix" this by turning them into timestamps. The client never builds a Date
  from them either, except to do arithmetic on local parts.
- **`board.theme` and `board.renamed` deliberately do not.** They look like
  siblings of `member.updated` and are not: a theme is one attribute on `<html>`
  and a name is one string in the header, and neither touches note data - so
  `changes()` returns the new value in a `theme` / `name` field and the client
  repaints or relabels in place. Do not "make it consistent" by turning either
  into a resync: the poll is 1s, and a theme change would then reload the whole
  board. `syncBoardsEntry()` on the client is what keeps the "Your boards" row
  in step with a rename without a second request.
- **A deleted board has to be excluded from every path, not just the list.**
  `Boards::forUser()` is the obvious one, but `requireMember()`,
  `requireMemberForNote()`, `peekInvite()` and
  `Calendar::requireMemberForEvent()` all need `b.deleted_at IS NULL` too -
  otherwise a stale link, an old invite or a note id still reaches a board its
  owner deleted. It returns 404 rather than 403, so a deleted board is
  indistinguishable from one that never existed.
- **Deploying the code before running its migration takes the site down.**
  Every board query names the columns the migrations add, so PHP that is one
  migration ahead of the database 503s on every request until the migration
  runs. Import the SQL first, or accept a window of downtime.
- **Purging a note cascades its `note_edits`.** Cards hard-deleted after the
  30-day window take their activity history with them. Acceptable, and it avoids
  orphan rows — but it means Activity is not a permanent record.
- **`min-height:30vh` on the card's text is a floor, not a height.** It was
  enough when the card was a sheet that sized itself to its content; on the
  full-screen card it left a third of the page dead under a short note, and a
  long one scrolled inside a 30vh textarea while the page around it stayed
  still - two scrollbars for one piece of text. `.sheet-body` is a flex column
  and the text grows into what is left: `.rich` is `flex:1 0 auto` so it is
  always at least the full page (which is also what makes the empty area under
  short text a tap target for the editor), `#e-content` is `flex:1 1 auto` so
  the editor fills the screen and scrolls inside itself. Do not put a fixed
  height back on either.
- **Light mode's frost is three things, not one.** The glass everywhere in this
  app is `backdrop-filter` over a *thin* surface - 5% white in dark. Flip the
  ground and keep the surface near-opaque and the blur has nothing behind it to
  show, so every panel goes flat white. Light mode therefore keeps the surface
  thin (38%), opens up the accent glow in `#bg` (dark's alpha washes out to
  nothing on a light ground), and raises `saturate()` on the blur to pull that
  colour back. All three are load-bearing; the inset white top edge is the lit
  rim. Do not "clean up" the light surfaces by making them solid.
- **`.side` is `display:contents` on a phone, and that is load-bearing.**
  The desktop rail wraps the tab bar in an `<aside class="side">` in
  `index.html`. On a phone that element generates no box at all, so `.head`,
  `.tabbar` and the board are laid out as direct children of `#screen` exactly
  as they were before the wrapper existed - the tab bar keeps floating at the
  bottom, absolutely positioned against `#screen`. Give `.side` any other
  `display` below 1024px and the phone layout collapses.
- **The desktop block has to out-specify the light-mode block.** Light mode
  restates the surfaces that carry literal dark values (`.tabbar`, `.sheet`,
  `.modal-card`...) at `:root[data-mode="light"] .x`. The desktop block comes
  later in the file but ties on specificity, so the rules that undo those
  surfaces are written `#screen .side .tabbar` / `#screen .sheet` to win
  outright rather than by source order. Dropping the `#screen` prefix leaves a
  desktop light-mode card wearing dark mode's shadow.
- **`localStorage` and `history.replaceState` are both wrapped.** Both throw
  outright in private windows and embedded contexts, and an unguarded call took
  the whole app down. Use `store.*` and `setUrl()`, never the raw APIs.
- **Saves in flight.** `flushSave()` compares an `editSeq` counter before and
  after the request; if it moved, the user typed during the save, so the server's
  response must not replace their newer text. This is subtle and easy to undo by
  accident — there is a comment on it, leave it there.
- **The service worker must never cache `/api/`.** `public_html/sw.js` caches
  the shell so the app opens without a network, and that is all it may do. A
  card served from disk would be a lie about what the rest of the board can
  see, and would sit behind the version checks that stop two people clobbering
  each other; `/uploads/` is excluded for the same reason. Navigations are
  network-first with the cached `index.html` as the fallback, so `/b/<slug>`
  still opens offline. `CACHE` is the whole of cache invalidation - bump it
  when the shell changes, and `activate` drops every older cache. Note the
  worker's own scope is the site root, so `sw.js` has to stay at the web root.
  Icons are generated, not hand-drawn: `php tools/make-icons.php`.
- **A due date flags its card once, not continuously.** `Notes::flagDue()`
  flags anything with `due_date <= CURDATE()` and marks it with a userless
  `note_edits` row (`action = 'due'`), which is both the "already fired" marker
  and the "came due" line in Activity. Without that marker the flag would be
  *derived* state: unflag an overdue card and the next poll would put it
  straight back, and you could never turn it off. `clearDueMarkIfMoved()`
  drops the marker when the date actually changes - the test is that the date
  moved, not that `dueDate` was in the body, because the client sends the whole
  card on every save. It runs before the UPDATE, since it joins the row's
  current date. The date is the **server's** (`CURDATE()`), like everything
  else wall-clock here, so two members in different zones cannot disagree.
  Called on a full board load and, behind a 1-in-30 gate, on the poll - the
  same opportunistic idiom as `Auth::sweep()`.
- **The board is uniform cards now, in one column or two.** The accent focus
  card and the thin `.row` list under it are gone: every card renders the same
  `.note-card` - title, the *rendered* body (bold, italic, tick boxes), a flag
  mark, a due pill - and only the column count changes. Two columns is CSS
  `column-count`, not a measured masonry, so cards read down one column and
  then the other rather than left-right; that is the price of not shipping a
  layout engine. The choice is the viewer's and device-local, exactly like
  light and dark: `conotes-cards-layout` through `store.*`, no column, no event.
  The focus card's styles (`.focus`, `.peek`, `.open-btn`, `.pencil`,
  `.time-pill`, `.snip`) are still in `app.css` and are now unused.
- **The card footer names two people from one line.** `Created by X · edited by
  Y 2h ago`, collapsing to `Created by X · edited 2h ago` when they are the same
  person, which most cards are. `createdBy` is null on the trash query - it
  selects `deleted_by` as its person and never joins `created_by` - so the
  client falls back to the last editor there rather than showing nothing.
- **Nothing renders `note.contributors` any more.** The stack of avatars it fed
  - on the focus card and in the card screen's head - was removed; a card now
  shows one person, the last editor, on its row and in the footer line. The
  server still derives the field (`Notes::contributorsFor()`, capped at 10 by
  `Notes::MAX_CONTRIBUTORS`) and still ships it on every note, so putting the
  stack back is a client-only change - but if it stays unused, that is one
  join per board load and per delta earning nothing.
- **`Push::pending()` moves a watermark, so reading it twice is not the same
  as reading it once.** `last_notified_at` on the subscription is what stops
  one flag buzzing twice when a push is redelivered, and a fresh subscription
  is created already caught up so enabling notifications does not replay an
  hour of other people's flags. The client also tags each notification with the
  card id, so a duplicate replaces rather than stacks - belt and braces, since
  the two mechanisms fail in different ways.
- **`userVisibleOnly` is a contract, not a flag.** The browser expects a
  notification for *every* push it delivers, and shows its own "site updated in
  the background" one if the worker shows nothing. That is why `sw.js` falls
  back to a generic notification when `/api/push/pending` comes back empty or
  the fetch fails - deleting that branch does not make it quieter, it makes the
  browser talk instead.
- **Web Push on iOS needs the app on the Home Screen**, and the check for that
  runs *before* the API sniff, not after. A Safari tab is given no Push API at
  all, so sniffing first reports "unsupported" and hides the row entirely - on
  exactly the devices that need telling. `needs-install` shows the three Add to
  Home Screen steps instead. Reorder those two checks and iPhone users see
  nothing and assume the feature does not exist.
- **Apple rejects a VAPID token whose `sub` claim is not a mailto:/https: URL.**
  Google accepts almost anything there, so a vague `VAPID_SUBJECT` breaks
  iPhones *only*, silently, while Android keeps working - about the worst
  failure shape there is. `Push::subject()` therefore validates it and falls
  back to the site's own https URL rather than to something cosmetic, and
  `doctor.php` says so when it is not set explicitly.
- **Session and OAuth-state cleanup is opportunistic** — roughly 1 request in
  200 runs `Auth::sweep()`. The real retention work is `tools/cleanup.php` on a
  daily cron; without it nothing breaks, but deleted cards are never purged.

## Not built, deliberately

Worth knowing before proposing them as bugs:

- **No password auth, no email/magic link.** OAuth only.
- **No per-card permissions.** The board is the unit of sharing, by design.
- **No WebSocket.** Shared hosting cannot hold one open; see Stack.

## Definition of done

- Two browsers on the same board see each other's edits within ~1 second.
- Two people editing *different* cards at the same time never lose data.
- Two people editing the *same* card get a clear conflict, not a silent
  overwrite — including text typed while a save is in flight.
- A page refresh restores identity without signing in again.
- The server rejects oversized, malformed, and unauthenticated requests.
- A board profile overrides the master one everywhere that person appears, and
  nowhere else; clearing it falls straight back to the master profile.
- Switching boards repaints to that board's theme and identity.
- One person changing the theme repaints it for everyone else on that board
  within ~1 second, without any client reloading the board.
- An event one member adds appears on every other member's calendar within
  ~1 second, and a card given a date shows up there too.
- A card with formatting reads as formatted, and a checklist can be ticked
  without opening the editor.
- Searching narrows the board as you type, matches text inside formatting
  markers, and composes with the filter chips instead of overriding them.
- Switching Appearance to Light flips the ground for the person who did it and
  for nobody else on the board, and survives a reload.
- An owner renaming a board relabels it for every other member within ~1 second,
  without anyone reloading, and every link and invite to it still works.
- On the Lime theme, in dark, the UI matches the prototype's geometry, type and
  spacing exactly; the accent surfaces carry the theme's gradient pair.
- At 1023px the app is that phone UI, unchanged, inside its frame. At 1024px it
  is a rail and a board: every tab reachable in one click, the boards list on
  screen without opening anything, the cards in three columns and four past
  1440px, and a card opening as a dialog over the board rather than covering
  the window.
