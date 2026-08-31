<?php
/**
 * Milestone 3 Comprehensive Automated & Adversarial Stress Test Suite
 * Tests System Configuration (/api/v1/system/config), Platform-Aware Updates (/api/v1/system/check-update),
 * Force Update Mechanics, Dynamic Config Updates, Input Validation / Error Standardization,
 * Resilience under Corrupt/Empty Configs, SQL Injection Defenses, and Semver Precedence Matrix.
 */

declare(strict_types=1);

$phpBinary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBinary)) {
    $phpBinary = 'php';
}

$dbPath = __DIR__ . '/test_m3.sqlite';
if (file_exists($dbPath)) {
    @unlink($dbPath);
}

function getTestDb(): PDO {
    global $dbPath;
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10
    ]);
    $db->exec("PRAGMA busy_timeout = 5000;");
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->sqliteCreateFunction('NOW', function() {
        return date('Y-m-d H:i:s');
    });
    $db->sqliteCreateFunction('DATE_ADD', function($date, $interval) {
        return date('Y-m-d H:i:s', time() + 3600);
    });
    return $db;
}

function queryDb(string $sql, array $params = []): array {
    $db = getTestDb();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $stmt->closeCursor();
    $stmt = null;
    $db = null;
    return $rows;
}

function queryOne(string $sql, array $params = []): ?array {
    $rows = queryDb($sql, $params);
    return !empty($rows) ? $rows[0] : null;
}

function execDb(string $sql, array $params = []): int {
    $db = getTestDb();
    if (empty($params)) {
        $count = $db->exec($sql);
        $db = null;
        return $count !== false ? (int)$count : 0;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    $stmt->closeCursor();
    $stmt = null;
    $db = null;
    return $count;
}

function seedBaselineConfig(): void {
    $db = getTestDb();
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid CHAR(36) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(150) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NULL,
            avatar_url VARCHAR(500) NULL,
            auth_provider VARCHAR(50) NOT NULL DEFAULT 'email',
            google_id VARCHAR(100) NULL,
            is_verified INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS system_config (
            key_name VARCHAR(100) NOT NULL PRIMARY KEY,
            key_value TEXT NOT NULL,
            description VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        DELETE FROM system_config;

        INSERT INTO system_config (key_name, key_value, description) VALUES
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
        ('app_update_published_at', '2026-08-24 10:00:00', 'Timestamp of the latest published update'),
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
        ('player_buffer_size_mb', '2', 'Initial video buffer size in megabytes');
    ");
    $db = null;
}

seedBaselineConfig();

// Helper function to execute request runner script
function runApiRequest(string $method, string $uri, array $body = [], array $headers = []): array {
    global $phpBinary, $dbPath;

    $runnerScript = __DIR__ . '/run_single_request.php';
    $payload = json_encode([
        'db_path' => $dbPath,
        'method'  => $method,
        'uri'     => $uri,
        'body'    => $body,
        'headers' => $headers
    ]);

    $encoded = base64_encode($payload);
    $cmd = "\"{$phpBinary}\" \"{$runnerScript}\" {$encoded}";
    $output = shell_exec($cmd);

    $json = json_decode((string)$output, true);
    return [
        'raw'  => (string)$output,
        'json' => is_array($json) ? $json : null
    ];
}

$passCount = 0;
$failCount = 0;
$failures = [];

function assertTest(string $name, bool $condition, string $details = '', mixed $debug = null) {
    global $passCount, $failCount, $failures;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$name}\n";
    } else {
        $failCount++;
        $extra = ($debug !== null) ? " (DEBUG: " . (is_array($debug) ? json_encode($debug) : (string)$debug) . ")" : "";
        $failures[] = "{$name} - {$details}{$extra}";
        echo "  [FAIL] {$name} - {$details}{$extra}\n";
    }
}

echo "========================================================\n";
echo "MAXPLEX BACKEND - MILESTONE 3 ADVERSARIAL STRESS TEST\n";
echo "========================================================\n\n";

// ========================================================
// SECTION 1: GET /api/v1/system/config Structured Blocks & Strict Types
// ========================================================
echo "1. Testing Dynamic App Config (GET /api/v1/system/config):\n";
$configRes = runApiRequest('GET', '/api/v1/system/config');
$cJson = $configRes['json'];

assertTest("Response status is true", ($cJson['status'] ?? false) === true);
assertTest("Response data contains base_url string", is_string($cJson['data']['base_url'] ?? null) && str_starts_with($cJson['data']['base_url'], 'http'));
assertTest("base_url has no trailing slash", !str_ends_with($cJson['data']['base_url'] ?? '', '/'));

// Maintenance Block
$maintenance = $cJson['data']['maintenance'] ?? [];
assertTest("Maintenance block exists and is an array", is_array($maintenance));
assertTest("Maintenance enabled is strictly boolean false", is_bool($maintenance['enabled'] ?? null) && $maintenance['enabled'] === false);
assertTest("Maintenance title is non-empty string", !empty($maintenance['title']) && is_string($maintenance['title']));
assertTest("Maintenance message is non-empty string", !empty($maintenance['message']) && is_string($maintenance['message']));

// Features Block
$features = $cJson['data']['features'] ?? [];
assertTest("Features block exists and is an array", is_array($features));
$expectedFlags = [
    'tv_pairing_enabled',
    'cross_device_sync_enabled',
    'proxy_streaming_enabled',
    'downloads_enabled',
    'watchlist_enabled',
    'fcm_notifications_enabled'
];
foreach ($expectedFlags as $flag) {
    assertTest("Feature {$flag} is strictly boolean true", is_bool($features[$flag] ?? null) && ($features[$flag] === true));
}

// Player Block
$player = $cJson['data']['player'] ?? [];
assertTest("Player block exists and is an array", is_array($player));
assertTest("Player sync_interval_seconds is 15 (int)", is_int($player['sync_interval_seconds'] ?? null) && $player['sync_interval_seconds'] === 15);
assertTest("Player default_quality is '720p'", ($player['default_quality'] ?? '') === '720p');
assertTest("Player buffer_size_mb is 2 (int)", is_int($player['buffer_size_mb'] ?? null) && $player['buffer_size_mb'] === 2);

// Announcement Block
$announcement = $cJson['data']['announcement'] ?? [];
assertTest("Announcement block exists and is an array", is_array($announcement));
assertTest("Announcement banner is non-empty string", !empty($announcement['banner']) && is_string($announcement['banner']));
assertTest("Announcement show is strictly boolean true", is_bool($announcement['show'] ?? null) && $announcement['show'] === true);

// Version Block
$version = $cJson['data']['version'] ?? [];
assertTest("Version block exists and is an array", is_array($version));
assertTest("Version latest_version is '3.3.0'", ($version['latest_version'] ?? '') === '3.3.0');
assertTest("Version latest_version_code is 33 (int)", is_int($version['latest_version_code'] ?? null) && $version['latest_version_code'] === 33);
assertTest("Version min_version is '3.0.0'", ($version['min_version'] ?? '') === '3.0.0');
assertTest("Version min_version_code is 30 (int)", is_int($version['min_version_code'] ?? null) && $version['min_version_code'] === 30);


// ========================================================
// SECTION 2: Degraded Database / Missing Keys Fallback Resilience
// ========================================================
echo "\n2. Testing Resilience Under Empty/Degraded System Config Table:\n";
execDb("DELETE FROM system_config");

$emptyConfigRes = runApiRequest('GET', '/api/v1/system/config');
$emptyCfg = $emptyConfigRes['json'];

assertTest("Empty table: GET /api/v1/system/config returns status true without crash", ($emptyCfg['status'] ?? false) === true);
assertTest("Empty table: base_url safely defaults to https://new1.hdhub4u.af", ($emptyCfg['data']['base_url'] ?? null) === 'https://new1.hdhub4u.af');
assertTest("Empty table: maintenance.enabled safely defaults to boolean false", ($emptyCfg['data']['maintenance']['enabled'] ?? null) === false);
assertTest("Empty table: features.tv_pairing_enabled safely defaults to boolean true", ($emptyCfg['data']['features']['tv_pairing_enabled'] ?? null) === true);
assertTest("Empty table: player.sync_interval_seconds safely defaults to int 15", ($emptyCfg['data']['player']['sync_interval_seconds'] ?? null) === 15);
assertTest("Empty table: version.latest_version safely defaults to '3.3.0'", ($emptyCfg['data']['version']['latest_version'] ?? null) === '3.3.0');

// Corrupt URL in DB test
execDb("INSERT INTO system_config (key_name, key_value) VALUES ('hdhub4u_base_url', 'invalid_non_url_str')");
$corruptUrlRes = runApiRequest('GET', '/api/v1/system/config');
assertTest("Corrupt URL in DB: base_url falls back safely to default URL", ($corruptUrlRes['json']['data']['base_url'] ?? null) === 'https://new1.hdhub4u.af');

// Restore baseline
seedBaselineConfig();


// ========================================================
// SECTION 3: Dynamic Config Mutation, Persistence, Boolean Parsing & Security
// ========================================================
echo "\n3. Testing Dynamic System Config Modification (POST /api/v1/system/config):\n";

// 3.1 Boolean string parsing test matrix
$boolTestCases = [
    ['key_name' => 'app_maintenance_mode', 'key_value' => '1', 'expected' => true],
    ['key_name' => 'app_maintenance_mode', 'key_value' => '0', 'expected' => false],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'true', 'expected' => true],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'false', 'expected' => false],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'yes', 'expected' => true],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'no', 'expected' => false],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'on', 'expected' => true],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'off', 'expected' => false],
];

foreach ($boolTestCases as $tc) {
    $mutRes = runApiRequest('POST', '/api/v1/system/config', [
        'key_name'  => $tc['key_name'],
        'key_value' => $tc['key_value']
    ]);
    assertTest("POST config {$tc['key_name']}='{$tc['key_value']}' succeeded", ($mutRes['json']['status'] ?? false) === true);

    $checkRes = runApiRequest('GET', '/api/v1/system/config');
    $val = $checkRes['json']['data']['maintenance']['enabled'] ?? null;
    assertTest("maintenance.enabled normalized to " . ($tc['expected'] ? 'true' : 'false') . " for input '{$tc['key_value']}'", $val === $tc['expected']);
}

// 3.2 Dynamic update of player parameters
$mutPlayer = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => 'player_sync_interval_seconds',
    'key_value' => '45'
]);
assertTest("Update player_sync_interval_seconds returned status true", ($mutPlayer['json']['status'] ?? false) === true);

$checkPlayer = runApiRequest('GET', '/api/v1/system/config');
assertTest("player.sync_interval_seconds immediately reflects 45 (as int)", ($checkPlayer['json']['data']['player']['sync_interval_seconds'] ?? 0) === 45);

// 3.3 New arbitrary configuration key insertion
$customKey = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => 'custom_theme_accent',
    'key_value' => '#E50914'
]);
assertTest("Inserting custom key 'custom_theme_accent' returns status true", ($customKey['json']['status'] ?? false) === true);
$dbCheck = queryDb("SELECT key_value FROM system_config WHERE key_name = 'custom_theme_accent'");
assertTest("Custom key successfully stored in database", ($dbCheck[0]['key_value'] ?? '') === '#E50914');

// 3.4 SQL Injection Defense in key_name and key_value
$sqlInjKey = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => "sqli_test'; DROP TABLE system_config; --",
    'key_value' => "danger_val"
]);
assertTest("SQL injection in key_name handled safely without table drop", ($sqlInjKey['json']['status'] ?? false) === true);
$tableCheck = queryDb("SELECT count(*) as cnt FROM system_config");
assertTest("system_config table remains fully intact after SQL injection attempt", ($tableCheck[0]['cnt'] ?? 0) > 0);

// 3.5 Special characters, Unicode and Emoji in key_value
$emojiVal = "🚀 Ultra HD 4K | <script>alert('xss')</script> | 100% Guaranteed! ✨";
$emojiMut = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => 'announcement_banner',
    'key_value' => $emojiVal
]);
assertTest("Special chars and emoji stored successfully", ($emojiMut['json']['status'] ?? false) === true);

$checkEmoji = runApiRequest('GET', '/api/v1/system/config');
assertTest("announcement.banner preserved verbatim with emoji & special chars", ($checkEmoji['json']['data']['announcement']['banner'] ?? '') === $emojiVal);

// 3.6 High-Frequency Rapid Config Mutation (20 rapid cycles)
$rapidSuccess = true;
for ($i = 1; $i <= 20; $i++) {
    $val = ($i % 2 === 0) ? '1' : '0';
    $r = runApiRequest('POST', '/api/v1/system/config', [
        'key_name'  => 'features_downloads_enabled',
        'key_value' => $val
    ]);
    if (($r['json']['status'] ?? false) !== true) {
        $rapidSuccess = false;
        break;
    }
}
assertTest("20 rapid consecutive config mutations executed without failure", $rapidSuccess);
$finalDownloads = runApiRequest('GET', '/api/v1/system/config');
assertTest("Final state matches last iteration (boolean true)", ($finalDownloads['json']['data']['features']['downloads_enabled'] ?? false) === true);

// Restore baseline
seedBaselineConfig();


// ========================================================
// SECTION 4: Platform-Aware App Updates (Android Mobile, TV, Windows & Aliases)
// ========================================================
echo "\n4. Testing Platform-Aware App Updates (GET /api/v1/system/check-update):\n";

$platformTests = [
    'android_mobile' => ['platform' => 'android_mobile', 'file' => 'hdhub4u-v3.3.0.apk', 'size' => '19.2 MB'],
    'android'        => ['platform' => 'android_mobile', 'file' => 'hdhub4u-v3.3.0.apk', 'size' => '19.2 MB'],
    'mobile'         => ['platform' => 'android_mobile', 'file' => 'hdhub4u-v3.3.0.apk', 'size' => '19.2 MB'],
    'ANDROID_MOBILE' => ['platform' => 'android_mobile', 'file' => 'hdhub4u-v3.3.0.apk', 'size' => '19.2 MB'],
    '  Mobile  '     => ['platform' => 'android_mobile', 'file' => 'hdhub4u-v3.3.0.apk', 'size' => '19.2 MB'],
    'android_tv'     => ['platform' => 'android_tv',     'file' => 'maxplex-tv-v3.3.0.apk', 'size' => '24.5 MB'],
    'tv'             => ['platform' => 'android_tv',     'file' => 'maxplex-tv-v3.3.0.apk', 'size' => '24.5 MB'],
    'firetv'         => ['platform' => 'android_tv',     'file' => 'maxplex-tv-v3.3.0.apk', 'size' => '24.5 MB'],
    'TV'             => ['platform' => 'android_tv',     'file' => 'maxplex-tv-v3.3.0.apk', 'size' => '24.5 MB'],
    'windows'        => ['platform' => 'windows',        'file' => 'maxplex-setup-v3.3.0.exe', 'size' => '68.0 MB'],
    'desktop'        => ['platform' => 'windows',        'file' => 'maxplex-setup-v3.3.0.exe', 'size' => '68.0 MB'],
    'pc'             => ['platform' => 'windows',        'file' => 'maxplex-setup-v3.3.0.exe', 'size' => '68.0 MB'],
    'win'            => ['platform' => 'windows',        'file' => 'maxplex-setup-v3.3.0.exe', 'size' => '68.0 MB'],
    'WINDOWS'        => ['platform' => 'windows',        'file' => 'maxplex-setup-v3.3.0.exe', 'size' => '68.0 MB'],
];

foreach ($platformTests as $inputPlat => $expected) {
    $encodedPlat = urlencode($inputPlat);
    $res = runApiRequest('GET', "/api/v1/system/check-update?platform={$encodedPlat}");
    $j = $res['json'];
    assertTest("Platform '{$inputPlat}' resolves status = true", ($j['status'] ?? false) === true);
    assertTest("Platform '{$inputPlat}' resolves platform to '{$expected['platform']}'", ($j['data']['platform'] ?? '') === $expected['platform']);
    assertTest("Platform '{$inputPlat}' download_url contains '{$expected['file']}'", str_contains($j['data']['download_url'] ?? '', $expected['file']));
    assertTest("Platform '{$inputPlat}' file_size is '{$expected['size']}'", ($j['data']['file_size'] ?? '') === $expected['size']);
}

// Default platform when omitted
$defaultRes = runApiRequest('GET', '/api/v1/system/check-update?version=3.0.0&version_code=30');
assertTest("Default platform resolves to 'android_mobile'", ($defaultRes['json']['data']['platform'] ?? '') === 'android_mobile');
assertTest("Default platform download_url is mobile APK", ($defaultRes['json']['data']['download_url'] ?? '') === 'https://mov.aimacademycbse.com/downloads/hdhub4u-v3.3.0.apk');


// ========================================================
// SECTION 5: Exhaustive Semver, Version Code & Force Update Precedence Matrix
// ========================================================
echo "\n5. Testing Exhaustive Version Comparison & Force Update Precedence Matrix:\n";

$precedenceMatrix = [
    ['Exact match at latest (v3.3.0 code 33)', '3.3.0', 33, '0', false, false],
    ['Exact match at latest with app_force_update=1', '3.3.0', 33, '1', false, false],
    ['Ahead of latest (v3.4.0 code 34)', '3.4.0', 34, '0', false, false],
    ['Ahead of latest with app_force_update=1', '3.4.0', 34, '1', false, false],
    ['Same semver 3.3.0, higher code 34', '3.3.0', 34, '0', false, false],
    ['Higher semver 3.4.0, same code 33', '3.4.0', 33, '0', false, false],
    ['Optional update (v3.2.0 code 32)', '3.2.0', 32, '0', true, false],
    ['Optional update (v3.1.0 code 31)', '3.1.0', 31, '0', true, false],
    ['Optional update exact at min boundary (v3.0.0 code 30)', '3.0.0', 30, '0', true, false],
    ['Optional update (v3.2.0 code 32) with app_force_update=1', '3.2.0', 32, '1', true, true],
    ['Optional update (v3.1.0 code 31) with app_force_update=1', '3.1.0', 31, '1', true, true],
    ['Optional update at min boundary (v3.0.0 code 30) with app_force_update=1', '3.0.0', 30, '1', true, true],
    ['Force update via code 29 < min 30 (semver 3.0.0)', '3.0.0', 29, '0', true, true],
    ['Force update via code 25 < min 30 (semver 3.2.0)', '3.2.0', 25, '0', true, true],
    ['Force update via semver 2.9.9 < min 3.0.0 (code 30)', '2.9.9', 30, '0', true, true],
    ['Force update via semver 2.5.0 < min 3.0.0 (code 32)', '2.5.0', 32, '0', true, true],
    ['Force update via ancient client (v1.0.0 code 10)', '1.0.0', 10, '0', true, true],
    ['Force update via code 0', '0.0.1', 0, '0', true, true],
    ['Pre-release tag v3.3.0-beta.1 (code 33)', '3.3.0-beta.1', 33, '0', true, false],
    ['Four-part patch v3.3.0.1 (code 33)', '3.3.0.1', 33, '0', false, false],
];

foreach ($precedenceMatrix as $row) {
    [$name, $ver, $verCode, $forceFlag, $expUpdate, $expForce] = $row;

    if ($forceFlag === '1') {
        execDb("UPDATE system_config SET key_value = '1' WHERE key_name = 'app_force_update'");
    } else {
        execDb("UPDATE system_config SET key_value = '0' WHERE key_name = 'app_force_update'");
    }

    $res = runApiRequest('GET', "/api/v1/system/check-update?version={$ver}&version_code={$verCode}");
    $j = $res['json'];

    assertTest("Precedence [{$name}]: update_available is " . ($expUpdate ? 'true' : 'false'), ($j['data']['update_available'] ?? null) === $expUpdate);
    assertTest("Precedence [{$name}]: force_update is " . ($expForce ? 'true' : 'false'), ($j['data']['force_update'] ?? null) === $expForce);
}

execDb("UPDATE system_config SET key_value = '0' WHERE key_name = 'app_force_update'");


// ========================================================
// SECTION 6: Route Aliases & Secondary Update Endpoints
// ========================================================
echo "\n6. Testing Route Aliases & Metadata Integrity (/api/v1/app/update):\n";
$appUpdateRes = runApiRequest('GET', '/api/v1/app/update?platform=android_tv&version=2.0.0&version_code=20');
$aData = $appUpdateRes['json']['data'] ?? [];

assertTest("Route alias /api/v1/app/update status is true", ($appUpdateRes['json']['status'] ?? false) === true);
assertTest("Route alias returns matching platform 'android_tv'", ($aData['platform'] ?? '') === 'android_tv');
assertTest("Route alias correctly marks force_update = true for outdated code", ($aData['force_update'] ?? false) === true);
assertTest("Alias data is_force_update boolean alias exists and matches force_update", ($aData['is_force_update'] ?? null) === true);
assertTest("Alias data min_supported_version alias exists and matches min_version", ($aData['min_supported_version'] ?? null) === '3.0.0');
assertTest("Alias data apk_url alias matches download_url", ($aData['apk_url'] ?? null) === $aData['download_url']);
assertTest("Alias data apk_size alias matches file_size", ($aData['apk_size'] ?? null) === $aData['file_size']);
assertTest("Response contains release_notes string", !empty($aData['release_notes']) && is_string($aData['release_notes']));
assertTest("Response contains release_notes_list array", is_array($aData['release_notes_list'] ?? null) && count($aData['release_notes_list']) === 3);
assertTest("Response contains published_at timestamp", !empty($aData['published_at']) && strtotime($aData['published_at']) !== false);


// ========================================================
// SECTION 7: Input Validation & Error Handling (HTTP 422 / 404)
// ========================================================
echo "\n7. Testing Input Validation & Error Responses:\n";

// 7.1 Unsupported platforms
$invalidPlatforms = [
    'ios',
    'macos',
    'linux',
    'ubuntu',
    'tizen',
    'webos',
    'roku',
    'playstation',
    'xbox',
    'unknown_os',
    '../../../etc/passwd',
    "<script>alert(1)</script>",
    "android_tv' OR 1=1--"
];

foreach ($invalidPlatforms as $badPlat) {
    $encodedBad = urlencode($badPlat);
    $res = runApiRequest('GET', "/api/v1/system/check-update?platform={$encodedBad}");
    $j = $res['json'];
    assertTest("Invalid platform '{$badPlat}' returns status = false (HTTP 422)", ($j['status'] ?? true) === false);
    assertTest("Invalid platform '{$badPlat}' error message lists supported platforms", str_contains($j['message'] ?? '', 'Supported platforms'));
}

// 7.2 Negative version_code inputs
$negCodes = ['-1', '-10', '-99999'];
foreach ($negCodes as $neg) {
    $res = runApiRequest('GET', "/api/v1/system/check-update?version_code={$neg}");
    assertTest("Negative version_code {$neg} returns status false (HTTP 422)", ($res['json']['status'] ?? true) === false);
    assertTest("Negative version_code error mentions positive integer", str_contains($res['json']['message'] ?? '', 'positive integer'));
}

// 7.3 Non-numeric version_code inputs
$badCodes = ['abc', '33a', 'NaN', 'null', 'undefined', 'true', 'v33', '12_34'];
foreach ($badCodes as $bad) {
    $res = runApiRequest('GET', "/api/v1/system/check-update?version_code=" . urlencode($bad));
    assertTest("Non-numeric version_code '{$bad}' returns status false (HTTP 422)", ($res['json']['status'] ?? true) === false);
}

// 7.4 Large 32-bit integer version code
$largeCodeRes = runApiRequest('GET', '/api/v1/system/check-update?version_code=2147483647&version=99.0.0');
assertTest("Huge version code handled gracefully without crash", ($largeCodeRes['json']['status'] ?? false) === true, '', $largeCodeRes);
assertTest("Huge version code recognized as up-to-date", ($largeCodeRes['json']['data']['update_available'] ?? true) === false, '', $largeCodeRes);

// 7.5 Missing / empty key_name in POST /api/v1/system/config
$missingKeyRes = runApiRequest('POST', '/api/v1/system/config', ['key_value' => 'some_val']);
assertTest("Missing key_name returns status false (HTTP 422)", ($missingKeyRes['json']['status'] ?? true) === false, '', $missingKeyRes);
assertTest("Missing key_name error mentions key_name is required", str_contains($missingKeyRes['json']['message'] ?? '', 'key_name is required'), '', $missingKeyRes);

$emptyKeyRes = runApiRequest('POST', '/api/v1/system/config', ['key_name' => '   ', 'key_value' => 'val']);
assertTest("Whitespace-only key_name returns status false (HTTP 422)", ($emptyKeyRes['json']['status'] ?? true) === false, '', $emptyKeyRes);

// 7.6 Non-existent route 404
$notFoundRes = runApiRequest('GET', '/api/v1/system/non-existent-endpoint');
assertTest("Non-existent route returns status false", ($notFoundRes['json']['status'] ?? true) === false, '', $notFoundRes);


// ========================================================
// SECTION 8: Cross-Milestone Config Synchronization
// ========================================================
echo "\n8. Testing Dynamic Version Bump & Immediate Update Propagation:\n";

// Update version in system_config via POST /api/v1/system/config
$vUpdate = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => 'app_latest_version',
    'key_value' => '4.0.0'
]);
assertTest("POST config update app_latest_version to '4.0.0' succeeded", ($vUpdate['json']['status'] ?? false) === true, '', $vUpdate);

$cUpdate = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => 'app_latest_version_code',
    'key_value' => '40'
]);
assertTest("POST config update app_latest_version_code to '40' succeeded", ($cUpdate['json']['status'] ?? false) === true, '', $cUpdate);

// Check that GET /api/v1/system/config immediately reflects 4.0.0 and 40
$configCheck = runApiRequest('GET', '/api/v1/system/config');
assertTest("GET config reflects latest_version '4.0.0'", ($configCheck['json']['data']['version']['latest_version'] ?? '') === '4.0.0', '', $configCheck);
assertTest("GET config reflects latest_version_code 40", ($configCheck['json']['data']['version']['latest_version_code'] ?? 0) === 40, '', $configCheck);

// Check that client previously on 3.3.0 now receives update_available = true against 4.0.0
$updateCheck = runApiRequest('GET', '/api/v1/system/check-update?version=3.3.0&version_code=33');
assertTest("Client on 3.3.0 now detects update_available = true for new 4.0.0 release", ($updateCheck['json']['data']['update_available'] ?? false) === true, '', $updateCheck);
assertTest("Client on 3.3.0 receives latest_version '4.0.0'", ($updateCheck['json']['data']['latest_version'] ?? '') === '4.0.0', '', $updateCheck);
assertTest("Client on 3.3.0 receives latest_version_code 40", ($updateCheck['json']['data']['latest_version_code'] ?? 0) === 40, '', $updateCheck);

// Client on 4.0.0 is up-to-date
$updateCheck4 = runApiRequest('GET', '/api/v1/system/check-update?version=4.0.0&version_code=40');
assertTest("Client on 4.0.0 detects update_available = false", ($updateCheck4['json']['data']['update_available'] ?? true) === false, '', $updateCheck4);

// Clean up
seedBaselineConfig();

echo "\n========================================================\n";
echo "SUMMARY: {$passCount} passed, {$failCount} failed\n";
echo "========================================================\n";

if ($failCount > 0) {
    echo "\n>>> FAILURES ENCOUNTERED:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
} else {
    echo "\n>>> EMPIRICAL CHALLENGER VERDICT: ALL {$passCount} ASSERTIONS PASSED (100% PASS RATE) <<<\n";
    exit(0);
}
