-- ---------------------------------------------------------------------------
-- 007_push - Web Push subscriptions, for the flagged-card notification.
--
-- One row per browser, not per user: the same person on a phone and a laptop
-- is two subscriptions, and both should buzz. The endpoint the push service
-- hands us is the natural key - it is already unique per browser per site, and
-- re-subscribing in the same browser hands back the same one, so the UNIQUE
-- below is what makes "enable notifications" idempotent.
--
-- p256dh and auth are the client's public key and shared secret. Nothing here
-- uses them yet - the pushes this app sends carry no payload, so there is
-- nothing to encrypt - but they are what a payload would be encrypted against,
-- and they arrive free with the subscription. Storing them now means adding a
-- payload later is a code change and not a migration plus a re-subscribe of
-- every existing browser.
--
-- endpoint is TEXT because push services do not agree on a length and some
-- FCM endpoints run past 512 bytes; the UNIQUE index takes a 255-byte prefix,
-- which is comfortably inside the utf8mb4 index limit and still selective
-- (the random part of an endpoint is at the end, but 255 bytes covers the
-- host plus most of the token).
--
-- Forward-only. Run after 006_board_delete.sql.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS push_subscriptions (
  id               CHAR(26)     NOT NULL,
  user_id          CHAR(26)     NOT NULL,
  endpoint         TEXT         NOT NULL,
  p256dh           VARCHAR(255) NOT NULL,
  auth             VARCHAR(255) NOT NULL,
  created_at       DATETIME     NOT NULL,
  -- How far the notifier has already caught this browser up. Everything
  -- flagged after this is what a push still owes it.
  last_notified_at DATETIME     NULL,
  -- Set when a push service answers 404/410. The row is deleted on sight, so
  -- this only exists for the window between the failure and the cleanup.
  failed_at        DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_push_endpoint (endpoint(255)),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
