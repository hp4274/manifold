-- ==========================================================================
-- Manifold Clean Energy — the whole database, in one file
--
-- Creates the `manifold` database with empty tables, one admin account and the
-- default settings. This is the current structure, for a fresh install.
--
-- WARNING: this DROPS the existing `manifold` database. Everything in it —
-- applications, payments, receipts, enquiries — is destroyed. Take a backup
-- first if this is not a clean machine.
--
-- Import:  mysql -u root -p < schema.sql
--
-- Then sign in at /manifold/admin/login.php with
--   the office     admin  (or admin@manifold.com)  password admin12345
--   R&F            rf@manifold.com                 password rf123
--
-- One sign-in, two destinations: the office lands on the dashboard, R&F on
-- their own commission screens.
--
-- CHANGE BOTH PASSWORDS before this touches a real server.
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
  `role` enum('admin','rf') NOT NULL DEFAULT 'admin',
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Distributors sit above the dealers: they sign dealers up, take a share of
-- what those dealers sell, and sell directly themselves. MX…… is their code,
-- one letter pair away from a dealer's MD…… and a customer's MF……
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `distributors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `distributor_code` varchar(20) NOT NULL,
  `full_name` varchar(160) NOT NULL,
  `company` varchar(160) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `mobile_number` varchar(30) DEFAULT NULL,
  `alt_mobile_number` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `pin_code` varchar(20) DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `gst_number` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `bank_account` varchar(60) DEFAULT NULL,
  `bank_ifsc` varchar(20) DEFAULT NULL,
  `upi_id` varchar(120) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_distributor_code` (`distributor_code`),
  KEY `ix_distributor_active` (`is_active`),
  KEY `fk_distributor_admin` (`created_by`),
  CONSTRAINT `fk_distributor_admin` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Dealers sell on our behalf and earn a share of every sale. They are not
-- applicants, so they live in their own table. Their code rides the same
-- `?ref=` link as the customer referral programme — the prefix keeps them
-- apart: MF…… is a customer's code, MD…… is a dealer's.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dealers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  /* issued when the office approves them: a dealer still waiting has no code,
     because a code that books nothing is a code somebody will share anyway */
  `dealer_code` varchar(20) DEFAULT NULL,
  -- every dealer answers to a distributor; there is no such thing as one
  -- without, which is why this is NOT NULL and the key below refuses rather
  -- than nulling it
  `distributor_id` int(10) unsigned NOT NULL,
  -- A dealer a distributor asked for waits on the office. Their code books
  -- nothing until it is approved, which is what dealer_for_code() insists on;
  -- one the office added itself is approved from the start.
  `approval_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `requested_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decided_by` int(10) unsigned DEFAULT NULL,
  `full_name` varchar(160) NOT NULL,
  `company` varchar(160) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `mobile_number` varchar(30) DEFAULT NULL,
  `alt_mobile_number` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `pin_code` varchar(20) DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `gst_number` varchar(30) DEFAULT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `bank_account` varchar(60) DEFAULT NULL,
  `bank_ifsc` varchar(20) DEFAULT NULL,
  `upi_id` varchar(120) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dealer_code` (`dealer_code`),
  KEY `ix_dealer_active` (`is_active`),
  KEY `ix_dealer_distributor` (`distributor_id`),
  KEY `ix_dealer_approval` (`approval_status`),
  KEY `fk_dealer_admin` (`created_by`),
  KEY `fk_dealer_requested_by` (`requested_by`),
  KEY `fk_dealer_decided_by` (`decided_by`),
  CONSTRAINT `fk_dealer_admin` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dealer_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `distributors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dealer_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dealer_distributor` FOREIGN KEY (`distributor_id`) REFERENCES `distributors` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Both apply forms. Column names match the `name` attributes on
-- apply-stove.html / apply-tuktuk.html.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product` enum('stove','tuktuk') NOT NULL,
  -- an application from the website waits on the office before it becomes a
  -- payment: 'submitted' is that wait, and approving it is what starts the rest
  `status` enum('submitted','booking_pending','booking_review','docs_pending','confirm_pending','delivery_pending','delivery_review','complete','cancelled','rejected')
      NOT NULL DEFAULT 'submitted',
  `reference_code` varchar(20) NOT NULL DEFAULT '',
  `referral_code` varchar(20) NOT NULL DEFAULT '',
  `referred_by_code` varchar(20) DEFAULT NULL,
  `referred_by_id` int(10) unsigned DEFAULT NULL,
  `dealer_id` int(10) unsigned DEFAULT NULL,
  `dealer_commission` decimal(10,2) NOT NULL DEFAULT 0.00,
  `distributor_id` int(10) unsigned DEFAULT NULL,
  `distributor_commission` decimal(10,2) NOT NULL DEFAULT 0.00,
  -- 'direct' is a sale a partner took and was paid for themselves: money the
  -- company never received, which is why it is told apart from an online one
  `sale_channel` enum('online','direct') NOT NULL DEFAULT 'online',
  `entered_by_dealer` int(10) unsigned DEFAULT NULL,
  `entered_by_distributor` int(10) unsigned DEFAULT NULL,
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
  `booking_amount` decimal(10,2) NOT NULL DEFAULT 3500.00,
  `delivery_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `loan_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gst_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `booking_paid_at` datetime DEFAULT NULL,
  `delivery_paid_at` datetime DEFAULT NULL,
  /* the finance team's check of the paperwork, between the two payments */
  `docs_verified_at` datetime DEFAULT NULL,
  `docs_verified_by` int(10) unsigned DEFAULT NULL,
  /* the client's own answer once the documents pass: build it, or refund me */
  `delivery_choice` enum('waiting','continue','cancel') NOT NULL DEFAULT 'waiting',
  `delivery_choice_at` datetime DEFAULT NULL,
  `loan_paid_at` datetime DEFAULT NULL,
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
  KEY `ix_app_dealer` (`dealer_id`),
  KEY `ix_app_distributor` (`distributor_id`),
  KEY `ix_app_sale_channel` (`sale_channel`),
  KEY `fk_app_entered_dealer` (`entered_by_dealer`),
  KEY `fk_app_entered_distributor` (`entered_by_distributor`),
  KEY `ix_app_reward_status` (`referral_reward_status`),
  KEY `ix_app_booking_paid` (`booking_paid_at`),
  KEY `fk_app_reward_admin` (`referral_reward_by`),
  CONSTRAINT `fk_app_referrer` FOREIGN KEY (`referred_by_id`) REFERENCES `applications` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_distributor` FOREIGN KEY (`distributor_id`) REFERENCES `distributors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_reward_admin` FOREIGN KEY (`referral_reward_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_entered_dealer` FOREIGN KEY (`entered_by_dealer`) REFERENCES `dealers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_app_entered_distributor` FOREIGN KEY (`entered_by_distributor`) REFERENCES `distributors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- One row per transfer. Every application has transfers (booking, loan,
-- delivery) and each verified one gets a receipt.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `stage` enum('booking','loan','delivery') NOT NULL DEFAULT 'booking',
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
  KEY `ix_pay_stage` (`application_id`,`stage`,`status`),
  KEY `fk_pay_admin` (`decided_by`),
  CONSTRAINT `fk_pay_admin` FOREIGN KEY (`decided_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pay_app` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Commission lines per tranche per party.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `commission_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `application_id` int(10) unsigned NOT NULL,
  `party_type` enum('dealer','distributor') NOT NULL,
  `party_id` int(10) unsigned NOT NULL,
  `stage` enum('booking','loan','delivery') NOT NULL,
  `paid_amount` decimal(10,2) NOT NULL,
  `gst_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `base_amount` decimal(10,2) NOT NULL,
  `rate` decimal(5,2) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `earned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_line_app_party_stage` (`application_id`,`party_type`,`party_id`,`stage`),
  KEY `idx_commission_party` (`party_type`,`party_id`),
  CONSTRAINT `fk_commission_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Commission vouchers, voucher lines, and voucher audit events.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `commission_vouchers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `party_type` enum('dealer','distributor') NOT NULL,
  `party_id` int(10) unsigned NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `is_bundle` tinyint(1) NOT NULL DEFAULT 0,
  `cycle_date` date NOT NULL,
  `status` enum('with_distributor','bundled','with_rf','with_admin','funded','paid','rejected','cancelled') NOT NULL DEFAULT 'with_distributor',
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reject_reason` varchar(255) DEFAULT NULL,
  `payment_reference` varchar(120) DEFAULT NULL,
  `raised_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_voucher_party` (`party_type`,`party_id`,`status`),
  KEY `idx_voucher_parent` (`parent_id`),
  KEY `idx_voucher_cycle` (`cycle_date`),
  CONSTRAINT `fk_voucher_parent` FOREIGN KEY (`parent_id`) REFERENCES `commission_vouchers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commission_voucher_lines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `voucher_id` int(10) unsigned NOT NULL,
  `party_type` enum('dealer','distributor') NOT NULL DEFAULT 'dealer',
  `party_id` int(10) unsigned NOT NULL DEFAULT 0,
  `application_id` int(10) unsigned NOT NULL,
  `commission_line_id` int(10) unsigned DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_line_voucher` (`voucher_id`),
  KEY `idx_line_application` (`application_id`),
  KEY `idx_line_commission_line` (`commission_line_id`),
  UNIQUE KEY `uq_line_party_app` (`application_id`,`party_type`,`party_id`),
  CONSTRAINT `fk_line_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `commission_vouchers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_line_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_line_commission` FOREIGN KEY (`commission_line_id`) REFERENCES `commission_lines` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `commission_voucher_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `voucher_id` int(10) unsigned NOT NULL,
  `from_status` varchar(30) DEFAULT NULL,
  `to_status` varchar(30) NOT NULL,
  `actor` varchar(60) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_voucher` (`voucher_id`),
  CONSTRAINT `fk_event_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `commission_vouchers` (`id`) ON DELETE CASCADE
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
CREATE TABLE IF NOT EXISTS `blog_posts` (
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
-- One row per transfer the office makes to a dealer.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dealer_payouts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dealer_id` int(10) unsigned NOT NULL,
  `voucher_id` int(10) unsigned DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `paid_by` int(10) unsigned DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_payout_dealer` (`dealer_id`),
  KEY `idx_payout_voucher` (`voucher_id`),
  KEY `fk_payout_admin` (`paid_by`),
  CONSTRAINT `fk_payout_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `dealers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payout_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `commission_vouchers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payout_admin` FOREIGN KEY (`paid_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------------------
-- Transfers made to a distributor.
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `distributor_payouts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `distributor_id` int(10) unsigned NOT NULL,
  `voucher_id` int(10) unsigned DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `paid_by` int(10) unsigned DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_dpayout_distributor` (`distributor_id`),
  KEY `idx_dpayout_voucher` (`voucher_id`),
  KEY `fk_dpayout_admin` (`paid_by`),
  CONSTRAINT `fk_dpayout_distributor` FOREIGN KEY (`distributor_id`) REFERENCES `distributors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dpayout_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `commission_vouchers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dpayout_admin` FOREIGN KEY (`paid_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
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
  ('commission_dealer_stove',    '3000.00'),
  ('commission_dealer_tuktuk',   '4500.00'),
  ('commission_override_stove',  '1000.00'),
  ('commission_override_tuktuk', '1500.00'),
  ('commission_direct_stove',    '3000.00'),
  ('commission_direct_tuktuk',   '4500.00'),
  ('dealer_limit',              '10'),
  ('stock_price_distributor_stove',  '17000.00'),
  ('stock_price_distributor_tuktuk', '25500.00'),
  ('stock_price_dealer_stove',       '18500.00'),
  ('stock_price_dealer_tuktuk',      '27000.00'),
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
-- The two accounts you start with.
--
--   admin@manifold.com  password admin12345  — the office, /admin
--   rf@manifold.com     password rf123       — the paying agent, /rf
--
-- Both sign in at the same door, /manifold/admin/login.php, and land in
-- different places: `role` is what decides which. R&F sees commission vouchers
-- and nothing else — no clients, no stock, no settings.
--
-- CHANGE BOTH PASSWORDS before this touches a real server. They are written in
-- this file, and this file is in the repository.
-- --------------------------------------------------------------------------
INSERT INTO `admin_users` (`name`, `email`, `role`, `password_hash`) VALUES
  ('admin', 'admin@manifold.com', 'admin', '$2y$10$UoO.3dsFFzlN0PsyNNbAjOAJ0yITCnUYzPcyiBX6nQNPLk6WPPJC6'),
  ('R&F',   'rf@manifold.com',    'rf',    '$2y$12$lZyHI3M1i5w1MIrlC/tJHOTelCSNU5vQClGBgwtHhVkpI5c8mJ4MK');

-- ---------------------------------------------------------------------------
-- Stock: what a partner has bought from the tier above them, and what is left
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `stock_orders` (
  `id`                    int(10) unsigned NOT NULL AUTO_INCREMENT,
  `buyer_type`            enum('distributor','dealer') NOT NULL,
  `buyer_id`              int(10) unsigned NOT NULL,
  -- NULL means the office sold it: a distributor buys from nobody else
  `seller_distributor_id` int(10) unsigned DEFAULT NULL,
  `product`               enum('stove','tuktuk') DEFAULT NULL,
  `quantity`              int(10) unsigned DEFAULT NULL,
  -- frozen at the moment of ordering, so a price change never rewrites a past order
  `unit_price`            decimal(10,2) DEFAULT NULL,
  `total_amount`          decimal(14,2) NOT NULL,
  `status`                enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reference`             varchar(120) DEFAULT NULL,
  `proof_path`            varchar(255) DEFAULT NULL,
  `note`                  varchar(255) DEFAULT NULL,
  `reject_reason`         varchar(255) DEFAULT NULL,
  `requested_at`          datetime NOT NULL DEFAULT current_timestamp(),
  `decided_at`            datetime DEFAULT NULL,
  `decided_by_admin`      int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stock_order_buyer` (`buyer_type`,`buyer_id`,`status`),
  KEY `idx_stock_order_seller` (`seller_distributor_id`,`status`),
  CONSTRAINT `fk_stock_order_seller` FOREIGN KEY (`seller_distributor_id`) REFERENCES `distributors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_order_admin` FOREIGN KEY (`decided_by_admin`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_order_items` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id`   int(10) unsigned NOT NULL,
  `product`    enum('stove','tuktuk') NOT NULL,
  `quantity`   int(10) unsigned NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_order_product` (`order_id`,`product`),
  CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`) REFERENCES `stock_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_ledger` (
  `id`             int(10) unsigned NOT NULL AUTO_INCREMENT,
  `owner_type`     enum('distributor','dealer') NOT NULL,
  `owner_id`       int(10) unsigned NOT NULL,
  `product`        enum('stove','tuktuk') NOT NULL,
  -- signed: stock in is positive, stock out negative, and the same for value
  `units`          int(11) NOT NULL,
  `value`          decimal(14,2) NOT NULL,
  `reason`         enum('purchase','sale','transfer_out','adjustment') NOT NULL,
  `order_id`       int(10) unsigned DEFAULT NULL,
  `application_id` int(10) unsigned DEFAULT NULL,
  `note`           varchar(255) DEFAULT NULL,
  `created_at`     datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ledger_owner` (`owner_type`,`owner_id`,`product`),
  CONSTRAINT `fk_ledger_order` FOREIGN KEY (`order_id`) REFERENCES `stock_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ledger_application` FOREIGN KEY (`application_id`) REFERENCES `applications` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
