-- ==========================================================================
-- Manifold Clean Energy — admin database schema
-- MySQL 5.7+ / MariaDB 10.2+
--
-- Import once:  mysql -u root -p < schema.sql
-- or paste into phpMyAdmin > SQL.
-- ==========================================================================

CREATE DATABASE IF NOT EXISTS `manifold`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `manifold`;

-- --------------------------------------------------------------------------
-- Admin users
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120)  NOT NULL,
  `email`         VARCHAR(190)  NOT NULL,
  `password_hash` VARCHAR(255)  NOT NULL,
  `is_active`     TINYINT(1)    NOT NULL DEFAULT 1,
  `last_login_at` DATETIME      NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Product applications — both apply forms live here, split by `product`
-- Column names match the `name` attributes on apply-stove.html /
-- apply-tuktuk.html so the two stay in sync.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `applications` (
  `id`                           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product`                      ENUM('stove','tuktuk') NOT NULL,
  -- new → pending → confirmed → payment_pending → complete (or rejected)
  `status`                       ENUM('new','pending','confirmed','payment_pending','complete','rejected') NOT NULL DEFAULT 'new',
  -- quoted to the applicant; how they find their application in the portal
  `reference_code`               VARCHAR(20) NOT NULL,

  -- 1. applicant
  `full_name`                    VARCHAR(160) NOT NULL,
  `date_of_birth`                DATE         NULL,
  `nationality`                  VARCHAR(80)  NULL,
  `gender`                       VARCHAR(30)  NULL,
  `occupation`                   VARCHAR(120) NULL,
  `mobile_number`                VARCHAR(32)  NOT NULL,
  `alt_mobile_number`            VARCHAR(32)  NULL,
  `email`                        VARCHAR(190) NOT NULL,

  -- 2. identification
  `id_number`                    VARCHAR(80)  NULL,
  `id_document_path`             VARCHAR(255) NULL,
  `residence_proof_path`         VARCHAR(255) NULL,

  -- 3. address
  `house_number`                 VARCHAR(120) NULL,
  `street`                       VARCHAR(160) NULL,
  `city`                         VARCHAR(120) NULL,
  `state`                        VARCHAR(120) NULL,
  `country`                      VARCHAR(120) NULL,
  `pin_code`                     VARCHAR(20)  NULL,

  -- 4. property / operator
  `property_type`                VARCHAR(80)  NULL,
  `property_type_other`          VARCHAR(160) NULL,
  `ownership_status`             VARCHAR(40)  NULL,
  `household_members`            INT          NULL,
  `existing_fuel`                VARCHAR(80)  NULL,
  `existing_fuel_other`          VARCHAR(160) NULL,

  -- 5. product requirement
  `units_required`               INT          NULL,
  `intended_usage`               VARCHAR(60)  NULL,
  `expected_daily_usage`         VARCHAR(120) NULL,
  `preferred_install_date`       DATE         NULL,

  -- 6. water supply
  `water_source`                 VARCHAR(80)  NULL,
  `water_source_other`           VARCHAR(160) NULL,
  `continuous_water`             ENUM('yes','no') NULL,
  `water_storage`                ENUM('yes','no') NULL,

  -- 7. technical assessment
  `dedicated_kitchen`            ENUM('yes','no') NULL,
  `countertop_space`             ENUM('yes','no') NULL,
  `existing_gas`                 ENUM('yes','no') NULL,
  `existing_electric`            ENUM('yes','no') NULL,

  -- 8. payment
  `payment_method`               VARCHAR(60)  NULL,
  `financing_option`             VARCHAR(160) NULL,
  `bank_name`                    VARCHAR(160) NULL,

  -- 9. referral
  `referral_source`              VARCHAR(80)  NULL,
  `referral_other`               VARCHAR(160) NULL,

  -- 10. environmental
  `monthly_gas_consumption`      VARCHAR(120) NULL,
  `monthly_electric_consumption` VARCHAR(120) NULL,
  `carbon_interest`              VARCHAR(20)  NULL,

  -- 11 & 12. consent
  `declaration_accepted`         TINYINT(1) NOT NULL DEFAULT 0,
  `testimonial_consent`          TINYINT(1) NOT NULL DEFAULT 0,
  `terms_accepted`               TINYINT(1) NOT NULL DEFAULT 0,

  -- payment (filled once the application is confirmed)
  `payment_reference`            VARCHAR(120) NULL,
  `payment_proof_path`           VARCHAR(255) NULL,
  `payment_uploaded_at`          DATETIME     NULL,
  `payment_verified_at`          DATETIME     NULL,
  `confirmed_at`                 DATETIME     NULL,
  `completed_at`                 DATETIME     NULL,

  -- admin
  `admin_note`                   TEXT      NULL,
  `ip_address`                   VARCHAR(45) NULL,
  `created_at`                   DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                   DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_reference` (`reference_code`),
  KEY `ix_app_product_status` (`product`, `status`),
  KEY `ix_app_created` (`created_at`),
  KEY `ix_app_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Contact enquiries — contact.html
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status`     ENUM('new','accepted','contacted','rejected') NOT NULL DEFAULT 'new',
  `name`       VARCHAR(160) NOT NULL,
  `company`    VARCHAR(160) NULL,
  `email`      VARCHAR(190) NOT NULL,
  `phone`      VARCHAR(32)  NOT NULL,
  `interest`   VARCHAR(60)  NULL,
  `city`       VARCHAR(120) NULL,
  `message`    TEXT         NOT NULL,
  `consent`    TINYINT(1)   NOT NULL DEFAULT 0,
  `admin_note` TEXT         NULL,
  `ip_address` VARCHAR(45)  NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_contact_status` (`status`),
  KEY `ix_contact_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Newsletter subscribers — footer form on every page
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status`      ENUM('new','accepted','contacted','rejected') NOT NULL DEFAULT 'new',
  `email`       VARCHAR(190) NOT NULL,
  `source_page` VARCHAR(120) NULL,
  `admin_note`  TEXT         NULL,
  `ip_address`  VARCHAR(45)  NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscriber_email` (`email`),
  KEY `ix_subscriber_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Audit trail for every status change
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `status_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity`     ENUM('application','contact','newsletter') NOT NULL,
  `entity_id`  INT UNSIGNED NOT NULL,
  `old_status` VARCHAR(20)  NULL,
  `new_status` VARCHAR(20)  NOT NULL,
  `changed_by` INT UNSIGNED NULL,
  `changed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_log_entity` (`entity`, `entity_id`),
  CONSTRAINT `fk_log_admin` FOREIGN KEY (`changed_by`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Applicant portal: one-time codes emailed for sign-in
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `applicant_otps` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(190) NOT NULL,
  `code_hash`  VARCHAR(255) NOT NULL,
  `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `used_at`    DATETIME     NULL,
  `expires_at` DATETIME     NOT NULL,
  `ip_address` VARCHAR(45)  NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_otp_email` (`email`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Every email the site tries to send, so failures are visible
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `to_email`   VARCHAR(190) NOT NULL,
  `subject`    VARCHAR(255) NOT NULL,
  `kind`       VARCHAR(40)  NOT NULL,
  `ok`         TINYINT(1)   NOT NULL DEFAULT 0,
  `error`      TEXT         NULL,
  `sent_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_email_kind` (`kind`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Failed login throttling
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(190) NOT NULL,
  `ip_address` VARCHAR(45)  NULL,
  `attempted_at` DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_attempt_email_time` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
