<?php
/**
 * Challenger Adversarial Stress Test Suite for Milestone 1
 * Targets: TV PIN Generation, Collision Resilience, TTL & Expiration Boundaries,
 * Polling Concurrency/Replay Protection, Malformed/Malicious Inputs, Deactivated Users,
 * Multi-Device Cross-Tenant Session Isolation, and Authentication Header Guarding.
 */

declare(strict_types=1);

// PSR-4 Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relativeClass = substr($class, $len);
    $path = str_replace('\\', '/', $relativeClass);
    $file = __DIR__ . '/../src/' . $path . '.php';
    if (file_exists($file)) {
        require_once $file;
        return;
    }
    if (str_starts_with($relativeClass, 'Config\\')) {
        $configName = substr($relativeClass, 7);
        $configFile = __DIR__ . '/../config/' . $configName . '.php';
        if (file_exists($configFile)) {
            require_once $configFile;
            return;
        }
    }
});

$phpBinary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBinary)) {
    $phpBinary = 'php';
}

$dbPath = __DIR__ . '/test_challenger_m1.sqlite';
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

// 1. Initialize SQLite database schema
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

    -- Insert User 1 (Alice - Active)
    INSERT INTO users (id, uuid, name, email, password_hash, auth_provider, is_verified, is_active)
    VALUES (1, 'user-uuid-alice', 'Alice Smith', 'alice@example.com', 'hash1', 'email', 1, 1);

    -- Insert User 2 (Bob - Active)
    INSERT INTO users (id, uuid, name, email, password_hash, auth_provider, is_verified, is_active)
    VALUES (2, 'user-uuid-bob', 'Bob Jones', 'bob@example.com', 'hash2', 'email', 1, 1);

    -- Insert User 3 (Charlie - Deactivated)
    INSERT INTO users (id, uuid, name, email, password_hash, auth_provider, is_verified, is_active)
    VALUES (3, 'user-uuid-charlie', 'Charlie Banned', 'charlie@example.com', 'hash3', 'email', 1, 0);
");
$initDb = null;

// Helper to run API requests
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

// Helpers for testing
$testsRun = 0;
$testsPassed = 0;
$testsFailed = 0;
$failures = [];

function assertTest(bool $condition, string $description, string $details = ''): void {
    global $testsRun, $testsPassed, $testsFailed, $failures;
    $testsRun++;
    if ($condition) {
        $testsPassed++;
        echo "  [PASS] {$description}\n";
    } else {
        $testsFailed++;
        $failures[] = "{$description} - {$details}";
        echo "  [FAIL] {$description} ({$details})\n";
    }
}

use App\Services\JWTService;

// Setup JWT Auth Tokens for Alice and Bob
$aliceToken = JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'user-uuid-alice',
    'email'      => 'alice@example.com',
    'name'       => 'Alice Smith',
    'session_id' => 101,
    'device_id'  => 'mobile_alice_01'
], 3600);

$bobToken = JWTService::generateToken([
    'sub'        => 2,
    'uuid'       => 'user-uuid-bob',
    'email'      => 'bob@example.com',
    'name'       => 'Bob Jones',
    'session_id' => 201,
    'device_id'  => 'mobile_bob_01'
], 3600);

$charlieToken = JWTService::generateToken([
    'sub'        => 3,
    'uuid'       => 'user-uuid-charlie',
    'email'      => 'charlie@example.com',
    'name'       => 'Charlie Banned',
    'session_id' => 301,
    'device_id'  => 'mobile_charlie_01'
], 3600);

echo "========================================================\n";
echo "CHALLENGER ADVERSARIAL STRESS TEST SUITE (M1 TV AUTH)\n";
echo "========================================================\n\n";

// ========================================================
// SECTION 1: TV PIN GENERATION, COLLISION RESILIENCE & ENTROPY
// ========================================================
echo "1. Testing TV PIN Generation, Collision Resilience & Entropy:\n";

// 1.1 High-Volume PIN Generation (30 distinct requests)
$generatedCodes = [];
$generatedTokens = [];
$allPinsValid = true;
$allUuidsValid = true;
$allQrValid = true;

for ($i = 1; $i <= 30; $i++) {
    $res = runApiRequest('POST', '/api/v1/auth/tv/code', [
        'device_id'   => "stress_tv_{$i}",
        'device_name' => "Stress TV {$i}",
        'os_type'     => 'android_tv',
        'app_version' => '1.2.0'
    ]);

    $data = $res['json']['data'] ?? [];
    $pin = $data['pairing_code'] ?? '';
    $token = $data['pairing_token'] ?? '';
    $qr = $data['qr_payload'] ?? '';

    if (!preg_match('/^[0-9]{6}$/', (string)$pin)) {
        $allPinsValid = false;
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$token)) {
        $allUuidsValid = false;
    }
    if (strpos((string)$qr, "code={$pin}") === false || strpos((string)$qr, "token={$token}") === false) {
        $allQrValid = false;
    }

    $generatedCodes[] = $pin;
    $generatedTokens[] = $token;
}

assertTest($allPinsValid, "High-volume generation: All 30 PINs are strictly 6-digit numeric strings");
assertTest($allUuidsValid, "High-volume generation: All 30 pairing tokens are valid UUIDv4 strings");
assertTest($allQrValid, "High-volume generation: All 30 QR payloads correctly embed code and token");
assertTest(count(array_unique($generatedTokens)) === 30, "All 30 pairing tokens are strictly unique UUIDs");

// 1.2 Device Re-generation Invalidates Prior Pending Codes
$devId = 'single_tv_device_repeat';
$gen1 = runApiRequest('POST', '/api/v1/auth/tv/code', ['device_id' => $devId, 'device_name' => 'Living Room TV']);
$token1 = $gen1['json']['data']['pairing_token'] ?? '';
$pin1 = $gen1['json']['data']['pairing_code'] ?? '';

$gen2 = runApiRequest('POST', '/api/v1/auth/tv/code', ['device_id' => $devId, 'device_name' => 'Living Room TV']);
$token2 = $gen2['json']['data']['pairing_token'] ?? '';
$pin2 = $gen2['json']['data']['pairing_code'] ?? '';

// Check in DB that token1 is expired
$row1 = queryOne("SELECT status FROM tv_pairing_codes WHERE pairing_token = :token", ['token' => $token1]);
$row2 = queryOne("SELECT status FROM tv_pairing_codes WHERE pairing_token = :token", ['token' => $token2]);

assertTest(($row1['status'] ?? '') === 'expired', "Regenerating PIN on same device marks previous token as 'expired'");
assertTest(($row2['status'] ?? '') === 'pending', "Newly generated PIN for device is 'pending'");

// Polling old token1 must return 410 Expired
$pollOld = runApiRequest('POST', '/api/v1/auth/tv/poll', ['pairing_token' => $token1]);
assertTest(($pollOld['json']['status'] ?? null) === false && ($pollOld['json']['data']['pairing_status'] ?? '') === 'expired', "Polling superseded token returns status false and pairing_status 'expired'");

// Authorizing old pin1 must fail if superseded
$authOld = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_token' => $token1], ['Authorization' => 'Bearer ' . $aliceToken]);
assertTest(($authOld['json']['status'] ?? null) === false, "Authorizing superseded pairing token is rejected");

// 1.3 Custom TTL Configuration & Fallback Enforcement
execDb("UPDATE system_config SET key_value = '120' WHERE key_name = 'tv_pairing_ttl_seconds'");
$ttlRes = runApiRequest('POST', '/api/v1/auth/tv/code', ['device_id' => 'custom_ttl_tv']);
assertTest(($ttlRes['json']['data']['expires_in'] ?? 0) === 120, "Dynamic TTL configuration (120s) is respected");

// Reset TTL to 300
execDb("UPDATE system_config SET key_value = '300' WHERE key_name = 'tv_pairing_ttl_seconds'");

// ========================================================
// SECTION 2: EXPIRATION, CLOCK DRIFT & TTL BOUNDARY TESTING
// ========================================================
echo "\n2. Testing Expiration, Clock Drift & TTL Boundaries:\n";

// 2.1 Code with expires_at in the past
$pastToken = 'past-expired-uuid-1234';
$pastPin = '888111';
execDb("
    INSERT INTO tv_pairing_codes (pairing_code, pairing_token, device_id, status, expires_at)
    VALUES ('{$pastPin}', '{$pastToken}', 'tv_past', 'pending', datetime('now', '-10 seconds'))
");

// Polling past code
$pollPast = runApiRequest('POST', '/api/v1/auth/tv/poll', ['pairing_token' => $pastToken]);
assertTest(($pollPast['json']['status'] ?? null) === false, "Polling past expired code returns status false");
assertTest(($pollPast['json']['data']['pairing_status'] ?? '') === 'expired', "Polling past expired code returns pairing_status 'expired'");

// Verify DB updated to expired
$rowPast = queryOne("SELECT status FROM tv_pairing_codes WHERE pairing_token = :token", ['token' => $pastToken]);
assertTest(($rowPast['status'] ?? '') === 'expired', "Database status updated to 'expired' upon polling expired code");

// 2.2 Attempt to Authorize an Expired PIN
$authPast = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => $pastPin], ['Authorization' => 'Bearer ' . $aliceToken]);
assertTest(($authPast['json']['status'] ?? null) === false, "Authorizing expired PIN returns status false");
assertTest(($authPast['json']['data']['pairing_status'] ?? '') === 'expired', "Authorizing expired PIN returns pairing_status 'expired'");

// Verify DB row remains unlinked (user_id is NULL)
$rowPast2 = queryOne("SELECT user_id, status FROM tv_pairing_codes WHERE pairing_token = :token", ['token' => $pastToken]);
assertTest(empty($rowPast2['user_id']), "Expired code was NOT linked to user_id on failed authorization");

// ========================================================
// SECTION 3: POLLING CONCURRENCY, REPLAY ATTACKS & CONSUMPTION
// ========================================================
echo "\n3. Testing Polling Concurrency, Single-Use Guarantee & Replay Defense:\n";

// 3.1 Setup an authorized TV code
$replayTvToken = 'replay-uuid-tv-7777';
$replayPin = '777123';
execDb("
    INSERT INTO tv_pairing_codes (pairing_code, pairing_token, user_id, device_id, device_name, os_type, status, expires_at, authorized_at)
    VALUES ('{$replayPin}', '{$replayTvToken}', 1, 'tv_replay_device', 'Bed Room TV', 'firetv', 'authorized', datetime('now', '+300 seconds'), datetime('now'))
");

// 3.2 First Poll -> Must succeed and transition to 'consumed'
$firstPoll = runApiRequest('POST', '/api/v1/auth/tv/poll', ['pairing_token' => $replayTvToken]);
assertTest(($firstPoll['json']['status'] ?? null) === true, "First poll on authorized code returns status true");
assertTest(($firstPoll['json']['data']['pairing_status'] ?? '') === 'authorized', "First poll returns pairing_status 'authorized'");
assertTest(!empty($firstPoll['json']['data']['tokens']['access_token']), "First poll returns valid access token");
assertTest(!empty($firstPoll['json']['data']['tokens']['refresh_token']), "First poll returns valid refresh token");

// 3.3 Rapid Replay Polls (10 consecutive attempts on same token)
$allReplaysRejected = true;
for ($r = 1; $r <= 10; $r++) {
    $rep = runApiRequest('POST', '/api/v1/auth/tv/poll', ['pairing_token' => $replayTvToken]);
    if (($rep['json']['status'] ?? null) !== false || ($rep['json']['data']['pairing_status'] ?? '') !== 'consumed') {
        $allReplaysRejected = false;
    }
}
assertTest($allReplaysRejected, "All 10 subsequent replay polls returned status false and pairing_status 'consumed'");

// 3.4 Verify user_sessions only contains exactly 1 session for this TV device
$sessionCountRow = queryOne("SELECT count(*) as cnt FROM user_sessions WHERE device_id = 'tv_replay_device'");
assertTest((int)($sessionCountRow['cnt'] ?? 0) === 1, "Exactly 1 user_session created; zero duplicate sessions leaked during replay polls");

// 3.5 Attempt to Authorize an already-consumed PIN / Token
$reAuthPin = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => $replayPin], ['Authorization' => 'Bearer ' . $bobToken]);
assertTest(($reAuthPin['json']['status'] ?? null) === false && ($reAuthPin['json']['data']['pairing_status'] ?? '') === 'consumed', "Re-authorizing consumed PIN by code returns status false and pairing_status 'consumed'");

$reAuthToken = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_token' => $replayTvToken], ['Authorization' => 'Bearer ' . $bobToken]);
assertTest(($reAuthToken['json']['status'] ?? null) === false && ($reAuthToken['json']['data']['pairing_status'] ?? '') === 'consumed', "Re-authorizing consumed token by UUID returns status false and pairing_status 'consumed'");

// ========================================================
// SECTION 4: ADVERSARIAL INPUT MINING & AUTHORIZATION DEFENSE
// ========================================================
echo "\n4. Testing Adversarial Input Mining & Authorization Validation:\n";

// 4.1 Malformed / Invalid PIN values
$malformedInputs = [
    '' => 'Empty PIN',
    '123' => 'Short 3-digit PIN',
    '12345678' => 'Long 8-digit PIN',
    'ABCDEF' => 'Alpha non-numeric PIN',
    '12345a' => 'Alphanumeric PIN',
    "' OR '1'='1" => 'SQL Injection Payload',
    "-55555" => 'Negative PIN',
    "12 34 56" => 'Space-separated PIN'
];

foreach ($malformedInputs as $input => $desc) {
    $res = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => $input], ['Authorization' => 'Bearer ' . $aliceToken]);
    assertTest(($res['json']['status'] ?? null) === false, "Adversarial PIN input rejected ({$desc})");
}

// 4.2 Non-existent PIN / Non-existent Token
$resNonExistentPin = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => '999999'], ['Authorization' => 'Bearer ' . $aliceToken]);
assertTest(($resNonExistentPin['json']['status'] ?? null) === false, "Non-existent PIN 999999 returns 404 / status false");

$resNonExistentToken = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_token' => '00000000-0000-0000-0000-000000000000'], ['Authorization' => 'Bearer ' . $aliceToken]);
assertTest(($resNonExistentToken['json']['status'] ?? null) === false, "Non-existent pairing token returns 404 / status false");

// 4.3 Double Authorization Race / Hijacking Attack
// Alice authorizes a code -> Bob attempts to authorize the same code before polling
$doubleTv = runApiRequest('POST', '/api/v1/auth/tv/code', ['device_id' => 'double_tv_device']);
$doublePin = $doubleTv['json']['data']['pairing_code'] ?? '';
$doubleToken = $doubleTv['json']['data']['pairing_token'] ?? '';

$aliceAuth = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => $doublePin], ['Authorization' => 'Bearer ' . $aliceToken]);
assertTest(($aliceAuth['json']['status'] ?? null) === true, "Alice successfully authorizes double_tv_device PIN");

$bobHijack = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => $doublePin], ['Authorization' => 'Bearer ' . $bobToken]);
assertTest(($bobHijack['json']['status'] ?? null) === false && ($bobHijack['json']['data']['pairing_status'] ?? '') === 'authorized', "Bob's hijack attempt on already authorized code is rejected with 409 and pairing_status 'authorized'");

// Verify in DB that user_id is still 1 (Alice)
$doubleRow = queryOne("SELECT user_id FROM tv_pairing_codes WHERE pairing_token = :token", ['token' => $doubleToken]);
assertTest((int)($doubleRow['user_id'] ?? 0) === 1, "Session ownership remains firmly with Alice (user_id = 1)");

// 4.4 Deactivated / Banned User Account Protection
// Charlie is a deactivated user (is_active = 0)
$charlieTv = runApiRequest('POST', '/api/v1/auth/tv/code', ['device_id' => 'charlie_tv_device']);
$charliePin = $charlieTv['json']['data']['pairing_code'] ?? '';
$charliePairToken = $charlieTv['json']['data']['pairing_token'] ?? '';

// Force link Charlie to test the poll security check
execDb("
    UPDATE tv_pairing_codes 
    SET user_id = 3, status = 'authorized', authorized_at = datetime('now') 
    WHERE pairing_token = '{$charliePairToken}'
");

$charliePoll = runApiRequest('POST', '/api/v1/auth/tv/poll', ['pairing_token' => $charliePairToken]);
assertTest(($charliePoll['json']['status'] ?? null) === false, "Polling for deactivated/banned user (is_active = 0) is rejected with 403 / status false");
assertTest(strpos(strtolower($charliePoll['json']['message'] ?? ''), 'deactivated') !== false || strpos(strtolower($charliePoll['json']['message'] ?? ''), 'invalid') !== false, "Message clearly indicates user is deactivated or invalid");

// ========================================================
// SECTION 5: CROSS-TENANT SESSION ISOLATION & REMOTE REVOCATION
// ========================================================
echo "\n5. Testing Cross-Tenant Multi-Device Session Isolation & Remote Revocation:\n";

// 5.1 Setup multi-device sessions for Alice and Bob
execDb("DELETE FROM user_sessions");
execDb("
    INSERT INTO user_sessions (id, user_id, refresh_token_hash, device_id, device_name, os_type, expires_at)
    VALUES 
    (101, 1, 'hash_alice_s1', 'mobile_alice_01', 'Alice iPhone', 'ios', datetime('now', '+30 days')),
    (102, 1, 'hash_alice_s2', 'tv_alice_02', 'Alice Living Room TV', 'android_tv', datetime('now', '+30 days')),
    (103, 1, 'hash_alice_s3', 'web_alice_03', 'Alice Chrome Web', 'web', datetime('now', '+30 days')),
    (201, 2, 'hash_bob_s1', 'mobile_bob_01', 'Bob Pixel', 'android', datetime('now', '+30 days')),
    (202, 2, 'hash_bob_s2', 'laptop_bob_02', 'Bob Windows Laptop', 'windows', datetime('now', '+30 days'))
");

// 5.2 Tenant Isolation: Alice lists devices -> only Alice's devices (3 items) returned
$aliceDevices = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $aliceToken]);
assertTest(($aliceDevices['json']['status'] ?? null) === true, "Alice retrieves device list");
$aliceDeviceList = $aliceDevices['json']['data'] ?? [];
assertTest(count($aliceDeviceList) === 3, "Alice receives exactly 3 active sessions");
$aliceIds = array_column($aliceDeviceList, 'id');
assertTest(!in_array(201, $aliceIds) && !in_array(202, $aliceIds), "Zero sessions from Bob (201, 202) leaked in Alice's device list");

// Verify is_current flag accuracy
$currentCount = 0;
foreach ($aliceDeviceList as $d) {
    if ($d['id'] === 101) {
        assertTest($d['is_current'] === true, "Alice's current session (id 101) correctly marked is_current = true");
        $currentCount++;
    } else {
        assertTest($d['is_current'] === false, "Alice's other session (id {$d['id']}) marked is_current = false");
    }
}
assertTest($currentCount === 1, "Exactly one session marked is_current = true");

// 5.3 Cross-Tenant Revocation Attack
// Alice attempts to revoke Bob's laptop session (id = 202)
$attackRevoke = runApiRequest('DELETE', '/api/v1/user/devices/202', [], ['Authorization' => 'Bearer ' . $aliceToken]);
assertTest(($attackRevoke['json']['status'] ?? null) === false, "Alice's attempt to revoke Bob's session (202) is rejected (404/Error)");

// Verify Bob's session 202 still exists in DB
$bobS2Check = queryOne("SELECT id FROM user_sessions WHERE id = 202");
assertTest(!empty($bobS2Check), "Bob's session 202 remains active in database after unauthorized revocation attempt");

// 5.4 Cross-Tenant Revoke-Others Isolation
// Alice calls revoke-others -> only Alice's other sessions (102, 103) should be deleted
$aliceRevokeOthers = runApiRequest('POST', '/api/v1/user/devices/revoke-others', [], ['Authorization' => 'Bearer ' . $aliceToken]);
assertTest(($aliceRevokeOthers['json']['status'] ?? null) === true, "Alice calls revoke-others successfully");
assertTest(($aliceRevokeOthers['json']['data']['revoked_count'] ?? 0) === 2, "revoked_count is exactly 2 (Alice's other 2 sessions)");

// Verify Alice's current session 101 is intact
$aliceS1 = queryOne("SELECT id FROM user_sessions WHERE id = 101");
assertTest(!empty($aliceS1), "Alice's current session (101) is preserved");

// Verify Bob's sessions (201, 202) are untouched
$bobAll = queryDb("SELECT id FROM user_sessions WHERE user_id = 2");
assertTest(count($bobAll) === 2, "Bob's sessions (201, 202) were completely untouched by Alice's revoke-others call");

// 5.5 Cross-Tenant Revoke-All Isolation
// Alice calls revoke-all -> deletes Alice's remaining session 101, but Bob's sessions stay
$aliceRevokeAll = runApiRequest('DELETE', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $aliceToken]);
assertTest(($aliceRevokeAll['json']['status'] ?? null) === true, "Alice calls revoke-all successfully");

$aliceCountAfter = queryOne("SELECT count(*) as cnt FROM user_sessions WHERE user_id = 1")['cnt'] ?? 0;
$bobCountAfter = queryOne("SELECT count(*) as cnt FROM user_sessions WHERE user_id = 2")['cnt'] ?? 0;
assertTest((int)$aliceCountAfter === 0, "Alice now has 0 active sessions");
assertTest((int)$bobCountAfter === 2, "Bob still has 2 active sessions (complete tenant isolation preserved)");

// ========================================================
// SECTION 6: END-TO-END TV SESSION MINTING & AUTHENTICATED CALL
// ========================================================
echo "\n6. Testing End-to-End TV Session Minting & Authenticated TV API Call:\n";

// Generate code for a new Android TV
$tvGen = runApiRequest('POST', '/api/v1/auth/tv/code', [
    'device_id'   => 'tv_living_room_4k',
    'device_name' => 'Living Room Android TV',
    'os_type'     => 'android_tv',
    'app_version' => '2.0.1'
]);
$tvPin = $tvGen['json']['data']['pairing_code'] ?? '';
$tvToken = $tvGen['json']['data']['pairing_token'] ?? '';

// Bob authorizes the TV from his Mobile app
$bobAuthTv = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => $tvPin], ['Authorization' => 'Bearer ' . $bobToken]);
assertTest(($bobAuthTv['json']['status'] ?? null) === true, "Bob authorizes Living Room Android TV");

// TV polls for tokens
$tvPoll = runApiRequest('POST', '/api/v1/auth/tv/poll', ['pairing_token' => $tvToken]);
assertTest(($tvPoll['json']['status'] ?? null) === true, "TV poll completes successfully");
$tvAccessToken = $tvPoll['json']['data']['tokens']['access_token'] ?? '';
assertTest(!empty($tvAccessToken), "TV received non-empty access token");

// TV immediately calls GET /api/v1/user/devices using its new access token
$tvDeviceList = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $tvAccessToken]);
assertTest(($tvDeviceList['json']['status'] ?? null) === true, "TV successfully calls GET /api/v1/user/devices with issued token");

$tvDevices = $tvDeviceList['json']['data'] ?? [];
$foundTvSession = false;
foreach ($tvDevices as $dev) {
    if ($dev['device_id'] === 'tv_living_room_4k') {
        $foundTvSession = true;
        assertTest($dev['is_current'] === true, "TV session in device list has is_current = true for the TV caller");
        assertTest($dev['os_type'] === 'android_tv', "TV session in device list has os_type 'android_tv'");
        assertTest($dev['device_name'] === 'Living Room Android TV', "TV session in device list has device_name 'Living Room Android TV'");
    }
}
assertTest($foundTvSession, "TV device session found in Bob's active device list");

// ========================================================
// SECTION 7: AUTHENTICATION HEADER GUARDING & MISSING PARAMETERS
// ========================================================
echo "\n7. Testing Authentication Header Guarding & Missing Parameters:\n";

// 7.1 Polling with empty payload / missing token
$pollEmpty = runApiRequest('POST', '/api/v1/auth/tv/poll', []);
assertTest(($pollEmpty['json']['status'] ?? null) === false, "Polling with empty body returns status false / 422");

// 7.2 Authorize endpoint without Authorization Header
$authNoHeader = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => '123456']);
assertTest(($authNoHeader['json']['status'] ?? null) === false, "POST /api/v1/auth/tv/verify without Bearer token returns 401 Unauthorized");

// 7.3 Authorize endpoint with forged/tampered Bearer Token
$authForged = runApiRequest('POST', '/api/v1/auth/tv/verify', ['pairing_code' => '123456'], ['Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.e30.tampered_signature']);
assertTest(($authForged['json']['status'] ?? null) === false, "POST /api/v1/auth/tv/verify with tampered Bearer token returns 401 Unauthorized");

// 7.4 Devices endpoint without Authorization Header
$devicesNoHeader = runApiRequest('GET', '/api/v1/user/devices', []);
assertTest(($devicesNoHeader['json']['status'] ?? null) === false, "GET /api/v1/user/devices without Bearer token returns 401 Unauthorized");

// ========================================================
// SECTION 8: PLATFORM OS_TYPE SANITIZATION & FALLBACK
// ========================================================
echo "\n8. Testing Platform OS_TYPE Sanitization & Fallback:\n";

$platforms = [
    'android_tv' => 'android_tv',
    'firetv'     => 'firetv',
    'appletv'    => 'appletv',
    'windows'    => 'windows',
    'linux'      => 'linux',
    'web'        => 'web',
    'UNKNOWN_OS' => 'android_tv' // Invalid should fallback to android_tv
];

foreach ($platforms as $inputOs => $expectedOs) {
    $osGen = runApiRequest('POST', '/api/v1/auth/tv/code', [
        'device_id' => 'os_test_' . strtolower($inputOs),
        'os_type'   => $inputOs
    ]);
    $osToken = $osGen['json']['data']['pairing_token'] ?? '';
    $osRow = queryOne("SELECT os_type FROM tv_pairing_codes WHERE pairing_token = :token", ['token' => $osToken]);
    assertTest(($osRow['os_type'] ?? '') === $expectedOs, "Input OS '{$inputOs}' correctly resolved to '{$expectedOs}'");
}

// ========================================================
// SUMMARY
// ========================================================
echo "\n========================================================\n";
echo "CHALLENGER STRESS TEST RESULTS SUMMARY:\n";
echo "  Total Tests Run: {$testsRun}\n";
echo "  Passed: {$testsPassed}\n";
echo "  Failed: {$testsFailed}\n";
echo "========================================================\n";

if ($testsFailed === 0) {
    echo "\n>>> EMPIRICAL CHALLENGER VERDICT: ALL STRESS & ADVERSARIAL TESTS PASSED (100%) <<<\n";
    exit(0);
} else {
    echo "\n>>> EMPIRICAL CHALLENGER VERDICT: FAILURES DETECTED <<<\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
