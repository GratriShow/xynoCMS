-- XynoCMS · migrations_v4.sql
--
-- Ajoute le support des abonnements Stripe récurrents et prépare la zone
-- fichiers refondue (logs d'événements, comptes joueurs/launcher, sessions).
--
-- Ce fichier est IDEMPOTENT : on peut le relancer sans risque, chaque bloc
-- vérifie l'existence de la colonne / table avant d'appliquer le changement.
--
-- Exécution :
--   mysql -u <user> -p xynocms < migrations_v4.sql
-- ou via phpMyAdmin : Importer > choisir ce fichier.

USE `xynocms`;

-- =========================================================================
-- 1) SUBSCRIPTIONS : ajout des colonnes Stripe (session/customer/subscription)
--    + amount_cents/currency pour l'historique de facturation côté dashboard.
-- =========================================================================

-- 1.a) ENUM status : 'active','expired','cancelled' → +'pending','past_due'
--      Le pre-insert lors du checkout pose 'pending' ; le webhook flip en
--      'active' (paiement OK) ou laisse en 'pending' si l'utilisateur abandonne.
ALTER TABLE `subscriptions`
  MODIFY COLUMN `status` ENUM('pending','active','expired','cancelled','past_due')
  NOT NULL DEFAULT 'pending';

-- 1.b) stripe_session_id (Checkout Session, unique pour l'idempotence webhook)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'stripe_session_id'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD COLUMN `stripe_session_id` VARCHAR(255) NULL AFTER `period`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND INDEX_NAME = 'subs_stripe_session_unique'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD UNIQUE KEY `subs_stripe_session_unique` (`stripe_session_id`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.c) stripe_subscription_id (sub_xxx, sert pour les renouvellements)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'stripe_subscription_id'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD COLUMN `stripe_subscription_id` VARCHAR(255) NULL AFTER `stripe_session_id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND INDEX_NAME = 'subs_stripe_sub_unique'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD UNIQUE KEY `subs_stripe_sub_unique` (`stripe_subscription_id`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.d) stripe_customer_id (cus_xxx)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'stripe_customer_id'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD COLUMN `stripe_customer_id` VARCHAR(255) NULL AFTER `stripe_subscription_id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1.e) amount_cents + currency (montant facturé par période)
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'amount_cents'
    ),
    'SELECT 1',
    'ALTER TABLE `subscriptions` ADD COLUMN `amount_cents` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `stripe_customer_id`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subscriptions' AND COLUMN_NAME = 'currency'
    ),
    'SELECT 1',
    "ALTER TABLE `subscriptions` ADD COLUMN `currency` VARCHAR(8) NOT NULL DEFAULT 'eur' AFTER `amount_cents`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================================
-- 2) FILE_EVENTS : journal des actions sur les fichiers d'un launcher
--    upload / delete / download — pour la zone fichier refondue (logs &
--    téléchargements).
-- =========================================================================

CREATE TABLE IF NOT EXISTS `file_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `file_id` INT NULL,
  `event` ENUM('upload','delete','download','rename','move') NOT NULL,
  `actor` ENUM('user','launcher','system') NOT NULL DEFAULT 'user',
  `actor_id` INT NULL,
  `path` VARCHAR(512) NOT NULL DEFAULT '',
  `name` VARCHAR(190) NOT NULL DEFAULT '',
  `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fe_launcher_idx` (`launcher_id`, `created_at`),
  KEY `fe_file_idx` (`file_id`),
  KEY `fe_event_idx` (`event`),
  CONSTRAINT `file_events_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 3) LAUNCHER_USERS : comptes joueurs côté launcher (auth custom)
--    Si la table existe déjà via /api/v2/session.php, on ajoute juste
--    `banned_at` et `last_seen_at` ; sinon on la crée minimaliste.
-- =========================================================================

CREATE TABLE IF NOT EXISTS `launcher_users` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `email` VARCHAR(190) NULL,
  `password_hash` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NULL,
  `banned_at` DATETIME NULL,
  `ban_reason` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `lu_unique_username` (`launcher_id`, `username`),
  KEY `lu_launcher_idx` (`launcher_id`),
  KEY `lu_banned_idx` (`banned_at`),
  CONSTRAINT `launcher_users_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Si la table existait avec un schéma plus ancien, on rajoute les colonnes manquantes.
SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launcher_users' AND COLUMN_NAME = 'banned_at'),
    'SELECT 1',
    'ALTER TABLE `launcher_users` ADD COLUMN `banned_at` DATETIME NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launcher_users' AND COLUMN_NAME = 'ban_reason'),
    'SELECT 1',
    'ALTER TABLE `launcher_users` ADD COLUMN `ban_reason` VARCHAR(255) NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'launcher_users' AND COLUMN_NAME = 'last_seen_at'),
    'SELECT 1',
    'ALTER TABLE `launcher_users` ADD COLUMN `last_seen_at` DATETIME NULL'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================================
-- 4) LAUNCHER_USER_SESSIONS : suivi des connexions launcher (joueur → IP/UA)
--    Sert au panneau "Joueurs" : qui s'est connecté, depuis où, avec
--    quelle version du launcher.
-- =========================================================================

CREATE TABLE IF NOT EXISTS `launcher_user_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `launcher_id` INT NOT NULL,
  `launcher_user_id` INT NULL,
  `username` VARCHAR(64) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `launcher_version` VARCHAR(64) NULL,
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `lus_launcher_idx` (`launcher_id`, `last_seen_at`),
  KEY `lus_user_idx` (`launcher_user_id`),
  KEY `lus_ip_idx` (`ip`),
  CONSTRAINT `launcher_user_sessions_launcher_id_fk`
    FOREIGN KEY (`launcher_id`) REFERENCES `launchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================================
-- 5) FILES : ajout d'un dossier virtuel (pour le browser refondu)
--    + folder_path qui range les fichiers dans une arborescence dashboard
--    distincte du chemin disque.
-- =========================================================================

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND COLUMN_NAME = 'folder_path'),
    'SELECT 1',
    "ALTER TABLE `files` ADD COLUMN `folder_path` VARCHAR(512) NOT NULL DEFAULT '' AFTER `name`"
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND INDEX_NAME = 'files_folder_idx'),
    'SELECT 1',
    'ALTER TABLE `files` ADD KEY `files_folder_idx` (`launcher_id`, `folder_path`)'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'files' AND COLUMN_NAME = 'download_count'),
    'SELECT 1',
    'ALTER TABLE `files` ADD COLUMN `download_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `size`'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =========================================================================
-- Fin · migrations_v4
-- Pour vérifier :
--   SHOW CREATE TABLE subscriptions\G
--   SHOW CREATE TABLE file_events\G
--   SHOW CREATE TABLE launcher_users\G
--   SHOW CREATE TABLE launcher_user_sessions\G
-- =========================================================================
