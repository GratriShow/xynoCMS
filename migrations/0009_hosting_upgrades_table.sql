-- Migration: Create hosting_upgrades table for tracking hosting add-on upgrades
-- Created: 2026-04-30

CREATE TABLE IF NOT EXISTS hosting_upgrades (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL COMMENT 'References users table',
    launcher_id BIGINT UNSIGNED NOT NULL COMMENT 'References launchers table',
    stripe_session_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'Stripe Checkout session ID (payment intent)',
    prorata_cents INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Prorated amount charged for first month (cents)',
    status VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending|active|cancelled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_user_id (user_id),
    KEY idx_launcher_id (launcher_id),
    KEY idx_stripe_session_id (stripe_session_id),
    KEY idx_status (status),
    CONSTRAINT fk_hosting_upgrades_launcher FOREIGN KEY (launcher_id) REFERENCES launchers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
