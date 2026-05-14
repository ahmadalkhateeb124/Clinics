-- ════════════════════════════════════════════════════════════════════════
-- Nour's Touch — Phase 8 Schema (Public site + CMS)
-- ════════════════════════════════════════════════════════════════════════
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── BLOG CATEGORIES ──────────────────────────────────────────────────────
DROP TABLE IF EXISTS `blog_categories`;
CREATE TABLE `blog_categories` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name_ar`    VARCHAR(150) NOT NULL,
    `name_en`    VARCHAR(150) DEFAULT NULL,
    `slug`       VARCHAR(180) NOT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_bcat_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── BLOG POSTS ───────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `blog_posts`;
CREATE TABLE `blog_posts` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_id`     INT UNSIGNED DEFAULT NULL,
    `author_id`       BIGINT UNSIGNED DEFAULT NULL,
    `title_ar`        VARCHAR(255) NOT NULL,
    `title_en`        VARCHAR(255) DEFAULT NULL,
    `slug`            VARCHAR(280) NOT NULL,
    `excerpt_ar`      TEXT DEFAULT NULL,
    `excerpt_en`      TEXT DEFAULT NULL,
    `content_ar`      LONGTEXT DEFAULT NULL,
    `content_en`      LONGTEXT DEFAULT NULL,
    `featured_image`  VARCHAR(255) DEFAULT NULL,
    `meta_title_ar`   VARCHAR(255) DEFAULT NULL,
    `meta_description_ar` VARCHAR(500) DEFAULT NULL,
    `meta_keywords_ar`    VARCHAR(500) DEFAULT NULL,
    `views`           INT UNSIGNED NOT NULL DEFAULT 0,
    `status`          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    `published_at`    DATETIME DEFAULT NULL,
    `created_by`      BIGINT UNSIGNED DEFAULT NULL,
    `updated_by`      BIGINT UNSIGNED DEFAULT NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_post_slug` (`slug`),
    KEY `idx_post_status` (`status`),
    KEY `idx_post_cat`    (`category_id`),
    CONSTRAINT `fk_post_cat`    FOREIGN KEY (`category_id`) REFERENCES `blog_categories`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_post_author` FOREIGN KEY (`author_id`)   REFERENCES `users`(`id`)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SLIDERS (homepage hero / sections) ───────────────────────────────────
DROP TABLE IF EXISTS `sliders`;
CREATE TABLE `sliders` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title_ar`   VARCHAR(200) NOT NULL,
    `title_en`   VARCHAR(200) DEFAULT NULL,
    `subtitle_ar` VARCHAR(255) DEFAULT NULL,
    `subtitle_en` VARCHAR(255) DEFAULT NULL,
    `image`      VARCHAR(255) DEFAULT NULL,
    `link_url`   VARCHAR(500) DEFAULT NULL,
    `link_text`  VARCHAR(120) DEFAULT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TESTIMONIALS ─────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `testimonials`;
CREATE TABLE `testimonials` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `author`     VARCHAR(150) NOT NULL,
    `role`       VARCHAR(150) DEFAULT NULL,
    `content_ar` TEXT NOT NULL,
    `content_en` TEXT DEFAULT NULL,
    `avatar`     VARCHAR(255) DEFAULT NULL,
    `rating`     TINYINT NOT NULL DEFAULT 5,
    `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── GALLERY ──────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `gallery`;
CREATE TABLE `gallery` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`      VARCHAR(200) DEFAULT NULL,
    `image`      VARCHAR(255) NOT NULL,
    `category`   VARCHAR(80)  DEFAULT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── FAQS ─────────────────────────────────────────────────────────────────
DROP TABLE IF EXISTS `faqs`;
CREATE TABLE `faqs` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `question_ar` VARCHAR(500) NOT NULL,
    `question_en` VARCHAR(500) DEFAULT NULL,
    `answer_ar`  TEXT NOT NULL,
    `answer_en`  TEXT DEFAULT NULL,
    `category`   VARCHAR(80)  DEFAULT NULL,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CMS PAGES (About, Privacy, Terms, custom …) ──────────────────────────
DROP TABLE IF EXISTS `pages`;
CREATE TABLE `pages` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title_ar`        VARCHAR(200) NOT NULL,
    `title_en`        VARCHAR(200) DEFAULT NULL,
    `slug`            VARCHAR(220) NOT NULL,
    `content_ar`      LONGTEXT DEFAULT NULL,
    `content_en`      LONGTEXT DEFAULT NULL,
    `meta_title_ar`   VARCHAR(255) DEFAULT NULL,
    `meta_description_ar` VARCHAR(500) DEFAULT NULL,
    `is_published`    TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_page_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── ONLINE BOOKING REQUESTS (queue → admin converts to appointment) ──────
DROP TABLE IF EXISTS `booking_requests`;
CREATE TABLE `booking_requests` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_name`   VARCHAR(150) NOT NULL,
    `phone`          VARCHAR(30)  NOT NULL,
    `email`          VARCHAR(150) DEFAULT NULL,
    `service_id`     BIGINT UNSIGNED DEFAULT NULL,
    `therapist_id`   BIGINT UNSIGNED DEFAULT NULL,
    `requested_at`   DATETIME NOT NULL,
    `notes`          TEXT DEFAULT NULL,
    `status`         ENUM('pending','contacted','confirmed','converted','rejected') NOT NULL DEFAULT 'pending',
    `appointment_id` BIGINT UNSIGNED DEFAULT NULL,
    `patient_id`     BIGINT UNSIGNED DEFAULT NULL,
    `ip`             VARCHAR(45) DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_br_status` (`status`),
    KEY `idx_br_when`   (`requested_at`),
    CONSTRAINT `fk_br_service`     FOREIGN KEY (`service_id`)     REFERENCES `services`(`id`)     ON DELETE SET NULL,
    CONSTRAINT `fk_br_therapist`   FOREIGN KEY (`therapist_id`)   REFERENCES `users`(`id`)        ON DELETE SET NULL,
    CONSTRAINT `fk_br_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_br_patient`     FOREIGN KEY (`patient_id`)     REFERENCES `patients`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── CONTACT MESSAGES ─────────────────────────────────────────────────────
DROP TABLE IF EXISTS `contact_messages`;
CREATE TABLE `contact_messages` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(150) NOT NULL,
    `email`      VARCHAR(150) DEFAULT NULL,
    `phone`      VARCHAR(30)  DEFAULT NULL,
    `subject`    VARCHAR(200) DEFAULT NULL,
    `message`    TEXT NOT NULL,
    `is_read`    TINYINT(1) NOT NULL DEFAULT 0,
    `ip`         VARCHAR(45)  DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_cm_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PATIENT PORTAL ACCOUNTS (link a patient row to a login user) ─────────
ALTER TABLE `patients` ADD COLUMN `user_id` BIGINT UNSIGNED DEFAULT NULL AFTER `id`;
ALTER TABLE `patients` ADD KEY `idx_p_user` (`user_id`);
ALTER TABLE `patients` ADD CONSTRAINT `fk_p_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;

-- Default custom pages
INSERT IGNORE INTO pages (title_ar, title_en, slug, content_ar, content_en, is_published) VALUES
    ('من نحن', 'About us', 'about',
     '<p>عيادة لمسة نور هي عيادة متعددة الخدمات تقدّم العلاج الطبيعي، تصريف السوائل، أنواع المساج المتعددة، الحجامة، التقشير والاستشارات الطبية المتخصصة.</p>',
     '<p>Nour''s Touch is a multi-service therapy clinic offering physical therapy, lymphatic drainage, all types of massage, cupping, body scrubs and paid consultations.</p>',
     1),
    ('سياسة الخصوصية', 'Privacy Policy', 'privacy',
     '<p>نحترم خصوصية مرضانا ونلتزم بحماية بياناتهم الشخصية.</p>',
     '<p>We respect our patients'' privacy and protect their personal data.</p>',
     1),
    ('الشروط والأحكام', 'Terms & Conditions', 'terms',
     '<p>باستخدامك هذا الموقع، فإنك توافق على شروط الاستخدام التالية.</p>',
     '<p>By using this site, you agree to the following terms.</p>',
     1);
