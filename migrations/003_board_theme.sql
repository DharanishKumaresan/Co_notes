-- ---------------------------------------------------------------------------
-- 003_board_theme - the theme becomes a property of the board.
--
-- Until now a theme was personal to a member on a board: board_members.theme
-- overrode users.default_theme, and picking one restyled the app for that
-- person alone. It is now shared - anyone on the board can change it and
-- everyone on that board sees it.
--
-- users.default_theme stays. It is no longer an inheritance root: it now seeds
-- the theme of a board at the moment that user creates it.
--
-- Forward-only, like every migration here. Run after 002_profiles.sql.
-- ---------------------------------------------------------------------------

ALTER TABLE boards
  ADD COLUMN theme VARCHAR(24) NOT NULL DEFAULT 'lime' AFTER name;

-- Carry the old setting forward rather than resetting every board to lime:
-- a board adopts whatever its creator was already looking at, which is their
-- own override on that board if they set one, and their master default if not.
UPDATE boards b
  JOIN board_members m ON m.board_id = b.id AND m.user_id = b.created_by
  JOIN users u        ON u.id = b.created_by
   SET b.theme = COALESCE(m.theme, u.default_theme);

-- The per-member override is what made a theme private. With the board owning
-- the theme there is nothing for it to override, and leaving it in place would
-- invite a future query to read it and quietly resurrect private themes.
ALTER TABLE board_members
  DROP COLUMN theme;
