-- ════════════════════════════════════════════════════════════════════════
-- Nour's Touch — Phase 3 Schema
-- Rooms · Therapists (lightweight) · Appointments · Appointment Services
-- (installments table is light here; full finance schema arrives in Phase 5)
-- ════════════════════════════════════════════════════════════════════════
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── ROOMS / BEDS ─────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `rooms`;
CREATE TABLE `rooms` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(80) NOT NULL,
    `type`       ENUM('room','bed','other') NOT NULL DEFAULT 'room',
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `notes`      VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_rooms_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── APPOINTMENTS ─────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `appointments`;
CREATE TABLE `appointments` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`      BIGINT UNSIGNED NOT NULL,
    `therapist_id`    BIGINT UNSIGNED DEFAULT NULL,    -- references users.id (role: doctor/therapist)
    `room_id`         INT UNSIGNED DEFAULT NULL,
    `patient_package_id` BIGINT UNSIGNED DEFAULT NULL, -- if booking against an active package
    `start_at`        DATETIME NOT NULL,
    `end_at`          DATETIME NOT NULL,
    `duration_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    `status`          ENUM('scheduled','confirmed','completed','no_show','cancelled')
                      NOT NULL DEFAULT 'scheduled',
    `total_price`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `notes`           TEXT DEFAULT NULL,
    `cancel_reason`   VARCHAR(255) DEFAULT NULL,
    `is_block_overridden` TINYINT(1) NOT NULL DEFAULT 0,
    `override_reason` VARCHAR(255) DEFAULT NULL,
    `confirmed_at`    DATETIME DEFAULT NULL,
    `completed_at`    DATETIME DEFAULT NULL,
    `cancelled_at`    DATETIME DEFAULT NULL,
    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_appt_patient`   (`patient_id`),
    KEY `idx_appt_therapist` (`therapist_id`),
    KEY `idx_appt_room`      (`room_id`),
    KEY `idx_appt_status`    (`status`),
    KEY `idx_appt_start`     (`start_at`),
    KEY `idx_appt_range`     (`start_at`,`end_at`),
    CONSTRAINT `fk_appt_patient`   FOREIGN KEY (`patient_id`)   REFERENCES `patients`(`id`)         ON DELETE CASCADE,
    CONSTRAINT `fk_appt_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `users`(`id`)            ON DELETE SET NULL,
    CONSTRAINT `fk_appt_room`      FOREIGN KEY (`room_id`)      REFERENCES `rooms`(`id`)            ON DELETE SET NULL,
    CONSTRAINT `fk_appt_pp`        FOREIGN KEY (`patient_package_id`) REFERENCES `patient_packages`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── APPOINTMENT ↔ SERVICES ───────────────────────────────────────────────
DROP TABLE IF EXISTS `appointment_services`;
CREATE TABLE `appointment_services` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `appointment_id` BIGINT UNSIGNED NOT NULL,
    `service_id`     BIGINT UNSIGNED NOT NULL,
    `price`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,   -- price snapshot at booking
    `commission_pct` DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    `duration_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    PRIMARY KEY (`id`),
    KEY `idx_as_appt` (`appointment_id`),
    KEY `idx_as_svc`  (`service_id`),
    CONSTRAINT `fk_as_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_as_svc`  FOREIGN KEY (`service_id`)     REFERENCES `services`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── INSTALLMENTS (Phase-3 stub: due_date drives the hard-block rule) ─────
DROP TABLE IF EXISTS `installments`;
CREATE TABLE `installments` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id`    BIGINT UNSIGNED NOT NULL,
    `invoice_id`    BIGINT UNSIGNED DEFAULT NULL,
    `amount`        DECIMAL(10,2) NOT NULL,
    `paid_amount`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `due_date`      DATE NOT NULL,
    `paid_at`       DATETIME DEFAULT NULL,
    `status`        ENUM('pending','paid','overdue','waived') NOT NULL DEFAULT 'pending',
    `notes`         VARCHAR(255) DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_inst_patient` (`patient_id`),
    KEY `idx_inst_status`  (`status`),
    KEY `idx_inst_due`     (`due_date`),
    CONSTRAINT `fk_inst_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ── Default rooms ────────────────────────────────────────────────────────
INSERT IGNORE INTO rooms (name, type, sort_order) VALUES
    ('غرفة 1', 'room', 1),
    ('غرفة 2', 'room', 2),
    ('غرفة 3', 'room', 3),
    ('سرير المساج 1', 'bed', 4),
    ('سرير المساج 2', 'bed', 5);
