-- ════════════════════════════════════════════════════════════════════════
-- Nour's Touch — Phase 5 Schema (Finance)
-- invoices · invoice_items · payments · expenses · expense_categories
-- refunds · cash_drawer_sessions
-- (installments table already exists from Phase 3 — extended here)
-- ════════════════════════════════════════════════════════════════════════
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── EXPENSE CATEGORIES ───────────────────────────────────────────────────
DROP TABLE IF EXISTS `expense_categories`;
CREATE TABLE `expense_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar`    VARCHAR(120) NOT NULL,
    `name_en`    VARCHAR(120) DEFAULT NULL,
    `slug`       VARCHAR(140) NOT NULL,
    `icon`       VARCHAR(60)  DEFAULT NULL,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_excat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── EXPENSES ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id`    INT UNSIGNED DEFAULT NULL,
    `title`          VARCHAR(200) NOT NULL,
    `amount`         DECIMAL(10,2) NOT NULL,
    `expense_date`   DATE NOT NULL,
    `payment_method` ENUM('cash','card','bank','online','other') NOT NULL DEFAULT 'cash',
    `vendor`         VARCHAR(200) DEFAULT NULL,
    `reference_no`   VARCHAR(80)  DEFAULT NULL,
    `attachment`     VARCHAR(255) DEFAULT NULL,
    `notes`          TEXT DEFAULT NULL,
    `linked_payroll_id` BIGINT UNSIGNED DEFAULT NULL,    -- filled in Phase 6
    `created_by`     BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`     BIGINT UNSIGNED DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_expense_cat`  (`category_id`),
    KEY `idx_expense_date` (`expense_date`),
    KEY `idx_expense_method` (`payment_method`),
    CONSTRAINT `fk_exp_cat` FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── INVOICES ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_no`      VARCHAR(30) NOT NULL,
    `patient_id`      BIGINT UNSIGNED NOT NULL,
    `appointment_id`  BIGINT UNSIGNED DEFAULT NULL,
    `consultation_id` BIGINT UNSIGNED DEFAULT NULL,
    `patient_package_id` BIGINT UNSIGNED DEFAULT NULL,
    `issue_date`      DATE NOT NULL,
    `due_date`        DATE DEFAULT NULL,
    `subtotal`        DECIMAL(12,2) NOT NULL DEFAULT 0,
    `discount`        DECIMAL(12,2) NOT NULL DEFAULT 0,
    `tax`             DECIMAL(12,2) NOT NULL DEFAULT 0,
    `total`           DECIMAL(12,2) NOT NULL DEFAULT 0,
    `paid_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `balance`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status`          ENUM('draft','issued','partial','paid','refunded','cancelled')
                      NOT NULL DEFAULT 'draft',
    `currency`        VARCHAR(8) NOT NULL DEFAULT 'JOD',
    `notes`           TEXT DEFAULT NULL,
    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_invoice_no` (`invoice_no`),
    KEY `idx_inv_patient` (`patient_id`),
    KEY `idx_inv_status`  (`status`),
    KEY `idx_inv_issue`   (`issue_date`),
    KEY `idx_inv_due`     (`due_date`),
    KEY `idx_inv_appt`    (`appointment_id`),
    KEY `idx_inv_cs`      (`consultation_id`),
    CONSTRAINT `fk_inv_patient`      FOREIGN KEY (`patient_id`)         REFERENCES `patients`(`id`)         ON DELETE CASCADE,
    CONSTRAINT `fk_inv_appt`         FOREIGN KEY (`appointment_id`)     REFERENCES `appointments`(`id`)     ON DELETE SET NULL,
    CONSTRAINT `fk_inv_consultation` FOREIGN KEY (`consultation_id`)    REFERENCES `consultations`(`id`)    ON DELETE SET NULL,
    CONSTRAINT `fk_inv_pp`           FOREIGN KEY (`patient_package_id`) REFERENCES `patient_packages`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── INVOICE ITEMS ────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `invoice_items`;
CREATE TABLE `invoice_items` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id`   BIGINT UNSIGNED NOT NULL,
    `service_id`   BIGINT UNSIGNED DEFAULT NULL,
    `package_id`   BIGINT UNSIGNED DEFAULT NULL,
    `description`  VARCHAR(255) NOT NULL,
    `quantity`     DECIMAL(10,2) NOT NULL DEFAULT 1,
    `unit_price`   DECIMAL(10,2) NOT NULL DEFAULT 0,
    `discount`     DECIMAL(10,2) NOT NULL DEFAULT 0,
    `total`        DECIMAL(12,2) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_ii_invoice` (`invoice_id`),
    CONSTRAINT `fk_ii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ii_service` FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ii_package` FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PAYMENTS ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `receipt_no`     VARCHAR(30) NOT NULL,
    `invoice_id`     BIGINT UNSIGNED DEFAULT NULL,
    `patient_id`     BIGINT UNSIGNED NOT NULL,
    `amount`         DECIMAL(12,2) NOT NULL,
    `method`         ENUM('cash','card','bank','online','other') NOT NULL DEFAULT 'cash',
    `reference_no`   VARCHAR(80) DEFAULT NULL,
    `paid_at`        DATETIME NOT NULL,
    `cash_drawer_session_id` BIGINT UNSIGNED DEFAULT NULL,
    `notes`          VARCHAR(255) DEFAULT NULL,
    `is_refund`      TINYINT(1) NOT NULL DEFAULT 0,
    `installment_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_by`     BIGINT UNSIGNED DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_receipt_no` (`receipt_no`),
    KEY `idx_pay_invoice` (`invoice_id`),
    KEY `idx_pay_patient` (`patient_id`),
    KEY `idx_pay_method`  (`method`),
    KEY `idx_pay_paid_at` (`paid_at`),
    CONSTRAINT `fk_pay_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_pay_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pay_inst`    FOREIGN KEY (`installment_id`) REFERENCES `installments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── REFUNDS / CREDIT NOTES ───────────────────────────────────────────────
DROP TABLE IF EXISTS `refunds`;
CREATE TABLE `refunds` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `refund_no`      VARCHAR(30) NOT NULL,
    `invoice_id`     BIGINT UNSIGNED NOT NULL,
    `patient_id`     BIGINT UNSIGNED NOT NULL,
    `payment_id`     BIGINT UNSIGNED DEFAULT NULL,
    `amount`         DECIMAL(12,2) NOT NULL,
    `method`         ENUM('cash','card','bank','online','other') NOT NULL DEFAULT 'cash',
    `reason`         VARCHAR(255) DEFAULT NULL,
    `refunded_at`    DATETIME NOT NULL,
    `created_by`     BIGINT UNSIGNED DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_refund_no` (`refund_no`),
    KEY `idx_ref_invoice` (`invoice_id`),
    KEY `idx_ref_patient` (`patient_id`),
    CONSTRAINT `fk_ref_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ref_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ref_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CASH DRAWER SESSIONS (shift open/close) ──────────────────────────────
DROP TABLE IF EXISTS `cash_drawer_sessions`;
CREATE TABLE `cash_drawer_sessions` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        BIGINT UNSIGNED NOT NULL,
    `opened_at`      DATETIME NOT NULL,
    `closed_at`      DATETIME DEFAULT NULL,
    `opening_float`  DECIMAL(12,2) NOT NULL DEFAULT 0,
    `expected_cash`  DECIMAL(12,2) NOT NULL DEFAULT 0,
    `counted_cash`   DECIMAL(12,2) NOT NULL DEFAULT 0,
    `variance`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `notes`          VARCHAR(255) DEFAULT NULL,
    `status`         ENUM('open','closed') NOT NULL DEFAULT 'open',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cd_user`   (`user_id`),
    KEY `idx_cd_status` (`status`),
    KEY `idx_cd_opened` (`opened_at`),
    CONSTRAINT `fk_cd_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Make sure we can link consultations.invoice_id (already exists from Phase 4 schema)
-- and link installments.invoice_id (already exists from Phase 3 stub) — both fine.

SET FOREIGN_KEY_CHECKS = 1;

-- Default expense categories
INSERT IGNORE INTO expense_categories (name_ar, name_en, slug, icon, sort_order) VALUES
    ('إيجار',           'Rent',        'rent',        'fa-house',         1),
    ('مرافق',           'Utilities',   'utilities',   'fa-bolt',          2),
    ('مستلزمات',        'Supplies',    'supplies',    'fa-box',           3),
    ('تسويق',           'Marketing',   'marketing',   'fa-bullhorn',      4),
    ('رواتب',           'Salaries',    'salaries',    'fa-money-check-dollar', 5),
    ('صيانة',           'Maintenance', 'maintenance', 'fa-screwdriver-wrench', 6),
    ('متفرقات',         'Miscellaneous','misc',       'fa-ellipsis',      7);
