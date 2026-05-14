-- ════════════════════════════════════════════════════════════════════════
-- Nour's Touch — Phase 9 Schema
-- notifications log
-- ════════════════════════════════════════════════════════════════════════
SET NAMES utf8mb4;

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind`          VARCHAR(60) NOT NULL,                    -- e.g. appointment_24h, appointment_2h
    `channel`       ENUM('email','sms','whatsapp','internal') NOT NULL DEFAULT 'email',
    `recipient`     VARCHAR(255) NOT NULL,                   -- email or phone
    `subject`       VARCHAR(255) DEFAULT NULL,
    `body`          MEDIUMTEXT DEFAULT NULL,
    `subject_type`  VARCHAR(80) DEFAULT NULL,                -- 'appointment'
    `subject_id`    BIGINT UNSIGNED DEFAULT NULL,
    `status`        ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
    `error`         TEXT DEFAULT NULL,
    `sent_at`       DATETIME DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_n_kind`     (`kind`),
    KEY `idx_n_status`   (`status`),
    KEY `idx_n_subject`  (`subject_type`, `subject_id`),
    KEY `idx_n_created`  (`created_at`),
    -- Prevent duplicate reminders for the same appointment+kind
    UNIQUE KEY `uniq_subject_kind` (`subject_type`, `subject_id`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
