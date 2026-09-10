-- Co notes - master profile and per-board profiles
--
-- Adds:
--   * a master profile on `users`: a general @username, an uploaded photo,
--     and a default theme
--   * a per-board profile on `board_members`: display name, colour, photo and
--     theme, each nullable and each falling back to the master profile
--
-- Forward-only. Import after 001_init.sql.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Master profile
-- ---------------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN username      VARCHAR(32) NULL AFTER email,
  ADD COLUMN avatar_path   VARCHAR(255) NULL AFTER avatar_url,
  ADD COLUMN default_theme VARCHAR(24) NOT NULL DEFAULT 'lime' AFTER avatar_path;

-- Backfill a handle for anyone who signed in before this migration, so the
-- unique index below can be added safely. `id` is a ULID, so the tail is
-- already random enough to avoid collisions.
UPDATE users
   SET username = CONCAT('user', LOWER(RIGHT(id, 8)))
 WHERE username IS NULL;

ALTER TABLE users
  ADD UNIQUE KEY uq_users_username (username);

-- ---------------------------------------------------------------------------
-- Per-board profile
--
-- All four columns are NULL by default, meaning "inherit the master profile".
-- Nothing is copied down at join time: a change to the master profile should
-- show up on every board the person has not deliberately overridden.
-- ---------------------------------------------------------------------------
ALTER TABLE board_members
  ADD COLUMN display_name VARCHAR(28)  NULL AFTER role,
  ADD COLUMN color        CHAR(7)      NULL AFTER display_name,
  ADD COLUMN avatar_path  VARCHAR(255) NULL AFTER color,
  ADD COLUMN theme        VARCHAR(24)  NULL AFTER avatar_path;
