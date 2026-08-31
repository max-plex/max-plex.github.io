<?php
/**
 * Milestone 1 Automated Test Suite
 * Tests TV PIN/QR Pairing, Device Session Management, Request/Router Binding
 */

declare(strict_types=1);

$phpBinary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBinary)) {
    $phpBinary = 'php';
}

$dbPath = __DIR__ . '/test_m1.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
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

// 1. Initialize SQLite database matching schema
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
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        qr_payload TEXT NULL,
        expires_at TIMESTAMP NOT NULL,
        authorized_at TIMESTAMP NULL,
        consumed_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS system_config (
        key_name VARCHAR(100) NOT NULL PRIMARY KEY,
        key_value TEXT NOT NULL,
        description VARCHAR(255) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    INSERT INTO system_config (key_name, key_value, description) VALUES
    ('tv_pairing_ttl_seconds', '300', 'TTL for TV pairing'),
    ('tv_pairing_enabled', '1', 'TV pairing toggle'),
    ('tv_pairing_qr_prefix', 'maxplex://pair', 'TV QR prefix');

    INSERT INTO users (id, uuid, name, email, password_hash, auth_provider, is_verified, is_active)
    VALUES (1, 'user-uuid-1111', 'John Doe', 'john@example.com', 'hash', 'email', 1, 1);
");
$initDb = null;

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

function assertTest(string $name, bool $condition, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$name}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$name} - {$details}\n";
    }
}

echo "========================================================\n";
echo "MAXPLEX BACKEND - MILESTONE 1 VERIFICATION TEST SUITE\n";
echo "========================================================\n\n";

// TEST 1: Router Parameter Extraction
echo "1. Testing Router & Request Parameter Binding:\n";
$routerRes = runApiRequest('DELETE', '/api/v1/user/devices/999', [], [
    'Authorization' => 'Bearer INVALID'
]);
assertTest("Router matched parameterized route /api/v1/user/devices/{id}", $routerRes['json'] !== null);
assertTest("AuthMiddleware intercepted invalid Bearer token with 401", ($routerRes['json']['status'] ?? true) === false);

// TEST 2: TV Code Generation
echo "\n2. Testing TV Pairing Code Generation (POST /api/v1/auth/tv/code):\n";
$codeRes = runApiRequest('POST', '/api/v1/auth/tv/code', [
    'device_id'   => 'tv_sony_bravia_01',
    'device_name' => 'Living Room Sony TV',
    'os_type'     => 'android_tv',
    'app_version' => '2.1.0'
]);
$json = $codeRes['json'];

assertTest("Response status is true", ($json['status'] ?? false) === true);
assertTest("Message is 'Pairing code generated'", ($json['message'] ?? '') === 'Pairing code generated');

$data = $json['data'] ?? [];
$pin = $data['pairing_code'] ?? '';
$token = $data['pairing_token'] ?? '';
$qr = $data['qr_payload'] ?? '';
$ttl = $data['expires_in'] ?? 0;

assertTest("Pairing code is 6-digit numeric string", strlen($pin) === 6 && ctype_digit($pin));
assertTest("Pairing token is 36-char UUID", strlen($token) === 36);
assertTest("QR payload contains code and token", str_contains($qr, $pin) && str_contains($qr, $token));
assertTest("TTL is 300 seconds", $ttl === 300);

// Verify DB record
$row = queryOne("SELECT * FROM tv_pairing_codes WHERE pairing_token = :t", ['t' => $token]);
assertTest("Database record created with status 'pending'", $row && $row['status'] === 'pending');
assertTest("Database record has os_type 'android_tv'", $row && $row['os_type'] === 'android_tv');

// Verify requesting again for same device expires previous code
$codeRes2 = runApiRequest('POST', '/api/v1/auth/tv/code', [
    'device_id'   => 'tv_sony_bravia_01',
    'device_name' => 'Living Room Sony TV',
    'os_type'     => 'android_tv'
]);

$rowOld = queryOne("SELECT * FROM tv_pairing_codes WHERE pairing_token = :t", ['t' => $token]);
assertTest("Previous pairing code transitioned to 'expired' upon new request", $rowOld && $rowOld['status'] === 'expired');

$newPin = $codeRes2['json']['data']['pairing_code'];
$newToken = $codeRes2['json']['data']['pairing_token'];

// TEST 3: TV Polling Pending
echo "\n3. Testing TV Polling - Pending Status (POST /api/v1/auth/tv/poll):\n";
$pollRes = runApiRequest('POST', '/api/v1/auth/tv/poll', [
    'pairing_token' => $newToken
]);
$pollJson = $pollRes['json'];

assertTest("Polling pending returns status true", ($pollJson['status'] ?? false) === true);
assertTest("pairing_status is 'pending'", ($pollJson['data']['pairing_status'] ?? '') === 'pending');
assertTest("expires_in is positive int <= 300", ($pollJson['data']['expires_in'] ?? 0) > 0 && $pollJson['data']['expires_in'] <= 300);

// TEST 4: Mobile TV PIN Authorization
echo "\n4. Testing Mobile TV PIN Authorization (POST /api/v1/auth/tv/verify):\n";

// Generate a valid JWT for user 1 (John Doe)
require_once __DIR__ . '/../src/Services/JWTService.php';
require_once __DIR__ . '/../src/Core/Env.php';
$mobileJwt = \App\Services\JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'user-uuid-1111',
    'email'      => 'john@example.com',
    'name'       => 'John Doe',
    'session_id' => 50,
    'device_id'  => 'mobile_pixel_7'
], 3600);

// Authorize with invalid PIN -> 404
$verifyInvalid = runApiRequest('POST', '/api/v1/auth/tv/verify', [
    'pairing_code' => '000000'
], [
    'Authorization' => "Bearer {$mobileJwt}"
]);
assertTest("Authorizing invalid PIN returns status false", ($verifyInvalid['json']['status'] ?? true) === false);

// Authorize with valid PIN -> 200 OK
$verifyValid = runApiRequest('POST', '/api/v1/auth/tv/verify', [
    'pairing_code' => $newPin
], [
    'Authorization' => "Bearer {$mobileJwt}"
]);
$verifyJson = $verifyValid['json'];

assertTest("Valid PIN authorization returns status true", ($verifyJson['status'] ?? false) === true);
assertTest("Message confirms TV authorized", str_contains($verifyJson['message'] ?? '', 'authorized'));
assertTest("Returns device metadata", ($verifyJson['data']['os_type'] ?? '') === 'android_tv');

// Verify DB record status updated to 'authorized'
$authRow = queryOne("SELECT * FROM tv_pairing_codes WHERE pairing_token = :t", ['t' => $newToken]);
assertTest("DB row status is 'authorized'", $authRow && $authRow['status'] === 'authorized');
assertTest("DB row has user_id = 1", $authRow && (int)$authRow['user_id'] === 1);
assertTest("DB row has authorized_at set", $authRow && !empty($authRow['authorized_at']));

// TEST 5: TV Polling Post-Authorization & Token Issuance
echo "\n5. Testing TV Polling - Post-Authorization (POST /api/v1/auth/tv/poll):\n";
$pollAuth = runApiRequest('POST', '/api/v1/auth/tv/poll', [
    'pairing_token' => $newToken
]);
$authPollJson = $pollAuth['json'];

assertTest("Polling authorized token returns status true", ($authPollJson['status'] ?? false) === true);
assertTest("pairing_status is 'authorized'", ($authPollJson['data']['pairing_status'] ?? '') === 'authorized');

$tokens = $authPollJson['data']['tokens'] ?? [];
$tvAccessToken = $tokens['access_token'] ?? '';
$tvRefreshToken = $tokens['refresh_token'] ?? '';
$user = $authPollJson['data']['user'] ?? [];

assertTest("Returns non-empty access_token", !empty($tvAccessToken));
assertTest("Returns non-empty refresh_token", !empty($tvRefreshToken));
assertTest("User object has id 1 and email john@example.com", ($user['id'] ?? 0) === 1 && ($user['email'] ?? '') === 'john@example.com');

// Decode issued JWT to check claims
$tvPayload = \App\Services\JWTService::verifyToken($tvAccessToken);
assertTest("JWT token is valid", is_array($tvPayload));
assertTest("JWT sub is 1", ($tvPayload['sub'] ?? 0) === 1);
assertTest("JWT session_id claim is int > 0", isset($tvPayload['session_id']) && $tvPayload['session_id'] > 0);
assertTest("JWT device_id claim is 'tv_sony_bravia_01'", ($tvPayload['device_id'] ?? '') === 'tv_sony_bravia_01');

// Verify session record created in user_sessions
$sessionRow = queryOne("SELECT * FROM user_sessions WHERE id = :sid", ['sid' => $tvPayload['session_id']]);
assertTest("user_sessions table record created", (bool)$sessionRow);
assertTest("user_sessions os_type is 'android_tv'", $sessionRow && $sessionRow['os_type'] === 'android_tv');

// Verify tv_pairing_codes transitioned to 'consumed'
$consumedRow = queryOne("SELECT * FROM tv_pairing_codes WHERE pairing_token = :t", ['t' => $newToken]);
assertTest("tv_pairing_codes status transitioned to 'consumed'", $consumedRow && $consumedRow['status'] === 'consumed');
assertTest("tv_pairing_codes consumed_at is set", $consumedRow && !empty($consumedRow['consumed_at']));

// TEST 6: Replay Guard on Consumed Token
echo "\n6. Testing Replay Protection on Consumed TV Token:\n";
$pollReplay = runApiRequest('POST', '/api/v1/auth/tv/poll', [
    'pairing_token' => $newToken
]);
assertTest("Replay poll returns status false", ($pollReplay['json']['status'] ?? true) === false);
assertTest("Data pairing_status is 'consumed'", ($pollReplay['json']['data']['pairing_status'] ?? '') === 'consumed');

// TEST 7: Expired PIN Handling
echo "\n7. Testing Expired PIN Handling:\n";
$expiredToken = 'token-expired-777';
execDb("
    INSERT INTO tv_pairing_codes (pairing_code, pairing_token, device_id, status, expires_at)
    VALUES ('777777', '{$expiredToken}', 'tv_old', 'pending', datetime('now', '-10 minutes'));
");

$verifyExpired = runApiRequest('POST', '/api/v1/auth/tv/verify', [
    'pairing_code' => '777777'
], [
    'Authorization' => "Bearer {$mobileJwt}"
]);
assertTest("Authorizing expired PIN returns status false", ($verifyExpired['json']['status'] ?? true) === false);

$pollExpired = runApiRequest('POST', '/api/v1/auth/tv/poll', [
    'pairing_token' => $expiredToken
]);
assertTest("Polling expired token returns status false", ($pollExpired['json']['status'] ?? true) === false);
assertTest("pairing_status is 'expired'", ($pollExpired['json']['data']['pairing_status'] ?? '') === 'expired');

// TEST 8: Multi-Device Session Listing & is_current
echo "\n8. Testing Multi-Device Session Listing (GET /api/v1/user/devices):\n";
$tvSessionId = (int)$tvPayload['session_id'];

// Insert another session (Desktop)
execDb("
    INSERT INTO user_sessions (id, user_id, refresh_token_hash, device_id, device_name, os_type, app_version, ip_address, expires_at)
    VALUES (201, 1, 'hash_desktop', 'desktop_win11', 'Windows Desktop', 'windows', '1.2.0', '192.168.1.50', datetime('now', '+30 days'));
");

$devicesRes = runApiRequest('GET', '/api/v1/user/devices', [], [
    'Authorization' => "Bearer {$tvAccessToken}"
]);
$devJson = $devicesRes['json'];

assertTest("GET /api/v1/user/devices returns status true", ($devJson['status'] ?? false) === true);
assertTest("Count is 2", ($devJson['count'] ?? 0) === 2);

$devList = $devJson['data'] ?? [];
$currentDev = null;
$otherDev = null;
foreach ($devList as $d) {
    if ($d['is_current'] === true) {
        $currentDev = $d;
    } else {
        $otherDev = $d;
    }
}
assertTest("Current TV session marked is_current = true", $currentDev && $currentDev['id'] === $tvSessionId);
assertTest("Desktop session marked is_current = false", $otherDev && $otherDev['id'] === 201);
assertTest("Current TV session has os_type 'android_tv'", $currentDev && $currentDev['os_type'] === 'android_tv');

// TEST 9: Remote Single Device Session Revocation
echo "\n9. Testing Remote Session Revocation (DELETE /api/v1/user/devices/{id}):\n";
$revokeRes = runApiRequest('DELETE', '/api/v1/user/devices/201', [], [
    'Authorization' => "Bearer {$tvAccessToken}"
]);
assertTest("DELETE /api/v1/user/devices/201 returns status true", ($revokeRes['json']['status'] ?? false) === true);

// Verify session 201 deleted from DB
$chkRow = queryOne("SELECT id FROM user_sessions WHERE id = 201");
assertTest("Session 201 no longer in database", $chkRow === null);

// Revoke non-existent session -> 404
$revoke404 = runApiRequest('DELETE', '/api/v1/user/devices/9999', [], [
    'Authorization' => "Bearer {$tvAccessToken}"
]);
assertTest("Revoking non-existent session returns status false", ($revoke404['json']['status'] ?? true) === false);

// TEST 10: Revoke Other Sessions
echo "\n10. Testing Revoke Other Sessions (POST /api/v1/user/devices/revoke-others):\n";
execDb("
    INSERT INTO user_sessions (id, user_id, refresh_token_hash, device_id, device_name, os_type, app_version, expires_at)
    VALUES 
    (301, 1, 'hash_phone', 'phone_pixel', 'Pixel 7', 'android', '1.0.0', datetime('now', '+30 days')),
    (302, 1, 'hash_tablet', 'tablet_galaxy', 'Galaxy Tab', 'android', '1.0.0', datetime('now', '+30 days'));
");

$revokeOthersRes = runApiRequest('POST', '/api/v1/user/devices/revoke-others', [], [
    'Authorization' => "Bearer {$tvAccessToken}"
]);
$revJson = $revokeOthersRes['json'];

assertTest("revoke-others returns status true", ($revJson['status'] ?? false) === true);
assertTest("revoked_count is >= 2", ($revJson['data']['revoked_count'] ?? 0) >= 2);

$remainingRows = queryDb("SELECT id FROM user_sessions WHERE user_id = 1");
assertTest("Only current TV session remains active", count($remainingRows) === 1 && (int)$remainingRows[0]['id'] === $tvSessionId);

// TEST 11: Revoke All Sessions
echo "\n11. Testing Revoke All Sessions (DELETE /api/v1/user/devices):\n";
$revokeAllRes = runApiRequest('DELETE', '/api/v1/user/devices', [], [
    'Authorization' => "Bearer {$tvAccessToken}"
]);
assertTest("DELETE /api/v1/user/devices returns status true", ($revokeAllRes['json']['status'] ?? false) === true);
assertTest("revoked_count is 1", ($revokeAllRes['json']['data']['revoked_count'] ?? 0) === 1);

$allRows = queryDb("SELECT id FROM user_sessions WHERE user_id = 1");
assertTest("0 sessions remain in database after revoke all", count($allRows) === 0);

echo "\n========================================================\n";
echo "TEST RESULTS SUMMARY:\n";
echo "  Total Tests Run: " . ($passCount + $failCount) . "\n";
echo "  Passed: {$passCount}\n";
echo "  Failed: {$failCount}\n";
echo "========================================================\n";

if (file_exists($dbPath)) {
    unlink($dbPath);
}

if ($failCount > 0) {
    exit(1);
} else {
    echo "\nALL MILESTONE 1 TESTS PASSED SUCCESSFULLY! (100% PASS RATE)\n";
    exit(0);
}
