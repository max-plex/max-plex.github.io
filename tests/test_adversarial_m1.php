<?php
/**
 * Adversarial and Multi-Tenant Isolation Test Suite for Milestone 1
 */

declare(strict_types=1);

$phpBinary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBinary)) {
    $phpBinary = 'php';
}

$dbPath = __DIR__ . '/test_adv_m1.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

function getAdvTestDb(): PDO {
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
    $db = getAdvTestDb();
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
    $db = getAdvTestDb();
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    $stmt->closeCursor();
    $stmt = null;
    $db = null;
    return $count;
}

// 1. Initialize SQLite database matching schema
$initDb = getAdvTestDb();
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
    VALUES 
    (1, 'uuid-user-alice', 'Alice', 'alice@example.com', 'hash_alice', 'email', 1, 1),
    (2, 'uuid-user-bob',   'Bob',   'bob@example.com',   'hash_bob',   'email', 1, 1),
    (3, 'uuid-user-banned','Banned','banned@example.com','hash_banned','email', 1, 0);
");
$initDb = null;

// Helper to run requests
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

require_once __DIR__ . '/../src/Services/JWTService.php';
require_once __DIR__ . '/../src/Core/Env.php';

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
echo "MAXPLEX BACKEND - ADVERSARIAL & TENANT ISOLATION TESTS\n";
echo "========================================================\n\n";

// Mint Tokens
$aliceToken = \App\Services\JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'uuid-user-alice',
    'email'      => 'alice@example.com',
    'name'       => 'Alice',
    'session_id' => 101,
    'device_id'  => 'alice_phone'
], 3600);

$bobToken = \App\Services\JWTService::generateToken([
    'sub'        => 2,
    'uuid'       => 'uuid-user-bob',
    'email'      => 'bob@example.com',
    'name'       => 'Bob',
    'session_id' => 201,
    'device_id'  => 'bob_phone'
], 3600);

$bannedToken = \App\Services\JWTService::generateToken([
    'sub'        => 3,
    'uuid'       => 'uuid-user-banned',
    'email'      => 'banned@example.com',
    'name'       => 'Banned',
    'session_id' => 301,
    'device_id'  => 'banned_phone'
], 3600);

// Setup initial sessions
execDb("
    INSERT INTO user_sessions (id, user_id, refresh_token_hash, device_id, device_name, os_type, app_version, ip_address, expires_at)
    VALUES 
    (101, 1, 'rth_alice_1', 'alice_phone', 'Alice Phone', 'android', '1.0.0', '10.0.0.1', datetime('now', '+30 days')),
    (102, 1, 'rth_alice_2', 'alice_tv',    'Alice TV',    'android_tv', '1.0.0', '10.0.0.2', datetime('now', '+30 days')),
    (201, 2, 'rth_bob_1',   'bob_phone',   'Bob Phone',   'ios',     '1.0.0', '10.0.0.3', datetime('now', '+30 days')),
    (202, 2, 'rth_bob_2',   'bob_pc',      'Bob PC',      'windows', '1.0.0', '10.0.0.4', datetime('now', '+30 days'));
");

// SECTION 1: Tenant Boundary Isolation on Session Listing
echo "1. Testing Tenant Isolation on GET /api/v1/user/devices:\n";
$aliceDevices = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => "Bearer {$aliceToken}"]);
$aliceList = $aliceDevices['json']['data'] ?? [];
$aliceIds = array_column($aliceList, 'id');

assertTest("Alice sees only her 2 sessions (101, 102)", count($aliceList) === 2 && in_array(101, $aliceIds) && in_array(102, $aliceIds));
assertTest("Alice does NOT see Bob's sessions (201, 202)", !in_array(201, $aliceIds) && !in_array(202, $aliceIds));

$bobDevices = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => "Bearer {$bobToken}"]);
$bobList = $bobDevices['json']['data'] ?? [];
$bobIds = array_column($bobList, 'id');
assertTest("Bob sees only his 2 sessions (201, 202)", count($bobList) === 2 && in_array(201, $bobIds) && in_array(202, $bobIds));
assertTest("Bob does NOT see Alice's sessions (101, 102)", !in_array(101, $bobIds) && !in_array(102, $bobIds));


// SECTION 2: Adversarial Tenant Cross-Session Revocation
echo "\n2. Testing Cross-Tenant Attack on DELETE /api/v1/user/devices/{id}:\n";
// Bob tries to delete Alice's session 101
$bobAttackAlice = runApiRequest('DELETE', '/api/v1/user/devices/101', [], ['Authorization' => "Bearer {$bobToken}"]);
assertTest("Bob deleting Alice session 101 returns status false (404 Not Found)", ($bobAttackAlice['json']['status'] ?? true) === false);

$alice101Check = queryOne("SELECT id FROM user_sessions WHERE id = 101 AND user_id = 1");
assertTest("Alice session 101 is STILL intact in database", (bool)$alice101Check);

// Alice tries to delete Bob's session 201
$aliceAttackBob = runApiRequest('DELETE', '/api/v1/user/devices/201', [], ['Authorization' => "Bearer {$aliceToken}"]);
assertTest("Alice deleting Bob session 201 returns status false (404 Not Found)", ($aliceAttackBob['json']['status'] ?? true) === false);

$bob201Check = queryOne("SELECT id FROM user_sessions WHERE id = 201 AND user_id = 2");
assertTest("Bob session 201 is STILL intact in database", (bool)$bob201Check);


// SECTION 3: Cross-Tenant Revoke-Others Isolation
echo "\n3. Testing Cross-Tenant Revoke-Others (POST /api/v1/user/devices/revoke-others):\n";
// Bob calls revoke-others
$bobRevokeOthers = runApiRequest('POST', '/api/v1/user/devices/revoke-others', [], ['Authorization' => "Bearer {$bobToken}"]);
assertTest("Bob revoke-others succeeds", ($bobRevokeOthers['json']['status'] ?? false) === true);

// Verify Bob's session 202 is deleted, Bob's 201 remains
$bobRemaining = queryDb("SELECT id FROM user_sessions WHERE user_id = 2");
assertTest("Bob has exactly 1 remaining session (201)", count($bobRemaining) === 1 && (int)$bobRemaining[0]['id'] === 201);

// Verify Alice's sessions (101, 102) are 100% UNTOUCHED
$aliceRemaining = queryDb("SELECT id FROM user_sessions WHERE user_id = 1");
assertTest("Alice STILL has both sessions (101, 102) intact", count($aliceRemaining) === 2);


// SECTION 4: Cross-Tenant Revoke-All Isolation
echo "\n4. Testing Cross-Tenant Revoke-All (DELETE /api/v1/user/devices):\n";
// Bob calls revoke-all
$bobRevokeAll = runApiRequest('DELETE', '/api/v1/user/devices', [], ['Authorization' => "Bearer {$bobToken}"]);
assertTest("Bob revoke-all succeeds", ($bobRevokeAll['json']['status'] ?? false) === true);

$bobAll = queryDb("SELECT id FROM user_sessions WHERE user_id = 2");
assertTest("Bob has 0 sessions in database", count($bobAll) === 0);

$aliceStillAll = queryDb("SELECT id FROM user_sessions WHERE user_id = 1");
assertTest("Alice STILL has both sessions (101, 102) intact after Bob revoke-all", count($aliceStillAll) === 2);


// SECTION 5: Malformed & Boundary Inputs
echo "\n5. Testing Malformed & Boundary Inputs:\n";
// Non-numeric ID in route
$malformedRes = runApiRequest('DELETE', '/api/v1/user/devices/abc', [], ['Authorization' => "Bearer {$aliceToken}"]);
assertTest("DELETE non-numeric ID returns status false", ($malformedRes['json']['status'] ?? true) === false);

// SQL injection attempt in URL param
$sqliRes = runApiRequest('DELETE', '/api/v1/user/devices/101%20OR%201=1', [], ['Authorization' => "Bearer {$aliceToken}"]);
assertTest("DELETE with SQL injection string is safely rejected", ($sqliRes['json']['status'] ?? true) === false);

// Deactivated / Banned user token
$bannedRes = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => "Bearer {$bannedToken}"]);
assertTest("Deactivated user is rejected with 401 Unauthorized", ($bannedRes['json']['status'] ?? true) === false);

// Missing Authorization header
$noAuthRes = runApiRequest('GET', '/api/v1/user/devices', []);
assertTest("Unauthenticated request rejected with 401", ($noAuthRes['json']['status'] ?? true) === false);

$noAuthRevoke = runApiRequest('DELETE', '/api/v1/user/devices/101', []);
assertTest("Unauthenticated delete rejected with 401", ($noAuthRevoke['json']['status'] ?? true) === false);


// SECTION 6: TV Pairing Expiry & Replay Edge Cases
echo "\n6. Testing TV Pairing Expiry & Edge Cases:\n";
// Generate code
$codeGen = runApiRequest('POST', '/api/v1/auth/tv/code', ['device_id' => 'tv_test_edge']);
$cPin = $codeGen['json']['data']['pairing_code'];
$cTok = $codeGen['json']['data']['pairing_token'];

// Tamper expiration in DB to simulate expired code
execDb("UPDATE tv_pairing_codes SET expires_at = datetime('now', '-5 seconds') WHERE pairing_token = :t", ['t' => $cTok]);

// Poll should return expired (410)
$pollExp = runApiRequest('POST', '/api/v1/auth/tv/poll', ['pairing_token' => $cTok]);
assertTest("Polling expired code returns expired status", ($pollExp['json']['status'] ?? true) === false && ($pollExp['json']['data']['pairing_status'] ?? '') === 'expired');

// Alice tries to authorize expired code
$authExp = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => $cPin], ['Authorization' => "Bearer {$aliceToken}"]);
assertTest("Authorizing expired code returns error", ($authExp['json']['status'] ?? true) === false);

echo "\n========================================================\n";
echo "ADVERSARIAL TEST RESULTS SUMMARY:\n";
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
    echo "\nALL ADVERSARIAL & ISOLATION TESTS PASSED (100% PASS RATE)\n";
    exit(0);
}
