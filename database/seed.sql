-- ==========================================================
-- Initial Production System Configuration & Admin Seeds
-- ==========================================================

-- 1. Dynamic System Configuration Seeds
INSERT INTO `system_config` (`key_name`, `key_value`, `description`) VALUES
('hdhub4u_base_url', 'https://new1.hdhub4u.af', 'Dynamic live base URL for HDHub4u scraping engine'),
('app_maintenance_mode', '0', 'Set to 1 to enable app-wide maintenance mode'),
('maintenance_title', 'Scheduled Platform Maintenance', 'Title displayed during active maintenance mode'),
('maintenance_message', 'Maxplex services are currently undergoing maintenance. Please check back shortly.', 'Detailed message displayed during maintenance mode'),
('announcement_banner', 'Welcome to Maxplex OTT Streaming Engine!', 'App banner notification text'),
('announcement_show', '1', 'Toggle visibility of the announcement banner'),
('app_latest_version', '3.3.0', 'Latest available APK version for in-house OTA updates'),
('app_latest_version_code', '33', 'Latest APK version integer code'),
('app_min_version', '3.0.0', 'Minimum supported version below which force update is triggered'),
('app_min_version_code', '30', 'Minimum supported version code'),
('app_force_update', '0', 'Set to 1 to mandate update before accessing the app'),
('app_apk_url', 'https://mov.aimacademycbse.com/downloads/hdhub4u-v3.3.0.apk', 'Direct Android Mobile APK package download URL'),
('app_apk_size', '19.2 MB', 'Direct Android Mobile APK package file size'),
('app_tv_apk_url', 'https://mov.aimacademycbse.com/downloads/maxplex-tv-v3.3.0.apk', 'Direct Android TV APK package download URL'),
('app_tv_apk_size', '24.5 MB', 'Direct Android TV APK package file size'),
('app_windows_url', 'https://mov.aimacademycbse.com/downloads/maxplex-setup-v3.3.0.exe', 'Direct Windows Desktop package download URL'),
('app_windows_size', '68.0 MB', 'Direct Windows Desktop package file size'),
('app_release_notes', '🚀 Supercharged 4K 60FPS Player\n⚡ Instant HubCloud 10Gbps Bypass\n🔔 OTA In-App Auto-Update System', 'Changelog bullet points for in-app update popup'),
('app_update_published_at', NOW(), 'Timestamp of the latest published update'),
('stream_proxy_enabled', '1', 'Toggle between internal proxy streaming and direct CDN'),
('jwt_access_expiry_minutes', '60', 'Access token lifetime in minutes'),
('jwt_refresh_expiry_days', '30', 'Refresh token lifetime in days'),
('tv_pairing_ttl_seconds', '300', 'TTL in seconds for TV pairing numeric PIN and QR token'),
('tv_pairing_enabled', '1', 'Toggle for leanback TV pairing authentication'),
('tv_pairing_qr_prefix', 'maxplex://pair', 'Base URI scheme or URL prefix for TV pairing QR codes'),
('features_tv_pairing_enabled', '1', 'Toggle for TV pairing feature flag'),
('features_cross_device_sync_enabled', '1', 'Toggle for cross-device sync feature flag'),
('features_proxy_streaming_enabled', '1', 'Toggle for proxy streaming feature flag'),
('features_downloads_enabled', '1', 'Toggle for offline downloads feature flag'),
('features_watchlist_enabled', '1', 'Toggle for user watchlist feature flag'),
('features_fcm_notifications_enabled', '1', 'Toggle for FCM push notifications feature flag'),
('player_sync_interval_seconds', '15', 'Interval in seconds between player progress sync heartbeats'),
('player_default_quality', '720p', 'Default video playback quality selection'),
('player_buffer_size_mb', '2', 'Initial video buffer size in megabytes')
ON DUPLICATE KEY UPDATE 
  `key_value` = VALUES(`key_value`), 
  `description` = VALUES(`description`);

-- 2. Default Superadmin Seed (admin / Antigravity@21)
INSERT INTO `admin_users` (`username`, `email`, `password_hash`, `role`) VALUES
('admin', 'admin@hdhub4u.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'superadmin')
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);
