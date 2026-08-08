-- ==========================================================================
-- Instalments: an applicant may pay the fee in several transfers, each with
-- its own receipt. Payments move out of the applications row into their own
-- table so four ₹1,000 transfers produce four receipts.
--
-- Run once:  mysql -u root -p manifold < upgrade-instalments.sql
-- ==========================================================================

USE `manifold`;

CREATE TABLE IF NOT EXISTS `payments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `application_id` INT UNSIGNED NOT NULL,
  `amount`         DECIMAL(10,2) NOT NULL,
  `reference`      VARCHAR(120) NULL,
  `proof_path`     VARCHAR(255) NULL,
  `status`         ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `receipt_no`     VARCHAR(30)  NULL,
  `reject_reason`  VARCHAR(255) NULL,
  `uploaded_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at`     DATETIME     NULL,
  `decided_by`     INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `ix_pay_app` (`application_id`, `status`),
  CONSTRAINT `fk_pay_app` FOREIGN KEY (`application_id`)
    REFERENCES `applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pay_admin` FOREIGN KEY (`decided_by`)
    REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carry any receipt already attached to an application into the new table.
INSERT INTO `payments` (`application_id`, `amount`, `reference`, `proof_path`, `status`,
                        `receipt_no`, `uploaded_at`, `decided_at`)
SELECT `id`,
       `payment_amount`,
       `payment_reference`,
       `payment_proof_path`,
       CASE WHEN `payment_verified_at` IS NOT NULL THEN 'verified' ELSE 'pending' END,
       CASE WHEN `payment_verified_at` IS NOT NULL THEN CONCAT(`reference_code`, '-R1') ELSE NULL END,
       COALESCE(`payment_uploaded_at`, `created_at`),
       `payment_verified_at`
  FROM `applications`
 WHERE `payment_proof_path` IS NOT NULL
    OR `payment_verified_at` IS NOT NULL;
