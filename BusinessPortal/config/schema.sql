-- ════════════════════════════════════════════════════════════════════════
-- Nour's Touch Clinic — Database Schema (Phase 1)
-- Tables: users, roles, permissions, role_permissions, user_roles,
--         settings, activity_logs, password_resets
-- ════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── USERS ────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`             VARCHAR(150) NOT NULL,
    `email`            VARCHAR(150) NOT NULL,
    `phone`            VARCHAR(30)  DEFAULT NULL,
    `password`         VARCHAR(255) NOT NULL,
    `avatar`           VARCHAR(255) DEFAULT NULL,
    `status`           ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    `must_change_pw`   TINYINT(1) NOT NULL DEFAULT 0,
    `last_login_at`    DATETIME DEFAULT NULL,
    `last_login_ip`    VARCHAR(45) DEFAULT NULL,
    `two_factor_secret` VARCHAR(255) DEFAULT NULL,
    `remember_token`   VARCHAR(100) DEFAULT NULL,
    `created_by`       BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`       BIGINT UNSIGNED DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`       DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_users_email` (`email`),
    KEY `idx_users_status` (`status`),
    KEY `idx_users_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ROLES ────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(80) NOT NULL,
    `slug`         VARCHAR(80) NOT NULL,
    `description`  VARCHAR(255) DEFAULT NULL,
    `is_system`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`   DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_roles_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PERMISSIONS ──────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(120) NOT NULL,
    `slug`       VARCHAR(120) NOT NULL,
    `module`     VARCHAR(60)  NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_perm_slug` (`slug`),
    KEY `idx_perm_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ROLE ↔ PERMISSION ────────────────────────────────────────────────────
DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
    `role_id`       INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── USER ↔ ROLE ──────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`user_id`, `role_id`),
    CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SETTINGS ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `key`        VARCHAR(100) NOT NULL,
    `value`      LONGTEXT DEFAULT NULL,
    `group`      VARCHAR(60)  NOT NULL DEFAULT 'general',
    `type`       VARCHAR(30)  NOT NULL DEFAULT 'text',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_settings_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ACTIVITY LOGS (immutable audit trail) ────────────────────────────────
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`      BIGINT UNSIGNED DEFAULT NULL,
    `user_name`    VARCHAR(150) DEFAULT NULL,
    `action`       VARCHAR(80) NOT NULL,
    `module`       VARCHAR(60) DEFAULT NULL,
    `subject_type` VARCHAR(80) DEFAULT NULL,
    `subject_id`   BIGINT UNSIGNED DEFAULT NULL,
    `description`  TEXT DEFAULT NULL,
    `meta`         JSON DEFAULT NULL,
    `ip`           VARCHAR(45) DEFAULT NULL,
    `user_agent`   VARCHAR(255) DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_log_user`   (`user_id`),
    KEY `idx_log_module` (`module`),
    KEY `idx_log_action` (`action`),
    KEY `idx_log_created`(`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PASSWORD RESETS ──────────────────────────────────────────────────────
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
    `email`      VARCHAR(150) NOT NULL,
    `token`      VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`email`),
    KEY `idx_pwreset_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── LOGIN ATTEMPTS (rate limiting) ───────────────────────────────────────
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(150) NOT NULL,
    `ip`         VARCHAR(45) NOT NULL,
    `success`    TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_email_ip_time` (`email`,`ip`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
