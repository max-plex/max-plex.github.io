<?php
/**
 * Challenger Adversarial & Empirical Stress Test Suite for Milestone 3
 * Focus:
 * 1. Deep Structural & Type Safety of GET /api/v1/system/config
 * 2. Resilience under Empty/Corrupted DB Configurations
 * 3. Dynamic Configuration Mutation, Persistence & SQL Injection Resilience (POST /api/v1/system/config)
 * 4. High-Frequency Rapid Config Mutation Concurrency
 * 5. Platform Resolution, Aliases, Whitespace Normalization & Rejection of Invalid Platforms (GET /api/v1/system/check-update)
 * 6. Semver & Integer Version Code Precedence, Boundary Values, Negative Inputs & Global Force Overrides
 * 7. Release Notes Parsing, Published Timestamp & Route Aliases (/api/v1/app/update)
 */

declare(strict_types=1);

$phpBinary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBinary)) {
    $phpBinary = 'php';
}

$dbPath = __DIR__ . '/test_challenger_m3.sqlite';
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
        CREATE TABLE IF NOT EXISTS system_config (
            key_name VARCHAR(100) NOT NULL PRIMARY KEY,
            key_value TEXT NOT NULL,
            description VARCHAR(255) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        DELETE FROM system_config;
        INSERT INTO system_config (key_name, key_value, description) VALUES
        ('hdhub4u_base_url', 'https://new1.hdhub4u.af', 'Dynamic live base URL for scraping engine'),
        ('app_maintenance_mode', '0', 'Maintenance mode flag'),
        ('maintenance_title', 'Scheduled Maintenance', 'Maintenance modal title'),
        ('maintenance_message', 'Maxplex services are currently undergoing maintenance.', 'Maintenance message'),
        ('announcement_banner', 'Welcome to Maxplex OTT Streaming Engine!', 'App banner notification text'),
        ('announcement_show', '1', 'Toggle visibility of the announcement banner'),
        ('app_latest_version', '3.3.0', 'Latest APK version'),
        ('app_latest_version_code', '33', 'Latest APK version integer code'),
        ('app_min_version', '3.0.0', 'Minimum supported version'),
        ('app_min_version_code', '30', 'Minimum supported version code'),
        ('app_force_update', '0', 'Mandatory update flag'),
        ('app_apk_url', 'https://mov.aimacademycbse.com/downloads/hdhub4u-v3.3.0.apk', 'Android Mobile APK URL'),
        ('app_apk_size', '19.2 MB', 'Android Mobile APK size'),
        ('app_tv_apk_url', 'https://mov.aimacademycbse.com/downloads/maxplex-tv-v3.3.0.apk', 'Android TV APK URL'),
        ('app_tv_apk_size', '24.5 MB', 'Android TV APK size'),
        ('app_windows_url', 'https://mov.aimacademycbse.com/downloads/maxplex-setup-v3.3.0.exe', 'Windows installer URL'),
        ('app_windows_size', '68.0 MB', 'Windows installer size'),
        ('app_release_notes', '🚀 4K 60FPS Direct Video Streaming\n⚡ Faster HubCloud Token Bypass\n🐞 Subtitle Sync Fixes', 'Changelog'),
        ('app_update_published_at', '2026-08-24 10:00:00', 'Timestamp of latest published update'),
        ('features_tv_pairing_enabled', '1', 'Toggle for TV pairing'),
        ('features_cross_device_sync_enabled', '1', 'Toggle for cross-device sync'),
        ('features_proxy_streaming_enabled', '1', 'Toggle for proxy streaming'),
        ('features_downloads_enabled', '1', 'Toggle for offline downloads'),
        ('features_watchlist_enabled', '1', 'Toggle for user watchlist'),
        ('features_fcm_notifications_enabled', '1', 'Toggle for FCM push notifications'),
        ('player_sync_interval_seconds', '15', 'Interval in seconds between player syncs'),
        ('player_default_quality', '720p', 'Default video playback quality'),
        ('player_buffer_size_mb', '2', 'Initial buffer size in MB');
    ");
    $db = null;
}

seedBaselineConfig();

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

function assertTest(string $name, bool $condition, string $details = '') {
    global $passCount, $failCount, $failures;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$name}\n";
    } else {
        $failCount++;
        $failures[] = "{$name} - {$details}";
        echo "  [FAIL] {$name} - {$details}\n";
    }
}

echo "====================================================================\n";
echo "CHALLENGER EMPIRICAL ADVERSARIAL TEST SUITE - MILESTONE 3\n";
echo "====================================================================\n\n";

// =========================================================================
// CATEGORY 1: Deep Type Safety & Envelope Conformance of GET /api/v1/system/config
// =========================================================================
echo "1. Testing Strict Type Safety & Envelope of GET /api/v1/system/config:\n";
$configRes = runApiRequest('GET', '/api/v1/system/config');
$cfg = $configRes['json'];

assertTest("Config response status is true", ($cfg['status'] ?? false) === true);
assertTest("Config message is non-empty string", is_string($cfg['message'] ?? null) && !empty($cfg['message']));
assertTest("Config data is an associative array", is_array($cfg['data'] ?? null));

$data = $cfg['data'] ?? [];

// Base URL
assertTest("base_url is valid string URL", is_string($data['base_url'] ?? null) && filter_var($data['base_url'], FILTER_VALIDATE_URL) !== false);
assertTest("base_url has no trailing slash", !str_ends_with($data['base_url'] ?? '', '/'));

// Maintenance block
assertTest("maintenance is array", is_array($data['maintenance'] ?? null));
assertTest("maintenance.enabled is strictly boolean false", is_bool($data['maintenance']['enabled'] ?? null) && $data['maintenance']['enabled'] === false);
assertTest("maintenance.title is non-empty string", is_string($data['maintenance']['title'] ?? null) && !empty($data['maintenance']['title']));
assertTest("maintenance.message is non-empty string", is_string($data['maintenance']['message'] ?? null) && !empty($data['maintenance']['message']));

// Features block
$features = $data['features'] ?? [];
assertTest("features is array", is_array($features));
$expectedFlags = [
    'tv_pairing_enabled',
    'cross_device_sync_enabled',
    'proxy_streaming_enabled',
    'downloads_enabled',
    'watchlist_enabled',
    'fcm_notifications_enabled'
];
foreach ($expectedFlags as $flag) {
    assertTest("feature flag {$flag} is strictly boolean", is_bool($features[$flag] ?? null));
    assertTest("feature flag {$flag} is boolean true by default", ($features[$flag] ?? false) === true);
}

// Player block
$player = $data['player'] ?? [];
assertTest("player is array", is_array($player));
assertTest("player.sync_interval_seconds is strictly integer", is_int($player['sync_interval_seconds'] ?? null) && $player['sync_interval_seconds'] === 15);
assertTest("player.default_quality is string '720p'", ($player['default_quality'] ?? null) === '720p');
assertTest("player.buffer_size_mb is strictly integer", is_int($player['buffer_size_mb'] ?? null) && $player['buffer_size_mb'] === 2);

// Announcement block
$announcement = $data['announcement'] ?? [];
assertTest("announcement is array", is_array($announcement));
assertTest("announcement.banner is string", is_string($announcement['banner'] ?? null) && !empty($announcement['banner']));
assertTest("announcement.show is strictly boolean true", is_bool($announcement['show'] ?? null) && $announcement['show'] === true);

// Version block
$verBlock = $data['version'] ?? [];
assertTest("version is array", is_array($verBlock));
assertTest("version.latest_version is '3.3.0'", ($verBlock['latest_version'] ?? null) === '3.3.0');
assertTest("version.latest_version_code is strictly int 33", is_int($verBlock['latest_version_code'] ?? null) && $verBlock['latest_version_code'] === 33);
assertTest("version.min_version is '3.0.0'", ($verBlock['min_version'] ?? null) === '3.0.0');
assertTest("version.min_version_code is strictly int 30", is_int($verBlock['min_version_code'] ?? null) && $verBlock['min_version_code'] === 30);


// =========================================================================
// CATEGORY 2: Degraded Database / Missing Keys Fallback Resilience
// =========================================================================
echo "\n2. Testing Resilience Under Empty/Degraded System Config Table:\n";
// Wipe all rows from system_config
execDb("DELETE FROM system_config");

$emptyConfigRes = runApiRequest('GET', '/api/v1/system/config');
$emptyCfg = $emptyConfigRes['json'];

assertTest("Empty table: GET /api/v1/system/config returns status true without crash", ($emptyCfg['status'] ?? false) === true);
assertTest("Empty table: base_url safely defaults to https://new1.hdhub4u.af", ($emptyCfg['data']['base_url'] ?? null) === 'https://new1.hdhub4u.af');
assertTest("Empty table: maintenance.enabled safely defaults to boolean false", ($emptyCfg['data']['maintenance']['enabled'] ?? null) === false);
assertTest("Empty table: features.tv_pairing_enabled safely defaults to boolean true", ($emptyCfg['data']['features']['tv_pairing_enabled'] ?? null) === true);
assertTest("Empty table: player.sync_interval_seconds safely defaults to int 15", ($emptyCfg['data']['player']['sync_interval_seconds'] ?? null) === 15);
assertTest("Empty table: version.latest_version safely defaults to '3.3.0'", ($emptyCfg['data']['version']['latest_version'] ?? null) === '3.3.0');

// Test invalid URL in database fallback
execDb("INSERT INTO system_config (key_name, key_value) VALUES ('hdhub4u_base_url', 'invalid-non-url-string')");
$corruptUrlRes = runApiRequest('GET', '/api/v1/system/config');
assertTest("Corrupt URL in DB: base_url falls back safely to default URL", ($corruptUrlRes['json']['data']['base_url'] ?? null) === 'https://new1.hdhub4u.af');

// Restore baseline
seedBaselineConfig();


// =========================================================================
// CATEGORY 3: Dynamic Config Mutation, Persistence & SQL Injection Resilience
// =========================================================================
echo "\n3. Testing POST /api/v1/system/config Mutations, Persistence & Security:\n";

// 3.1 Toggle boolean flags via various string inputs ('1', '0', 'true', 'false', 'yes', 'no', 'on', 'off')
$boolTestCases = [
    ['key_name' => 'app_maintenance_mode', 'key_value' => '1', 'path' => 'maintenance.enabled', 'expected' => true],
    ['key_name' => 'app_maintenance_mode', 'key_value' => '0', 'path' => 'maintenance.enabled', 'expected' => false],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'true', 'path' => 'maintenance.enabled', 'expected' => true],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'false', 'path' => 'maintenance.enabled', 'expected' => false],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'yes', 'path' => 'maintenance.enabled', 'expected' => true],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'no', 'path' => 'maintenance.enabled', 'expected' => false],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'on', 'path' => 'maintenance.enabled', 'expected' => true],
    ['key_name' => 'app_maintenance_mode', 'key_value' => 'off', 'path' => 'maintenance.enabled', 'expected' => false],
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
    'key_name'  => 'custom_banner_color',
    'key_value' => '#FF0055'
]);
assertTest("Inserting custom key 'custom_banner_color' returns status true", ($customKey['json']['status'] ?? false) === true);
$dbCheck = queryDb("SELECT key_value FROM system_config WHERE key_name = 'custom_banner_color'");
assertTest("Custom key successfully stored in database", ($dbCheck[0]['key_value'] ?? '') === '#FF0055');

// 3.4 Input Validation Errors on POST /api/v1/system/config
$missingKeyRes1 = runApiRequest('POST', '/api/v1/system/config', ['key_value' => 'only_value']);
assertTest("Missing key_name returns status false (HTTP 422)", ($missingKeyRes1['json']['status'] ?? true) === false);

$emptyKeyRes = runApiRequest('POST', '/api/v1/system/config', ['key_name' => '   ', 'key_value' => 'val']);
assertTest("Whitespace-only key_name returns status false (HTTP 422)", ($emptyKeyRes['json']['status'] ?? true) === false);

// 3.5 SQL Injection Defense in key_name and key_value
$sqlInjKey = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => "sqli_test'; DROP TABLE system_config; --",
    'key_value' => "danger_val"
]);
assertTest("SQL injection in key_name handled safely without table drop", ($sqlInjKey['json']['status'] ?? false) === true);
$tableCheck = queryDb("SELECT count(*) as cnt FROM system_config");
assertTest("system_config table remains fully intact after SQL injection attempt", ($tableCheck[0]['cnt'] ?? 0) > 0);

// 3.6 Special characters, Unicode and Emoji in key_value
$emojiVal = "🚀 Ultra HD 4K | <script>alert('xss')</script> | 100% Guaranteed! ✨";
$emojiMut = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => 'announcement_banner',
    'key_value' => $emojiVal
]);
assertTest("Special chars and emoji stored successfully", ($emojiMut['json']['status'] ?? false) === true);

$checkEmoji = runApiRequest('GET', '/api/v1/system/config');
assertTest("announcement.banner preserved verbatim with emoji & special chars", ($checkEmoji['json']['data']['announcement']['banner'] ?? '') === $emojiVal);

// 3.7 High-Frequency Config Mutations (20 rapid cycles)
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


// =========================================================================
// CATEGORY 4: Platform Resolution, Aliases & Malformed Platform Rejection
// =========================================================================
echo "\n4. Testing Platform Resolution & Malformed Platform Handling (GET /api/v1/system/check-update):\n";

// 4.1 Valid Platform & Alias Resolution Matrix
$platformTests = [
    // Input platform => Expected normalized platform & substring in URL
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
    assertTest("Platform '{$inputPlat}' resolves download_url containing '{$expected['file']}'", str_contains($j['data']['download_url'] ?? '', $expected['file']));
    assertTest("Platform '{$inputPlat}' resolves file_size to '{$expected['size']}'", ($j['data']['file_size'] ?? '') === $expected['size']);
}

// 4.2 Adversarial & Invalid Platform Inputs (Strict HTTP 422)
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


// =========================================================================
// CATEGORY 5: Exhaustive Semver, Version Code & Force Update Precedence Matrix
// =========================================================================
echo "\n5. Testing Exhaustive Version Comparison & Force-Update Precedence Matrix:\n";
// Baseline DB: latest = 3.3.0 (code 33), min = 3.0.0 (code 30), app_force_update = 0

// Matrix Definition:
// [name, version, version_code, force_flag, exp_update_avail, exp_force_update]
$precedenceMatrix = [
    // 1. Fully up to date (exact match)
    ['Exact match at latest', '3.3.0', 33, '0', false, false],
    // 2. Exact match at latest with force flag enabled (no update available, so cannot force!)
    ['Exact match at latest with app_force_update=1', '3.3.0', 33, '1', false, false],
    // 3. Ahead of latest (beta version)
    ['Ahead of latest (v3.4.0 code 34)', '3.4.0', 34, '0', false, false],
    ['Ahead of latest with app_force_update=1', '3.4.0', 34, '1', false, false],
    // 4. Higher code, same semver
    ['Same semver 3.3.0, higher code 34', '3.3.0', 34, '0', false, false],
    // 5. Higher semver 3.4.0, same code 33
    ['Higher semver 3.4.0, same code 33', '3.4.0', 33, '0', false, false],
    // 6. Optional update (between min and latest)
    ['Optional update (v3.2.0 code 32)', '3.2.0', 32, '0', true, false],
    ['Optional update (v3.1.0 code 31)', '3.1.0', 31, '0', true, false],
    ['Optional update exact at min boundary (v3.0.0 code 30)', '3.0.0', 30, '0', true, false],
    // 7. Optional update with global force flag active
    ['Optional update (v3.2.0 code 32) with app_force_update=1', '3.2.0', 32, '1', true, true],
    ['Optional update (v3.1.0 code 31) with app_force_update=1', '3.1.0', 31, '1', true, true],
    ['Optional update at min boundary (v3.0.0 code 30) with app_force_update=1', '3.0.0', 30, '1', true, true],
    // 8. Force update triggered by version_code < min_version_code (code 29 < 30)
    ['Force update via code 29 < min 30 (semver 3.0.0)', '3.0.0', 29, '0', true, true],
    ['Force update via code 25 < min 30 (semver 3.2.0)', '3.2.0', 25, '0', true, true],
    // 9. Force update triggered by semver < min_version ('2.9.9' < '3.0.0')
    ['Force update via semver 2.9.9 < min 3.0.0 (code 30)', '2.9.9', 30, '0', true, true],
    ['Force update via semver 2.5.0 < min 3.0.0 (code 32)', '2.5.0', 32, '0', true, true],
    // 10. Force update triggered by both code and semver
    ['Force update via ancient client (v1.0.0 code 10)', '1.0.0', 10, '0', true, true],
    ['Force update via code 0', '0.0.1', 0, '0', true, true],
    // 11. Semver pre-release tag vs release
    ['Pre-release tag v3.3.0-beta.1 (code 33)', '3.3.0-beta.1', 33, '0', true, false],
    // 12. Four-part semver ahead
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

// Restore force update to 0
execDb("UPDATE system_config SET key_value = '0' WHERE key_name = 'app_force_update'");


// =========================================================================
// CATEGORY 6: Version Code Boundary & Input Validation Stress
// =========================================================================
echo "\n6. Testing Version Code Validation & Boundary Inputs:\n";

// 6.1 Negative version_code inputs (HTTP 422)
$negCodes = ['-1', '-10', '-99999'];
foreach ($negCodes as $neg) {
    $res = runApiRequest('GET', "/api/v1/system/check-update?version_code={$neg}");
    assertTest("Negative version_code {$neg} returns status false (HTTP 422)", ($res['json']['status'] ?? true) === false);
    assertTest("Negative version_code error mentions must be a positive integer", str_contains($res['json']['message'] ?? '', 'positive integer'));
}

// 6.2 Non-numeric version_code inputs (HTTP 422)
$badCodes = ['abc', '33a', 'NaN', 'null', 'undefined', 'true', 'v33', '12_34'];
foreach ($badCodes as $bad) {
    $res = runApiRequest('GET', "/api/v1/system/check-update?version_code=" . urlencode($bad));
    assertTest("Non-numeric version_code '{$bad}' returns status false (HTTP 422)", ($res['json']['status'] ?? true) === false);
}

// 6.3 Extremely large 64-bit integer version code
$largeCodeRes = runApiRequest('GET', '/api/v1/system/check-update?version_code=9223372036854775800&version=99.0.0');
assertTest("Huge version code handled gracefully", ($largeCodeRes['json']['status'] ?? false) === true);
assertTest("Huge version code recognized as up-to-date (no update)", ($largeCodeRes['json']['data']['update_available'] ?? true) === false);

// 6.4 Version code omitted -> defaults to 1
$omittedRes = runApiRequest('GET', '/api/v1/system/check-update');
assertTest("Omitted version_code and version handled gracefully", ($omittedRes['json']['status'] ?? false) === true);
assertTest("Omitted version defaults to 1.0.0 and triggers update", ($omittedRes['json']['data']['update_available'] ?? false) === true);


// =========================================================================
// CATEGORY 7: Route Aliases, Release Notes & Response Normalization
// =========================================================================
echo "\n7. Testing Route Aliases & Metadata Integrity (/api/v1/app/update):\n";

$aliasRes = runApiRequest('GET', '/api/v1/app/update?platform=windows&version=2.0.0&version_code=20');
$aData = $aliasRes['json']['data'] ?? [];

assertTest("Route alias /api/v1/app/update status is true", ($aliasRes['json']['status'] ?? false) === true);
assertTest("Alias data platform is 'windows'", ($aData['platform'] ?? null) === 'windows');
assertTest("Alias data update_available is true", ($aData['update_available'] ?? null) === true);
assertTest("Alias data force_update is true", ($aData['force_update'] ?? null) === true);
assertTest("Alias data is_force_update boolean alias exists and matches force_update", ($aData['is_force_update'] ?? null) === true);
assertTest("Alias data min_supported_version alias exists and matches min_version", ($aData['min_supported_version'] ?? null) === '3.0.0');
assertTest("Alias data apk_url alias matches download_url", ($aData['apk_url'] ?? null) === $aData['download_url']);
assertTest("Alias data apk_size alias matches file_size", ($aData['apk_size'] ?? null) === $aData['file_size']);

// Release notes list parsing (splits newline-delimited bullet points)
assertTest("release_notes_list is an array", is_array($aData['release_notes_list'] ?? null));
assertTest("release_notes_list has 3 items", count($aData['release_notes_list'] ?? []) === 3);
assertTest("release_notes_list item 1 contains 4K streaming note", str_contains($aData['release_notes_list'][0] ?? '', '4K 60FPS'));
assertTest("published_at timestamp is formatted as datetime", !empty($aData['published_at']) && strtotime($aData['published_at']) !== false);

// =========================================================================
// CATEGORY 8: Cross-Milestone Config Synchronization
// =========================================================================
echo "\n8. Testing Cross-Milestone Config Dynamic Synchronization:\n";

// Update version in system_config via POST /api/v1/system/config
$vUpdate = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => 'app_latest_version',
    'key_value' => '4.0.0'
]);
assertTest("POST config update app_latest_version to '4.0.0' succeeded", ($vUpdate['json']['status'] ?? false) === true);

$cUpdate = runApiRequest('POST', '/api/v1/system/config', [
    'key_name'  => 'app_latest_version_code',
    'key_value' => '40'
]);
assertTest("POST config update app_latest_version_code to '40' succeeded", ($cUpdate['json']['status'] ?? false) === true);

// Check that GET /api/v1/system/config immediately reflects 4.0.0 and 40
$configCheck = runApiRequest('GET', '/api/v1/system/config');
assertTest("GET config reflects latest_version '4.0.0'", ($configCheck['json']['data']['version']['latest_version'] ?? '') === '4.0.0');
assertTest("GET config reflects latest_version_code 40", ($configCheck['json']['data']['version']['latest_version_code'] ?? 0) === 40);

// Check that client previously on 3.3.0 now receives update_available = true against 4.0.0
$updateCheck = runApiRequest('GET', '/api/v1/system/check-update?version=3.3.0&version_code=33');
assertTest("Client on 3.3.0 now detects update_available = true for new 4.0.0 release", ($updateCheck['json']['data']['update_available'] ?? false) === true);
assertTest("Client on 3.3.0 receives latest_version '4.0.0'", ($updateCheck['json']['data']['latest_version'] ?? '') === '4.0.0');
assertTest("Client on 3.3.0 receives latest_version_code 40", ($updateCheck['json']['data']['latest_version_code'] ?? 0) === 40);

// Client on 4.0.0 is up-to-date
$updateCheck4 = runApiRequest('GET', '/api/v1/system/check-update?version=4.0.0&version_code=40');
assertTest("Client on 4.0.0 detects update_available = false", ($updateCheck4['json']['data']['update_available'] ?? true) === false);

// Clean up
seedBaselineConfig();

echo "\n====================================================================\n";
echo "CHALLENGER STRESS TEST RESULTS SUMMARY:\n";
echo "  Total Tests Run: " . ($passCount + $failCount) . "\n";
echo "  Passed: {$passCount}\n";
echo "  Failed: {$failCount}\n";
echo "====================================================================\n\n";

if ($failCount === 0) {
    echo ">>> EMPIRICAL CHALLENGER VERDICT: ALL TESTS PASSED (100% PASS RATE) <<<\n";
    exit(0);
} else {
    echo ">>> EMPIRICAL CHALLENGER VERDICT: FAILURES DETECTED <<<\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
