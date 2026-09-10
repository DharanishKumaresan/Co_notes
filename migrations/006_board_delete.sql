-- ---------------------------------------------------------------------------
-- 006_board_delete - an owner can delete a whole board.
--
-- Soft, like every other delete in this app (see decision 3 in CLAUDE.md).
-- It matters more here than anywhere else: deleting a board takes every other
-- member's cards, calendar and history with it, so the row survives the
-- retention window before tools/cleanup.php purges it for real.
--
-- The purge itself needs no new SQL. Every board-owned table already declares
-- ON DELETE CASCADE against boards - board_members, board_invites, notes,
-- note_edits, board_events, calendar_events (and calendar_event_edits in turn
-- from calendar_events) - so one DELETE on boards takes the lot, with no
-- orphans left behind.
--
-- Forward-only. Run after 005_calendar_activity.sql.
-- ---------------------------------------------------------------------------

ALTER TABLE boards
  ADD COLUMN deleted_at DATETIME NULL AFTER created_at,
  ADD COLUMN deleted_by CHAR(26) NULL AFTER deleted_at;

ALTER TABLE boards
  ADD CONSTRAINT fk_boards_deleter FOREIGN KEY (deleted_by) REFERENCES users (id) ON DELETE SET NULL;

CREATE INDEX idx_boards_deleted ON boards (deleted_at);
