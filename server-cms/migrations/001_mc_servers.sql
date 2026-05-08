-- ============================================================
--  XynoServer CMS — Migration 001
--  Tables : mc_servers, mc_server_plugins, mc_server_mods,
--           mc_server_launcher_links, mc_server_players
--  Compatible avec la DB xynocms existante.
--  À importer via phpMyAdmin ou CLI : mysql xynocms < 001_mc_servers.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Serveurs Minecraft ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mc_servers` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `user_id`      INT NOT NULL,
  `uuid`         VARCHAR(36)  NOT NULL,
  `api_key`      VARCHAR(64)  NOT NULL,
  `name`         VARCHAR(190) NOT NULL,
  `description`  TEXT NULL,

  -- Type de serveur
  `server_type`  ENUM('vanilla','paper','spigot','forge','fabric') NOT NULL DEFAULT 'paper',

  -- Version Minecraft (ex: "1.20.4")
  `mc_version`   VARCHAR(32)  NOT NULL,

  -- Version du loader (ex: Paper build 400, Forge 47.2.0, Fabric 0.15.7)
  `loader_version` VARCHAR(64) NULL,

  -- Connexion
  `server_ip`    VARCHAR(255) NULL,
  `server_port`  SMALLINT UNSIGNED NOT NULL DEFAULT 25565,

  -- Paramètres du serveur (server.properties en JSON)
  `server_config` JSON NULL,

  -- Statut simulé
  `status`       ENUM('configuring','ready','running','stopped') NOT NULL DEFAULT 'configuring',

  -- RAM allouée (Mo) — pour la commande de démarrage générée
  `ram_mb`       SMALLINT UNSIGNED NOT NULL DEFAULT 2048,

  -- Fichiers générés
  `generated_at` DATETIME NULL,

  `last_ping`    DATETIME NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `mc_servers_uuid_unique`    (`uuid`),
  UNIQUE KEY `mc_servers_api_key_unique` (`api_key`),
  KEY `mc_servers_user_id_index`         (`user_id`),
  CONSTRAINT `mc_servers_user_id_fk`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Plugins (Paper/Spigot) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `mc_server_plugins` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `server_id`     INT NOT NULL,
  `source`        ENUM('modrinth','hangar','manual') NOT NULL DEFAULT 'modrinth',
  `external_id`   VARCHAR(128) NULL COMMENT 'ID Modrinth ou Hangar',
  `slug`          VARCHAR(128) NULL,
  `name`          VARCHAR(190) NOT NULL,
  `version`       VARCHAR(64)  NOT NULL,
  `file_url`      VARCHAR(1024) NULL,
  `file_name`     VARCHAR(255) NULL,
  `file_size`     BIGINT UNSIGNED NULL DEFAULT 0,
  `file_hash`     VARCHAR(64)  NULL,
  `added_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `mc_server_plugins_server_id_index` (`server_id`),
  CONSTRAINT `mc_server_plugins_server_id_fk`
    FOREIGN KEY (`server_id`) REFERENCES `mc_servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Mods (Forge/Fabric) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS `mc_server_mods` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `server_id`     INT NOT NULL,
  `source`        ENUM('modrinth','curseforge','manual') NOT NULL DEFAULT 'modrinth',
  `external_id`   VARCHAR(128) NULL,
  `slug`          VARCHAR(128) NULL,
  `name`          VARCHAR(190) NOT NULL,
  `version`       VARCHAR(64)  NOT NULL,
  `file_url`      VARCHAR(1024) NULL,
  `file_name`     VARCHAR(255) NULL,
  `file_size`     BIGINT UNSIGNED NULL DEFAULT 0,
  `file_hash`     VARCHAR(64)  NULL,
  `added_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `mc_server_mods_server_id_index` (`server_id`),
  CONSTRAINT `mc_server_mods_server_id_fk`
    FOREIGN KEY (`server_id`) REFERENCES `mc_servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Liaison Serveur ↔ Launcher ─────────────────────────────
CREATE TABLE IF NOT EXISTS `mc_server_launcher_links` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `server_id`     INT NOT NULL,
  `launcher_uuid` VARCHAR(36) NOT NULL COMMENT 'UUID du launcher xynoCMS',
  `linked_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `mc_server_launcher_links_unique` (`server_id`, `launcher_uuid`),
  KEY `mc_server_launcher_links_server_id_index` (`server_id`),
  CONSTRAINT `mc_server_launcher_links_server_id_fk`
    FOREIGN KEY (`server_id`) REFERENCES `mc_servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Joueurs autorisés (whitelist partagée) ─────────────────
CREATE TABLE IF NOT EXISTS `mc_server_players` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `server_id`     INT NOT NULL,
  `mc_username`   VARCHAR(64)  NOT NULL,
  `mc_uuid`       VARCHAR(36)  NULL COMMENT 'UUID Mojang du joueur',
  `added_by`      INT NULL COMMENT 'user_id xynoCMS de l admin',
  `whitelisted`   TINYINT(1) NOT NULL DEFAULT 1,
  `added_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `mc_server_players_unique` (`server_id`, `mc_username`),
  KEY `mc_server_players_server_id_index` (`server_id`),
  CONSTRAINT `mc_server_players_server_id_fk`
    FOREIGN KEY (`server_id`) REFERENCES `mc_servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
