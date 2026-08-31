-- ==========================================================
-- Enterprise OTT Platform - Complete Database Schema (v3.0)
-- MySQL / MariaDB (InnoDB, utf8mb4_unicode_ci)
-- ==========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. USERS TABLE
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NULL,
  `avatar_url` VARCHAR(500) NULL,
  `auth_provider` ENUM('email', 'google', 'apple') NOT NULL DEFAULT 'email',
  `google_id` VARCHAR(100) NULL UNIQUE,
  `is_verified` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_email` (`email`),
  INDEX `idx_user_uuid` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. USER SESSIONS & MULTI-DEVICE REFRESH TOKENS
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `refresh_token_hash` VARCHAR(255) NOT NULL UNIQUE,
  `device_id` VARCHAR(100) NOT NULL,
  `device_name` VARCHAR(100) NULL,
  `os_type` ENUM('android', 'ios', 'windows', 'macos', 'web', 'android_tv', 'firetv', 'appletv', 'linux', 'other') NOT NULL DEFAULT 'android',
  `app_version` VARCHAR(20) NULL,
  `ip_address` VARCHAR(45) NULL,
  `last_active_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_session_user` (`user_id`),
  INDEX `idx_session_device` (`device_id`),
  INDEX `idx_session_last_active` (`last_active_at`),
  CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. LIVE APP HEARTBEAT & REAL-TIME ACTIVE USERS
CREATE TABLE IF NOT EXISTS `app_heartbeats` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `device_id` VARCHAR(100) NOT NULL,
  `session_id` VARCHAR(100) NULL,
  `current_screen` VARCHAR(50) NOT NULL DEFAULT 'home',
  `current_media_slug` VARCHAR(200) NULL,
  `current_media_title` VARCHAR(255) NULL,
  `current_playback_pos` INT NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NULL,
  `last_ping_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_heartbeat` (`device_id`),
  INDEX `idx_heartbeat_user` (`user_id`),
  INDEX `idx_heartbeat_last_ping` (`last_ping_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. WATCH HISTORY & RESUME PLAYBACK (CONTINUE WATCHING)
CREATE TABLE IF NOT EXISTS `watch_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `media_slug` VARCHAR(200) NOT NULL,
  `media_title` VARCHAR(255) NOT NULL,
  `media_poster` VARCHAR(500) NULL,
  `content_type` ENUM('movie', 'web_series') NOT NULL DEFAULT 'movie',
  `season_number` INT NULL DEFAULT 1,
  `episode_number` INT NULL DEFAULT 1,
  `episode_title` VARCHAR(100) NULL,
  `playback_time_seconds` INT NOT NULL DEFAULT 0,
  `duration_seconds` INT NOT NULL DEFAULT 0,
  `percentage_watched` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_media_ep` (`user_id`, `media_slug`, `episode_number`),
  INDEX `idx_watch_user` (`user_id`),
  INDEX `idx_watch_updated` (`updated_at`),
  CONSTRAINT `fk_watch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. SEARCH HISTORY
CREATE TABLE IF NOT EXISTS `search_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `query` VARCHAR(150) NOT NULL,
  `clicked_media_slug` VARCHAR(200) NULL,
  `searched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_search_user` (`user_id`),
  INDEX `idx_search_query` (`query`),
  CONSTRAINT `fk_search_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. DOWNLOAD HISTORY
CREATE TABLE IF NOT EXISTS `download_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `media_slug` VARCHAR(200) NOT NULL,
  `media_title` VARCHAR(255) NOT NULL,
  `episode_number` INT NULL,
  `quality_downloaded` VARCHAR(50) NOT NULL,
  `file_size` VARCHAR(50) NULL,
  `download_server` VARCHAR(100) NULL,
  `downloaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_dl_user` (`user_id`),
  CONSTRAINT `fk_dl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. USER GENRE PREFERENCES & RECOMMENDATION SCORING
CREATE TABLE IF NOT EXISTS `user_genre_preferences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `genre_slug` VARCHAR(100) NOT NULL,
  `interaction_score` INT NOT NULL DEFAULT 1,
  `last_interacted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_genre` (`user_id`, `genre_slug`),
  INDEX `idx_pref_user` (`user_id`),
  CONSTRAINT `fk_genre_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. USER FAVORITES / WATCHLIST
CREATE TABLE IF NOT EXISTS `user_favorites` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `media_slug` VARCHAR(200) NOT NULL,
  `media_title` VARCHAR(255) NOT NULL,
  `media_poster` VARCHAR(500) NULL,
  `content_type` ENUM('movie', 'web_series') NOT NULL DEFAULT 'movie',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_fav` (`user_id`, `media_slug`),
  INDEX `idx_fav_user` (`user_id`),
  CONSTRAINT `fk_fav_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. USER DEVICE & PUSH NOTIFICATIONS REGISTRY (FCM)
CREATE TABLE IF NOT EXISTS `user_device` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `fcm_token` VARCHAR(255) NOT NULL UNIQUE,
  `device_id` VARCHAR(100) NOT NULL,
  `os_type` ENUM('android', 'ios', 'windows', 'macos', 'web', 'android_tv', 'firetv', 'appletv', 'linux', 'other') NOT NULL DEFAULT 'android',
  `topics` JSON NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_device_user` (`user_id`),
  INDEX `idx_device_id` (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. SYSTEM DYNAMIC CONFIGURATION (REMOTE CONFIG)
CREATE TABLE IF NOT EXISTS `system_config` (
  `key_name` VARCHAR(100) NOT NULL PRIMARY KEY,
  `key_value` TEXT NOT NULL,
  `description` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. ADMIN USERS & RBAC TABLE
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(60) NOT NULL UNIQUE,
  `email` VARCHAR(120) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('superadmin', 'admin', 'moderator') DEFAULT 'superadmin',
  `last_login_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. TV PAIRING CODES & LEANBACK AUTHENTICATION
CREATE TABLE IF NOT EXISTS `tv_pairing_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pairing_code` VARCHAR(10) NOT NULL,
  `pairing_token` CHAR(36) NOT NULL UNIQUE,
  `user_id` BIGINT UNSIGNED NULL,
  `device_id` VARCHAR(100) NOT NULL,
  `device_name` VARCHAR(100) NULL,
  `os_type` ENUM('android', 'ios', 'windows', 'macos', 'web', 'android_tv', 'firetv', 'appletv', 'linux', 'other') NOT NULL DEFAULT 'android_tv',
  `app_version` VARCHAR(20) NULL,
  `ip_address` VARCHAR(45) NULL,
  `status` ENUM('pending', 'authorized', 'consumed', 'expired') NOT NULL DEFAULT 'pending',
  `qr_payload` TEXT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `authorized_at` TIMESTAMP NULL,
  `consumed_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pairing_token` (`pairing_token`),
  INDEX `idx_pairing_code_status` (`pairing_code`, `status`, `expires_at`),
  INDEX `idx_pairing_user` (`user_id`),
  INDEX `idx_pairing_device` (`device_id`),
  CONSTRAINT `fk_pairing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
-- 13. SEED DEFAULT SYSTEM CONFIGURATION
INSERT INTO `system_config` (`key_name`, `key_value`, `description`) VALUES
('is_playstore_testing', '1', 'Play Store Testing / Review Mode toggle (true=during review, false=after review passed)'),
('hdhub4u_base_url', 'https://new5.hdhub4u.cl', 'Primary HDHub4u dynamic scraper engine domain'),
('app_maintenance_mode', '0', 'Global maintenance mode toggle (0=disabled, 1=enabled)'),
('features_tv_pairing_enabled', '1', 'Enable TV numeric PIN and QR code login pairing'),
('features_cross_device_sync_enabled', '1', 'Enable cross-device watch history and resume sync')
ON DUPLICATE KEY UPDATE `key_name` = `key_name`;

SET FOREIGN_KEY_CHECKS = 1;
