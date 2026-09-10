-- ---------------------------------------------------------------------------
-- 005_calendar_activity - calendar events get an audit trail, so they can
-- appear in the Activity tab alongside card edits.
--
-- Mirrors note_edits exactly, one table per kind of thing being audited.
-- Deliberately NOT folded into note_edits: that table's rows always resolve
-- against `notes`, and CLAUDE.md already leans on that (contributorsFor(),
-- the purge-cascade behaviour). A parallel table keeps both simple; app/
-- Activity.php is what merges the two into one feed at read time.
--
-- No coalescing here, unlike note_edits. Coalescing exists there because a
-- 650ms typing debounce would otherwise write an activity row every few
-- keystrokes; an event is saved once from a modal with an explicit Save
-- button, so every save is already one deliberate action.
--
-- Forward-only. Run after 004_calendar.sql.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS calendar_event_edits (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id   CHAR(26)    NOT NULL,
  board_id   CHAR(26)    NOT NULL,
  user_id    CHAR(26)    NULL,
  action     VARCHAR(24) NOT NULL,                  -- created|updated|deleted
  edited_at  DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY idx_cal_edits_board (board_id, id),
  CONSTRAINT fk_cal_edits_event FOREIGN KEY (event_id) REFERENCES calendar_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
