<?php
declare(strict_types=1);

/**
 * Send the "coming up" notifications.
 *
 *     php tools/remind.php
 *
 * Cron this a few times a day - hourly is plenty, and every five minutes is
 * harmless. It is safe to run as often as you like: `Push::sweepReminders()`
 * stamps `reminded_at` in the same UPDATE that selects a row, so nothing is
 * ever picked up twice. Not running it at all costs you the reminders and
 * nothing else; everything the app does otherwise is unaffected.
 *
 * This is separate from tools/cleanup.php on purpose. Cleanup is a daily
 * retention job and it does not matter which hour it lands on. A reminder
 * does: "due tomorrow" told at 3am is worse than not told, so this wants a
 * schedule you choose, and one you can change without moving the purge.
 *
 * hPanel > Advanced > Cron Jobs:
 *   /usr/bin/php /home/<user>/domains/yourdomain.com/tools/remind.php
 */

require __DIR__ . '/../app/bootstrap.php';

use CoNotes\Push;

if (!Push::configured()) {
    echo "Web Push is not configured - nothing to do.\n";
    echo "Run php tools/make-vapid.php and put the keys in .env.\n";
    exit(0);
}

$counts = Push::sweepReminders();

printf(
    "reminded about %d card%s and %d event%s\n",
    $counts['notes'],
    $counts['notes'] === 1 ? '' : 's',
    $counts['events'],
    $counts['events'] === 1 ? '' : 's'
);
