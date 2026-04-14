-- XynoCMS / XynoLauncher
-- MySQL schema (phpMyAdmin import)

-- Optionnel : crée la base (sinon crée-la via phpMyAdmin et commente ces lignes)
CREATE DATABASE IF NOT EXISTS `xynocms`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `xynocms`;

-- Pour ré-importer facilement
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `manifest_cache`;
DROP TABLE IF EXISTS `api_logs`;
DROP TABLE IF EXISTS `api_rate_limits`;
DROP TABLE IF EXISTS `files`;
DROP TABLE IF EXISTS `subscriptions`;
DROP TABLE IF EXISTS `launchers`;
DROP TABLE IF EXISTS `users`;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `uuid` VARCHAR(36) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_uuid_unique` (`uuid`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `launchers` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `uuid` VARCHAR(36) NOT NULL,
  `api_key` VARCHAR(64) NOT NULL,
  `name` VARCHAR(190) NOT NULL,
  `description` TEXT NULL,
  `version` VARCHAR(32) NOT NULL,
  `loader` VARCHAR(32) NOT NULL,
  `theme` VARCHAR(64) NOT NULL,
  `modules` TEXT NULL,
  `files_changed_at` DATETIME NULL,
  `last_ping` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `launchers_uuid_unique` (`uuid`),
  UNIQUE KEY `launchers_api_key_unique` (`api_key`),
  KEY `launchers_user_id_index` (`user_id`),
  CONSTRAINT `launchers_user_id_fk`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `subscriptions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `launcher_id` INT NOT NULL,
  `status` ENUM('active','expired') NOT NULL DEFAULT 'expired',
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `subscriptions_user_id_index` (`user_id`),
  KEY `subscriptions_launcher_id_index` (`launcher_id`),
  KEY `subscriptions_status_expires_at_index` (`status`, `expires_at`),
  CONSTRAINT `subscriptions_user_id_fk`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subscriptions_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `files` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `type` ENUM('mod','config','asset','version') NOT NULL,
  `module` VARCHAR(64) NOT NULL DEFAULT '',
  `mc_version` VARCHAR(32) NOT NULL DEFAULT '',
  `version` VARCHAR(32) NOT NULL DEFAULT '',
  `name` VARCHAR(190) NOT NULL,
  `relative_path` VARCHAR(512) NOT NULL,
  `path` VARCHAR(512) NOT NULL,
  `hash` CHAR(40) NOT NULL,
  `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `files_launcher_id_index` (`launcher_id`),
  KEY `files_launcher_type_index` (`launcher_id`, `type`),
  KEY `files_hash_index` (`hash`),
  UNIQUE KEY `files_launcher_type_name_unique` (`launcher_id`, `type`, `mc_version`, `module`, `name`),
  UNIQUE KEY `files_launcher_relative_path_unique` (`launcher_id`, `relative_path`),
  KEY `files_launcher_relative_path_index` (`launcher_id`, `relative_path`),
  KEY `files_updated_at_index` (`updated_at`),
  CONSTRAINT `files_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `manifest_cache` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `params_hash` CHAR(40) NOT NULL,
  `etag` VARCHAR(80) NOT NULL,
  `json` MEDIUMTEXT NOT NULL,
  `generated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `manifest_cache_launcher_params_unique` (`launcher_id`, `params_hash`),
  KEY `manifest_cache_launcher_generated_at_index` (`launcher_id`, `generated_at`),
  CONSTRAINT `manifest_cache_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bonus: rate limiting simple (par IP + endpoint)
CREATE TABLE `api_rate_limits` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL,
  `endpoint` VARCHAR(64) NOT NULL,
  `window_start` DATETIME NOT NULL,
  `count` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_rate_limits_ip_endpoint_window_unique` (`ip`, `endpoint`, `window_start`),
  KEY `api_rate_limits_window_start_index` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bonus: logs API
CREATE TABLE `api_logs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `endpoint` VARCHAR(64) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `launcher_uuid` VARCHAR(36) NULL,
  `status_code` INT NOT NULL,
  `message` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `api_logs_created_at_index` (`created_at`),
  KEY `api_logs_endpoint_index` (`endpoint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
