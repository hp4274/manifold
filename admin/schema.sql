-- ==========================================================================
-- Manifold Clean Energy — the whole database, in one file
--
-- Creates the `manifold` database with empty tables, one admin account and the
-- default settings. This is the only SQL file the project keeps: there are no
-- migrations to run in order, so whatever is here is the current structure.
--
--   WARNING: this DROPS the existing `manifold` database. Everything in it —
--   applications, payments, receipts, enquiries — is destroyed. Take a backup
--   first if this is not a clean machine.
--
-- Import:  mysql -u root -p < schema.sql
--
-- Then sign in at /manifold/admin/login.php with
--   username  admin        (or admin@manifold.com)
--   password  admin12345
--
-- CHANGE THAT PASSWORD before this touches a real server.
-- ==========================================================================

DROP DATABASE IF EXISTS `manifold`;

CREATE DATABASE `manifold`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `manifold`;

-- --------------------------------------------------------------------------
-- Admin accounts for the submissions dashboard.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Both apply forms. Column names match the `name` attributes on
-- apply-stove.html / apply-tuktuk.html.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product` enum('stove','tuktuk') NOT NULL,
  `status` enum('payment_pending','payment_review','complete','rejected') NOT NULL DEFAULT 'payment_pending',
  `reference_code` varchar(20) NOT NULL DEFAULT '',
  `referral_code` varchar(20) NOT NULL DEFAULT '',
  `referred_by_code` varchar(20) DEFAULT NULL,
  `referred_by_id` int(10) unsigned DEFAULT NULL,
  `referral_reward` decimal(10,2) NOT NULL DEFAULT 0.00,
  `referral_reward_status` enum('none','pending','sent','cancelled') NOT NULL DEFAULT 'none',
  `referral_reward_sent_at` datetime DEFAULT NULL,
  `referral_reward_note` varchar(255) DEFAULT NULL,
  `referral_reward_by` int(10) unsigned DEFAULT NULL,
  `full_name` varchar(160) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `nationality` varchar(80) DEFAULT NULL,
  `gender` varchar(30) DEFAULT NULL,
  `occupation` varchar(120) DEFAULT NULL,
  `mobile_number` varchar(32) NOT NULL,
  `alt_mobile_number` varchar(32) DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `id_number` varchar(80) DEFAULT NULL,
  `id_document_path` varchar(255) DEFAULT NULL,
  `residence_proof_path` varchar(255) DEFAULT NULL,
  `house_number` varchar(120) DEFAULT NULL,
  `street` varchar(160) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  `pin_code` varchar(20) DEFAULT NULL,
  `property_type` varchar(80) DEFAULT NULL,
  `property_type_other` varchar(160) DEFAULT NULL,
  `ownership_status` varchar(40) DEFAULT NULL,
  `household_members` int(11) DEFAULT NULL,
  `existing_fuel` varchar(80) DEFAULT NULL,
  `existing_fuel_other` varchar(160) DEFAULT NULL,
  `units_required` int(11) DEFAULT NULL,
  `intended_usage` varchar(60) DEFAULT NULL,
  `expected_daily_usage` varchar(120) DEFAULT NULL,
  `preferred_install_date` date DEFAULT NULL,
  `water_source` varchar(80) DEFAULT NULL,
  `water_source_other` varchar(160) DEFAULT NULL,
  `continuous_water` enum('yes','no') DEFAULT NULL,
  `water_storage` enum('yes','no') DEFAULT NULL,
  `dedicated_kitchen` enum('yes','no') DEFAULT NULL,
  `countertop_space` enum('yes','no') DEFAULT NULL,
  `existing_gas` enum('yes','no') DEFAULT NULL,
  `existing_electric` enum('yes','no') DEFAULT NULL,
  `payment_method` varchar(60) DEFAULT NULL,
  `financing_option` varchar(160) DEFAULT NULL,
  `bank_name` varchar(160) DEFAULT NULL,
  `referral_source` varchar(80) DEFAULT NULL,
  `referral_other` varchar(160) DEFAULT NULL,
  `monthly_gas_consumption` varchar(120) DEFAULT NULL,
  `monthly_electric_consumption` varchar(120) DEFAULT NULL,
  `carbon_interest` varchar(20) DEFAULT NULL,
  `declaration_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `testimonial_consent` tinyint(1) NOT NULL DEFAULT 0,
  `terms_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `payment_amount` decimal(10,2) NOT NULL DEFAULT 3500.00,
  `payment_reference` varchar(120) DEFAULT NULL,
  `payment_proof_path` varchar(255) DEFAULT NULL,
  `payment_uploaded_at` datetime DEFAULT NULL,
  `payment_verified_at` datetime DEFAULT NULL,
  `payment_rejected_at` datetime DEFAULT NULL,
  `payment_reject_reason` varchar(255) DEFAULT NULL,
  `reminded_at` datetime DEFAULT NULL,
  `reminder_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `confirmed_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_reference` (`reference_code`),
  UNIQUE KEY `uq_app_referral` (`referral_code`),
  KEY `ix_app_product_status` (`product`,`status`),
  KEY `ix_app_created` (`created_at`),
  KEY `ix_app_email` (`email`),
  KEY `ix_app_referred_by` (`referred_by_id`),
  KEY `ix_app_reward_status` (`referral_reward_status`),
  KEY `fk_app_reward_admin` (`referral_reward_by`),
  CONSTRAINT `fk_app_referrer` FOREIGN KEY (`referred_by_id`) REFERENCES `applications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_reward_admin` FOREIGN KEY (`referral_reward_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- One row per transfer, so the fee can be paid in instalments and
-- each verified payment gets its own receipt.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `receipt_no` varchar(30) DEFAULT NULL,
  `reject_reason` varchar(255) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_at` datetime DEFAULT NULL,
  `decided_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_pay_app` (`application_id`,`status`),
  KEY `fk_pay_admin` (`decided_by`),
  CONSTRAINT `fk_pay_admin` FOREIGN KEY (`decided_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pay_app` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Contact enquiries from contact.html.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `status` enum('new','accepted','contacted','rejected') NOT NULL DEFAULT 'new',
  `name` varchar(160) NOT NULL,
  `company` varchar(160) DEFAULT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(32) NOT NULL,
  `interest` varchar(60) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `message` text NOT NULL,
  `consent` tinyint(1) NOT NULL DEFAULT 0,
  `admin_note` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_contact_status` (`status`),
  KEY `ix_contact_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Footer newsletter signups.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `status` enum('new','accepted','contacted','rejected') NOT NULL DEFAULT 'new',
  `email` varchar(190) NOT NULL,
  `source_page` varchar(120) DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subscriber_email` (`email`),
  KEY `ix_subscriber_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Audit trail of every status change.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `status_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `entity` enum('application','contact','newsletter','raffle_winner') NOT NULL,
  `entity_id` int(10) unsigned NOT NULL,
  `old_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) NOT NULL,
  `changed_by` int(10) unsigned DEFAULT NULL,
  `changed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_log_entity` (`entity`,`entity_id`),
  KEY `fk_log_admin` (`changed_by`),
  CONSTRAINT `fk_log_admin` FOREIGN KEY (`changed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- One-time codes for the applicant portal sign-in.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `applicant_otps` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_otp_email` (`email`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Every email the site tried to send, successful or not.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `to_email` varchar(190) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `kind` varchar(40) NOT NULL,
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `error` text DEFAULT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_email_kind` (`kind`,`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Failed admin logins, used for throttling.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_attempt_email_time` (`email`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Blog posts shown on the home page above the call to action.
-- 'scheduled' is 'published' with a date in the future: the post appears on
-- its own once publish_at passes.
-- --------------------------------------------------------------------------
CREATE TABLE `blog_posts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(160) NOT NULL,
  `title` varchar(200) NOT NULL,
  `subtitle` varchar(300) DEFAULT NULL,
  `body` mediumtext NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('draft','scheduled','published','unpublished') NOT NULL DEFAULT 'draft',
  `publish_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blog_slug` (`slug`),
  KEY `ix_blog_live` (`status`,`publish_at`),
  KEY `fk_blog_author` (`created_by`),
  CONSTRAINT `fk_blog_author` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Raffle
--
-- Every cycle (90 days as advertised) the office draws winners from the
-- applicants who have paid in full. Each takes one gram of pure gold or the
-- cash value of it, less the discount in `settings` below.
--
-- One row per cycle in `raffle_draws`, one per place in `raffle_winners`.
-- Nobody is picked automatically: the office holds the draw in front of
-- witnesses and records each winner by hand from the Raffle screen, searching
-- by name, reference code or mobile number. A list appears on the website once
-- its draw's reveal_at has passed.
--
-- Several columns here are left from an earlier version that drew winners
-- automatically and tracked what each of them took. Nothing reads or writes
-- them now, and they are kept only so this file still describes a database
-- that has been running:
--
--   raffle_draws     pool_size, drawn_at, drawn_by
--   raffle_winners   prize_choice, cash_amount, payout_status, paid_at,
--                    note, shuffles
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `raffle_draws` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `draw_no` int(10) unsigned NOT NULL,
  `reveal_at` datetime NOT NULL,
  `winner_count` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `gold_grams` decimal(6,3) NOT NULL DEFAULT 1.000,
  `gold_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pool_size` int(10) unsigned NOT NULL DEFAULT 0,
  `drawn_at` datetime DEFAULT NULL,
  `drawn_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_raffle_draw_no` (`draw_no`),
  KEY `ix_raffle_reveal` (`reveal_at`),
  KEY `fk_raffle_drawn_by` (`drawn_by`),
  CONSTRAINT `fk_raffle_drawn_by` FOREIGN KEY (`drawn_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A place is unique twice over: one person per place, and one place per person.
CREATE TABLE IF NOT EXISTS `raffle_winners` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `draw_id` int(10) unsigned NOT NULL,
  `application_id` int(10) unsigned NOT NULL,
  `position` tinyint(3) unsigned NOT NULL,
  `prize_choice` enum('undecided','gold','cash') NOT NULL DEFAULT 'undecided',
  `cash_amount` decimal(10,2) DEFAULT NULL,
  `payout_status` enum('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `shuffles` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_raffle_position` (`draw_id`,`position`),
  UNIQUE KEY `uq_raffle_application` (`draw_id`,`application_id`),
  KEY `ix_raffle_winner_app` (`application_id`),
  CONSTRAINT `fk_raffle_winner_draw` FOREIGN KEY (`draw_id`) REFERENCES `raffle_draws` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_raffle_winner_app` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Values the admin can change from the dashboard without editing config.php.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `name` varchar(60) NOT NULL,
  `value` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A blank raffle_first_draw means the promotion is not running: there is nothing
-- to count down to and the popup on the website says the dates are still to come.
-- Only the raffle date, cycle and winner count are editable from the admin; the
-- prize figures below are changed here or straight in the `settings` table.
INSERT INTO `settings` (`name`, `value`) VALUES
  ('referral_reward',           '500'),
  ('raffle_enabled',            '1'),
  ('raffle_first_draw',         ''),
  ('raffle_cycle_days',         '90'),
  ('raffle_winner_count',       '5'),
  ('raffle_gold_grams',         '1'),
  ('raffle_gold_rate',          '7000'),
  ('raffle_cash_discount_min',  '5'),
  ('raffle_cash_discount_max',  '7');

-- --------------------------------------------------------------------------
-- The one account you start with.
-- Sign in with "admin" or "admin@manifold.com", password "admin12345".
-- --------------------------------------------------------------------------
INSERT INTO `admin_users` (`name`, `email`, `password_hash`) VALUES
  ('admin', 'admin@manifold.com', '$2y$10$UoO.3dsFFzlN0PsyNNbAjOAJ0yITCnUYzPcyiBX6nQNPLk6WPPJC6');
