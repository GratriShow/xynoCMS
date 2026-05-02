-- XynoCMS migration: per-launcher custom background image.
--
-- Adds a column on `launchers` that stores the on-disk filename (relative to
-- /uploads/launchers/<id>/) of the background uploaded by the tenant from
-- the dashboard. The full URL is built at runtime by the manifest endpoint.
--
-- IDEMPOTENT: safe to re-run.

USE `xynocms`;

-- MySQL doesn't have "ADD COLUMN IF NOT EXISTS", so guard with a procedure.
DROP PROCEDURE IF EXISTS xyno_add_background_path;
DELIMITER $$
CREATE PROCEDURE xyno_add_background_path()
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'launchers'
      AND COLUMN_NAME = 'background_path'
  ) THEN
    ALTER TABLE `launchers`
      ADD COLUMN `background_path` VARCHAR(255) NULL AFTER `theme`;
  END IF;
END$$
DELIMITER ;
CALL xyno_add_background_path();
DROP PROCEDURE xyno_add_background_path;
