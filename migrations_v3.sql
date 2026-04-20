-- XynoCMS · migrations_v3.sql
--
-- Ajoute tout ce dont le dashboard v2 a besoin :
--   * subscriptions : status 'cancelled' + colonne cancelled_at + plan/period
--   * launchers : logo_url, auth_mode (custom auth), billing_period
--   * launcher_logs : logs applicatifs remontés par le launcher
--   * launcher_downloads_log + launcher_builds_log : compteurs anti-abus
--   * launcher_extensions : extensions activables (news, player_count, leaderboard, ...)
--   * launcher_auth : configuration d'auth personnalisée (Bearer)
--
-- Ce fichier est IDEMPOTENT : on peut le relancer sans risque, chaque bloc
-- vérifie l'existence de la colonne / table avant d'appliquer le changement.
--
-- Exécution :
--   mysql -u <user> -p xynocms < migrations_v3.sql
-- ou via phpMyAdmin : Importer > choisir ce fichier.

USE `xynocms`;

-- =========================================================================
-- 1) SUBSCRIPTIONS : autoriser 'cancelled' + colonnes cancelled_at/plan/period
-- =========================================================================

-- 1.a) ENUM status : 'active','expired' → 'active','expired','cancelled'
--      (ALTER MODIFY est safe, il ne supprime pas les lignes existantes.)
ALTER TABLE `subscriptions`
  MODIFY COLUMN `status` ENUM('active','expired','cancelled') NOT NULL DEFAULT 'expired';

-- 1.b) cancelled_at
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'cancelled_at'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD COLUMN `cancelled_at` DATETIME NULL AFTER `expires_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.c) plan (starter/pro/premium)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'plan'
    ),
    'SELECT 1',
    "ALTER TABLE `subscriptions` ADD COLUMN `plan` VARCHAR(32) NOT NULL DEFAULT 'starter' AFTER `status`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.d) period (monthly/quarterly/semestrial/yearly)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'period'
    ),
    'SELECT 1',
    "ALTER TABLE `subscriptions` ADD COLUMN `period` VARCHAR(16) NOT NULL DEFAULT 'monthly' AFTER `plan`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.e) next_billing_at (calculé côté app, on veut juste l'afficher)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'next_billing_at'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD COLUMN `next_billing_at` DATETIME NULL AFTER `expires_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================================
-- 2) LAUNCHERS : logo_url (fallback URL si pas de fichier local)
-- =========================================================================

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND COLUMN_NAME = 'logo_url'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD COLUMN `logo_url` VARCHAR(512) NULL AFTER `theme`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================================
-- 3) LAUNCHER_LOGS : logs remontés par l'app Electron (affichés au dashboard)
-- =========================================================================

CREATE TABLE IF NOT EXISTS `launcher_logs` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `level` ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
  `source` VARCHAR(64) NOT NULL DEFAULT 'client',
  `message` VARCHAR(2000) NOT NULL,
  `meta_json` TEXT NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `launcher_logs_launcher_id_index` (`launcher_id`, `created_at`),
  KEY `launcher_logs_level_index` (`level`),
  CONSTRAINT `launcher_logs_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 4) LAUNCHER_DOWNLOADS_LOG : chaque téléchargement installer (anti-abus)
-- =========================================================================

CREATE TABLE IF NOT EXISTS `launcher_downloads_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `platform` VARCHAR(16) NULL,
  `ip` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `launcher_downloads_log_launcher_id_index` (`launcher_id`, `created_at`),
  KEY `launcher_downloads_log_ip_index` (`ip`, `created_at`),
  CONSTRAINT `launcher_downloads_log_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 5) LAUNCHER_BUILDS_LOG : chaque build GitHub Actions déclenché (anti-abus)
-- =========================================================================

CREATE TABLE IF NOT EXISTS `launcher_builds_log` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `triggered_by` VARCHAR(64) NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'queued',
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `launcher_builds_log_launcher_id_index` (`launcher_id`, `created_at`),
  CONSTRAINT `launcher_builds_log_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 6) LAUNCHER_EXTENSIONS : extensions activables par le client
--    Le client fournit une URL + une API key pour chaque extension qui
--    nécessite une source externe (news, player count, leaderboard, ...).
-- =========================================================================

CREATE TABLE IF NOT EXISTS `launcher_extensions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `ext_key` VARCHAR(64) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `api_url` VARCHAR(512) NULL,
  `api_key` VARCHAR(255) NULL,
  `config_json` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `launcher_extensions_unique` (`launcher_id`, `ext_key`),
  KEY `launcher_extensions_launcher_index` (`launcher_id`),
  CONSTRAINT `launcher_extensions_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 7) LAUNCHER_AUTH : config auth personnalisée (API Bearer)
--    mode = 'microsoft' → OAuth Microsoft standard (rien à configurer)
--    mode = 'custom'    → API Bearer fournie par le client (url + key)
--    mode = 'offline'   → pas d'auth, pseudo côté client (dev uniquement)
-- =========================================================================

CREATE TABLE IF NOT EXISTS `launcher_auth` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `mode` ENUM('microsoft','custom','offline') NOT NULL DEFAULT 'microsoft',
  `login_url` VARCHAR(512) NULL,
  `verify_url` VARCHAR(512) NULL,
  `refresh_url` VARCHAR(512) NULL,
  `api_key` VARCHAR(255) NULL,
  `extra_headers_json` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `launcher_auth_unique` (`launcher_id`),
  CONSTRAINT `launcher_auth_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Fin · migrations_v3
-- Pour vérifier, on peut exécuter :
--   SHOW CREATE TABLE subscriptions\G
--   SHOW TABLES LIKE 'launcher_%';
-- =========================================================================
