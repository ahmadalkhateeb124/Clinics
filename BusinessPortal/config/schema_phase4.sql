-- ════════════════════════════════════════════════════════════════════════
-- Nour's Touch — Phase 4 Schema
-- Consultations + Treatment Plans
-- ════════════════════════════════════════════════════════════════════════
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── CONSULTATIONS ────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `consultations`;
CREATE TABLE `consultations` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`      BIGINT UNSIGNED NOT NULL,
    `doctor_id`       BIGINT UNSIGNED DEFAULT NULL,           -- users.id (role: doctor)
    `appointment_id`  BIGINT UNSIGNED DEFAULT NULL,           -- linked booking (optional)
    `service_id`      BIGINT UNSIGNED DEFAULT NULL,           -- consultation service used
    `mode`            ENUM('in_clinic','video','phone') NOT NULL DEFAULT 'in_clinic',
    `video_link`      VARCHAR(500) DEFAULT NULL,
    `consultation_date` DATETIME NOT NULL,
    `duration_minutes`  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `complaint`       TEXT DEFAULT NULL,
    `examination`     TEXT DEFAULT NULL,
    `diagnosis`       TEXT DEFAULT NULL,
    `recommendations` TEXT DEFAULT NULL,
    `prescription`    TEXT DEFAULT NULL,
    `follow_up_date`  DATE DEFAULT NULL,
    `prescribed_sessions` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `fee`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `paid`            TINYINT(1) NOT NULL DEFAULT 0,
    `invoice_id`      BIGINT UNSIGNED DEFAULT NULL,           -- filled in Phase 5
    `status`          ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_cs_patient` (`patient_id`),
    KEY `idx_cs_doctor`  (`doctor_id`),
    KEY `idx_cs_date`    (`consultation_date`),
    KEY `idx_cs_status`  (`status`),
    KEY `idx_cs_appt`    (`appointment_id`),
    CONSTRAINT `fk_cs_patient`  FOREIGN KEY (`patient_id`)     REFERENCES `patients`(`id`)     ON DELETE CASCADE,
    CONSTRAINT `fk_cs_doctor`   FOREIGN KEY (`doctor_id`)      REFERENCES `users`(`id`)        ON DELETE SET NULL,
    CONSTRAINT `fk_cs_appt`     FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cs_service`  FOREIGN KEY (`service_id`)     REFERENCES `services`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TREATMENT PLANS (consultation → plan → package) ──────────────────────
DROP TABLE IF EXISTS `treatment_plans`;
CREATE TABLE `treatment_plans` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`      BIGINT UNSIGNED NOT NULL,
    `consultation_id` BIGINT UNSIGNED DEFAULT NULL,
    `package_id`      BIGINT UNSIGNED DEFAULT NULL,    -- if converted to a package
    `patient_package_id` BIGINT UNSIGNED DEFAULT NULL, -- if assigned to patient
    `title`           VARCHAR(200) NOT NULL,
    `goals`           TEXT DEFAULT NULL,
    `total_sessions`  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `start_date`      DATE DEFAULT NULL,
    `end_date`        DATE DEFAULT NULL,
    `status`          ENUM('draft','active','completed','cancelled') NOT NULL DEFAULT 'draft',
    `notes`           TEXT DEFAULT NULL,
    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_tp_patient`      (`patient_id`),
    KEY `idx_tp_consultation` (`consultation_id`),
    KEY `idx_tp_status`       (`status`),
    CONSTRAINT `fk_tp_patient`      FOREIGN KEY (`patient_id`)      REFERENCES `patients`(`id`)         ON DELETE CASCADE,
    CONSTRAINT `fk_tp_consultation` FOREIGN KEY (`consultation_id`) REFERENCES `consultations`(`id`)    ON DELETE SET NULL,
    CONSTRAINT `fk_tp_package`      FOREIGN KEY (`package_id`)      REFERENCES `packages`(`id`)         ON DELETE SET NULL,
    CONSTRAINT `fk_tp_pp`           FOREIGN KEY (`patient_package_id`) REFERENCES `patient_packages`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TREATMENT PLAN ↔ SERVICES ────────────────────────────────────────────
DROP TABLE IF EXISTS `treatment_plan_services`;
CREATE TABLE `treatment_plan_services` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `plan_id`         BIGINT UNSIGNED NOT NULL,
    `service_id`      BIGINT UNSIGNED NOT NULL,
    `sessions_count`  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `notes`           VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_tps` (`plan_id`,`service_id`),
    CONSTRAINT `fk_tps_plan`    FOREIGN KEY (`plan_id`)    REFERENCES `treatment_plans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tps_service` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allow medical_records to reference a consultation (already has patient_id)
ALTER TABLE `medical_records`
    ADD COLUMN `consultation_id` BIGINT UNSIGNED DEFAULT NULL AFTER `patient_id`,
    ADD KEY `idx_mr_consultation` (`consultation_id`);

-- Allow patient_files to attach to a consultation too
ALTER TABLE `patient_files`
    ADD COLUMN `consultation_id` BIGINT UNSIGNED DEFAULT NULL AFTER `record_id`,
    ADD KEY `idx_pf_consultation` (`consultation_id`);

SET FOREIGN_KEY_CHECKS = 1;
