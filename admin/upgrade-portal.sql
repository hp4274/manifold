-- ==========================================================================
-- Upgrade for databases created with the first version of schema.sql.
-- Adds the applicant portal, the payment step and the new status flow.
--
-- Run once:  mysql -u root -p manifold < upgrade-portal.sql
-- A fresh import of schema.sql already contains everything below.
-- ==========================================================================

USE `manifold`;

-- 1. Widen the status column so both the old and the new values fit ---------
ALTER TABLE `applications`
  MODIFY `status` ENUM('new','accepted','contacted','rejected',
                       'pending','confirmed','payment_pending','complete')
  NOT NULL DEFAULT 'new';

-- 2. Move the old admin statuses onto the new flow --------------------------
UPDATE `applications` SET `status` = 'confirmed' WHERE `status` = 'accepted';
UPDATE `applications` SET `status` = 'pending'   WHERE `status` = 'contacted';

-- 3. Drop the retired values ------------------------------------------------
ALTER TABLE `applications`
  MODIFY `status` ENUM('new','pending','confirmed','payment_pending','complete','rejected')
  NOT NULL DEFAULT 'new';

-- 4. Reference code and payment columns ------------------------------------
ALTER TABLE `applications`
  ADD COLUMN `reference_code`      VARCHAR(20)  NOT NULL DEFAULT '' AFTER `status`,
  ADD COLUMN `payment_reference`   VARCHAR(120) NULL AFTER `terms_accepted`,
  ADD COLUMN `payment_proof_path`  VARCHAR(255) NULL AFTER `payment_reference`,
  ADD COLUMN `payment_uploaded_at` DATETIME     NULL AFTER `payment_proof_path`,
  ADD COLUMN `payment_verified_at` DATETIME     NULL AFTER `payment_uploaded_at`,
  ADD COLUMN `confirmed_at`        DATETIME     NULL AFTER `payment_verified_at`,
  ADD COLUMN `completed_at`        DATETIME     NULL AFTER `confirmed_at`;

-- Back-fill a reference for anything already in the table
UPDATE `applications`
   SET `reference_code` = CONCAT('MF-', DATE_FORMAT(`created_at`, '%Y'), '-', LPAD(`id`, 5, '0'))
 WHERE `reference_code` = '';

ALTER TABLE `applications`
  ADD UNIQUE KEY `uq_app_reference` (`reference_code`);

-- 5. Portal sign-in codes and the email log --------------------------------
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
