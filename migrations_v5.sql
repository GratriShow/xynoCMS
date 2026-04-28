-- XynoCMS · migrations_v5.sql
--
-- Phase 4 : compte utilisateur (paramètres + suppression RGPD), section
-- admin (is_admin + audit log), table de tokens email (vérification de
-- changement d'email).
--
-- Ce fichier est IDEMPOTENT : on peut le relancer sans risque, chaque bloc
-- vérifie l'existence de la colonne / table avant d'appliquer le changement.
--
-- Exécution :
--   mysql -u <user> -p xynocms < migrations_v5.sql
-- ou via phpMyAdmin : Importer > choisir ce fichier.

USE `xynocms`;

-- =========================================================================
-- 1) USERS : flag admin + soft-delete RGPD + email pending
-- =========================================================================

-- 1.a) is_admin (TINYINT 0/1)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_admin'
    ),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `is_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.b) deleted_at : soft-delete RGPD (grâce 30 jours avant purge réelle)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at'
    ),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `deleted_at` DATETIME NULL AFTER `created_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.c) email_pending : email en attente de vérification (nouveau email saisi)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_pending'
    ),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `email_pending` VARCHAR(190) NULL AFTER `email`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.d) updated_at : trace dernière modification (mot de passe / email)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'updated_at'
    ),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `updated_at` DATETIME NULL AFTER `created_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.e) last_login_at : dernière connexion (utile à l'admin)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at'
    ),
    'SELECT 1',
    'ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL AFTER `created_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================================
-- 2) USER_TOKENS : tokens à usage unique (email change, reset password, ...)
-- =========================================================================

CREATE TABLE IF NOT EXISTS `user_tokens` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `token` CHAR(64) NOT NULL,
  `kind` ENUM('email_change','password_reset','email_verify','account_delete') NOT NULL,
  `payload` VARCHAR(255) NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_tokens_token_unique` (`token`),
  KEY `user_tokens_user_id_index` (`user_id`),
  KEY `user_tokens_kind_index` (`kind`),
  CONSTRAINT `user_tokens_user_id_fk`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 3) EMAIL_LOG : trace des emails sortants (envoyés via Resend)
--    Sert à débugger + à éviter les double-sends en cas d'erreur transient.
-- =========================================================================

CREATE TABLE IF NOT EXISTS `email_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NULL,
  `to_email` VARCHAR(190) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `template` VARCHAR(64) NOT NULL DEFAULT 'custom',
  `provider_id` VARCHAR(190) NULL,
  `status` ENUM('queued','sent','failed') NOT NULL DEFAULT 'queued',
  `error` VARCHAR(500) NULL,
  `sent_by_admin_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email_log_user_idx` (`user_id`, `created_at`),
  KEY `email_log_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 4) ADMIN_ACTIONS : journal des actions de la console admin
-- =========================================================================

CREATE TABLE IF NOT EXISTS `admin_actions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `admin_id` INT NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `target_type` VARCHAR(32) NOT NULL DEFAULT '',
  `target_id` INT NULL,
  `notes` VARCHAR(500) NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `admin_actions_admin_idx` (`admin_id`, `created_at`),
  KEY `admin_actions_action_idx` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- Fin · migrations_v5
-- Pour vérifier :
--   SHOW CREATE TABLE users\G
--   SHOW CREATE TABLE user_tokens\G
--   SHOW CREATE TABLE email_log\G
--   SHOW CREATE TABLE admin_actions\G
--
-- Pour créer ton premier admin (à exécuter une fois dans phpMyAdmin) :
--   UPDATE users SET is_admin = 1 WHERE email = 'contact@xynoweb.fr';
-- =========================================================================
