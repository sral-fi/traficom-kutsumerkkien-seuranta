-- =============================================================================
-- Callsign Tracker – Database initialisation
-- Generated from db.py
--
-- Usage:
--   mysql -u root -p < calls_sral_fi.sql
-- =============================================================================

CREATE DATABASE IF NOT EXISTS traficom_tracker
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE traficom_tracker;

-- ---------------------------------------------------------------------------
-- User (adjust password before running in production)
-- ---------------------------------------------------------------------------
-- CREATE USER IF NOT EXISTS 'calls'@'localhost' IDENTIFIED BY 'changeme';
-- GRANT ALL PRIVILEGES ON calls_tracker.* TO 'calls'@'localhost';
-- FLUSH PRIVILEGES;

-- ---------------------------------------------------------------------------
-- snapshots
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS snapshots (
    id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    fetched_at DATETIME     NOT NULL,
    callsign   VARCHAR(20)  NOT NULL,
    status     VARCHAR(20)  NOT NULL,
    INDEX idx_date (fetched_at),
    INDEX idx_call (callsign)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- daily_changes
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_changes (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    change_date DATE        NOT NULL,
    callsign    VARCHAR(20) NOT NULL,
    change_type ENUM('added','removed') NOT NULL,
    category    ENUM('new','renewal','genuine_remove','pending')
                NOT NULL DEFAULT 'pending',
    INDEX idx_date     (change_date),
    INDEX idx_call     (callsign),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- daily_stats
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS daily_stats (
    stat_date       DATE PRIMARY KEY,
    total           INT NOT NULL,
    added           INT NOT NULL DEFAULT 0,
    removed         INT NOT NULL DEFAULT 0,
    new_callsigns   INT NOT NULL DEFAULT 0,
    renewals        INT NOT NULL DEFAULT 0,
    genuine_removes INT NOT NULL DEFAULT 0,
    pending_removes INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
