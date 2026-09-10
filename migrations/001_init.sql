-- Co notes - initial schema
-- MySQL 8.0+ / MariaDB 10.4+
-- Forward-only. Import via phpMyAdmin (Import tab) or `php tools/migrate.php`.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- users - one row per OAuth identity
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id               CHAR(26)     NOT NULL,
  provider         VARCHAR(16)  NOT NULL,           -- 'google' | 'github'
  provider_user_id VARCHAR(191) NOT NULL,           -- stable subject id at the provider
  email            VARCHAR(191) NULL,
  display_name     VARCHAR(28)  NOT NULL,
  color            CHAR(7)      NOT NULL,           -- #RRGGBB, derived from display_name
  avatar_url       VARCHAR(512) NULL,
  created_at       DATETIME     NOT NULL,
  last_seen_at     DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_provider (provider, provider_user_id),
  KEY idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- sessions - server-side session store; the cookie carries only the id
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
  id           CHAR(43)     NOT NULL,               -- 256-bit random, base64url
  user_id      CHAR(26)     NOT NULL,
  created_at   DATETIME     NOT NULL,
  expires_at   DATETIME     NOT NULL,
  last_seen_at DATETIME     NOT NULL,
  user_agent   VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_sessions_user (user_id),
  KEY idx_sessions_expiry (expires_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- oauth_states - short-lived CSRF state for the OAuth redirect dance
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS oauth_states (
  state         CHAR(43)     NOT NULL,
  provider      VARCHAR(16)  NOT NULL,
  redirect_to   VARCHAR(512) NULL,                  -- app path to land on after login
  invite_token  CHAR(22)     NULL,                  -- join this board once signed in
  code_verifier VARCHAR(128) NULL,                  -- PKCE
  created_at    DATETIME     NOT NULL,
  expires_at    DATETIME     NOT NULL,
  PRIMARY KEY (state),
  KEY idx_oauth_states_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- boards
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS boards (
  id         CHAR(26)     NOT NULL,
  slug       VARCHAR(64)  NOT NULL,
  name       VARCHAR(80)  NOT NULL,
  created_by CHAR(26)     NULL,
  created_at DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_boards_slug (slug),
  CONSTRAINT fk_boards_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- board_members - membership is the permission. No row, no access.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS board_members (
  board_id  CHAR(26)    NOT NULL,
  user_id   CHAR(26)    NOT NULL,
  role      VARCHAR(16) NOT NULL DEFAULT 'member',  -- 'owner' | 'member'
  joined_at DATETIME    NOT NULL,
  PRIMARY KEY (board_id, user_id),
  KEY idx_members_user (user_id),
  CONSTRAINT fk_members_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE,
  CONSTRAINT fk_members_user  FOREIGN KEY (user_id)  REFERENCES users (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- board_invites - an invite link grants membership, once redeemed
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS board_invites (
  token      CHAR(22)  NOT NULL,
  board_id   CHAR(26)  NOT NULL,
  created_by CHAR(26)  NULL,
  created_at DATETIME  NOT NULL,
  expires_at DATETIME  NOT NULL,
  max_uses   INT       NOT NULL DEFAULT 100,
  uses       INT       NOT NULL DEFAULT 0,
  revoked_at DATETIME  NULL,
  PRIMARY KEY (token),
  KEY idx_invites_board (board_id),
  CONSTRAINT fk_invites_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE,
  CONSTRAINT fk_invites_user  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- notes - `version` is the optimistic-concurrency token, bumped server-side
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notes (
  id         CHAR(26)     NOT NULL,
  board_id   CHAR(26)     NOT NULL,
  title      VARCHAR(120) NOT NULL DEFAULT '',
  content    LONGTEXT     NOT NULL,
  pinned     TINYINT(1)   NOT NULL DEFAULT 0,
  version    INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME     NOT NULL,
  created_by CHAR(26)     NULL,
  updated_at DATETIME     NOT NULL,
  updated_by CHAR(26)     NULL,
  deleted_at DATETIME     NULL,                     -- soft delete
  deleted_by CHAR(26)     NULL,
  PRIMARY KEY (id),
  KEY idx_notes_board_live (board_id, deleted_at, updated_at),
  KEY idx_notes_board_trash (board_id, deleted_at),
  CONSTRAINT fk_notes_board   FOREIGN KEY (board_id)   REFERENCES boards (id) ON DELETE CASCADE,
  CONSTRAINT fk_notes_creator FOREIGN KEY (created_by) REFERENCES users (id)  ON DELETE SET NULL,
  CONSTRAINT fk_notes_editor  FOREIGN KEY (updated_by) REFERENCES users (id)  ON DELETE SET NULL,
  CONSTRAINT fk_notes_deleter FOREIGN KEY (deleted_by) REFERENCES users (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- note_edits - the audit trail. Powers both `contributors` and Activity.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS note_edits (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  note_id   CHAR(26)    NOT NULL,
  board_id  CHAR(26)    NOT NULL,
  user_id   CHAR(26)    NULL,
  action    VARCHAR(16) NOT NULL,                   -- created|edited|flagged|unflagged|deleted|restored
  edited_at DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY idx_edits_note (note_id, edited_at),
  KEY idx_edits_board (board_id, id),
  CONSTRAINT fk_edits_note  FOREIGN KEY (note_id)  REFERENCES notes (id) ON DELETE CASCADE,
  CONSTRAINT fk_edits_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE,
  CONSTRAINT fk_edits_user  FOREIGN KEY (user_id)  REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- board_events - the live-sync cursor. Clients poll `id > since`.
-- Stands in for the WebSocket a shared host cannot hold open.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS board_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  board_id   CHAR(26)    NOT NULL,
  type       VARCHAR(24) NOT NULL,                  -- note.created|note.updated|note.deleted|note.restored|board.reset|member.joined
  note_id    CHAR(26)    NULL,
  actor_id   CHAR(26)    NULL,
  created_at DATETIME    NOT NULL,
  PRIMARY KEY (id),
  KEY idx_events_board (board_id, id),
  KEY idx_events_age (created_at),
  CONSTRAINT fk_events_board FOREIGN KEY (board_id) REFERENCES boards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
