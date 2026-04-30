-- XynoCMS · migrations_v6.sql
--
-- Phase 5 : observabilité launcher (heartbeats, sessions) + index utiles
-- pour la console admin (recherche, audit, abonnements).
--
-- IDEMPOTENT : peut être relancé sans risque.
--
-- Exécution :
--   mysql -u <user> -p xynocms < migrations_v6.sql
-- ou via phpMyAdmin : Importer.

USE `xynocms`;

-- =========================================================================
-- 1) LAUNCHER_HEARTBEATS : un ping toutes les ~30s envoyé par le launcher
--    Electron. Sert à mesurer si le client tourne, sa version, son OS, son
--    "tick rate" (intervalle réel entre deux pings) et la durée de session.
--
--    On garde TOUTES les rows (pour les graphes) — pruner via cron au-delà
--    de 30 jours si la table grossit trop :
--       DELETE FROM launcher_heartbeats WHERE created_at < NOW() - INTERVAL 30 DAY;
-- =========================================================================

CREATE TABLE IF NOT EXISTS `launcher_heartbeats` (
  `id` BIGINT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `user_id` INT NULL,
  `app_version` VARCHAR(32) NULL,
  `os` VARCHAR(32) NULL,
  `os_version` VARCHAR(64) NULL,
  `arch` VARCHAR(16) NULL,
  `theme` VARCHAR(48) NULL,
  `uptime_s` INT NULL,
  `tick_rate_ms` INT NULL,
  `session_id` CHAR(36) NULL,
  `state` VARCHAR(32) NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `lhb_launcher_idx` (`launcher_id`, `created_at`),
  KEY `lhb_user_idx` (`user_id`, `created_at`),
  KEY `lhb_session_idx` (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 2) LAUNCHERS : colonnes dénormalisées pour la console admin (évite un
--    SELECT MAX(created_at) FROM launcher_heartbeats à chaque page).
-- =========================================================================

-- 2.a) last_seen_at : dernier heartbeat reçu
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND COLUMN_NAME = 'last_seen_at'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD COLUMN `last_seen_at` DATETIME NULL AFTER `created_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2.b) last_app_version
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND COLUMN_NAME = 'last_app_version'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD COLUMN `last_app_version` VARCHAR(32) NULL AFTER `last_seen_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2.c) last_os
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launchers' AND COLUMN_NAME = 'last_os'
    ),
    'SELECT 1',
    'ALTER TABLE `launchers` ADD COLUMN `last_os` VARCHAR(32) NULL AFTER `last_app_version`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================================
-- 3) SUBSCRIPTIONS : flag `extended_until` pour les prolongations
--    commerciales (geste local sans toucher Stripe). La logique
--    de subscription_is_active() lit MAX(expires_at, extended_until).
-- =========================================================================

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'extended_until'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD COLUMN `extended_until` DATETIME NULL AFTER `expires_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'cancel_at_period_end'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD COLUMN `cancel_at_period_end` TINYINT(1) NOT NULL DEFAULT 0 AFTER `cancelled_at`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================================
-- Fin · migrations_v6
-- Vérifications :
--   SHOW CREATE TABLE launcher_heartbeats\G
--   SHOW COLUMNS FROM launchers LIKE 'last_%';
--   SHOW COLUMNS FROM subscriptions LIKE '%cancel%';
--   SHOW COLUMNS FROM subscriptions LIKE 'extended_until';
-- =========================================================================
