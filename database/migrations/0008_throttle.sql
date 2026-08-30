-- ══════════════════════════════════════════════════════════════
-- 0008_throttle
-- ══════════════════════════════════════════════════════════════
--
-- Run with `php scripts/migrate.php up`. The two section markers are comments,
-- not special syntax, so the file stays valid SQL that can be pasted into any
-- client as-is.

-- @UP
-- ════════════════════════════════════════════════════════════════════════════
-- Migration: a unified throttle for the sensitive entry points, plus preventing TOTP code reuse
--
-- 1) throttle_attempts — a general attempt counter serving every entry point.
--
--    Why a new table rather than extending login_attempts? Because the two existing tables
--    (`login_attempts` and `admin_login_attempts`) are tied to an email address: both answer
--    the question "how many times has sign-in to **this account** failed", and the interface
--    shows the result to the admin as failed_attempts. That question is still needed.
--
--    This table answers another question nobody was asking: "how many requests has **this
--    source** sent to this endpoint". It is what stops somebody trying a million TOTP codes,
--    or calling password recovery a thousand times to flood an inbox.
--    Merging the two questions into one table would have forced one of them to lie.
--
--    The key is composite because every query asks about all three together: which bucket,
--    for which source, within which window. attempted_at coming last is deliberate — it is
--    the only field queried by range rather than by equality.
--
-- 2) admins.last_totp_slice — the last time slice consumed.
--
--    verifyCode accepts a ±30-second window, so one code stays valid for ninety seconds.
--    Anyone catching a valid code (over a shoulder, or from a log) can reuse it inside that
--    window. Storing the consumed slice makes every code single-use.
--    BIGINT rather than INT: the slice is time()/30, and it passes the signed INT limit in
--    2038.
-- ════════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `throttle_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket`       VARCHAR(40)     NOT NULL
                   COMMENT 'The guarded endpoint''s name — login, forgot, twofa …',
    `identifier`   VARCHAR(64)     NOT NULL
                   COMMENT 'The guarded source — an IP address today (wide enough for IPv6)',
    `attempted_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bucket_identifier_time` (`bucket`, `identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `admins`
    ADD COLUMN `last_totp_slice` BIGINT NULL DEFAULT NULL
    COMMENT 'The last TOTP slice consumed — it prevents reusing the same code'
    AFTER `totp_enabled`;

-- @DOWN
ALTER TABLE `admins` DROP COLUMN `last_totp_slice`;
DROP TABLE IF EXISTS `throttle_attempts`;
