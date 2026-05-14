-- ════════════════════════════════════════════════════════════════════════
-- Nour's Touch — Phase 2 Schema
-- Patients · Services · Packages · Medical Records · Files
-- ════════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── SERVICE CATEGORIES ───────────────────────────────────────────────────
DROP TABLE IF EXISTS `service_categories`;
CREATE TABLE `service_categories` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar`     VARCHAR(150) NOT NULL,
    `name_en`     VARCHAR(150) DEFAULT NULL,
    `slug`        VARCHAR(160) NOT NULL,
    `icon`        VARCHAR(60) DEFAULT NULL,
    `description_ar` TEXT DEFAULT NULL,
    `description_en` TEXT DEFAULT NULL,
    `sort_order`  INT NOT NULL DEFAULT 0,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`  BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`  BIGINT UNSIGNED DEFAULT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`  DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_cat_slug` (`slug`),
    KEY `idx_cat_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SERVICES ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `services`;
CREATE TABLE `services` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id`     INT UNSIGNED DEFAULT NULL,
    `name_ar`         VARCHAR(200) NOT NULL,
    `name_en`         VARCHAR(200) DEFAULT NULL,
    `slug`            VARCHAR(220) NOT NULL,
    `description_ar`  TEXT DEFAULT NULL,
    `description_en`  TEXT DEFAULT NULL,
    `duration_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `price`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `commission_pct`  DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    `default_therapist_id` BIGINT UNSIGNED DEFAULT NULL,
    `image`           VARCHAR(255) DEFAULT NULL,
    `is_consultation` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`      INT NOT NULL DEFAULT 0,
    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_services_slug` (`slug`),
    KEY `idx_services_category` (`category_id`),
    KEY `idx_services_active` (`is_active`),
    KEY `idx_services_deleted` (`deleted_at`),
    CONSTRAINT `fk_services_category`
        FOREIGN KEY (`category_id`) REFERENCES `service_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PACKAGES ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `packages`;
CREATE TABLE `packages` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar`          VARCHAR(200) NOT NULL,
    `name_en`          VARCHAR(200) DEFAULT NULL,
    `slug`             VARCHAR(220) NOT NULL,
    `description_ar`   TEXT DEFAULT NULL,
    `description_en`   TEXT DEFAULT NULL,
    `total_sessions`   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `price`            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `validity_days`    SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    `image`            VARCHAR(255) DEFAULT NULL,
    `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`       INT NOT NULL DEFAULT 0,
    `created_by`       BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`       BIGINT UNSIGNED DEFAULT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`       DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_packages_slug` (`slug`),
    KEY `idx_packages_active` (`is_active`),
    KEY `idx_packages_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PACKAGE ↔ SERVICES (which services this package covers) ──────────────
DROP TABLE IF EXISTS `package_services`;
CREATE TABLE `package_services` (
    `package_id`        BIGINT UNSIGNED NOT NULL,
    `service_id`        BIGINT UNSIGNED NOT NULL,
    `sessions_included` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (`package_id`,`service_id`),
    CONSTRAINT `fk_ps_package` FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ps_service` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PATIENTS ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `patients`;
CREATE TABLE `patients` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code`           VARCHAR(20) NOT NULL,
    `first_name`     VARCHAR(80) NOT NULL,
    `last_name`      VARCHAR(80) NOT NULL,
    `gender`         ENUM('male','female','other') NOT NULL DEFAULT 'female',
    `dob`            DATE DEFAULT NULL,
    `phone`          VARCHAR(30) NOT NULL,
    `email`          VARCHAR(150) DEFAULT NULL,
    `address`        VARCHAR(255) DEFAULT NULL,
    `city`           VARCHAR(80) DEFAULT NULL,
    `country`        VARCHAR(80) DEFAULT 'Jordan',
    `national_id`    VARCHAR(40) DEFAULT NULL,
    `emergency_name` VARCHAR(120) DEFAULT NULL,
    `emergency_phone` VARCHAR(30) DEFAULT NULL,
    `medical_history` TEXT DEFAULT NULL,
    `allergies`      TEXT DEFAULT NULL,
    `chronic_conditions` TEXT DEFAULT NULL,
    `current_medications` TEXT DEFAULT NULL,
    `notes`          TEXT DEFAULT NULL,
    `avatar`         VARCHAR(255) DEFAULT NULL,
    `outstanding_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `is_blocked`     TINYINT(1) NOT NULL DEFAULT 0,
    `created_by`     BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`     BIGINT UNSIGNED DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_patients_code` (`code`),
    KEY `idx_patients_phone` (`phone`),
    KEY `idx_patients_email` (`email`),
    KEY `idx_patients_name` (`first_name`,`last_name`),
    KEY `idx_patients_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── MEDICAL RECORDS (encrypted-friendly TEXT) ────────────────────────────
DROP TABLE IF EXISTS `medical_records`;
CREATE TABLE `medical_records` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`    BIGINT UNSIGNED NOT NULL,
    `record_type`   ENUM('note','diagnosis','treatment','prescription','test_result','xray','other')
                    NOT NULL DEFAULT 'note',
    `title`         VARCHAR(200) NOT NULL,
    `content`       LONGTEXT DEFAULT NULL,
    `record_date`   DATE NOT NULL,
    `created_by`    BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`    BIGINT UNSIGNED DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_mr_patient` (`patient_id`),
    KEY `idx_mr_type`    (`record_type`),
    KEY `idx_mr_date`    (`record_date`),
    CONSTRAINT `fk_mr_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PATIENT FILES (X-rays, reports, photos) ──────────────────────────────
DROP TABLE IF EXISTS `patient_files`;
CREATE TABLE `patient_files` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`     BIGINT UNSIGNED NOT NULL,
    `record_id`      BIGINT UNSIGNED DEFAULT NULL,
    `file_name`      VARCHAR(255) NOT NULL,
    `original_name`  VARCHAR(255) NOT NULL,
    `mime_type`      VARCHAR(100) NOT NULL,
    `size_bytes`     INT UNSIGNED NOT NULL,
    `category`       VARCHAR(50) DEFAULT 'general',
    `uploaded_by`    BIGINT UNSIGNED DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pf_patient` (`patient_id`),
    KEY `idx_pf_record`  (`record_id`),
    CONSTRAINT `fk_pf_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pf_record`  FOREIGN KEY (`record_id`)  REFERENCES `medical_records`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PATIENT PACKAGES (active package subscriptions) ──────────────────────
DROP TABLE IF EXISTS `patient_packages`;
CREATE TABLE `patient_packages` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`      BIGINT UNSIGNED NOT NULL,
    `package_id`      BIGINT UNSIGNED NOT NULL,
    `purchase_date`   DATE NOT NULL,
    `expiry_date`     DATE NOT NULL,
    `total_sessions`  SMALLINT UNSIGNED NOT NULL,
    `used_sessions`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `price`           DECIMAL(10,2) NOT NULL,
    `paid_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status`          ENUM('active','expired','completed','cancelled') NOT NULL DEFAULT 'active',
    `notes`           TEXT DEFAULT NULL,
    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pp_patient` (`patient_id`),
    KEY `idx_pp_package` (`package_id`),
    KEY `idx_pp_status`  (`status`),
    KEY `idx_pp_expiry`  (`expiry_date`),
    CONSTRAINT `fk_pp_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pp_package` FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
