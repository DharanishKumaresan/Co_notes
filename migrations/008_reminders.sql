-- ---------------------------------------------------------------------------
-- 008_reminders - "this is coming up" notifications for dated things.
--
-- A push in this app has so far always meant *somebody did something*, and the
-- watermark on push_subscriptions was enough to say what was still owed. A
-- reminder is the other kind: nobody did anything, a date simply got close. So
-- it needs its own marker, and `reminded_at` is it.
--
-- Why a column and not another edits row. `note_edits` already carries a
-- userless `action='due'` row from Notes::flagDue(), and reusing it was
-- tempting - but flagDue fires *on* the due date and a reminder fires *before*
-- it, so one marker cannot mean both. Overloading it would have made
-- "already flagged" and "already reminded" the same fact, and unflagging a
-- card would have silently re-armed its reminder.
--
-- It is nullable and set once. Moving the date clears it, so a card pushed
-- forward is reminded about again - see clearReminderIfMoved() in Notes.php
-- and Calendar.php, which mirror the existing clearDueMarkIfMoved().
--
-- Forward-only. Run after 007_push.sql.
-- ---------------------------------------------------------------------------

ALTER TABLE notes
  ADD COLUMN reminded_at DATETIME NULL AFTER due_date;

ALTER TABLE calendar_events
  ADD COLUMN reminded_at DATETIME NULL AFTER start_time;

-- The reminder sweep asks "what is dated soon and not yet reminded", so both
-- halves of that question want an index.
CREATE INDEX idx_notes_reminder ON notes (due_date, reminded_at);
CREATE INDEX idx_events_reminder ON calendar_events (event_date, reminded_at);
