-- ============================================================
--  Migration 0010 : Joueurs offline XynoWeb
--  Tables : offline_players
--  Ajoute aussi offline_auth_enabled + hmac_secret sur launchers
-- ============================================================

-- ── Colonne offline_auth_enabled sur launchers ────────────────
ALTER TABLE `launchers`
  ADD COLUMN IF NOT EXISTS `offline_auth_enabled` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Activer l\'auth offline (mode crack sans compte Mojang)',
  ADD COLUMN IF NOT EXISTS `hmac_secret` VARCHAR(128) NULL
    COMMENT 'Secret HMAC pour signer les requêtes offline_auth (optionnel)';

-- ── Table des joueurs offline ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `offline_players` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `launcher_id`  VARCHAR(64)  NOT NULL  COMMENT 'ID du launcher XynoWeb',
  `username`     VARCHAR(16)  NOT NULL  COMMENT 'Pseudo Minecraft du joueur',
  `uuid`         CHAR(36)     NOT NULL  COMMENT 'UUID v3 déterministe (offline Minecraft)',
  `xyno_token`   CHAR(64)     NOT NULL  COMMENT 'Token opaque retourné au launcher',
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `offline_players_launcher_username_unique` (`launcher_id`, `username`),
  KEY `offline_players_uuid_index` (`uuid`),
  KEY `offline_players_launcher_id_index` (`launcher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Profils joueurs en mode offline XynoWeb (sans compte Mojang premium)';
