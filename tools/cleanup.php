<?php
declare(strict_types=1);

/**
 * Retention job. Purges soft-deleted cards past the restore window, prunes the
 * sync-event log, and clears expired sessions and OAuth states.
 *
 * Hostinger: hPanel > Advanced > Cron Jobs, daily:
 *   /usr/bin/php /home/<user>/domains/<domain>/tools/cleanup.php
 */

require_once __DIR__ . '/../app/bootstrap.php';

use CoNotes\Auth;
use CoNotes\Boards;
use CoNotes\Calendar;
use CoNotes\Events;
use CoNotes\Notes;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$purged = Notes::purgeExpired();
$events = Calendar::purgeExpired();
$boards = Boards::purgeDeleted();
$pruned = Events::prune();
Auth::sweep();

echo "purged {$purged} expired card(s), {$events} calendar event(s) and "
   . "{$boards} board(s), pruned {$pruned} sync event(s), swept sessions.\n";
