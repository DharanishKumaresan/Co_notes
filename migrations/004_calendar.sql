-- ---------------------------------------------------------------------------
-- 004_calendar - a shared calendar on every board.
--
-- Two things land on it:
--   * calendar_events - standalone entries ("site visit, Tue 3pm") that need
--     no card behind them.
--   * notes.due_date  - an optional date on an existing card, so a card with a
--     checklist can also have a deadline.
--
-- Dates are WALL CLOCK, deliberately. event_date is a DATE and start_time a
-- TIME, neither carrying a zone: "14 March, 3pm" reads the same for every
-- member wherever they are, which is what a shared board calendar wants. This
-- is the one place in the app that is not UTC-over-the-wire, and it is on
-- purpose - converting per viewer would mean two people on one board disagreeing
-- about which day something is on.
--
-- Forward-only. Run after 003_board_theme.sql.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS calendar_events (
  id         CHAR(26)     NOT NULL,
  board_id   CHAR(26)     NOT NULL,
  title      VARCHAR(120) NOT NULL,
  detail     TEXT         NULL,
  event_date DATE         NOT NULL,
  start_time TIME         NULL,                  -- NULL means an all-day entry
  version    INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL,
  created_by CHAR(26)     NULL,
  updated_at DATETIME     NOT NULL,
  updated_by CHAR(26)     NULL,
  deleted_at DATETIME     NULL,
  deleted_by CHAR(26)     NULL,
  PRIMARY KEY (id),
  KEY idx_cal_board_date (board_id, event_date),
  KEY idx_cal_age (deleted_at),
  CONSTRAINT fk_cal_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notes
  ADD COLUMN due_date DATE NULL AFTER pinned;

CREATE INDEX idx_notes_due ON notes (board_id, due_date);
