-- Migration: Add hosting column to launchers table
-- Created: 2026-04-30

ALTER TABLE launchers
ADD COLUMN hosting BOOLEAN DEFAULT FALSE COMMENT 'true = Xyno hosting (+€/mo), false = self-hosted (free)' AFTER modules;

-- Also add hosting_price tracking for future invoice calculations
ALTER TABLE launchers
ADD COLUMN hosting_price_cents INT DEFAULT 0 COMMENT 'Monthly cost in cents for hosting option' AFTER hosting;
