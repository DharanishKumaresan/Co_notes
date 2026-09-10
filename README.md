# Co notes

A shared notes board. Everyone on a board sees the same cards and can read,
edit, flag, and delete any of them.

The frontend is the signed-off prototype, unchanged in appearance. Behind it is
a PHP 8 / MySQL API with Google and GitHub sign-in, per-note optimistic
concurrency, membership-gated boards, and soft deletes with a 30-day restore
window.

People have one **master profile** — handle, email, display name, photo — and,
on each board, an optional **board profile** that can override the name and the
photo just for that board. The theme is not personal: it belongs to the board
and everyone on it sees the same one.

**[docs/architecture.html](docs/architecture.html)** is an illustrated walk
through how the whole thing fits together — the request lifecycle, the
`board_events` sync spine that replaces a WebSocket, the write transaction and
its 409, and why nearly every decision here is downstream of the host. Open it
in a browser; it is a single self-contained file with no assets.

---

## Stack, and why

Deployment target is **Hostinger shared hosting with phpMyAdmin**, which decides
most of this:

| Piece | Choice | Reason |
|---|---|---|
| Runtime | PHP 8.1+, no Composer | What the host runs. Zero dependencies means nothing to install or keep updated. |
| Database | MySQL / MariaDB | What phpMyAdmin manages. |
| Live updates | Cursor-based delta polling, 1s | Shared hosting has no long-lived process, so a WebSocket is not available. See below. |
| Auth | Google + GitHub OAuth 2.0 | Real user ids, so two people called Sam stay distinct. |
| Concurrency | Per-note `version`, 409 on mismatch | A notes board, not a live document editor. |

### On the missing WebSocket

The original plan called for `WS /api/boards/:slug/live`. Shared hosting cannot
hold a socket open, so the same events arrive over
`GET /api/boards/:slug/changes?since=<cursor>` instead. Every mutation appends a
row to `board_events`; the client polls with its cursor and gets back only what
changed. An idle response is a few dozen bytes, which is what makes a 1s poll
affordable. Two browsers see each other's edits in about a second.

The transport is isolated in one function (`tick()` in `app.js`). Moving to a
host that supports WebSocket means replacing that function and nothing else.

---

## Layout

```
public_html/            <- upload the contents of this to your web root
  index.html            the app shell
  assets/app.css        the prototype stylesheet, plus a few additions
  assets/app.js         the client: API calls, sync, conflict handling
  api/index.php         front controller; everything under /api/ lands here
  uploads/avatars/      profile photos, with PHP execution switched off
  .htaccess             routing, caching, security headers
app/                    <- upload ALONGSIDE public_html, not inside it
  bootstrap.php         config loading and autoloading
  Router.php            the entire API surface
  Auth.php OAuth.php    sessions and sign-in
  Boards.php Notes.php  the domain
  Identity.php          how a person appears on a given board
  Uploads.php           profile photo validation and storage
  Themes.php            the theme catalogue
  Db.php Http.php Validate.php ApiError.php Env.php Ids.php Events.php Log.php
migrations/              001_init.sql, 002_profiles.sql
tools/
  migrate.php           forward-only migration runner (CLI)
  cleanup.php           retention job (cron)
  doctor.php            deployment self-check - run this first
  preview/              offline mock harness (build.sh / build.ps1); not deployed
.env.example            copy to .env and fill in
```

`co-notes-lime.html` is the original single-file prototype, kept for reference.
It is not served.

---

## Deploying to Hostinger

### 1. Create the database

hPanel → Databases → MySQL Databases. Create a database and a user, and note
the name, user, and password — Hostinger prefixes both with your account id.

### 2. Upload

Using File Manager or SFTP, for the domain `yourdomain.com`:

```
/home/<user>/domains/yourdomain.com/
  public_html/     <- contents of public_html/
  app/             <- the app/ directory
  migrations/
  tools/
  .env
```

`app/`, `.env`, `tools/` and `migrations/` must sit **outside** `public_html`.
The app looks one level above the web root for them. If your host forces
everything into the web root it still works — `public_html/.htaccess` rewrites
those paths away, and each of `app/`, `tools/` and `migrations/` carries its own
deny-all `.htaccess` for the case where `mod_rewrite` is off — but outside is
safer. Note that `.htaccess` files are easy to miss when uploading: most FTP
clients hide dotfiles by default.

### 3. Configure

Copy `.env.example` to `.env` and fill in `APP_BASE_URL` and the `DB_*` values.
`APP_BASE_URL` must match the address people actually use, exactly — including
`https://` and whether you use `www.` — because it is checked against the
`Origin` header on writes.

### 4. Create the schema

Easiest path with no shell: phpMyAdmin → your database → Import → choose
`migrations/001_init.sql` → Go, then repeat for every file after it, in
filename order, through `migrations/008_reminders.sql`. They are
forward-only, so importing one twice will error rather than corrupt anything,
and each alters what the one before it created, so none can be run instead of
another.

With SSH:

```bash
php tools/migrate.php
```

### 5. Set up sign-in

**Google** — console.cloud.google.com → APIs & Services → Credentials → Create
OAuth client ID → Web application. Authorised redirect URI:

```
https://yourdomain.com/api/auth/google/callback
```

**GitHub** — github.com/settings/developers → New OAuth App. Authorization
callback URL:

```
https://yourdomain.com/api/auth/github/callback
```

Put the client ids and secrets in `.env`. Either provider alone is enough; the
sign-in screen only shows the ones you configure.

### 6. Notifications (optional)

Members get a notification when:

- somebody **flags** a card, or **adds an event** to the board calendar -
  everyone but the person who did it;
- a card **falls due** or an event is **coming up** within two days -
  everyone on the board, including whoever set the date.

Nothing else, because everything else the app already shows live.

Generate the keypair that identifies this server to the push services:

```bash
php tools/make-vapid.php
```

Paste the three lines it prints into `.env`. It needs the `openssl` extension.
Leave the keys blank to switch the feature off, and the client hides the
setting rather than offering something that cannot work.

Two things worth knowing before you promise anyone notifications:

- **iPhone and iPad only get them if the site is added to the Home Screen**
  and opened from there. A Safari tab cannot receive a push. Android has no
  such restriction - browser tab or installed, both work. The app detects this:
  an iPhone in Safari sees Add to Home Screen instructions in the Notifications
  setting rather than a switch that would do nothing.
- **Set `VAPID_SUBJECT` to a real `mailto:` or `https:` URL.** Apple refuses a
  push whose token does not carry one, so leaving it vague breaks iPhones only,
  while Android keeps working. `doctor.php` flags it.
- **The pair is this deployment's identity.** Regenerating it invalidates every
  existing subscription, because the public key is baked into each one at
  subscribe time. Generate once; give a staging site its own.

### 7. Check the deployment

```bash
php tools/doctor.php
```

It verifies the PHP version and extensions, the database connection, that every
table exists, and that OAuth is configured — and prints the exact redirect URIs
to paste into Google and GitHub. No shell? Copy `doctor.php` into `public_html`,
open it in a browser, then delete it.

### 8. Schedule the reminder job

Only needed if you turned notifications on. hPanel → Advanced → Cron Jobs,
hourly is plenty:

```
/usr/bin/php /home/<user>/domains/yourdomain.com/tools/remind.php
```

Safe to run as often as you like - a row is stamped in the same query that
picks it up, so nothing is ever notified about twice. Without it the other
notifications still work; you just do not get the "coming up" ones.

### 9. Schedule the retention job

hPanel → Advanced → Cron Jobs, daily:

```
/usr/bin/php /home/<user>/domains/yourdomain.com/tools/cleanup.php
```

It purges cards deleted more than `TRASH_RETENTION_DAYS` ago, prunes the sync
event log, and clears expired sessions. Without it nothing breaks — deleted
cards simply stay in the database forever.

---

## Using it

The first person to sign in gets a board called "My board" created for them.
From the header, tap the board name to switch boards or create another. In the
**You** tab, the owner can mint an invite link; anyone who opens it joins that
board. Membership is the permission — a board you are not a member of returns
404, so slugs cannot be probed.

Deleted cards go to **Recently deleted** in the Profile tab and can be restored
for 30 days. Board reset does the same thing to every card at once, so it is
recoverable rather than final.

An owner can also **delete the whole board**, at the bottom of the Profile tab.
That one asks you to type the board's name, because it removes the board for
every member along with its cards, calendar and history - and unlike everything
else here, there is no way to undo it from inside the app. It is still a soft
delete underneath: the row survives `TRASH_RETENTION_DAYS` before the cleanup
job purges it for real, so a mistake is recoverable from the database if you
catch it in time.

### The board, in one column or two

Every card on the **Cards** tab renders the same way — its title, its body as
you actually wrote it (bold, italic, ticked and unticked boxes), a flag mark if
it is flagged, and a date pill if it has one. A card whose text does not fit is
clipped with a fade, so it reads as "there is more" rather than as a line sliced
in half; tapping anywhere on it opens the card.

The button beside **Sync** in the header switches between one column and two,
and shows the layout it will switch you *to*. Two columns is CSS columns rather
than a measured masonry: the browser balances the pair, which is what makes it
look packed, and the trade is that cards read down one column and then the other
instead of left to right. Like light and dark, the choice is yours and stays on
this device — it changes nothing anyone else sees, so nothing about it reaches
the server. The button hides itself on the tabs that have no cards to lay out.

The tick boxes on a card are inert on the board. Ticking one belongs inside the
card, where the version-checked save and its 409 live.

**A card flags itself when its due date arrives.** The date is the server's, not
the viewer's — dates here are wall-clock and a board is shared, so two members
in different time zones must not disagree about whether a card is flagged. It
fires **once**: unflag an overdue card afterwards and it stays unflagged, since
otherwise the flag would be derived state you could never turn off. Give the
card a new date and it can come due again. Each firing leaves a line in
Activity — *"Supplier shortlist came due"*, with no name against it, because
nobody did it.

### Profiles

The **Profile** tab holds both profiles, and the distinction is the point:

- **Your profile** is who you are across Co notes: display name, `@username`,
  email (from your provider, not editable), photo, and a default theme. It is
  what every board shows unless that board says otherwise.
- **On <board>** is who you are on the board you have open. Set a name or photo
  here and only that board sees it; leave them empty and they follow your
  profile, live — change your master photo and every board that has not
  overridden it updates too.

The tab opens with an identity **hero** — your photo under the accent bloom the
focus card sits under, your name and `@handle`, and three counts that describe
your corner of the app: boards you are on, cards on this board, people on it.
All three are numbers the client already holds; nothing is fetched for them.

Under it is one **settings list** — a single panel whose rows are divided by
hairlines, each with a glyph, a label, and what it currently says on the right:
**Your profile**, **Board settings**, **Appearance**, **On this board**,
**Recently deleted**. Every row folds and all are collapsed by default, so the
tab answers most questions without being opened at all. The Appearance glyph
follows the mode it controls — a moon in dark, a sun in light — and an open
row's glyph picks up the accent. Only the trash has a request behind it, and it
waits until you open it.

Below the list sit the destructive actions, kept in their own panel and out of
the folds, with **Sign out** as a quiet line under everything rather than a
fourth red button.

Everything that belongs to the *board* rather than to you — your profile on it,
its theme, its name — is inside **Board settings**. The theme there is not part
of either profile: it sets the board's theme, for everyone. See below.

**Switching boards is not on this tab.** It is the board name in the header,
which is where you already look to see which board you are on; that opens the
switcher, and creating a board lives there too.

**Appearance** deliberately stays outside that panel. Light and dark is yours
and device-local, not the board's, and filing it under board settings would say
the opposite.

The `@username` exists so two people with the same display name are still
distinguishable. It is unique across the install.

### Themes

Six accent themes: Lime (the original), Amber, Teal, Sky, Violet, Rose. Each one
swaps the accent set and the ground tint; layout, type and spacing never move.

**An accent is a pair, not a colour.** Every theme carries a second stop —
lime→mint, amber→orange, teal→blue, sky→periwinkle, violet→pink, rose→peach —
and the gradient between them fills the surfaces big enough to show it: primary
buttons, the active tab, the ambient wash behind the whole app, the bloom behind
your photo on the profile. Small marks stay the flat primary, because a gradient
across an 8px dot is just a muddier flat colour. The picker's discs show both
stops, so you are choosing the pair rather than half of it.

Both stops of a pair have to be light enough for `--on-acc` — the dark ink that
sits on accent fills — to read across the whole sweep. That is the one
constraint on inventing a new pair.

A theme belongs to **the board**. Anyone on a board can change it, and everyone
on that board sees the change — within about a second, over the same delta feed
that carries card edits. Each board keeps its own, so switching boards repaints.

The **default theme** on your master profile is not an override any more. It is
the theme a board starts in when *you* create it; after that the board owns it.

The change arrives as a `board.theme` event and is applied without a resync: a
theme is one attribute on `<html>` and moves no note data, so the delta carries
the new value and nothing else. (Contrast `member.updated`, which does force a
full reload — see CLAUDE.md.)

Adding a theme is one block of tokens at the bottom of `app.css` (`--acc` and
`--acc-2` with their `-rgb` forms, plus the ground), one entry in
`Themes::ALL`, one swatch *pair* in `THEME_SWATCH` in `app.js`, and — for light
mode — one `--acc-ink` line.

### Installing it

Co notes is a PWA: open it in a browser on the phone and use *Add to home screen*
and it launches standalone, with no address bar, from its own icon.

- `public_html/manifest.webmanifest` — name, icon set, `display: standalone`.
- `public_html/icons/` — generated, not hand-drawn. `php tools/make-icons.php`
  redraws them from the Lime pair with GD; run it only if the mark changes.
  The output is committed because a shared host has no build step.
- `public_html/sw.js` — caches the shell so the app opens with no network. It
  **never** touches `/api/` or `/uploads/`: a card served from disk would be a
  lie about what the rest of the board can see. Offline you get the shell, the
  offline dot already in the header, and the existing retry path.
- `<meta name="theme-color">` is kept in step with the open board's ground, so
  the system chrome is lime on one board and rose on another, and follows
  light mode.

Two deployment notes. Service workers need **HTTPS** (or localhost) — on plain
HTTP the app still works, it just is not installable. And after a deploy that
changes `app.css` or `app.js`, bump `CACHE` at the top of `sw.js`; that constant
is the entire cache invalidation story.

### Light and dark

The **Appearance** control on the profile tab switches the whole app between the
matte dark ground it has always had and a light one. It is deliberately *not* a
theme: a theme is the board's and is shared, this is yours and stays on the
device you set it on, remembered in `localStorage`. Two people on one board can
read the same lime cards on different grounds, and nobody is told about it —
there is no column, no event and no request behind it.

It rides on the same mechanism a theme does: one attribute on `<html>`,
`data-mode="light"`. Dark is the *absence* of that attribute, so the default
rendering is byte for byte what it was. The overrides live in one block at the
bottom of `app.css`, which redefines the ground tokens and restates the handful
of prototype rules that carry literal dark values.

The frosted glass needs three things together, and dropping any one of them
turns the app into flat white panels — which is what a first pass at this looked
like. The surface has to stay thin (dark mode's is 5% white; light's is 38%), so
there is something behind it to see; the ground has to have an accent glow worth
blurring, which at dark mode's alpha washes out to nothing on a light ground and
so is opened up; and the blur needs a much higher `saturate()` to pull that
colour back out. The inset top highlight is what reads as the lit edge of a pane. Accent-as-text needs a darker
value of each accent to stay legible on a light ground — that is `--acc-ink`,
one line per theme; `--acc-deep` is left alone, because it is a *surface* colour
on `.peek` and `.acc-btn:hover`. A new theme therefore wants one `--acc-ink`
line alongside its token block.

### Renaming a board

Owners can rename the board they own, from **Name** inside Board settings on
the profile tab.
The new name reaches everyone else on the board in about a second, through the
same delta feed as a theme change and for the same reason: a name is one board
attribute with no note data behind it, so `changes()` returns the new value in a
`name` field and nobody reloads anything.

The **slug does not move**. It is the board's address — it is in members' URL
bars and in invite links already sent — so a rename changes the label and
nothing else, and every link that worked before still works.

Renaming is owner-only, unlike the theme. A theme is a look and any member may
change it; the name is how the board announces itself to everyone on it, so it
sits with reset and delete. The server enforces that regardless of who is asking.

### Formatting and checklists

A card opens as formatted text and switches to the editor when you tap it. The
formatting bar above the editor has **B**, *I*, U and a checklist button; each
one writes a marker into the text rather than styling a hidden document:

```
**bold**        __underline__        *italic*  or  _italic_
- [ ] a task            - [x] a finished task
```

Checkboxes are tappable while reading, so ticking something off does not mean
opening the editor. A tick rewrites just that one line and saves through the
same version-checked path as any other edit — including the same 409 if someone
beat you to it.

It is stored as plain text, not HTML. The board is shared, so HTML would mean
sanitising one person's card before showing it to everyone else; markers in
text avoid that entirely. `renderRich()` in `app.js` escapes the content first
and applies the markers second — that order is the whole safety argument, so do
not reverse it. The server stores content exactly as typed and needed no change
to support any of this.

### Searching

The search box above the filter chips narrows the open board as you type. It
matches card titles and card text, ignores the formatting markers (searching
`640mm` finds a card containing `**640mm**`), and is case-insensitive. Escape
or the x clears it.

It runs entirely in the browser, over the cards already loaded for the board -
no request is made, so it also works offline. It **composes** with the filter
chips rather than replacing them: Flagged plus a search shows flagged cards
matching that search, and the chip counts describe what the search has left.

What it does not cover: the trash, and your other boards. It is the open
board's live cards only.

### The calendar

Every board has one, and it is shared exactly like the cards are: any member can
add to it, and everyone on the board sees the same thing within about a second.

Two kinds of item land on it:

- **Events** — standalone entries with a title, a date, an optional time and a
  line of detail. "Site visit, Tuesday 3pm" needs no card behind it. Leave the
  time empty for an all-day entry.
- **Cards with a date** — open a card and tap the date pill under the title. It
  then appears on the calendar alongside the events, and tapping it there opens
  the card.

Dates and times are **wall clock, not UTC** — the one place in the app that is
not. `event_date` is a DATE and `start_time` a TIME, neither carrying a zone, so
"14 March, 3pm" reads identically for every member wherever they are. Converting
per viewer would mean two people on one board disagreeing about which day
something falls on, which is worse than the alternative for a shared calendar.

Events use the same optimistic `version` as cards, so two people editing one at
once get a 409 rather than a silent overwrite — but the client reloads and says
so rather than offering the keep-mine/use-theirs choice a card gets. An event is
four words; there is nothing worth merging. Deleting an event is a soft delete
purged by the cleanup job, but unlike cards there is no restore UI for them.

Changes ride the same delta feed as everything else: the poll returns a
`calendar` flag rather than the events themselves, and the client re-reads the
month it is showing. A board with years of history therefore costs the same few
bytes per poll as an empty one.

Every event write also lands in the **Activity** tab, interleaved with card
edits by time - "Sam added the event Site visit" next to "Rina edited Supplier
shortlist". That merge happens server-side in `app/Activity.php`, which reads
from both `note_edits` and a parallel `calendar_event_edits` table, since the
two need independent audit trails but one combined feed.

### Photos

Uploads land in `public_html/uploads/avatars/` and are never trusted:
`getimagesize()` must recognise them, they are re-encoded through GD (which
drops EXIF and anything else that is not pixels), square-cropped to 512px, and
written under a generated name with a fixed extension. The directory has PHP
execution switched off and serves nothing but images.

Requires the **GD extension** — `tools/doctor.php` checks for it. Without GD the
app refuses uploads rather than storing an unexamined file.

---

## API

All responses are JSON. Errors are `{ "error": { "code", "message" } }`.

```
GET    /api/session                     master profile, providers, theme list
PATCH  /api/session                     displayName / username / defaultTheme
POST   /api/session/photo               upload a master photo   (multipart)
DELETE /api/session/photo               drop it, fall back to the provider one
DELETE /api/session                     sign out

GET    /api/auth/providers              which providers are configured
GET    /api/auth/:provider              start sign-in  (?next=, ?invite=)
GET    /api/auth/:provider/callback     finish sign-in

GET    /api/boards                      boards I belong to
POST   /api/boards                      create a board
GET    /api/boards/:slug                board + my profile + notes + members + cursor
PATCH  /api/boards/:slug                rename the board  (owner only; slug unchanged)
GET    /api/boards/:slug/profile        my profile on this board
PATCH  /api/boards/:slug/profile        displayName  (null = inherit)
PATCH  /api/boards/:slug/theme          the board's theme, shared  (any member)
POST   /api/boards/:slug/profile/photo  board-only photo     (multipart)
DELETE /api/boards/:slug/profile/photo  go back to my master photo
POST   /api/boards/:slug/notes          create a card
GET    /api/boards/:slug/calendar?from=&to=   events + cards due, in a range
POST   /api/boards/:slug/calendar       add an event   (any member)
PATCH  /api/calendar/:id                edit an event  (needs version)
DELETE /api/calendar/:id                delete an event
GET    /api/boards/:slug/changes?since= delta sync  (stands in for the WebSocket)
GET    /api/boards/:slug/activity       cards + calendar, merged  (?before=, ?limit=)
GET    /api/boards/:slug/trash          deleted cards still restorable
GET    /api/boards/:slug/members        people on the board
POST   /api/boards/:slug/invites        mint an invite link  (owner only)
POST   /api/boards/:slug/leave          leave the board
DELETE /api/boards/:slug                delete the board  (owner only)
POST   /api/boards/:slug/reset          soft-delete every card  (owner only)

GET    /api/invites/:token              what board an invite leads to
POST   /api/invites/:token/accept       redeem it

GET    /api/notes/:id                   one card
PATCH  /api/notes/:id                   update; requires `version`
DELETE /api/notes/:id                   soft delete
POST   /api/notes/:id/restore           undelete

GET    /api/health                      liveness
```

### Concurrency

`PATCH /api/notes/:id` requires the client's `version`. If the server has moved
on, it answers **409** with the current server state under `current`, and the
client shows a conflict with *Keep mine* / *Use theirs*. Stale writes are never
silently accepted.

### Limits, enforced server-side

Title 120 characters, content 100KB, display name 28 characters, username 3–32
characters, photos 3MB, request body 128KB. The client caps the same values, but
the server does not trust it.

---

## Working on it locally

There is no build step. `public_html/` is the deployable artifact as-is.

Without PHP and MySQL installed you can still run the whole frontend against a
mock API. On macOS or Linux:

```
sh tools/preview/build.sh
```

On Windows:

```
powershell -ExecutionPolicy Bypass -File tools\preview\build.ps1
```

Both write the same `tools/preview/co-notes-preview.html` — open it in a browser.
It inlines the real `app.css` and `app.js` and stubs the API in memory, so
rebuild it after changing either. From the console:

```js
__mock.forceConflict()   // next save returns 409
__mock.remoteEdit()      // someone else edits a card; arrives via sync
__mock.disguiseSam()     // someone else takes a board-specific name
__mock.remoteTheme()     // someone else restyles the board; everyone repaints
__mock.remoteRename()    // the owner renames the board; every client relabels
__mock.remoteEvent()     // someone else drops an event on the calendar
__mock.goOffline()       // network failures, offline dot, retry backoff
__mock.signOut()         // sign-in screen
```

Photo uploads work there too: the file becomes a data: URL, so picking one
really does change the avatars.

With PHP available, point a local vhost at `public_html/` and set `APP_ENV=dev`
in `.env` to get real error messages in API responses instead of generic ones.

---

## What changed from the prototype

| Prototype | Now |
|---|---|
| Whole board written on every save; concurrent saves clobbered each other | Per-note `PATCH`, versioned, 409 on conflict |
| `order` array, ignored by the render code | Dropped; sorting is by `updatedAt`, as it always really was |
| Identity was a self-declared name | OAuth accounts with real ids |
| `contributors` array on each note, unbounded | Dropped; a card names its creator and its last editor, both resolved per board |
| Activity feed could only show one entry per card | Every edit by every person, paginated |
| Delete and reset were permanent | Soft delete, restorable for 30 days |
| No server-side validation | Everything validated and length-capped server-side |
| 4s full-board poll | 1s delta poll, only what changed |

The UI is unchanged apart from what the new capabilities required: the sign-in
screen replaces the name prompt, the board name in the header switches boards,
the You tab gained boards / invites / restore, an `offline` state joined the
existing status dot, and the editor can show a conflict. Colours, type and
spacing are untouched.

The card itself has since moved on twice, both deliberately. It reads by
default and edits on tap, so a checklist can be ticked without entering an
editor. And it is now a **full screen** rather than a sheet stopping short of
the top: a back button at the top left is the way out, and flag, date and
delete sit behind the three dots at the top right — with what they have set
shown as read-only tags under the title. The formatting bar and the "last
edited by" line are pinned to the bottom of the screen, so the toolbar is
still there when you have typed past the fold. The slide-to-delete rail went with
that change; delete is a menu item that asks once, and the card is restorable
from the trash for 30 days either way.

---

## Conventions

- Timestamps are ISO 8601 UTC over the wire, formatted client-side only.
- Errors return `{ error: { code, message } }`. Stack traces are logged, never
  returned.
- Migrations are checked in and run forward-only.
- No secrets in the repo. `.env` is git-ignored; `.env.example` is committed.
