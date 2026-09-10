<?php
declare(strict_types=1);

/**
 * Deployment self-check. Run it after uploading, before you hand anyone a link.
 *
 *   php tools/doctor.php
 *
 * No shell on your host? Copy this file into public_html temporarily and open
 * it in a browser - it prints the same report as plain text. Delete it after.
 */

require_once __DIR__ . '/../app/bootstrap.php';

use CoNotes\Db;
use CoNotes\Env;
use CoNotes\OAuth;
use CoNotes\Push;

header('Content-Type: text/plain; charset=utf-8');

$problems = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $problems;
    if (!$ok) {
        $problems++;
    }
    printf("%-34s %s%s\n", $label, $ok ? 'OK' : 'FAIL', $detail !== '' ? '  - ' . $detail : '');
}

echo "Co notes deployment check\n";
echo str_repeat('=', 58) . "\n\n";

/** Informational only: something optional that is switched off, not broken. */
function note(string $label, string $detail): void
{
    printf("%-34s %s  - %s
", $label, '--', $detail);
}

check('PHP >= 8.1', PHP_VERSION_ID >= 80100, PHP_VERSION);
check('PDO MySQL driver', extension_loaded('pdo_mysql'));
check('curl (needed for OAuth)', extension_loaded('curl'));
check('mbstring', extension_loaded('mbstring'));
check('GD (needed for photos)', extension_loaded('gd'),
    extension_loaded('gd') ? '' : 'profile photo uploads will be refused');

$uploadDir = null;
try {
    $uploadDir = CoNotes\Uploads::dir();
    check('Upload directory writable', is_writable($uploadDir), $uploadDir);
} catch (Throwable $e) {
    check('Upload directory writable', false, $e->getMessage());
}

check('Uploads cannot execute PHP',
    $uploadDir !== null && is_file(dirname($uploadDir) . '/.htaccess'),
    'public_html/uploads/.htaccess must be uploaded too');
check('APP_BASE_URL set', Env::baseUrl() !== '', Env::baseUrl());
check('APP_BASE_URL is https', str_starts_with(Env::baseUrl(), 'https://'),
    'cookies are only marked Secure over https');

$dbOk = false;
try {
    Db::scalar('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    check('Database connection', false, $e->getMessage());
}
if ($dbOk) {
    check('Database connection', true, Env::get('DB_NAME', '?') ?? '?');

    $expected = [
        'users', 'sessions', 'oauth_states', 'boards', 'board_members',
        'board_invites', 'notes', 'note_edits', 'board_events',
        'calendar_events', 'calendar_event_edits',
    ];
    $expectedColumns = [
        'users'         => ['username', 'avatar_path', 'default_theme'],
        'board_members' => ['display_name', 'color', 'avatar_path'],
        'boards'        => ['theme', 'deleted_at'],
        // 008 adds these. Code one migration ahead of the database 503s on
        // every board query, so a missing column has to be loud here.
        'notes'          => ['due_date', 'reminded_at'],
        'calendar_events'=> ['reminded_at'],
    ];
    // Which migration adds each set, so a failure names the file to run.
    $columnSource = [
        'users'         => '002_profiles.sql',
        'board_members' => '002_profiles.sql',
        'boards'        => '003_board_theme.sql / 006_board_delete.sql',
        'notes'         => '004_calendar.sql',
    ];
    $found = [];
    foreach (Db::all('SHOW TABLES') as $row) {
        $found[] = (string) array_values($row)[0];
    }
    $missing = array_diff($expected, $found);
    check('Schema tables present', $missing === [], $missing === [] ? '' : 'missing: ' . implode(', ', $missing));

    if ($missing === []) {
        // 002 adds the profile columns and 003 moves the theme onto the board;
        // a half-migrated database is the likeliest cause of odd behaviour
        // after an upgrade.
        $presentByTable = [];
        foreach ($expectedColumns as $table => $columns) {
            $present = [];
            foreach (Db::all("SHOW COLUMNS FROM {$table}") as $col) {
                $present[] = (string) $col['Field'];
            }
            $presentByTable[$table] = $present;

            $lack = array_diff($columns, $present);
            check("Columns on {$table}", $lack === [],
                $lack === [] ? '' : 'run migrations/' . $columnSource[$table]
                    . ' - missing: ' . implode(', ', $lack));
        }

        // 003 also drops the per-member theme. Finding it still there means the
        // migration stopped part way, and the app is not what the schema says.
        $staleTheme = in_array('theme', $presentByTable['board_members'] ?? [], true);
        check('Per-member theme column dropped', !$staleTheme,
            $staleTheme ? 'board_members.theme is still present - finish migrations/003_board_theme.sql' : '');

        $counts = [];
        foreach (['users', 'boards', 'notes'] as $t) {
            $counts[] = $t . '=' . (int) Db::scalar("SELECT COUNT(*) FROM {$t}");
        }
        echo "\n  rows: " . implode('  ', $counts) . "\n\n";
    }
}

$providers = OAuth::available();
check('OAuth provider configured', $providers !== [],
    $providers === [] ? 'set GOOGLE_* or GITHUB_* in .env' : implode(', ', $providers));

foreach ($providers as $p) {
    echo "  redirect URI for {$p}: " . Env::baseUrl() . "/api/auth/{$p}/callback\n";
}

echo "\n";
$envPath = dirname(__DIR__) . '/.env';
// Web Push is optional, so switched off is a note rather than a failure. But
// switched ON with a piece missing is a real problem: subscribing would 500,
// and nothing else in this file would have caught it.
if (Env::get('VAPID_PUBLIC_KEY', '') === '') {
    note('Web Push', 'off - run php tools/make-vapid.php to enable flag notifications');
} elseif (!extension_loaded('openssl')) {
    check('Web Push', false, 'VAPID keys are set but the openssl extension is missing');
} elseif (!Push::configured()) {
    check('Web Push', false, 'VAPID keys are set but malformed - regenerate them');
} else {
    $pushTable = false;
    try {
        CoNotes\Db::scalar('SELECT 1 FROM push_subscriptions LIMIT 1');
        $pushTable = true;
    } catch (Throwable $e) {
        $pushTable = false;
    }
    check('Web Push', $pushTable, $pushTable
        ? 'VAPID keys loaded, subscriptions table present'
        : 'keys are set but push_subscriptions is missing - import migrations/007_push.sql');

    // Apple rejects a token whose sub claim is not a mailto:/https: URL, so a
    // vague VAPID_SUBJECT breaks iPhones only, silently, while Android works.
    if (!Push::subjectIsExplicit()) {
        note('  VAPID_SUBJECT', 'not set to a mailto:/https: URL - iPhones may reject the push');
    }
}

check('.env outside public_html', !str_contains(str_replace('\\', '/', $envPath), '/public_html/'),
    $envPath);


/*
 * The shell's asset version has to be the same number in two files, because
 * there is no build step to derive one from the other. When they drift the
 * failure is nasty and does not look like a cache problem: index.html is
 * served network-first and the assets cache-first, so the browser runs the
 * new markup against the previous app.js for a whole launch - buttons that
 * are visibly present do nothing, and old behaviour hides behind new UI.
 */
$webRoot = dirname(__DIR__) . '/public_html';
$shellHtml = @file_get_contents($webRoot . '/index.html');
$shellSw   = @file_get_contents($webRoot . '/sw.js');
if ($shellHtml !== false && $shellSw !== false) {
    preg_match_all('~/assets/app\.(?:css|js)\?v=([0-9]+)~', $shellHtml, $mHtml);
    $htmlVersions = array_values(array_unique($mHtml[1]));
    preg_match("~const VERSION = '([0-9]+)'~", $shellSw, $mVer);
    preg_match("~const CACHE = 'conotes-shell-v([0-9]+)'~", $shellSw, $mCache);

    $swVersion = $mVer[1] ?? null;
    $swCache   = $mCache[1] ?? null;
    $agreed = count($htmlVersions) === 1
           && $swVersion !== null
           && $htmlVersions[0] === $swVersion
           && $swCache === $swVersion;

    check('Shell version agrees in index.html and sw.js', $agreed, $agreed
        ? 'v' . $swVersion
        : sprintf('index.html asks for [%s], sw.js VERSION is %s, CACHE is v%s - bump all three together',
            implode(', ', $htmlVersions) ?: 'no ?v= on the asset tags',
            $swVersion ?? 'missing',
            $swCache ?? 'missing'));
} else {
    note('Shell version', 'could not read public_html/index.html or sw.js');
}

echo "\n" . str_repeat('=', 58) . "\n";
echo $problems === 0
    ? "Everything checks out.\n"
    : "{$problems} problem(s) above need fixing.\n";
