-- Migration: Create gifts system (coupons + credits)
-- Created: 2026-04-30

-- Gifts: Define gifts (type, description, value, expiration)
CREATE TABLE IF NOT EXISTS gifts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type ENUM('coupon', 'credit') NOT NULL COMMENT 'coupon = Stripe discount, credit = days added',
    description VARCHAR(255) NOT NULL COMMENT 'e.g. "Black Friday 50% off"',
    value INT NOT NULL COMMENT 'For coupon: percent (1-100). For credit: days',
    single_code BOOLEAN DEFAULT FALSE COMMENT 'true = single code for all, false = unique codes per user',
    code VARCHAR(50) COMMENT 'Used if single_code = true',
    expires_at DATETIME NOT NULL COMMENT 'When gift expires',
    created_by INT NOT NULL COMMENT 'Admin user ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_expires (expires_at),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gift codes: Unique codes generated per user (if single_code = false)
CREATE TABLE IF NOT EXISTS gift_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    gift_id INT NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    redeemed_by INT COMMENT 'User ID who redeemed',
    redeemed_at DATETIME COMMENT 'When redeemed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gift_id) REFERENCES gifts(id) ON DELETE CASCADE,
    FOREIGN KEY (redeemed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_code (code),
    INDEX idx_gift (gift_id),
    INDEX idx_redeemed (redeemed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gift recipients: Track who was sent which gift
CREATE TABLE IF NOT EXISTS gift_recipients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    gift_id INT NOT NULL,
    user_id INT,
    email VARCHAR(255) NOT NULL,
    code VARCHAR(50) COMMENT 'The code sent to this user',
    sent_at DATETIME,
    redeemed_at DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gift_id) REFERENCES gifts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_gift (gift_id),
    INDEX idx_user (user_id),
    INDEX idx_email (email),
    INDEX idx_redeemed (redeemed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log for gift actions
CREATE TABLE IF NOT EXISTS gift_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    action VARCHAR(50) NOT NULL COMMENT 'created, sent, redeemed',
    gift_id INT,
    user_id INT,
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (gift_id) REFERENCES gifts(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_action (action),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
