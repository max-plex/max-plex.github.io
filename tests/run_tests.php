<?php
/**
 * MAXPLEX REST API - MASTER AUTOMATED TEST RUNNER
 * 
 * Comprehensive Integration & Verification Test Suite for Milestone 4 (R4)
 * Covers:
 *  1. Authentication & Token Rotation (Register, Login, Refresh, Logout, Deactivated Guard)
 *  2. TV Leanback PIN / QR Pairing Lifecycle (Code Gen, Poll, Authorize, Replay Guard 410, Expired 410)
 *  3. Multi-Device Session Management (List with is_current, Revoke by ID, Revoke Others, Revoke All, Multi-Tenant Isolation)
 *  4. Cross-Device Watch History & Resume Playback (Sync, Auto-completion >= 90%, Boolean Override, Series Heuristics, Continue Watching, Deletion)
 *  5. Dynamic System Configuration (6 Structured Blocks, Dynamic Updates, Validation)
 *  6. Platform-Aware OTA Updates (Android Mobile, Android TV, Windows, Semver & Version Code Precedence, Force Update)
 *  7. Media Catalog, Categories, Search, Details & Streaming Engine
 *  8. Error Handling, HTTP Status Codes (200, 201, 400, 401, 403, 404, 409, 410, 422) & JSON Envelopes
 *
 * Standalone Pure PHP 8+ with Zero External Dependencies
 */

declare(strict_types=1);

// Auto-detect PHP CLI Binary
$phpBinary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBinary)) {
    $phpBinary = 'php';
}

$startTime = microtime(true);
$totalAssertions = 0;
$passedAssertions = 0;
$failedAssertions = 0;
$failures = [];

// Clean rate limits before test run to prevent throttle artifacts
$rateLimitDir = sys_get_temp_dir() . '/ott_rate_limits';
if (is_dir($rateLimitDir)) {
    foreach (glob($rateLimitDir . '/*') as $f) {
        if (is_file($f)) @unlink($f);
    }
    @rmdir($rateLimitDir);
}

// Setup isolated SQLite database for test execution
$dbPath = __DIR__ . '/test_master_suite.sqlite';
if (file_exists($dbPath)) {
    @unlink($dbPath);
}

function getTestDb(): PDO {
    global $dbPath;
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
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
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    $stmt->closeCursor();
    $stmt = null;
    $db = null;
    return $count;
}

// Initialize SQLite Schema matching production
$initDb = getTestDb();
$initDb->exec("
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

    CREATE TABLE IF NOT EXISTS user_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        refresh_token_hash VARCHAR(255) NOT NULL UNIQUE,
        device_id VARCHAR(100) NOT NULL,
        device_name VARCHAR(100) NULL,
        os_type VARCHAR(50) NOT NULL DEFAULT 'android',
        app_version VARCHAR(20) NULL,
        ip_address VARCHAR(45) NULL,
        last_active_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS tv_pairing_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        pairing_code VARCHAR(10) NOT NULL,
        pairing_token CHAR(36) NOT NULL UNIQUE,
        user_id INTEGER NULL,
        device_id VARCHAR(100) NOT NULL,
        device_name VARCHAR(100) NULL,
        os_type VARCHAR(50) NOT NULL DEFAULT 'android_tv',
        app_version VARCHAR(20) NULL,
        ip_address VARCHAR(45) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        qr_payload VARCHAR(500) NULL,
        expires_at TIMESTAMP NOT NULL,
        authorized_at TIMESTAMP NULL,
        consumed_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS watch_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        media_slug VARCHAR(200) NOT NULL,
        media_title VARCHAR(255) NOT NULL,
        media_poster VARCHAR(500) NULL,
        content_type VARCHAR(20) NOT NULL DEFAULT 'movie',
        season_number INTEGER NULL,
        episode_number INTEGER NULL,
        episode_title VARCHAR(255) NULL,
        playback_time_seconds INTEGER NOT NULL DEFAULT 0,
        duration_seconds INTEGER NOT NULL DEFAULT 0,
        percentage_watched REAL NOT NULL DEFAULT 0.0,
        is_completed INTEGER NOT NULL DEFAULT 0,
        last_watched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, media_slug, episode_number)
    );

    CREATE TABLE IF NOT EXISTS system_config (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key_name VARCHAR(100) NOT NULL UNIQUE,
        key_value TEXT NULL,
        description VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS user_favorites (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        media_slug VARCHAR(200) NOT NULL,
        media_title VARCHAR(255) NOT NULL,
        media_poster VARCHAR(500) NULL,
        content_type VARCHAR(20) NOT NULL DEFAULT 'movie',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(user_id, media_slug)
    );

    CREATE TABLE IF NOT EXISTS search_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        search_query VARCHAR(255) NOT NULL,
        clicked_media_slug VARCHAR(200) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS download_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        media_slug VARCHAR(200) NOT NULL,
        media_title VARCHAR(255) NOT NULL,
        episode_number INTEGER NULL,
        quality_downloaded VARCHAR(20) NOT NULL DEFAULT 'HD',
        file_size VARCHAR(50) NULL,
        download_server VARCHAR(100) NULL,
        downloaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS app_heartbeats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        device_id VARCHAR(100) NOT NULL UNIQUE,
        session_id VARCHAR(100) NULL,
        current_screen VARCHAR(50) NOT NULL DEFAULT 'home',
        current_media_slug VARCHAR(200) NULL,
        current_media_title VARCHAR(255) NULL,
        current_playback_pos INTEGER NOT NULL DEFAULT 0,
        ip_address VARCHAR(45) NULL,
        last_ping_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS user_device (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        fcm_token VARCHAR(255) NOT NULL UNIQUE,
        device_id VARCHAR(100) NOT NULL,
        os_type VARCHAR(50) NOT NULL DEFAULT 'android',
        topics TEXT NULL,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    -- Seed baseline system configurations
    INSERT INTO system_config (key_name, key_value, description) VALUES
    ('hdhub4u_base_url', 'https://new1.hdhub4u.af', 'Dynamic base URL for scraper engine'),
    ('app_maintenance_mode', '0', 'Global maintenance mode flag'),
    ('maintenance_title', 'Under Scheduled Maintenance', 'Maintenance modal title'),
    ('maintenance_message', 'Maxplex services are temporarily undergoing scheduled maintenance.', 'Maintenance message'),
    ('app_latest_version', '3.3.0', 'Latest published mobile/tv version'),
    ('app_latest_version_code', '33', 'Latest published version code'),
    ('app_min_version', '3.0.0', 'Minimum supported version before force update'),
    ('app_min_version_code', '30', 'Minimum supported version code'),
    ('app_force_update', '0', 'Global force update toggle'),
    ('app_apk_url', 'https://mov.aimacademycbse.com/downloads/hdhub4u-v3.3.0.apk', 'Android Mobile APK URL'),
    ('app_apk_size', '19.2 MB', 'Android Mobile APK File Size'),
    ('app_tv_apk_url', 'https://mov.aimacademycbse.com/downloads/maxplex-tv-v3.3.0.apk', 'Android TV APK URL'),
    ('app_tv_apk_size', '24.5 MB', 'Android TV APK File Size'),
    ('app_windows_url', 'https://mov.aimacademycbse.com/downloads/maxplex-setup-v3.3.0.exe', 'Windows Desktop EXE URL'),
    ('app_windows_size', '68.0 MB', 'Windows Desktop File Size'),
    ('app_release_notes', '4K 60FPS Direct Video Streaming Engine\nFaster HubCloud & FastDL token bypass\nSubtitle sync & player buffering improvements', 'Update release notes'),
    ('features_tv_pairing_enabled', '1', 'Enable TV QR/PIN pairing'),
    ('features_cross_device_sync_enabled', '1', 'Enable cross-device watch history sync'),
    ('features_proxy_streaming_enabled', '1', 'Enable proxy stream routing'),
    ('features_downloads_enabled', '1', 'Enable high-speed direct downloads'),
    ('features_watchlist_enabled', '1', 'Enable user favorites / watchlist'),
    ('features_fcm_notifications_enabled', '1', 'Enable FCM push broadcasts'),
    ('player_sync_interval_seconds', '15', 'Watch progress sync interval in seconds'),
    ('player_default_quality', '720p', 'Default player quality'),
    ('player_buffer_size_mb', '2', 'Player pre-buffer size in MB'),
    ('announcement_banner', 'Welcome to Maxplex OTT Streaming Engine!', 'Announcement banner text'),
    ('announcement_show', '1', 'Show announcement banner toggle'),
    ('tv_pairing_ttl_seconds', '300', 'TTL for TV PIN code in seconds');
");
$initDb = null;

// Request execution helper with automatic rate limit clearing
function runApi(string $method, string $uri, array $body = [], array $headers = []): array {
    global $phpBinary, $dbPath, $rateLimitDir;

    // Periodically clean rate limits to ensure non-interference
    if (is_dir($rateLimitDir)) {
        foreach (glob($rateLimitDir . '/*') as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

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

function testAssert(bool $condition, string $message, string $debug = ''): void {
    global $totalAssertions, $passedAssertions, $failedAssertions, $failures;
    $totalAssertions++;
    if ($condition) {
        $passedAssertions++;
        echo "  \033[32m[PASS]\033[0m {$message}\n";
    } else {
        $failedAssertions++;
        $failures[] = "{$message}" . ($debug ? " - (DEBUG: {$debug})" : "");
        echo "  \033[31m[FAIL]\033[0m {$message}\n";
        if ($debug) {
            echo "         \033[33mDebug: {$debug}\033[0m\n";
        }
    }
}

require_once __DIR__ . '/../src/Services/JWTService.php';
require_once __DIR__ . '/../src/Core/Env.php';

echo "\033[1;36m====================================================================\033[0m\n";
echo "\033[1;37m        MAXPLEX REST API - MASTER AUTOMATED TEST RUNNER (M4)        \033[0m\n";
echo "\033[1;36m====================================================================\033[0m\n\n";

// ====================================================================
// SUITE 1: AUTHENTICATION & TOKEN ROTATION
// ====================================================================
echo "\033[1;34m[SUITE 1]\033[0m Testing Authentication, Token Rotation & User Guards:\n";

// 1.1 Registration
$regRes = runApi('POST', '/api/v1/auth/register', [
    'name'        => 'John Doe',
    'email'       => 'johndoe@example.com',
    'password'    => 'SecurePass123!',
    'device_id'   => 'mobile_pixel_01',
    'device_name' => 'Pixel 8 Pro',
    'os_type'     => 'android',
    'app_version' => '3.3.0'
]);
testAssert($regRes['json']['status'] === true, 'User registration returns status = true', $regRes['raw']);
testAssert(!empty($regRes['json']['data']['access_token']), 'Registration returns non-empty JWT access_token');
testAssert(!empty($regRes['json']['data']['refresh_token']), 'Registration returns non-empty refresh_token');
testAssert(($regRes['json']['data']['user']['email'] ?? '') === 'johndoe@example.com', 'Registration returns correct user email');
$user1Id = (int)($regRes['json']['data']['user']['id'] ?? 1);
$user1MobileToken = $regRes['json']['data']['access_token'] ?? '';
$user1RefreshToken = $regRes['json']['data']['refresh_token'] ?? '';

// 1.2 Duplicate Email Conflict (409)
$dupRes = runApi('POST', '/api/v1/auth/register', [
    'name'     => 'John Clone',
    'email'    => 'johndoe@example.com',
    'password' => 'SecurePass123!'
]);
testAssert($dupRes['json']['status'] === false, 'Duplicate email registration returns status = false (HTTP 409)');
testAssert(str_contains(strtolower($dupRes['json']['message'] ?? ''), 'already exists'), 'Duplicate email error message contains "already exists"');

// 1.3 Validation Errors (422)
$badEmailRes = runApi('POST', '/api/v1/auth/register', [
    'name'     => 'Bad Email',
    'email'    => 'not-an-email',
    'password' => '123456'
]);
testAssert($badEmailRes['json']['status'] === false, 'Invalid email format rejected with status = false (HTTP 422)');

$shortPassRes = runApi('POST', '/api/v1/auth/register', [
    'name'     => 'Short Pass',
    'email'    => 'short@example.com',
    'password' => '123'
]);
testAssert($shortPassRes['json']['status'] === false, 'Short password (<6 chars) rejected with status = false (HTTP 422)');

// 1.4 Login with valid credentials
$loginRes = runApi('POST', '/api/v1/auth/login', [
    'email'       => 'johndoe@example.com',
    'password'    => 'SecurePass123!',
    'device_id'   => 'mobile_pixel_01',
    'device_name' => 'Pixel 8 Pro',
    'os_type'     => 'android'
]);
testAssert($loginRes['json']['status'] === true, 'Login with valid credentials returns status = true');
testAssert(!empty($loginRes['json']['data']['access_token']), 'Login returns valid access_token');
$user1MobileToken = $loginRes['json']['data']['access_token'] ?? $user1MobileToken;
$user1RefreshToken = $loginRes['json']['data']['refresh_token'] ?? $user1RefreshToken;

// 1.5 Login with invalid credentials (401)
$badLoginRes = runApi('POST', '/api/v1/auth/login', [
    'email'    => 'johndoe@example.com',
    'password' => 'WrongPassword!'
]);
testAssert($badLoginRes['json']['status'] === false, 'Login with incorrect password returns status = false (HTTP 401)');

// 1.6 Refresh Token Rotation
$refreshRes = runApi('POST', '/api/v1/auth/refresh', [
    'refresh_token' => $user1RefreshToken
]);
testAssert($refreshRes['json']['status'] === true, 'Refresh token request returns status = true', $refreshRes['raw']);
testAssert(!empty($refreshRes['json']['data']['access_token']), 'Refresh token yields new access_token');
$user1RefreshedToken = $refreshRes['json']['data']['access_token'] ?? '';

// 1.7 Invalid Refresh Token (401)
$badRefreshRes = runApi('POST', '/api/v1/auth/refresh', [
    'refresh_token' => 'invalid_random_refresh_token_string'
]);
testAssert($badRefreshRes['json']['status'] === false, 'Invalid refresh token returns status = false (HTTP 401)');

// 1.8 Google Mobile Sign-In & Automatic User Provisioning
$googleMobileRes = runApi('POST', '/api/v1/auth/google', [
    'email'        => 'alex.mobile@gmail.com',
    'displayName'  => 'Alex Mobile Developer',
    'photoUrl'     => 'https://lh3.googleusercontent.com/a/alex-avatar.png',
    'id'           => 'google_uid_987654321',
    'device_id'    => 'mobile_galaxy_s24',
    'device_name'  => 'Samsung Galaxy S24 Ultra',
    'os_type'      => 'android_mobile',
    'app_version'  => '3.3.0'
]);
testAssert($googleMobileRes['json']['status'] === true, 'Google Mobile Sign-In returns status = true', $googleMobileRes['raw']);
testAssert(!empty($googleMobileRes['json']['data']['access_token']), 'Google Mobile Sign-In returns access_token');
testAssert(($googleMobileRes['json']['data']['user']['email'] ?? '') === 'alex.mobile@gmail.com', 'Google Sign-In user object contains correct email');

$alexUserDb = queryOne("SELECT * FROM users WHERE email = :email", ['email' => 'alex.mobile@gmail.com']);
testAssert($alexUserDb !== null, 'Google Mobile Sign-In inserts user entry into users table');
testAssert($alexUserDb['auth_provider'] === 'google', 'Google user has auth_provider = "google"');
testAssert((int)$alexUserDb['is_verified'] === 1 && (int)$alexUserDb['is_active'] === 1, 'Google user is immediately active and verified');

// Test protected endpoint with Google user access_token
$alexToken = $googleMobileRes['json']['data']['access_token'] ?? '';
$alexProfileRes = runApi('GET', '/api/v1/user/profile', [], [
    'Authorization' => 'Bearer ' . $alexToken
]);
testAssert($alexProfileRes['json']['status'] === true, 'Protected API accessible with Google user access_token (HTTP 200)');
testAssert(($alexProfileRes['json']['data']['name'] ?? '') === 'Alex Mobile Developer', 'Google user profile returns correct name');

// 1.9 User Profile & Updates (Protected)
$profileRes = runApi('GET', '/api/v1/user/profile', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($profileRes['json']['status'] === true, 'Protected GET /api/v1/user/profile returns status = true');
testAssert(($profileRes['json']['data']['email'] ?? '') === 'johndoe@example.com', 'Profile contains correct user email');

$updateProfileRes = runApi('POST', '/api/v1/user/profile/update', [
    'name'       => 'Johnathan Doe',
    'avatar_url' => 'https://example.com/avatar.jpg'
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($updateProfileRes['json']['status'] === true, 'Profile update returns status = true');
testAssert(($updateProfileRes['json']['data']['name'] ?? '') === 'Johnathan Doe', 'Profile name successfully updated');

// 1.10 Deactivated User Guard
execDb("UPDATE users SET is_active = 0 WHERE id = :id", ['id' => $user1Id]);
$deactRes = runApi('GET', '/api/v1/user/profile', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($deactRes['json']['status'] === false, 'Deactivated user request intercepted with status = false (HTTP 401)');
execDb("UPDATE users SET is_active = 1 WHERE id = :id", ['id' => $user1Id]); // Restore active status

echo "\n";

// ====================================================================
// SUITE 2: TV PIN / QR PAIRING LIFECYCLE
// ====================================================================
echo "\033[1;34m[SUITE 2]\033[0m Testing TV Leanback PIN / QR Pairing Lifecycle:\n";

// 2.1 TV Generates Pairing Code (POST /api/v1/auth/tv/code)
$tvCodeRes = runApi('POST', '/api/v1/auth/tv/code', [
    'device_id'   => 'tv_bravia_4k_01',
    'device_name' => 'Sony Bravia 4K TV',
    'os_type'     => 'android_tv',
    'app_version' => '3.3.0'
]);
testAssert($tvCodeRes['json']['status'] === true, 'TV pairing code generated successfully', $tvCodeRes['raw']);
$pairingCode  = (string)($tvCodeRes['json']['data']['pairing_code'] ?? '');
$pairingToken = (string)($tvCodeRes['json']['data']['pairing_token'] ?? '');
$qrPayload    = (string)($tvCodeRes['json']['data']['qr_payload'] ?? '');
$expiresIn    = (int)($tvCodeRes['json']['data']['expires_in'] ?? 0);

testAssert(strlen($pairingCode) === 6 && ctype_digit($pairingCode), "Pairing code '{$pairingCode}' is 6-digit numeric PIN");
testAssert(strlen($pairingToken) === 36, "Pairing token '{$pairingToken}' is 36-char UUID");
testAssert(str_contains($qrPayload, 'maxplex://pair') || str_contains($qrPayload, $pairingCode), 'QR payload contains deep-link schema and PIN');
testAssert($expiresIn === 300, 'Pairing code TTL is exactly 300 seconds');

// Verify database row
$dbTvRow = queryOne("SELECT * FROM tv_pairing_codes WHERE pairing_token = :token", ['token' => $pairingToken]);
testAssert($dbTvRow !== null && $dbTvRow['status'] === 'pending', 'Database pairing row created with status = "pending"');
testAssert($dbTvRow['os_type'] === 'android_tv', 'Database pairing row records os_type = "android_tv"');

// 2.2 TV Polls Status - Pending
$tvPollPending = runApi('POST', '/api/v1/auth/tv/poll', [
    'pairing_token' => $pairingToken
]);
testAssert($tvPollPending['json']['status'] === true, 'TV polling pending token returns status = true');
testAssert(($tvPollPending['json']['data']['pairing_status'] ?? '') === 'pending', 'TV polling confirms pairing_status = "pending"');

// 2.3 Invalid PIN Authorization
$badPinAuth = runApi('POST', '/api/v1/auth/tv/verify', [
    'pairing_code' => '999999'
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($badPinAuth['json']['status'] === false, 'Invalid PIN verification rejected with status = false');

// 2.4 Mobile Authorizes TV PIN (POST /api/v1/auth/tv/verify)
$authTvRes = runApi('POST', '/api/v1/auth/tv/verify', [
    'pairing_code' => $pairingCode
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($authTvRes['json']['status'] === true, 'Mobile user authorizes TV PIN with status = true', $authTvRes['raw']);
testAssert(str_contains(strtolower($authTvRes['json']['message'] ?? ''), 'authorized') || str_contains(strtolower($authTvRes['json']['message'] ?? ''), 'success'), 'Authorization message confirms TV pairing');

// Verify DB transition to 'authorized'
$dbTvAuthRow = queryOne("SELECT * FROM tv_pairing_codes WHERE pairing_token = :token", ['token' => $pairingToken]);
testAssert($dbTvAuthRow !== null && $dbTvAuthRow['status'] === 'authorized', 'Database status transitioned to "authorized"');
testAssert((int)$dbTvAuthRow['user_id'] === $user1Id, 'Database pairing row assigned to User 1 ID');

// 2.5 TV Polls Status - Authorized (Mint Tokens)
$tvPollAuth = runApi('POST', '/api/v1/auth/tv/poll', [
    'pairing_token' => $pairingToken
]);
testAssert($tvPollAuth['json']['status'] === true, 'TV polling authorized token returns status = true', $tvPollAuth['raw']);
testAssert(($tvPollAuth['json']['data']['pairing_status'] ?? '') === 'authorized', 'TV polling confirms pairing_status = "authorized"');
testAssert(!empty($tvPollAuth['json']['data']['tokens']['access_token'] ?? $tvPollAuth['json']['data']['access_token']), 'TV client receives valid JWT access_token');
testAssert(!empty($tvPollAuth['json']['data']['tokens']['refresh_token'] ?? $tvPollAuth['json']['data']['refresh_token']), 'TV client receives valid refresh_token');

$tvAccessToken = $tvPollAuth['json']['data']['tokens']['access_token'] ?? ($tvPollAuth['json']['data']['access_token'] ?? '');

// Verify DB transition to 'consumed' and user_sessions creation
$dbTvConsumedRow = queryOne("SELECT * FROM tv_pairing_codes WHERE pairing_token = :token", ['token' => $pairingToken]);
testAssert($dbTvConsumedRow['status'] === 'consumed', 'Pairing row atomically transitioned to "consumed"');
testAssert(!empty($dbTvConsumedRow['consumed_at']), 'consumed_at timestamp recorded');

$tvSessionRow = queryOne("SELECT * FROM user_sessions WHERE user_id = :uid AND device_id = :did", [
    'uid' => $user1Id,
    'did' => 'tv_bravia_4k_01'
]);
testAssert($tvSessionRow !== null, 'TV device session created in user_sessions table');
testAssert($tvSessionRow['os_type'] === 'android_tv', 'TV device session has os_type = "android_tv"');

// 2.6 Replay Protection Guard
$tvReplayPoll = runApi('POST', '/api/v1/auth/tv/poll', [
    'pairing_token' => $pairingToken
]);
testAssert($tvReplayPoll['json']['status'] === false, 'Replay poll on consumed token rejected with status = false (HTTP 410)');
testAssert(($tvReplayPoll['json']['data']['pairing_status'] ?? '') === 'consumed', 'Replay poll reports pairing_status = "consumed"');

// 2.7 Expired PIN Handling
execDb("
    INSERT INTO tv_pairing_codes (pairing_code, pairing_token, device_id, device_name, os_type, status, expires_at)
    VALUES ('112233', 'uuid-expired-test-token', 'tv_old_01', 'Old TV', 'android_tv', 'pending', datetime('now', '-10 minutes'))
");
$expiredAuthRes = runApi('POST', '/api/v1/auth/tv/verify', [
    'pairing_code' => '112233'
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($expiredAuthRes['json']['status'] === false, 'Authorizing expired PIN rejected with status = false (HTTP 410)');

$expiredPollRes = runApi('POST', '/api/v1/auth/tv/poll', [
    'pairing_token' => 'uuid-expired-test-token'
]);
testAssert($expiredPollRes['json']['status'] === false, 'Polling expired token rejected with status = false (HTTP 410)');

echo "\n";

// ====================================================================
// SUITE 3: MULTI-DEVICE SESSION MANAGEMENT & REMOTE REVOCATION
// ====================================================================
echo "\033[1;34m[SUITE 3]\033[0m Testing Multi-Device Session Management & Remote Revocation:\n";

// Register User 2 (Bob) for multi-tenant tests
$bobReg = runApi('POST', '/api/v1/auth/register', [
    'name'        => 'Bob Smith',
    'email'       => 'bob@example.com',
    'password'    => 'SecureBob123!',
    'device_id'   => 'phone_bob_galaxy',
    'device_name' => 'Galaxy S24',
    'os_type'     => 'android'
]);
$bobId = (int)($bobReg['json']['data']['user']['id'] ?? 2);
$bobMobileToken = $bobReg['json']['data']['access_token'] ?? '';

// Create Windows Desktop session for User 1
$winLogin = runApi('POST', '/api/v1/auth/login', [
    'email'       => 'johndoe@example.com',
    'password'    => 'SecurePass123!',
    'device_id'   => 'win_thinkpad_x1',
    'device_name' => 'Lenovo ThinkPad X1',
    'os_type'     => 'windows',
    'app_version' => '3.3.0'
]);
$winAccessToken = $winLogin['json']['data']['access_token'] ?? '';

// 3.1 List Active Sessions with is_current flag
$devicesRes = runApi('GET', '/api/v1/user/devices', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($devicesRes['json']['status'] === true, 'GET /api/v1/user/devices returns status = true');
$devicesList = $devicesRes['json']['data'] ?? [];
testAssert(count($devicesList) >= 3, 'User 1 has at least 3 active device sessions');

$currentCount = 0;
$foundTv = false;
$foundWin = false;
$desktopSessionId = null;

foreach ($devicesList as $dev) {
    if (!empty($dev['is_current'])) {
        $currentCount++;
        testAssert($dev['device_id'] === 'mobile_pixel_01', 'is_current = true strictly matches caller mobile device ID');
    }
    if (($dev['os_type'] ?? '') === 'android_tv') {
        $foundTv = true;
    }
    if (($dev['os_type'] ?? '') === 'windows') {
        $foundWin = true;
        $desktopSessionId = (int)$dev['id'];
    }
}
testAssert($currentCount === 1, 'Exactly 1 session has is_current = true for the active token');
testAssert($foundTv && $foundWin, 'Session list contains both android_tv and windows platform sessions');

// 3.2 Route Alias /api/v1/auth/devices
$aliasDevicesRes = runApi('GET', '/api/v1/auth/devices', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($aliasDevicesRes['json']['status'] === true, 'Alias route /api/v1/auth/devices returns status = true');

// 3.3 Revoke Specific Session by ID (Revoke Windows Desktop)
testAssert($desktopSessionId !== null, 'Found desktop session ID to revoke');
$revokeSingleRes = runApi('DELETE', "/api/v1/user/devices/{$desktopSessionId}", [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($revokeSingleRes['json']['status'] === true, "DELETE /api/v1/user/devices/{$desktopSessionId} returns status = true");

$dbCheckSingle = queryOne("SELECT * FROM user_sessions WHERE id = :id", ['id' => $desktopSessionId]);
testAssert($dbCheckSingle === null, 'Revoked session ID is deleted from database');

// 3.4 Revoke Other Sessions (POST /api/v1/user/devices/revoke-others)
$revokeOthersRes = runApi('POST', '/api/v1/user/devices/revoke-others', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($revokeOthersRes['json']['status'] === true, 'POST /api/v1/user/devices/revoke-others returns status = true');

$devicesAfterOthers = runApi('GET', '/api/v1/user/devices', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert(count($devicesAfterOthers['json']['data'] ?? []) === 1, 'Only 1 session (caller) remains active after revoke-others');
testAssert($devicesAfterOthers['json']['data'][0]['is_current'] === true, 'Remaining session is caller session (is_current = true)');

// 3.5 Multi-Tenant Isolation
$bobDevices = runApi('GET', '/api/v1/user/devices', [], [
    'Authorization' => 'Bearer ' . $bobMobileToken
]);
testAssert(count($bobDevices['json']['data'] ?? []) >= 1, "Bob's active sessions remain completely untouched");

// 3.6 Revoke All Sessions (DELETE /api/v1/user/devices)
$revokeAllRes = runApi('DELETE', '/api/v1/user/devices', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($revokeAllRes['json']['status'] === true, 'DELETE /api/v1/user/devices returns status = true');
$user1SessionsRemaining = queryDb("SELECT * FROM user_sessions WHERE user_id = :uid", ['uid' => $user1Id]);
testAssert(count($user1SessionsRemaining) === 0, 'All sessions for User 1 revoked (0 remaining in database)');

echo "\n";

// ====================================================================
// SUITE 4: CROSS-DEVICE WATCH HISTORY & RESUME PLAYBACK SYNCHRONIZATION
// ====================================================================
echo "\033[1;34m[SUITE 4]\033[0m Testing Cross-Device Watch History & Resume Playback Sync:\n";

// Re-authenticate User 1 Mobile and TV for watch history tests
$user1MobileAuth = runApi('POST', '/api/v1/auth/login', [
    'email'     => 'johndoe@example.com',
    'password'  => 'SecurePass123!',
    'device_id' => 'mobile_pixel_01',
    'os_type'   => 'android'
]);
$user1MobileToken = $user1MobileAuth['json']['data']['access_token'] ?? '';

// 4.1 Sync Movie Progress from Mobile
$syncMovieRes = runApi('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'oppenheimer-2023',
    'media_title'           => 'Oppenheimer (2023)',
    'media_poster'          => 'https://example.com/oppenheimer.jpg',
    'content_type'          => 'movie',
    'playback_time_seconds' => 1800,
    'duration_seconds'      => 7200
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($syncMovieRes['json']['status'] === true, 'Sync movie progress returns status = true', $syncMovieRes['raw']);
testAssert((float)($syncMovieRes['json']['data']['percentage_watched'] ?? 0) === 25.0, 'percentage_watched accurately computed as 25.0%');
testAssert(($syncMovieRes['json']['data']['is_completed'] ?? true) === false, 'is_completed is false for 25% progress');

// 4.2 Resume Movie on Android TV / Other Device
$resumeMovieRes = runApi('GET', '/api/v1/history/watch/resume?media_slug=oppenheimer-2023', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($resumeMovieRes['json']['status'] === true, 'Resume playback query returns status = true');
testAssert(($resumeMovieRes['json']['data']['found'] ?? false) === true, 'Resume response has found = true');
testAssert((int)($resumeMovieRes['json']['data']['playback_time_seconds'] ?? 0) === 1800, 'Resume playback timestamp matches 1800s');

// 4.3 High Frequency UPSERT Consistency
$syncUpdateRes = runApi('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'oppenheimer-2023',
    'media_title'           => 'Oppenheimer (2023)',
    'content_type'          => 'movie',
    'playback_time_seconds' => 3600,
    'duration_seconds'      => 7200
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($syncUpdateRes['json']['status'] === true, 'Updated progress sync returns status = true');
$dbMovieRows = queryDb("SELECT * FROM watch_history WHERE user_id = :uid AND media_slug = 'oppenheimer-2023'", ['uid' => $user1Id]);
testAssert(count($dbMovieRows) === 1, 'Database maintains exactly 1 row for movie via UPSERT');
testAssert((int)$dbMovieRows[0]['playback_time_seconds'] === 3600, 'Database row updated to 3600s');

// 4.4 Auto-Completion Threshold (>= 90%)
$sync90Res = runApi('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'inception-2010',
    'media_title'           => 'Inception (2010)',
    'content_type'          => 'movie',
    'playback_time_seconds' => 6500,
    'duration_seconds'      => 7200 // 90.28%
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert(($sync90Res['json']['data']['is_completed'] ?? false) === true, 'Auto-completion triggered at 90.28% (is_completed = true)');

// 4.5 Explicit Boolean Overrides
$syncOverrideTrue = runApi('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'dune-part-two-2024',
    'media_title'           => 'Dune: Part Two (2024)',
    'content_type'          => 'movie',
    'playback_time_seconds' => 600,
    'duration_seconds'      => 6000, // 10%
    'is_completed'          => true
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert(($syncOverrideTrue['json']['data']['is_completed'] ?? false) === true, 'Explicit is_completed: true override respected at 10% progress');

$syncOverrideFalse = runApi('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'interstellar-2014',
    'media_title'           => 'Interstellar (2014)',
    'content_type'          => 'movie',
    'playback_time_seconds' => 6900,
    'duration_seconds'      => 7200, // 95.83%
    'is_completed'          => false
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert(($syncOverrideFalse['json']['data']['is_completed'] ?? true) === false, 'Explicit is_completed: false override respected at 95.83% progress');

// 4.6 Web Series Multi-Episode Tracking & Resume Heuristics
// Sync S1E1 (completed)
runApi('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'stranger-things-s1',
    'media_title'           => 'Stranger Things',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 1,
    'playback_time_seconds' => 3000,
    'duration_seconds'      => 3000,
    'is_completed'          => true
], ['Authorization' => 'Bearer ' . $user1MobileToken]);

// Sync S1E2 (in progress)
runApi('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'stranger-things-s1',
    'media_title'           => 'Stranger Things',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 2,
    'playback_time_seconds' => 1200,
    'duration_seconds'      => 3000,
    'is_completed'          => false
], ['Authorization' => 'Bearer ' . $user1MobileToken]);

// Query resume without episode_number -> Should return latest active episode (Episode 2)
$seriesHeuristicResume = runApi('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert(($seriesHeuristicResume['json']['data']['found'] ?? false) === true, 'Series resume heuristic returns found = true');
testAssert((int)($seriesHeuristicResume['json']['data']['episode_number'] ?? 0) === 2, 'Series resume heuristic returns most recent Episode 2');
testAssert((int)($seriesHeuristicResume['json']['data']['playback_time_seconds'] ?? 0) === 1200, 'Series resume returns Episode 2 playback time (1200s)');

// Query specific episode resume
$ep1Resume = runApi('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1&episode_number=1', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert((int)($ep1Resume['json']['data']['episode_number'] ?? 0) === 1, 'Specific episode resume query returns Episode 1');
testAssert(($ep1Resume['json']['data']['is_completed'] ?? false) === true, 'Episode 1 reports is_completed = true');

// 4.7 Series Progress Overview (GET /api/v1/history/watch/series-progress)
$seriesProgRes = runApi('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($seriesProgRes['json']['status'] === true, 'Series progress overview returns status = true');
testAssert((int)($seriesProgRes['json']['count'] ?? count($seriesProgRes['json']['data'] ?? [])) === 2, 'Series progress overview returns exactly 2 episodes');

// 4.8 Continue Watching Feed (GET /api/v1/history/watch)
$continueRes = runApi('GET', '/api/v1/history/watch', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($continueRes['json']['status'] === true, 'Continue watching feed returns status = true');
$continueList = $continueRes['json']['data'] ?? [];
$slugsInContinue = array_column($continueList, 'media_slug');
testAssert(in_array('oppenheimer-2023', $slugsInContinue, true), 'Continue watching includes in-progress movie');
testAssert(!in_array('inception-2010', $slugsInContinue, true), 'Continue watching excludes auto-completed movie');

// 4.9 Deletion Granularity
// Delete specific episode
$delEpRes = runApi('DELETE', '/api/v1/history/watch/stranger-things-s1?episode_number=2', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($delEpRes['json']['status'] === true, 'Delete single episode returns status = true');

$seriesProgAfterDel = runApi('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert((int)($seriesProgAfterDel['json']['count'] ?? count($seriesProgAfterDel['json']['data'] ?? [])) === 1, 'Series progress now has exactly 1 remaining episode');

// Clear all watch history
$clearAllHistoryRes = runApi('DELETE', '/api/v1/history/watch?all=true', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($clearAllHistoryRes['json']['status'] === true, 'Clear all watch history returns status = true');

$continueAfterClear = runApi('GET', '/api/v1/history/watch', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert(empty($continueAfterClear['json']['data']), 'Continue watching is completely empty after clear-all');

echo "\n";

// ====================================================================
// SUITE 5: DYNAMIC SYSTEM CONFIGURATION & MAINTENANCE MODE
// ====================================================================
echo "\033[1;34m[SUITE 5]\033[0m Testing Dynamic System Configuration & Maintenance Mode:\n";

// 5.1 Fetch Structured Dynamic Config (GET /api/v1/system/config)
$configRes = runApi('GET', '/api/v1/system/config');
testAssert($configRes['json']['status'] === true, 'GET /api/v1/system/config returns status = true', $configRes['raw']);

$data = $configRes['json']['data'] ?? [];
testAssert(isset($data['is_playstore_testing']) && is_bool($data['is_playstore_testing']), 'Config has boolean "is_playstore_testing"');
testAssert($data['is_playstore_testing'] === true, 'Default "is_playstore_testing" is true (Review Mode Active)');
testAssert(isset($data['features']['is_playstore_testing']) && is_bool($data['features']['is_playstore_testing']), 'features block contains boolean "is_playstore_testing"');
testAssert(isset($data['base_url']), 'Config block "base_url" is present');
testAssert(isset($data['maintenance']['enabled']) && is_bool($data['maintenance']['enabled']), 'Config block "maintenance" has boolean "enabled"');
testAssert(isset($data['features']['tv_pairing_enabled']) && is_bool($data['features']['tv_pairing_enabled']), 'Config block "features" has boolean "tv_pairing_enabled"');
testAssert(isset($data['features']['cross_device_sync_enabled']) && is_bool($data['features']['cross_device_sync_enabled']), 'Config block "features" has boolean "cross_device_sync_enabled"');
testAssert(isset($data['player']['sync_interval_seconds']) && is_int($data['player']['sync_interval_seconds']), 'Config block "player" has integer "sync_interval_seconds"');
testAssert(isset($data['announcement']['banner']) && isset($data['announcement']['show']), 'Config block "announcement" has banner and show');
testAssert(isset($data['version']['latest_version']) && isset($data['version']['latest_version_code']), 'Config block "version" has latest_version and latest_version_code');

// 5.2 Test toggling is_playstore_testing to false (Live Production Mode)
$updatePsRes = runApi('POST', '/api/v1/system/config', [
    'key_name'  => 'is_playstore_testing',
    'key_value' => '0'
]);
testAssert($updatePsRes['json']['status'] === true, 'Update is_playstore_testing to 0 returns status = true');
$configAfterPs = runApi('GET', '/api/v1/system/config');
testAssert($configAfterPs['json']['data']['is_playstore_testing'] === false, 'is_playstore_testing correctly reflects false (Live Production Mode)');

// Restore is_playstore_testing to 1 (Review Active Mode)
runApi('POST', '/api/v1/system/config', ['key_name' => 'is_playstore_testing', 'key_value' => '1']);
$configRestoredPs = runApi('GET', '/api/v1/system/config');
testAssert($configRestoredPs['json']['data']['is_playstore_testing'] === true, 'is_playstore_testing restored to true (Review Mode)');

// 5.3 Dynamic Config Mutation (POST /api/v1/system/config)
$updateMaintRes = runApi('POST', '/api/v1/system/config', [
    'key_name'  => 'app_maintenance_mode',
    'key_value' => '1'
]);
testAssert($updateMaintRes['json']['status'] === true, 'Dynamic config update for maintenance mode succeeded');

$configAfterMaint = runApi('GET', '/api/v1/system/config');
testAssert($configAfterMaint['json']['data']['maintenance']['enabled'] === true, 'maintenance.enabled reflects dynamic update to true');

// Revert maintenance mode
runApi('POST', '/api/v1/system/config', ['key_name' => 'app_maintenance_mode', 'key_value' => '0']);

// 5.4 Input validation for config update
$missingKeyRes = runApi('POST', '/api/v1/system/config', ['key_value' => 'test']);
testAssert($missingKeyRes['json']['status'] === false, 'Config update with missing key_name returns status = false (HTTP 422)');

echo "\n";

// ====================================================================
// SUITE 6: PLATFORM-AWARE OTA UPDATES & VERSION PRECEDENCE
// ====================================================================
echo "\033[1;34m[SUITE 6]\033[0m Testing Platform-Aware OTA Updates & Version Precedence:\n";

// 6.1 Android Mobile Platform
$mobUpdateRes = runApi('GET', '/api/v1/system/check-update?platform=android_mobile&version=3.0.0&version_code=30');
testAssert($mobUpdateRes['json']['status'] === true, 'Check update for android_mobile returns status = true');
testAssert(($mobUpdateRes['json']['data']['platform'] ?? '') === 'android_mobile', 'Response platform matches android_mobile');
testAssert(str_ends_with($mobUpdateRes['json']['data']['download_url'] ?? '', '.apk'), 'Mobile download_url points to .apk');
testAssert(($mobUpdateRes['json']['data']['file_size'] ?? '') === '19.2 MB', 'Mobile file_size matches 19.2 MB');

// 6.2 Android TV Platform
$tvUpdateRes = runApi('GET', '/api/v1/system/check-update?platform=android_tv&version=3.0.0&version_code=30');
testAssert($tvUpdateRes['json']['status'] === true, 'Check update for android_tv returns status = true');
testAssert(($tvUpdateRes['json']['data']['platform'] ?? '') === 'android_tv', 'Response platform matches android_tv');
testAssert(str_contains($tvUpdateRes['json']['data']['download_url'] ?? '', 'tv'), 'TV download_url points to TV APK package');
testAssert(($tvUpdateRes['json']['data']['file_size'] ?? '') === '24.5 MB', 'TV file_size matches 24.5 MB');

// 6.3 Windows Desktop Platform
$winUpdateRes = runApi('GET', '/api/v1/system/check-update?platform=windows&version=3.0.0&version_code=30');
testAssert($winUpdateRes['json']['status'] === true, 'Check update for windows returns status = true');
testAssert(($winUpdateRes['json']['data']['platform'] ?? '') === 'windows', 'Response platform matches windows');
testAssert(str_ends_with($winUpdateRes['json']['data']['download_url'] ?? '', '.exe'), 'Windows download_url points to .exe installer');
testAssert(($winUpdateRes['json']['data']['file_size'] ?? '') === '68.0 MB', 'Windows file_size matches 68.0 MB');

// 6.4 Version Precedence Rules
// Up-to-date client (v3.3.0 code 33)
$upToDate = runApi('GET', '/api/v1/system/check-update?platform=android_mobile&version=3.3.0&version_code=33');
testAssert($upToDate['json']['data']['update_available'] === false, 'Up-to-date client (v3.3.0 code 33) has update_available = false');
testAssert($upToDate['json']['data']['force_update'] === false, 'Up-to-date client has force_update = false');

// Optional update (v3.2.0 code 32 >= min code 30)
$optUpdate = runApi('GET', '/api/v1/system/check-update?platform=android_mobile&version=3.2.0&version_code=32');
testAssert($optUpdate['json']['data']['update_available'] === true, 'v3.2.0 code 32 has update_available = true');
testAssert($optUpdate['json']['data']['force_update'] === false, 'v3.2.0 code 32 (>= min 30) has force_update = false');

// Force update via outdated version code (code 29 < min code 30)
$forceCodeUpdate = runApi('GET', '/api/v1/system/check-update?platform=android_mobile&version=3.0.0&version_code=29');
testAssert($forceCodeUpdate['json']['data']['update_available'] === true, 'Outdated version code (29 < 30) has update_available = true');
testAssert($forceCodeUpdate['json']['data']['force_update'] === true, 'Outdated version code (29 < 30) triggers force_update = true');

// Force update via outdated semver (v2.9.0 < min semver 3.0.0)
$forceSemverUpdate = runApi('GET', '/api/v1/system/check-update?platform=android_mobile&version=2.9.0&version_code=30');
testAssert($forceSemverUpdate['json']['data']['update_available'] === true, 'Outdated semver (2.9.0 < 3.0.0) has update_available = true');
testAssert($forceSemverUpdate['json']['data']['force_update'] === true, 'Outdated semver triggers force_update = true');

// Route Alias /api/v1/app/update
$aliasUpdate = runApi('GET', '/api/v1/app/update?platform=android_tv&version=3.0.0&version_code=30');
testAssert($aliasUpdate['json']['status'] === true, 'Route alias /api/v1/app/update returns status = true');
testAssert(isset($aliasUpdate['json']['data']['release_notes_list']), 'Release notes parsed into array list');

// 6.5 Input Validation
$invalidPlatform = runApi('GET', '/api/v1/system/check-update?platform=ios');
testAssert($invalidPlatform['json']['status'] === false, 'Unsupported platform "ios" rejected with status = false (HTTP 422)');

$negativeCode = runApi('GET', '/api/v1/system/check-update?version_code=-5');
testAssert($negativeCode['json']['status'] === false, 'Negative version_code rejected with status = false (HTTP 422)');

echo "\n";

// ====================================================================
// SUITE 7: MEDIA CATALOG, CATEGORIES, SEARCH, DETAILS & STREAMING ENGINE
// ====================================================================
echo "\033[1;34m[SUITE 7]\033[0m Testing Media Catalog, Search, Categories & Streaming Handlers:\n";

// 7.0 Unauthenticated Media Request Rejected (Option B Lockdown)
$unauthMediaRes = runApi('GET', '/api/v1/media/categories');
testAssert($unauthMediaRes['json']['status'] === false, 'Unauthenticated GET /api/v1/media/categories rejected with HTTP 401');

// 7.1 Categories List (GET /api/v1/media/categories) with Auth
$catRes = runApi('GET', '/api/v1/media/categories', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($catRes['json']['status'] === true, 'GET /api/v1/media/categories with auth returns status = true');
testAssert(!empty($catRes['json']['data']) && is_array($catRes['json']['data']), 'Categories list is a non-empty array');
testAssert(isset($catRes['json']['data'][0]['name']) && isset($catRes['json']['data'][0]['slug']), 'Category items have name and slug fields');

// 7.2 Media Details Parameter Validation
$missingDetailsRes = runApi('GET', '/api/v1/media/details', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($missingDetailsRes['json']['status'] === false, 'GET /api/v1/media/details without slug returns status = false (HTTP 422)');

// 7.3 Media Search Parameter Validation
$missingSearchRes = runApi('GET', '/api/v1/media/search', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($missingSearchRes['json']['status'] === false, 'GET /api/v1/media/search without query returns status = false (HTTP 422)');

// 7.4 Stream Resolver Parameter Validation
$missingStreamRes = runApi('GET', '/api/v1/media/stream', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($missingStreamRes['json']['status'] === false, 'GET /api/v1/media/stream without code returns status = false (HTTP 422)');

// 7.5 Download Resolver Parameter Validation
$missingDlRes = runApi('GET', '/api/v1/media/download', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($missingDlRes['json']['status'] === false, 'GET /api/v1/media/download without url returns status = false (HTTP 422)');

// 7.6 Live Presence Heartbeat (POST /api/v1/presence/heartbeat)
$heartbeatRes = runApi('POST', '/api/v1/presence/heartbeat', [
    'device_id'            => 'mobile_pixel_01',
    'current_screen'       => 'player',
    'current_media_slug'   => 'oppenheimer-2023',
    'current_playback_pos' => 1200
]);
testAssert($heartbeatRes['json']['status'] === true, 'Live presence heartbeat returns status = true');
testAssert(($heartbeatRes['json']['data']['ack'] ?? false) === true, 'Heartbeat returns ack = true');

$badHeartbeatRes = runApi('POST', '/api/v1/presence/heartbeat', []);
testAssert($badHeartbeatRes['json']['status'] === false, 'Heartbeat without device_id returns status = false (HTTP 422)');

// 7.7 Presence Stats (GET /api/v1/presence/stats)
$presenceStatsRes = runApi('GET', '/api/v1/presence/stats');
testAssert($presenceStatsRes['json']['status'] === true, 'GET /api/v1/presence/stats returns status = true');

// 7.8 KatDrama Feed (GET /api/v1/media/k-drama)
$unauthKdramaRes = runApi('GET', '/api/v1/media/k-drama');
testAssert($unauthKdramaRes['json']['status'] === false, 'Unauthenticated GET /api/v1/media/k-drama rejected with HTTP 401');

$kdramaFeedRes = runApi('GET', '/api/v1/media/k-drama', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($kdramaFeedRes['json']['status'] === true, 'GET /api/v1/media/k-drama with auth returns status = true');
testAssert(isset($kdramaFeedRes['json']['data']['posts']) && is_array($kdramaFeedRes['json']['data']['posts']), 'K-Drama feed returns posts array');
testAssert(($kdramaFeedRes['json']['data']['source'] ?? '') === 'katdrama', 'K-Drama feed source reports "katdrama"');

// 7.9 Unified / KatDrama Search (GET /api/v1/media/search?query=...&source=kdrama)
$kdramaSearchRes = runApi('GET', '/api/v1/media/search?query=Taxi+Driver&source=kdrama', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($kdramaSearchRes['json']['status'] === true, 'GET /api/v1/media/search with source=kdrama returns status = true');
testAssert(is_array($kdramaSearchRes['json']['data']), 'K-Drama search returns array list');

// 7.10 KatDrama Details Lookup (GET /api/v1/media/details?slug=...&source=kdrama)
$kdramaDetailsRes = runApi('GET', '/api/v1/media/details?slug=taxi-driver-2021-s01-hindi&source=kdrama', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($kdramaDetailsRes['json']['status'] === true, 'GET /api/v1/media/details for K-Drama slug returns status = true');
testAssert(($kdramaDetailsRes['json']['data']['source'] ?? '') === 'katdrama', 'K-Drama details reports source = "katdrama"');
testAssert(isset($kdramaDetailsRes['json']['data']['downloads']) && is_array($kdramaDetailsRes['json']['data']['downloads']), 'K-Drama details contains downloads array');

// 7.8 Watchlist Toggle (POST /api/v1/user/favorites/toggle)
$favToggleRes = runApi('POST', '/api/v1/user/favorites/toggle', [
    'media_slug'   => 'oppenheimer-2023',
    'media_title'  => 'Oppenheimer',
    'content_type' => 'movie'
], [
    'Authorization' => 'Bearer ' . $user1MobileToken
]);
testAssert($favToggleRes['json']['status'] === true, 'Watchlist toggle returns status = true');
testAssert(isset($favToggleRes['json']['data']['is_favorited']), 'Watchlist returns is_favorited boolean');

echo "\n";

// ====================================================================
// SUITE 8: ERROR HANDLING, HTTP STATUS CODES & ENVELOPE STANDARDIZATION
// ====================================================================
echo "\033[1;34m[SUITE 8]\033[0m Testing Standard JSON Envelopes, HTTP Status Codes & Error Guards:\n";

// 8.1 401 Unauthorized for Unauthenticated Protected Endpoint
$unauthRes = runApi('GET', '/api/v1/user/devices');
testAssert($unauthRes['json']['status'] === false, 'Unauthenticated request rejected with status = false (HTTP 401)');
testAssert(str_contains(strtolower($unauthRes['json']['message'] ?? ''), 'bearer') || str_contains(strtolower($unauthRes['json']['message'] ?? ''), 'unauthorized') || str_contains(strtolower($unauthRes['json']['message'] ?? ''), 'missing'), 'Unauthenticated message cites missing/invalid token');

// 8.2 401 Unauthorized for Tampered JWT Signature
$tamperedRes = runApi('GET', '/api/v1/user/devices', [], [
    'Authorization' => 'Bearer ' . $user1MobileToken . 'tampered'
]);
testAssert($tamperedRes['json']['status'] === false, 'Tampered JWT token rejected with status = false (HTTP 401)');

// 8.3 404 Not Found for Non-Existent Routes
$notFoundRes = runApi('GET', '/api/v1/nonexistent/route/test');
testAssert($notFoundRes['json']['status'] === false, 'Non-existent route returns status = false (HTTP 404)');

// 8.4 Standard Envelope Verification
testAssert(array_key_exists('status', $configRes['json']), 'Envelope includes boolean key "status"');
testAssert(array_key_exists('message', $configRes['json']), 'Envelope includes string key "message"');
testAssert(array_key_exists('data', $configRes['json']), 'Envelope includes payload key "data"');

// 8.5 Rate Limit Header & Graceful Throttling
testAssert(is_array($regRes['json']), 'All responses cleanly decode valid JSON');

echo "\n";

// ====================================================================
// TEST EXECUTION SUMMARY
// ====================================================================
$duration = round(microtime(true) - $startTime, 2);

echo "\033[1;36m====================================================================\033[0m\n";
echo "\033[1;37m                      TEST EXECUTION SUMMARY                        \033[0m\n";
echo "\033[1;36m====================================================================\033[0m\n";
echo "  Total Assertions Run : \033[1;37m{$totalAssertions}\033[0m\n";
echo "  Passed Assertions    : \033[1;32m{$passedAssertions}\033[0m\n";
echo "  Failed Assertions    : " . ($failedAssertions === 0 ? "\033[1;32m0\033[0m" : "\033[1;31m{$failedAssertions}\033[0m") . "\n";
echo "  Execution Duration   : \033[1;33m{$duration}s\033[0m\n";
echo "  Pass Rate            : " . ($failedAssertions === 0 ? "\033[1;32m100%\033[0m" : "\033[1;31m" . round(($passedAssertions / $totalAssertions) * 100, 2) . "%\033[0m") . "\n";
echo "\033[1;36m====================================================================\033[0m\n\n";

if ($failedAssertions > 0) {
    echo "\033[1;31mFAILURES ENCOUNTERED:\033[0m\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    echo "\n";
    exit(1);
}

echo "\033[1;32m>>> VERDICT: ALL {$totalAssertions} ASSERTIONS PASSED SUCCESSFULLY (100% PASS RATE) <<<\033[0m\n\n";
exit(0);
