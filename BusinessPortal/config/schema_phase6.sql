-- ════════════════════════════════════════════════════════════════════════
-- Nour's Touch — Phase 6 Schema (HR)
-- employees · attendance · leaves · salary_components · advances ·
-- commissions · payslips
-- ════════════════════════════════════════════════════════════════════════
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── EMPLOYEES (1:1 with users) ───────────────────────────────────────────
DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`         BIGINT UNSIGNED NOT NULL,
    `code`            VARCHAR(20) NOT NULL,
    `first_name`      VARCHAR(80) NOT NULL,
    `last_name`       VARCHAR(80) NOT NULL,
    `job_title`       VARCHAR(120) DEFAULT NULL,
    `department`      VARCHAR(80)  DEFAULT NULL,
    `national_id`     VARCHAR(40)  DEFAULT NULL,
    `phone`           VARCHAR(30)  DEFAULT NULL,
    `dob`             DATE         DEFAULT NULL,
    `gender`          ENUM('male','female','other') DEFAULT NULL,
    `address`         VARCHAR(255) DEFAULT NULL,
    `hire_date`       DATE NOT NULL,
    `termination_date` DATE        DEFAULT NULL,
    `contract_type`   ENUM('full_time','part_time','contract','intern') NOT NULL DEFAULT 'full_time',
    `base_salary`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `commission_default_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `bank_name`       VARCHAR(120) DEFAULT NULL,
    `bank_account`    VARCHAR(60)  DEFAULT NULL,
    `iban`            VARCHAR(60)  DEFAULT NULL,
    `emergency_name`  VARCHAR(120) DEFAULT NULL,
    `emergency_phone` VARCHAR(30)  DEFAULT NULL,
    `notes`           TEXT         DEFAULT NULL,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_emp_code` (`code`),
    UNIQUE KEY `uniq_emp_user` (`user_id`),
    KEY `idx_emp_active` (`is_active`),
    KEY `idx_emp_dept`   (`department`),
    CONSTRAINT `fk_emp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── EMPLOYEE DOCUMENTS ───────────────────────────────────────────────────
DROP TABLE IF EXISTS `employee_documents`;
CREATE TABLE `employee_documents` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_id`   BIGINT UNSIGNED NOT NULL,
    `doc_type`      VARCHAR(60) NOT NULL DEFAULT 'general',
    `title`         VARCHAR(200) NOT NULL,
    `file_name`     VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `mime_type`     VARCHAR(100) NOT NULL,
    `size_bytes`    INT UNSIGNED NOT NULL,
    `expires_on`    DATE DEFAULT NULL,
    `uploaded_by`   BIGINT UNSIGNED DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`    DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ed_emp` (`employee_id`),
    CONSTRAINT `fk_ed_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ATTENDANCE ───────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `attendance`;
CREATE TABLE `attendance` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_id`    BIGINT UNSIGNED NOT NULL,
    `work_date`      DATE NOT NULL,
    `check_in`       DATETIME DEFAULT NULL,
    `check_out`      DATETIME DEFAULT NULL,
    `expected_in`    TIME DEFAULT '09:00:00',
    `expected_out`   TIME DEFAULT '17:00:00',
    `late_minutes`   SMALLINT NOT NULL DEFAULT 0,
    `early_leave_minutes` SMALLINT NOT NULL DEFAULT 0,
    `worked_minutes` SMALLINT NOT NULL DEFAULT 0,
    `status`         ENUM('present','absent','half_day','leave','holiday','remote') NOT NULL DEFAULT 'present',
    `source`         ENUM('manual','ip','biometric','self') NOT NULL DEFAULT 'manual',
    `check_in_ip`    VARCHAR(45) DEFAULT NULL,
    `check_out_ip`   VARCHAR(45) DEFAULT NULL,
    `notes`          VARCHAR(255) DEFAULT NULL,
    `created_by`     BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`     BIGINT UNSIGNED DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_att_emp_date` (`employee_id`,`work_date`),
    KEY `idx_att_date`   (`work_date`),
    KEY `idx_att_status` (`status`),
    CONSTRAINT `fk_att_emp` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── LEAVES ───────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `leaves`;
CREATE TABLE `leaves` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_id`    BIGINT UNSIGNED NOT NULL,
    `leave_type`     ENUM('annual','sick','unpaid','maternity','emergency','other') NOT NULL DEFAULT 'annual',
    `start_date`     DATE NOT NULL,
    `end_date`       DATE NOT NULL,
    `days_count`     DECIMAL(4,1) NOT NULL DEFAULT 1,
    `reason`         TEXT DEFAULT NULL,
    `attachment`     VARCHAR(255) DEFAULT NULL,
    `status`         ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `approved_by`    BIGINT UNSIGNED DEFAULT NULL,
    `approved_at`    DATETIME DEFAULT NULL,
    `decision_notes` VARCHAR(255) DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_lv_emp`    (`employee_id`),
    KEY `idx_lv_status` (`status`),
    KEY `idx_lv_range`  (`start_date`,`end_date`),
    CONSTRAINT `fk_lv_emp`      FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lv_approver` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SALARY ADVANCES ──────────────────────────────────────────────────────
DROP TABLE IF EXISTS `advances`;
CREATE TABLE `advances` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_id`    BIGINT UNSIGNED NOT NULL,
    `amount`         DECIMAL(12,2) NOT NULL,
    `request_date`   DATE NOT NULL,
    `disbursed_at`   DATETIME DEFAULT NULL,
    `reason`         VARCHAR(255) DEFAULT NULL,
    `status`         ENUM('pending','approved','disbursed','rejected','deducted') NOT NULL DEFAULT 'pending',
    `expense_id`     BIGINT UNSIGNED DEFAULT NULL,   -- linked to finance.expenses
    `payslip_id`     BIGINT UNSIGNED DEFAULT NULL,   -- which payslip deducted it
    `approved_by`    BIGINT UNSIGNED DEFAULT NULL,
    `approved_at`    DATETIME DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_adv_emp`    (`employee_id`),
    KEY `idx_adv_status` (`status`),
    CONSTRAINT `fk_adv_emp`      FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_adv_expense`  FOREIGN KEY (`expense_id`)  REFERENCES `expenses`(`id`)  ON DELETE SET NULL,
    CONSTRAINT `fk_adv_approver` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PAYSLIPS ─────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `payslips`;
CREATE TABLE `payslips` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payslip_no`      VARCHAR(40) NOT NULL,
    `employee_id`     BIGINT UNSIGNED NOT NULL,
    `period_year`     SMALLINT NOT NULL,
    `period_month`    TINYINT  NOT NULL,
    `working_days`    DECIMAL(5,1) NOT NULL DEFAULT 0,
    `present_days`    DECIMAL(5,1) NOT NULL DEFAULT 0,
    `absent_days`     DECIMAL(5,1) NOT NULL DEFAULT 0,
    `leave_days`      DECIMAL(5,1) NOT NULL DEFAULT 0,
    `late_minutes`    INT NOT NULL DEFAULT 0,
    `base_salary`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `commissions`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    `bonuses`         DECIMAL(12,2) NOT NULL DEFAULT 0,
    `deductions`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `advances_deduct` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `gross_salary`    DECIMAL(12,2) NOT NULL DEFAULT 0,
    `net_salary`      DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status`          ENUM('draft','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
    `paid_at`         DATETIME DEFAULT NULL,
    `payment_method`  ENUM('cash','card','bank','online','other') DEFAULT 'bank',
    `reference_no`    VARCHAR(80) DEFAULT NULL,
    `expense_id`      BIGINT UNSIGNED DEFAULT NULL,   -- when posted to expenses
    `notes`           VARCHAR(255) DEFAULT NULL,
    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_payslip_no` (`payslip_no`),
    UNIQUE KEY `uniq_emp_period` (`employee_id`,`period_year`,`period_month`),
    KEY `idx_ps_status` (`status`),
    CONSTRAINT `fk_ps_emp`     FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ps_expense` FOREIGN KEY (`expense_id`)  REFERENCES `expenses`(`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PAYSLIP COMPONENTS (line-items: bonus, deduction, allowance, …) ──────
DROP TABLE IF EXISTS `payslip_components`;
CREATE TABLE `payslip_components` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `payslip_id`  BIGINT UNSIGNED NOT NULL,
    `kind`        ENUM('earning','deduction') NOT NULL,
    `label`       VARCHAR(120) NOT NULL,
    `amount`      DECIMAL(12,2) NOT NULL,
    `notes`       VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_pc_payslip` (`payslip_id`),
    CONSTRAINT `fk_pc_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── COMMISSIONS LEDGER ───────────────────────────────────────────────────
-- One row per completed appointment / consultation that earns commission.
DROP TABLE IF EXISTS `commissions`;
CREATE TABLE `commissions` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_id`     BIGINT UNSIGNED NOT NULL,
    `appointment_id`  BIGINT UNSIGNED DEFAULT NULL,
    `consultation_id` BIGINT UNSIGNED DEFAULT NULL,
    `service_id`      BIGINT UNSIGNED DEFAULT NULL,
    `earned_on`       DATE NOT NULL,
    `service_price`   DECIMAL(12,2) NOT NULL DEFAULT 0,
    `pct`             DECIMAL(5,2)  NOT NULL DEFAULT 0,
    `amount`          DECIMAL(12,2) NOT NULL DEFAULT 0,
    `payslip_id`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cm_emp`     (`employee_id`),
    KEY `idx_cm_date`    (`earned_on`),
    KEY `idx_cm_payslip` (`payslip_id`),
    CONSTRAINT `fk_cm_emp`     FOREIGN KEY (`employee_id`)     REFERENCES `employees`(`id`)     ON DELETE CASCADE,
    CONSTRAINT `fk_cm_appt`    FOREIGN KEY (`appointment_id`)  REFERENCES `appointments`(`id`)  ON DELETE SET NULL,
    CONSTRAINT `fk_cm_cs`      FOREIGN KEY (`consultation_id`) REFERENCES `consultations`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cm_payslip` FOREIGN KEY (`payslip_id`)      REFERENCES `payslips`(`id`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
