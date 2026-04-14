-- Migration pour ajouter l'API (si tu as déjà importé xynocms.sql ancien)

USE `xynocms`;

-- 1) launchers: api_key + last_ping
-- Make this migration idempotent (safe to re-run)

-- launchers.api_key
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND COLUMN_NAME = 'api_key'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD COLUMN `api_key` VARCHAR(64) NULL AFTER `uuid`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill api_key for existing rows (MariaDB/MySQL compatible)
UPDATE `launchers`
SET `api_key` = SHA2(CONCAT(UUID(), RAND(), NOW(6)), 256)
WHERE `api_key` IS NULL OR `api_key` = '';

-- Ensure api_key is NOT NULL
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'launchers'
        AND COLUMN_NAME = 'api_key'
        AND IS_NULLABLE = 'YES'
    ),
    'ALTER TABLE `launchers` MODIFY COLUMN `api_key` VARCHAR(64) NOT NULL',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- launchers.modules
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND COLUMN_NAME = 'modules'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD COLUMN `modules` TEXT NULL AFTER `theme`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- launchers.files_changed_at
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND COLUMN_NAME = 'files_changed_at'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD COLUMN `files_changed_at` DATETIME NULL AFTER `modules`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- launchers.last_ping
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND COLUMN_NAME = 'last_ping'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD COLUMN `last_ping` DATETIME NULL AFTER `files_changed_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- launchers.client_integrity_sha256 (optional, used by v2 integrity enforcement)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND COLUMN_NAME = 'client_integrity_sha256'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD COLUMN `client_integrity_sha256` CHAR(64) NULL AFTER `api_key`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- launchers api_key unique index
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND INDEX_NAME = 'launchers_api_key_unique'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD UNIQUE KEY `launchers_api_key_unique` (`api_key`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) subscriptions
CREATE TABLE IF NOT EXISTS `subscriptions` (
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

-- 3) files
CREATE TABLE IF NOT EXISTS `files` (
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

-- Upgrade existing tables if they already exist (best-effort, compatible MySQL)

-- launchers handled above

-- files.version
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND COLUMN_NAME = 'version'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `version` VARCHAR(32) NOT NULL DEFAULT '''' AFTER `mc_version`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- files.relative_path
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND COLUMN_NAME = 'relative_path'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `relative_path` VARCHAR(512) NOT NULL DEFAULT '''' AFTER `name`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- files.updated_at
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND COLUMN_NAME = 'updated_at'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `created_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill relative_path for existing rows (best-effort)
UPDATE `files`
SET `relative_path` = CASE
  WHEN `type` = 'mod' THEN CONCAT('mods/', `name`)
  WHEN `type` = 'config' THEN CONCAT('config/', IF(`module` <> '', CONCAT(`module`, '/'), ''), `name`)
  WHEN `type` = 'asset' THEN CONCAT('assets/', IF(`module` <> '', CONCAT(`module`, '/'), ''), `name`)
  WHEN `type` = 'version' THEN CONCAT('versions/', IF(`mc_version` <> '', CONCAT(`mc_version`, '/'), ''), `name`)
  ELSE `name`
END
WHERE (`relative_path` IS NULL OR `relative_path` = '');

-- Ensure uniqueness by path per launcher (may fail if duplicates exist)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND INDEX_NAME = 'files_launcher_relative_path_unique'
    ),
    'SELECT 1',
    'ALTER TABLE `files` ADD UNIQUE KEY `files_launcher_relative_path_unique` (`launcher_id`, `relative_path`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Cache table for manifest
CREATE TABLE IF NOT EXISTS `manifest_cache` (
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

-- 4) launcher_versions (snapshots / releases)
CREATE TABLE IF NOT EXISTS `launcher_versions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `version_name` VARCHAR(64) NOT NULL,
  `manifest_json` MEDIUMTEXT NOT NULL,
  `changelog` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `launcher_versions_launcher_version_unique` (`launcher_id`, `version_name`),
  KEY `launcher_versions_launcher_created_at_index` (`launcher_id`, `created_at`),
  KEY `launcher_versions_launcher_active_index` (`launcher_id`, `is_active`),
  CONSTRAINT `launcher_versions_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bonus: rate limiting simple
CREATE TABLE IF NOT EXISTS `api_rate_limits` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `ip` VARCHAR(45) NOT NULL,
  `endpoint` VARCHAR(64) NOT NULL,
  `window_start` DATETIME NOT NULL,
  `count` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_rate_limits_ip_endpoint_window_unique` (`ip`, `endpoint`, `window_start`),
  KEY `api_rate_limits_window_start_index` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5) launcher_client_releases (Electron auto-update packages)
CREATE TABLE IF NOT EXISTS `launcher_client_releases` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `version_name` VARCHAR(64) NOT NULL,
  `zip_url` VARCHAR(512) NOT NULL,
  `zip_sha256` CHAR(64) NOT NULL,
  `required` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `launcher_client_releases_launcher_version_unique` (`launcher_id`, `version_name`),
  KEY `launcher_client_releases_launcher_active_index` (`launcher_id`, `is_active`, `created_at`),
  CONSTRAINT `launcher_client_releases_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bonus: logs API
CREATE TABLE IF NOT EXISTS `api_logs` (
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

-- v2: sessions (short-lived secret for request signing)
CREATE TABLE IF NOT EXISTS `api_sessions` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `session_id` CHAR(36) NOT NULL,
  `launcher_id` INT NOT NULL,
  `secret_hex` CHAR(64) NOT NULL,
  `ip` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_sessions_session_id_unique` (`session_id`),
  KEY `api_sessions_launcher_id_index` (`launcher_id`),
  KEY `api_sessions_expires_at_index` (`expires_at`),
  CONSTRAINT `api_sessions_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v2: anti-replay nonces
CREATE TABLE IF NOT EXISTS `api_nonces` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `session_key` VARCHAR(64) NOT NULL,
  `nonce` VARCHAR(96) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_nonces_launcher_session_nonce_unique` (`launcher_id`, `session_key`, `nonce`),
  KEY `api_nonces_created_at_index` (`created_at`),
  CONSTRAINT `api_nonces_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) launcher_news (news feed displayed in Electron)
CREATE TABLE IF NOT EXISTS `launcher_news` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `title` VARCHAR(190) NOT NULL,
  `content` TEXT NOT NULL,
  `published_at` DATE NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `launcher_news_launcher_id_index` (`launcher_id`, `published_at`),
  CONSTRAINT `launcher_news_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7) launcher_configs (dynamic key/value config served to the launcher)
CREATE TABLE IF NOT EXISTS `launcher_configs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `config_key` VARCHAR(64) NOT NULL,
  `config_value` TEXT NOT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `launcher_configs_launcher_key_unique` (`launcher_id`, `config_key`),
  KEY `launcher_configs_launcher_id_index` (`launcher_id`),
  CONSTRAINT `launcher_configs_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8) launcher_downloads (website installers per OS)
CREATE TABLE IF NOT EXISTS `launcher_downloads` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `platform` ENUM('win','mac','linux') NOT NULL,
  `version_name` VARCHAR(64) NOT NULL,
  `file_url` VARCHAR(512) NOT NULL,
  `file_sha256` CHAR(64) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `launcher_downloads_launcher_platform_version_unique` (`launcher_id`, `platform`, `version_name`),
  KEY `launcher_downloads_launcher_active_index` (`launcher_id`, `platform`, `is_active`, `created_at`),
  CONSTRAINT `launcher_downloads_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
