<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Config\Database;
use PDO;

class ConfigController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public static function getDynamicBaseUrl(): string {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT key_value FROM system_config WHERE key_name = 'hdhub4u_base_url' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if (!empty($val) && filter_var($val, FILTER_VALIDATE_URL)) {
                return rtrim($val, '/');
            }
        } catch (\Throwable $e) {}

        return 'https://new5.hdhub4u.cl';
    }

    public static function updateDynamicBaseUrl(string $newBaseUrl): void {
        try {
            $newBaseUrl = rtrim($newBaseUrl, '/');
            if (empty($newBaseUrl) || !filter_var($newBaseUrl, FILTER_VALIDATE_URL)) {
                return;
            }

            $db = Database::getConnection();
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $db->prepare("
                    INSERT INTO system_config (key_name, key_value, updated_at)
                    VALUES ('hdhub4u_base_url', :val, datetime('now'))
                    ON CONFLICT(key_name) DO UPDATE SET key_value = :val2, updated_at = datetime('now')
                ");
                $stmt->execute(['val' => $newBaseUrl, 'val2' => $newBaseUrl]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO system_config (key_name, key_value)
                    VALUES ('hdhub4u_base_url', :val)
                    ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), updated_at = NOW()
                ");
                $stmt->execute(['val' => $newBaseUrl]);
            }
        } catch (\Throwable $e) {}
    }

    public function getAppConfig(Request $request): void {
        $stmt = $this->db->query("SELECT key_name, key_value FROM system_config");
        $rawConfig = [];
        if ($stmt) {
            while ($r = $stmt->fetch()) {
                $rawConfig[$r['key_name']] = $r['key_value'];
            }
        }

        $toBool = function(mixed $val, bool $default = false): bool {
            if ($val === null) return $default;
            if (is_bool($val)) return $val;
            $s = strtolower(trim((string)$val));
            if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return true;
            if (in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
            return $default;
        };

        // 1. Play Store Testing Mode & Base URL
        $isPlaystoreTesting = $toBool($rawConfig['is_playstore_testing'] ?? '1', true);
        $baseUrl = $rawConfig['hdhub4u_base_url'] ?? 'https://new1.hdhub4u.af';
        if (!empty($baseUrl) && filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            $baseUrl = rtrim($baseUrl, '/');
        } else {
            $baseUrl = 'https://new1.hdhub4u.af';
        }

        // 2. Maintenance Block
        $maintenanceEnabled = $toBool($rawConfig['app_maintenance_mode'] ?? $rawConfig['maintenance_enabled'] ?? '0', false);
        $maintenanceTitle = (string)($rawConfig['maintenance_title'] ?? 'Under Scheduled Maintenance');
        $maintenanceMessage = (string)($rawConfig['maintenance_message'] ?? 'Maxplex services are temporarily undergoing scheduled maintenance. We will be back online shortly.');
        $maintenance = [
            'enabled' => $maintenanceEnabled,
            'title'   => $maintenanceTitle,
            'message' => $maintenanceMessage
        ];

        // 3. Features Block
        $features = [
            'is_playstore_testing'      => $isPlaystoreTesting,
            'tv_pairing_enabled'        => $toBool($rawConfig['features_tv_pairing_enabled'] ?? $rawConfig['tv_pairing_enabled'] ?? '1', true),
            'cross_device_sync_enabled' => $toBool($rawConfig['features_cross_device_sync_enabled'] ?? $rawConfig['cross_device_sync_enabled'] ?? '1', true),
            'proxy_streaming_enabled'   => $toBool($rawConfig['features_proxy_streaming_enabled'] ?? $rawConfig['stream_proxy_enabled'] ?? '1', true),
            'downloads_enabled'         => $toBool($rawConfig['features_downloads_enabled'] ?? $rawConfig['downloads_enabled'] ?? '1', true),
            'watchlist_enabled'         => $toBool($rawConfig['features_watchlist_enabled'] ?? $rawConfig['watchlist_enabled'] ?? '1', true),
            'fcm_notifications_enabled' => $toBool($rawConfig['features_fcm_notifications_enabled'] ?? $rawConfig['fcm_notifications_enabled'] ?? '1', true),
        ];

        // 4. Player Block
        $player = [
            'sync_interval_seconds' => (int)($rawConfig['player_sync_interval_seconds'] ?? 15),
            'default_quality'       => (string)($rawConfig['player_default_quality'] ?? '720p'),
            'buffer_size_mb'        => (int)($rawConfig['player_buffer_size_mb'] ?? 2),
        ];

        // 5. Announcement Block
        $announcementBanner = (string)($rawConfig['announcement_banner'] ?? 'Welcome to Maxplex OTT Streaming Engine!');
        $announcementShow = $toBool($rawConfig['announcement_show'] ?? '1', !empty($announcementBanner));
        $announcement = [
            'banner' => $announcementBanner,
            'show'   => $announcementShow,
        ];

        // 6. Version Block
        $version = [
            'latest_version'      => (string)($rawConfig['app_latest_version'] ?? '3.3.0'),
            'latest_version_code' => (int)($rawConfig['app_latest_version_code'] ?? 33),
            'min_version'         => (string)($rawConfig['app_min_version'] ?? '3.0.0'),
            'min_version_code'    => (int)($rawConfig['app_min_version_code'] ?? 30),
        ];

        Response::success([
            'is_playstore_testing' => $isPlaystoreTesting,
            'base_url'             => $baseUrl,
            'maintenance'          => $maintenance,
            'features'             => $features,
            'player'               => $player,
            'announcement'         => $announcement,
            'version'              => $version
        ], 'Dynamic app configuration fetched');
    }

    public function updateConfigKey(Request $request): void {
        $body = $request->getBody();
        $key = trim((string)($body['key_name'] ?? ''));
        $value = trim((string)($body['key_value'] ?? ''));

        if (empty($key)) {
            Response::error('key_name is required', 422);
        }

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare("
                INSERT INTO system_config (key_name, key_value, updated_at)
                VALUES (:key, :val, datetime('now'))
                ON CONFLICT(key_name) DO UPDATE SET key_value = :val2, updated_at = datetime('now')
            ");
            $stmt->execute(['key' => $key, 'val' => $value, 'val2' => $value]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO system_config (key_name, key_value)
                VALUES (:key, :val)
                ON DUPLICATE KEY UPDATE key_value = VALUES(key_value), updated_at = NOW()
            ");
            $stmt->execute(['key' => $key, 'val' => $value]);
        }

        Response::success(['key_name' => $key, 'key_value' => $value], 'Config updated successfully');
    }

    public function checkUpdate(Request $request): void {
        $platformRaw = trim((string)$request->getQuery('platform', 'android_mobile'));
        $platformNorm = strtolower($platformRaw);

        // Platform validation and mapping
        $validPlatformMap = [
            'android_mobile' => 'android_mobile',
            'android'        => 'android_mobile',
            'mobile'         => 'android_mobile',
            'android_tv'     => 'android_tv',
            'tv'             => 'android_tv',
            'firetv'         => 'android_tv',
            'windows'        => 'windows',
            'desktop'        => 'windows',
            'pc'             => 'windows',
            'win'            => 'windows',
        ];

        if (!isset($validPlatformMap[$platformNorm])) {
            Response::error('Invalid or unsupported platform. Supported platforms: android_mobile, android_tv, windows', 422);
        }
        $platform = $validPlatformMap[$platformNorm];

        // Version code validation
        $rawVersionCode = $request->getQuery('version_code');
        if ($rawVersionCode !== null && $rawVersionCode !== '') {
            if (!is_numeric($rawVersionCode) || (int)$rawVersionCode < 0) {
                Response::error('Invalid version_code parameter. Must be a positive integer.', 422);
            }
            $clientVerCode = (int)$rawVersionCode;
        } else {
            $clientVerCode = 1;
        }

        $clientVer = trim((string)$request->getQuery('version', '1.0.0'));

        $stmt = $this->db->query("SELECT key_name, key_value FROM system_config");
        $config = [];
        if ($stmt) {
            while ($r = $stmt->fetch()) {
                $config[$r['key_name']] = $r['key_value'];
            }
        }

        $latestVer = $config['app_latest_version'] ?? '3.3.0';
        $latestVerCode = (int)($config['app_latest_version_code'] ?? 33);
        $minVer = $config['app_min_version'] ?? '3.0.0';
        $minVerCode = (int)($config['app_min_version_code'] ?? 30);
        $isForceConfig = in_array(strtolower((string)($config['app_force_update'] ?? '0')), ['1', 'true', 'yes'], true);

        // Resolve platform-specific download url and file size
        if ($platform === 'android_tv') {
            $downloadUrl = $config['app_tv_apk_url'] ?? 'https://mov.aimacademycbse.com/downloads/maxplex-tv-v3.3.0.apk';
            $fileSize = $config['app_tv_apk_size'] ?? '24.5 MB';
        } elseif ($platform === 'windows') {
            $downloadUrl = $config['app_windows_url'] ?? 'https://mov.aimacademycbse.com/downloads/maxplex-setup-v3.3.0.exe';
            $fileSize = $config['app_windows_size'] ?? '68.0 MB';
        } else {
            $downloadUrl = $config['app_apk_url'] ?? 'https://mov.aimacademycbse.com/downloads/hdhub4u-v3.3.0.apk';
            $fileSize = $config['app_apk_size'] ?? '19.2 MB';
        }

        $releaseNotes = $config['app_release_notes'] ?? "🚀 4K 60FPS Direct Video Streaming Engine\n⚡ Faster HubCloud & FastDL token bypass\n🐞 Subtitle sync & player buffering improvements";
        $publishedAt = $config['app_update_published_at'] ?? date('Y-m-d H:i:s');

        // Check if update is available:
        // true if version_code < latest_version_code OR semver < latest_version
        $updateAvailable = ($clientVerCode < $latestVerCode) || (version_compare($clientVer, $latestVer, '<'));

        // If client is already at or ahead of latest in both version_code and semver, no update
        if ($clientVerCode >= $latestVerCode && version_compare($clientVer, $latestVer, '>=')) {
            $updateAvailable = false;
        }

        // Check force update:
        // true if update is available AND (version_code < min_version_code OR semver < min_version OR app_force_update flag is set)
        if ($updateAvailable) {
            $mustForce = $isForceConfig || ($clientVerCode < $minVerCode) || (version_compare($clientVer, $minVer, '<'));
            $forceUpdate = (bool)$mustForce;
        } else {
            $forceUpdate = false;
        }

        $notesList = array_values(array_filter(array_map('trim', explode("\n", $releaseNotes))));

        Response::success([
            'update_available'      => $updateAvailable,
            'force_update'          => $forceUpdate,
            'is_force_update'       => $forceUpdate,
            'current_version'       => $clientVer,
            'latest_version'        => $latestVer,
            'latest_version_code'   => $latestVerCode,
            'min_version'           => $minVer,
            'min_version_code'      => $minVerCode,
            'min_supported_version' => $minVer,
            'platform'              => $platform,
            'download_url'          => $downloadUrl,
            'file_size'             => $fileSize,
            'apk_url'               => $downloadUrl,
            'apk_size'              => $fileSize,
            'release_notes'         => $releaseNotes,
            'release_notes_list'    => $notesList,
            'published_at'          => $publishedAt
        ], 'App update status fetched');
    }

    public function debugScrape(Request $request): void {
        $url = trim((string)$request->getQuery('url', 'https://new1.hdhub4u.af/search/panchayat/'));
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9'
        ]);

        $html = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        Response::success([
            'target_url'    => $url,
            'effective_url' => $effectiveUrl,
            'http_code'     => $httpCode,
            'curl_errno'    => $errno,
            'curl_error'    => $error,
            'html_length'   => strlen($html ?: ''),
            'html_snippet'  => substr($html ?: '', 0, 500)
        ], 'Scrape debug diagnostics');
    }
}
