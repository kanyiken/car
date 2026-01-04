-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jan 04, 2026 at 05:44 AM
-- Server version: 11.4.8-MariaDB-cll-lve
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pipiicok_pipii`
--

-- --------------------------------------------------------

--
-- Table structure for table `car_yards`
--

CREATE TABLE `car_yards` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `dealer_id` bigint(20) UNSIGNED NOT NULL,
  `sales_agent_id` bigint(20) UNSIGNED NOT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `yard_name` varchar(160) NOT NULL,
  `town_id` bigint(20) UNSIGNED NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` varchar(60) NOT NULL,
  `listing_id` bigint(20) UNSIGNED DEFAULT NULL,
  `dealer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `sales_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `payment_id` bigint(20) UNSIGNED DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `listings`
--

CREATE TABLE `listings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `dealer_id` bigint(20) UNSIGNED NOT NULL,
  `yard_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `town_id` bigint(20) UNSIGNED NOT NULL,
  `vehicle_model_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(220) NOT NULL,
  `make` varchar(80) NOT NULL,
  `model` varchar(80) NOT NULL,
  `trim` varchar(80) DEFAULT NULL,
  `year` smallint(5) UNSIGNED NOT NULL,
  `engine_cc` int(10) UNSIGNED NOT NULL,
  `mileage_km` int(10) UNSIGNED DEFAULT NULL,
  `fuel_type` enum('petrol','diesel','hybrid','electric','other') DEFAULT NULL,
  `transmission` enum('automatic','manual','other') DEFAULT NULL,
  `body_type` varchar(40) DEFAULT NULL,
  `color` varchar(60) DEFAULT NULL,
  `condition_type` enum('used','new') NOT NULL DEFAULT 'used',
  `cash_price_kes` int(10) UNSIGNED NOT NULL,
  `allows_cash` tinyint(1) NOT NULL DEFAULT 1,
  `allows_hp` tinyint(1) NOT NULL DEFAULT 0,
  `allows_trade_in` tinyint(1) NOT NULL DEFAULT 0,
  `allows_external_financing` tinyint(1) NOT NULL DEFAULT 0,
  `is_sponsored` tinyint(1) NOT NULL DEFAULT 0,
  `sponsored_until` datetime DEFAULT NULL,
  `approval_status` enum('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
  `approval_reason` varchar(120) DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `published_at` datetime DEFAULT NULL,
  `description` text DEFAULT NULL,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `features_text` varchar(1000) GENERATED ALWAYS AS (json_unquote(json_extract(`features`,'$'))) STORED,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `listing_hp_terms`
--

CREATE TABLE `listing_hp_terms` (
  `listing_id` bigint(20) UNSIGNED NOT NULL,
  `deposit_kes` int(10) UNSIGNED DEFAULT NULL,
  `months` tinyint(3) UNSIGNED DEFAULT NULL,
  `interest_apr` decimal(5,2) DEFAULT NULL,
  `min_deposit_kes` int(10) UNSIGNED NOT NULL,
  `max_deposit_kes` int(10) UNSIGNED DEFAULT NULL,
  `default_deposit_kes` int(10) UNSIGNED DEFAULT NULL,
  `min_months` tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
  `max_months` tinyint(3) UNSIGNED NOT NULL DEFAULT 60,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `listing_images`
--

CREATE TABLE `listing_images` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `listing_id` bigint(20) UNSIGNED NOT NULL,
  `image_url` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `listing_search_index`
--

CREATE TABLE `listing_search_index` (
  `listing_id` bigint(20) UNSIGNED NOT NULL,
  `search_text` mediumtext NOT NULL,
  `make` varchar(80) DEFAULT NULL,
  `model` varchar(80) DEFAULT NULL,
  `town_name` varchar(120) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otp_codes`
--

CREATE TABLE `otp_codes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `phone_e164` varchar(20) NOT NULL,
  `role` enum('dealer','sales_agent','superadmin') NOT NULL DEFAULT 'dealer',
  `code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `consumed_at` datetime DEFAULT NULL,
  `request_ip_hash` varchar(128) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `provider` enum('paystack','manual','other') NOT NULL DEFAULT 'paystack',
  `reference` varchar(80) NOT NULL,
  `purpose` enum('listing_publish','listing_renew','listing_sponsor') NOT NULL,
  `dealer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `listing_id` bigint(20) UNSIGNED DEFAULT NULL,
  `amount_kes` int(10) UNSIGNED NOT NULL,
  `currency` varchar(10) NOT NULL DEFAULT 'KES',
  `status` enum('initiated','paid','failed','reversed') NOT NULL DEFAULT 'initiated',
  `provider_raw` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`provider_raw`)),
  `initiated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `schema_migrations`
--

CREATE TABLE `schema_migrations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `version` varchar(60) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `search_logs`
--

CREATE TABLE `search_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `q` varchar(200) DEFAULT NULL,
  `filters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`filters`)),
  `results_count` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(120) NOT NULL,
  `value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `towns`
--

CREATE TABLE `towns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `county` varchar(120) DEFAULT NULL,
  `country_code` char(2) NOT NULL DEFAULT 'KE',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `role` enum('superadmin','sales_agent','dealer') NOT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `full_name` varchar(140) NOT NULL,
  `phone_e164` varchar(20) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_makes`
--

CREATE TABLE `vehicle_makes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `slug` varchar(90) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_models`
--

CREATE TABLE `vehicle_models` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `make_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_model_bodies`
--

CREATE TABLE `vehicle_model_bodies` (
  `model_id` bigint(20) UNSIGNED NOT NULL,
  `body_type` varchar(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_model_years`
--

CREATE TABLE `vehicle_model_years` (
  `model_id` bigint(20) UNSIGNED NOT NULL,
  `year` smallint(5) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `car_yards`
--
ALTER TABLE `car_yards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_yards_dealer` (`dealer_id`,`id`),
  ADD KEY `idx_yards_agent` (`sales_agent_id`,`id`),
  ADD KEY `idx_yards_town` (`town_id`,`id`),
  ADD KEY `fk_yards_created_by` (`created_by`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_events_type_date` (`type`,`created_at`,`id`),
  ADD KEY `idx_events_listing` (`listing_id`,`created_at`),
  ADD KEY `idx_events_dealer` (`dealer_id`,`created_at`),
  ADD KEY `idx_events_agent` (`sales_agent_id`,`created_at`),
  ADD KEY `fk_events_payment` (`payment_id`);

--
-- Indexes for table `listings`
--
ALTER TABLE `listings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_listings_visibility` (`approval_status`,`expires_at`,`id`),
  ADD KEY `idx_listings_dealer` (`dealer_id`,`id`),
  ADD KEY `idx_listings_yard` (`yard_id`,`id`),
  ADD KEY `idx_listings_sponsored` (`is_sponsored`,`sponsored_until`,`id`),
  ADD KEY `idx_listings_town` (`town_id`,`id`),
  ADD KEY `idx_listings_make_model` (`make`,`model`,`year`),
  ADD KEY `idx_listings_vehicle_model` (`vehicle_model_id`),
  ADD KEY `idx_listings_features_text` (`features_text`(768)),
  ADD KEY `fk_listings_created_by` (`created_by`),
  ADD KEY `fk_listings_approved_by` (`approved_by`);

--
-- Indexes for table `listing_hp_terms`
--
ALTER TABLE `listing_hp_terms`
  ADD PRIMARY KEY (`listing_id`);

--
-- Indexes for table `listing_images`
--
ALTER TABLE `listing_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_listing_images_listing_sort` (`listing_id`,`sort_order`,`id`);

--
-- Indexes for table `listing_search_index`
--
ALTER TABLE `listing_search_index`
  ADD PRIMARY KEY (`listing_id`);
ALTER TABLE `listing_search_index` ADD FULLTEXT KEY `ft_search_text` (`search_text`);

--
-- Indexes for table `otp_codes`
--
ALTER TABLE `otp_codes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_otp_phone_role` (`phone_e164`,`role`,`id`),
  ADD KEY `idx_otp_expires` (`expires_at`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payments_reference` (`reference`),
  ADD KEY `idx_payments_dealer_status` (`dealer_id`,`status`,`id`),
  ADD KEY `idx_payments_listing` (`listing_id`,`id`),
  ADD KEY `idx_payments_purpose_status` (`purpose`,`status`,`id`);

--
-- Indexes for table `schema_migrations`
--
ALTER TABLE `schema_migrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_schema_migrations_version` (`version`);

--
-- Indexes for table `search_logs`
--
ALTER TABLE `search_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_search_date` (`created_at`,`id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_settings_key` (`key`);

--
-- Indexes for table `towns`
--
ALTER TABLE `towns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_towns_name_country` (`name`,`country_code`),
  ADD KEY `idx_towns_active_name` (`is_active`,`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_phone` (`phone_e164`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_role_active` (`role`,`is_active`,`id`),
  ADD KEY `idx_users_created_by` (`created_by`);

--
-- Indexes for table `vehicle_makes`
--
ALTER TABLE `vehicle_makes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vehicle_makes_name` (`name`),
  ADD UNIQUE KEY `uq_vehicle_makes_slug` (`slug`),
  ADD KEY `idx_vehicle_makes_active` (`is_active`,`name`);

--
-- Indexes for table `vehicle_models`
--
ALTER TABLE `vehicle_models`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vehicle_models_make_name` (`make_id`,`name`),
  ADD UNIQUE KEY `uq_vehicle_models_slug` (`slug`),
  ADD KEY `idx_vehicle_models_make` (`make_id`,`name`);

--
-- Indexes for table `vehicle_model_bodies`
--
ALTER TABLE `vehicle_model_bodies`
  ADD PRIMARY KEY (`model_id`,`body_type`),
  ADD KEY `idx_vehicle_model_bodies_body` (`body_type`);

--
-- Indexes for table `vehicle_model_years`
--
ALTER TABLE `vehicle_model_years`
  ADD PRIMARY KEY (`model_id`,`year`),
  ADD KEY `idx_vehicle_model_years_year` (`year`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `car_yards`
--
ALTER TABLE `car_yards`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `listings`
--
ALTER TABLE `listings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `listing_images`
--
ALTER TABLE `listing_images`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `otp_codes`
--
ALTER TABLE `otp_codes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schema_migrations`
--
ALTER TABLE `schema_migrations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `search_logs`
--
ALTER TABLE `search_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `towns`
--
ALTER TABLE `towns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicle_makes`
--
ALTER TABLE `vehicle_makes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicle_models`
--
ALTER TABLE `vehicle_models`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `car_yards`
--
ALTER TABLE `car_yards`
  ADD CONSTRAINT `fk_yards_agent` FOREIGN KEY (`sales_agent_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_yards_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_yards_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_yards_town` FOREIGN KEY (`town_id`) REFERENCES `towns` (`id`);

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `fk_events_agent` FOREIGN KEY (`sales_agent_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_events_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_events_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_events_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `listings`
--
ALTER TABLE `listings`
  ADD CONSTRAINT `fk_listings_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_listings_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_listings_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_listings_town` FOREIGN KEY (`town_id`) REFERENCES `towns` (`id`),
  ADD CONSTRAINT `fk_listings_vehicle_model` FOREIGN KEY (`vehicle_model_id`) REFERENCES `vehicle_models` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_listings_yard` FOREIGN KEY (`yard_id`) REFERENCES `car_yards` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `listing_hp_terms`
--
ALTER TABLE `listing_hp_terms`
  ADD CONSTRAINT `fk_hp_terms_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `listing_images`
--
ALTER TABLE `listing_images`
  ADD CONSTRAINT `fk_listing_images_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `listing_search_index`
--
ALTER TABLE `listing_search_index`
  ADD CONSTRAINT `fk_listing_search_index_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_dealer` FOREIGN KEY (`dealer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payments_listing` FOREIGN KEY (`listing_id`) REFERENCES `listings` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `vehicle_models`
--
ALTER TABLE `vehicle_models`
  ADD CONSTRAINT `fk_vehicle_models_make` FOREIGN KEY (`make_id`) REFERENCES `vehicle_makes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicle_model_bodies`
--
ALTER TABLE `vehicle_model_bodies`
  ADD CONSTRAINT `fk_vehicle_model_bodies_model` FOREIGN KEY (`model_id`) REFERENCES `vehicle_models` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicle_model_years`
--
ALTER TABLE `vehicle_model_years`
  ADD CONSTRAINT `fk_vehicle_model_years_model` FOREIGN KEY (`model_id`) REFERENCES `vehicle_models` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
