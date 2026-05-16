-- ============================================================
--  XynoServer CMS — Migration 003 : Upgrade mc_servers
--  Renomme `name` → `server_name`, ajoute les colonnes
--  manquantes pour la compatibilité avec le panel unifié.
--  À importer après 001 et 002.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ── Renommer name → server_name ────────────────────────────
ALTER TABLE `mc_servers`
  CHANGE COLUMN `name` `server_name` VARCHAR(190) NOT NULL;

-- ── Colonnes manquantes ─────────────────────────────────────
ALTER TABLE `mc_servers`
  ADD COLUMN IF NOT EXISTS `server_name`       VARCHAR(190) NOT NULL DEFAULT '' AFTER `api_key`,
  ADD COLUMN IF NOT EXISTS `plan_slug`          VARCHAR(64)  NULL DEFAULT 'spark'   AFTER `server_port`,
  ADD COLUMN IF NOT EXISTS `hosting_server_id`  VARCHAR(128) NULL DEFAULT NULL      AFTER `plan_slug`,
  ADD COLUMN IF NOT EXISTS `motd`               VARCHAR(255) NULL DEFAULT NULL      AFTER `hosting_server_id`,
  ADD COLUMN IF NOT EXISTS `max_players`        SMALLINT     NOT NULL DEFAULT 20    AFTER `motd`;

-- ── Index utile pour hosting_server_id ────────────────────
ALTER TABLE `mc_servers`
  ADD KEY IF NOT EXISTS `mc_servers_hosting_id_index` (`hosting_server_id`);

SET FOREIGN_KEY_CHECKS = 1;
