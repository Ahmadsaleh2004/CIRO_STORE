-- ══════════════════════════════════════════════════════════════
-- 0009_mail_queue
-- ══════════════════════════════════════════════════════════════
--
-- Run with `php scripts/migrate.php up`. The two section markers are comments,
-- not special syntax, so the file stays valid SQL that can be pasted into any
-- client as-is.

-- @UP
-- ════════════════════════════════════════════════════════════════════════════
-- Migration: the mail queue — taking SMTP out of the request path
--
-- Mailer::send used to open a Gmail SMTP connection **inside the request itself**: it
-- connected, authenticated and sent before the visitor saw any response. The effect was
-- threefold:
--
--   · the admin sign-in waited on Gmail every time;
--   · a slow or down SMTP server hung PHP threads rather than merely slowing them;
--   · and worst of all with /auth/forgot: every request meant a new SMTP connection, so
--     flooding it drained not only the quota but the server's threads with it.
--
-- The queue separates "deciding to send" from "sending": the request writes a row and
-- returns, and the worker (scripts/mail-worker.php) sends outside the request path.
--
-- Notes on the structure:
--
--   · status as an ENUM rather than a VARCHAR: the three values are known and closed, and
--     an ENUM prevents a fourth state being written by a typo and hanging there forever.
--
--   · attempts and last_error together: without the counter, a permanently failing message
--     circulates in the queue forever, and without the error text nobody knows why it failed.
--
--   · the index on (status, id): the worker asks one question — "the oldest pending
--     messages" — and the composite index answers it without scanning the table.
--
--   · body is a MEDIUMTEXT: HTML templates with long links exceed TEXT in edge cases, and
--     the difference in storage is negligible.
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `mail_queue` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `to_email`   VARCHAR(190)    NOT NULL,
    `to_name`    VARCHAR(150)    NOT NULL DEFAULT '',
    `subject`    VARCHAR(255)    NOT NULL,
    `body`       MEDIUMTEXT      NOT NULL,
    `status`     ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `last_error` VARCHAR(255)    DEFAULT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `sent_at`    DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_status_id` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @DOWN
DROP TABLE IF EXISTS `mail_queue`;
