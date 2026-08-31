<?php
/**
 * Milestone 1 Empirical Challenger Stress Test Suite
 * Focus: Device Sessions, Multi-platform Concurrency, is_current Resolution, Cross-user Isolation, Revoke-Others
 */

declare(strict_types=1);

$phpBinary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBinary)) {
    $phpBinary = 'php';
}

$dbPath = __DIR__ . '/test_device_sessions_stress_' . uniqid('', true) . '.sqlite';

register_shutdown_function(function() use ($dbPath) {
    if (file_exists($dbPath)) {
        @unlink($dbPath);
    }
});

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
");

// Insert 3 test users
$initDb->exec("
    INSERT INTO users (id, uuid, name, email, password_hash, auth_provider, is_verified, is_active) VALUES
    (1, 'user-uuid-1111', 'Alice TV & Mobile User', 'alice@maxplex.test', 'hash1', 'email', 1, 1),
    (2, 'user-uuid-2222', 'Bob Desktop & Work User', 'bob@maxplex.test', 'hash2', 'email', 1, 1),
    (3, 'user-uuid-3333', 'Charlie Multi-Platform', 'charlie@maxplex.test', 'hash3', 'email', 1, 1);
");
$initDb = null;

// Require JWTService for token generation
require_once __DIR__ . '/../src/Services/JWTService.php';
require_once __DIR__ . '/../src/Core/Env.php';

function createTestJwt(int $userId, string $uuid, string $email, string $name, ?int $sessionId = null, ?string $deviceId = null): string {
    $payload = [
        'sub'   => $userId,
        'uuid'  => $uuid,
        'email' => $email,
        'name'  => $name,
    ];
    if ($sessionId !== null) {
        $payload['session_id'] = $sessionId;
    }
    if ($deviceId !== null) {
        $payload['device_id'] = $deviceId;
    }
    return \App\Services\JWTService::generateToken($payload, 3600);
}

// Request dispatcher helper
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

// Test harness assert functions
$totalTests = 0;
$passedTests = 0;
$failedTests = 0;

function assertCondition(bool $condition, string $description, ?string $details = null): void {
    global $totalTests, $passedTests, $failedTests;
    $totalTests++;
    if ($condition) {
        $passedTests++;
        echo "  [PASS] {$description}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$description}\n";
        if ($details) {
            echo "         Details: {$details}\n";
        }
    }
}

echo "========================================================\n";
echo "CHALLENGER STRESS & ADVERSARIAL TEST: DEVICE SESSIONS\n";
echo "========================================================\n\n";

// =========================================================================
// SECTION 1: Massive Multi-Platform Session Generation & Listing
// =========================================================================
echo "1. Generating & Stress Testing 30 Sessions across TV, Mobile, Desktop for User 1:\n";

$platforms = [
    // TV platforms
    ['android_tv', 'Living Room Sony Bravia 65" 4K', 'tv_sony_65_001', '192.168.1.50'],
    ['android_tv', 'Bedroom TCL Android TV', 'tv_tcl_43_002', '192.168.1.51'],
    ['firetv',     'Basement Amazon Fire TV Stick 4K Max', 'tv_fire_stick_003', '192.168.1.52'],
    ['firetv',     'Guest Room Fire TV Cube', 'tv_fire_cube_004', '192.168.1.53'],
    ['appletv',    'Den Apple TV 4K 128GB', 'tv_appletv_005', '192.168.1.54'],
    ['appletv',    'Master Bed Apple TV HD', 'tv_appletv_006', '192.168.1.55'],
    ['android_tv', 'Kids Room Xiaomi Mi Box S', 'tv_mibox_007', '192.168.1.56'],
    ['android_tv', 'Patio Nvidia Shield TV Pro', 'tv_shield_008', '192.168.1.57'],
    ['android_tv', 'Kitchen Google TV with Chromecast', 'tv_gtv_009', '192.168.1.58'],
    ['linux',      'Home Theater PC (HTPC Linux Kodi)', 'tv_htpc_010', '192.168.1.59'],

    // Mobile platforms
    ['android',    'Samsung Galaxy S24 Ultra', 'mob_s24u_011', '172.56.21.101'],
    ['android',    'Google Pixel 8 Pro', 'mob_px8p_012', '172.56.21.102'],
    ['android',    'OnePlus 12', 'mob_op12_013', '172.56.21.103'],
    ['ios',        'iPhone 15 Pro Max', 'mob_ip15pm_014', '172.56.21.104'],
    ['ios',        'iPhone 14 Plus', 'mob_ip14p_015', '172.56.21.105'],
    ['ios',        'iPad Pro 12.9" M2', 'tab_ipad_016', '192.168.1.70'],
    ['android',    'Samsung Galaxy Tab S9 Ultra', 'tab_tabs9_017', '192.168.1.71'],
    ['android',    'Google Pixel Tablet', 'tab_pxtab_018', '192.168.1.72'],
    ['ios',        'iPad Mini 6', 'tab_ipadmini_019', '192.168.1.73'],
    ['android',    'Motorola Edge 40', 'mob_moto_020', '172.56.21.106'],

    // Desktop & Web platforms
    ['windows',    'Alienware Aurora R16 Gaming Desktop', 'pc_alienware_021', '192.168.1.100'],
    ['windows',    'Lenovo ThinkPad X1 Carbon Gen 11', 'lap_thinkpad_022', '192.168.1.101'],
    ['windows',    'Dell XPS 15 9530 Laptop', 'lap_dellxps_023', '192.168.1.102'],
    ['macos',      'MacBook Pro 16" M3 Max', 'lap_mbp16_024', '192.168.1.103'],
    ['macos',      'Mac Studio M2 Ultra', 'pc_macstudio_025', '192.168.1.104'],
    ['linux',      'Ubuntu Workstation 24.04 LTS', 'pc_ubuntu_026', '192.168.1.105'],
    ['linux',      'Arch Linux Gaming Rig', 'pc_arch_027', '192.168.1.106'],
    ['web',        'Chrome on Windows 11 (Office)', 'web_chrome_win_028', '203.0.113.45'],
    ['web',        'Safari on macOS Sonoma', 'web_safari_mac_029', '203.0.113.46'],
    ['web',        'Firefox on Pop!_OS Linux', 'web_firefox_lin_030', '203.0.113.47'],
];

$user1SessionIds = [];
$db = getTestDb();
$nowTime = time();

foreach ($platforms as $idx => $p) {
    $timeOffset = $nowTime - (30 - $idx) * 60; // 30 mins ago to 1 min ago
    $lastActive = date('Y-m-d H:i:s', $timeOffset);
    $expiresAt = date('Y-m-d H:i:s', $nowTime + 86400 * 30);
    $rth = hash('sha256', "refresh_token_alice_{$idx}_" . bin2hex(random_bytes(16)));

    $stmt = $db->prepare("
        INSERT INTO user_sessions (user_id, refresh_token_hash, device_id, device_name, os_type, app_version, ip_address, last_active_at, expires_at)
        VALUES (1, :rth, :did, :dname, :os, '2.4.0', :ip, :last_active, :expires)
    ");
    $stmt->execute([
        'rth'         => $rth,
        'did'         => $p[2],
        'dname'       => $p[1],
        'os'          => $p[0],
        'ip'          => $p[3],
        'last_active' => $lastActive,
        'expires'     => $expiresAt
    ]);
    $user1SessionIds[] = (int)$db->lastInsertId();
}

// Also seed 15 sessions for User 2
$user2SessionIds = [];
for ($i = 1; $i <= 15; $i++) {
    $rth = hash('sha256', "refresh_token_bob_{$i}_" . bin2hex(random_bytes(16)));
    $stmt = $db->prepare("
        INSERT INTO user_sessions (user_id, refresh_token_hash, device_id, device_name, os_type, app_version, ip_address, last_active_at, expires_at)
        VALUES (2, :rth, :did, :dname, 'windows', '2.4.0', '198.51.100.1', datetime('now'), datetime('now', '+30 days'))
    ");
    $stmt->execute([
        'rth'   => $rth,
        'did'   => "bob_device_{$i}",
        'dname' => "Bob Work Device {$i}"
    ]);
    $user2SessionIds[] = (int)$db->lastInsertId();
}

// Also seed 10 sessions for User 3
$user3SessionIds = [];
for ($i = 1; $i <= 10; $i++) {
    $rth = hash('sha256', "refresh_token_charlie_{$i}_" . bin2hex(random_bytes(16)));
    $stmt = $db->prepare("
        INSERT INTO user_sessions (user_id, refresh_token_hash, device_id, device_name, os_type, app_version, ip_address, last_active_at, expires_at)
        VALUES (3, :rth, :did, :dname, 'android', '2.4.0', '198.51.100.2', datetime('now'), datetime('now', '+30 days'))
    ");
    $stmt->execute([
        'rth'   => $rth,
        'did'   => "charlie_device_{$i}",
        'dname' => "Charlie Phone {$i}"
    ]);
    $user3SessionIds[] = (int)$db->lastInsertId();
}
$db = null;

// Query User 1 devices using TV Session #5 (Den Apple TV 4K)
$tvSessionId = $user1SessionIds[4]; // 5th session
$tvToken = createTestJwt(1, 'user-uuid-1111', 'alice@maxplex.test', 'Alice TV & Mobile User', $tvSessionId, 'tv_appletv_005');

$resAlice = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $tvToken]);
assertCondition(isset($resAlice['json']['status']) && $resAlice['json']['status'] === true, 'GET /api/v1/user/devices returns status true for Alice', $resAlice['raw'] ?? 'No response');
assertCondition(isset($resAlice['json']['count']) && $resAlice['json']['count'] === 30, "Alice active sessions count is exactly 30", $resAlice['raw'] ?? 'No response');
assertCondition(isset($resAlice['json']['data']) && is_array($resAlice['json']['data']) && count($resAlice['json']['data']) === 30, "Alice devices list array contains exactly 30 items", $resAlice['raw'] ?? 'No response');

// =========================================================================
// SECTION 2: Exact `is_current` Resolution Verification
// =========================================================================
echo "\n2. Stress Testing `is_current` Flag Accuracy Across Multiple Devices:\n";

// Check with TV token ($tvSessionId)
$currentCount = 0;
$tvMatchedCorrectly = false;
foreach ($resAlice['json']['data'] as $dev) {
    if ($dev['is_current'] === true) {
        $currentCount++;
        if ($dev['id'] === $tvSessionId && $dev['device_id'] === 'tv_appletv_005') {
            $tvMatchedCorrectly = true;
        }
    }
}
assertCondition($currentCount === 1, "Exactly ONE session is marked is_current = true with TV token (got: {$currentCount})");
assertCondition($tvMatchedCorrectly, "is_current = true specifically resolved to TV Session ID {$tvSessionId} ('Den Apple TV 4K')");

// Test with Mobile token (Session #14: iPhone 15 Pro Max)
$mobileSessionId = $user1SessionIds[13];
$mobileToken = createTestJwt(1, 'user-uuid-1111', 'alice@maxplex.test', 'Alice TV & Mobile User', $mobileSessionId, 'mob_ip15pm_014');
$resMobile = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $mobileToken]);

$mobCurrentCount = 0;
$mobMatchedCorrectly = false;
foreach ($resMobile['json']['data'] as $dev) {
    if ($dev['is_current'] === true) {
        $mobCurrentCount++;
        if ($dev['id'] === $mobileSessionId && $dev['device_id'] === 'mob_ip15pm_014') {
            $mobMatchedCorrectly = true;
        }
    }
}
assertCondition($mobCurrentCount === 1, "Exactly ONE session is marked is_current = true with Mobile token");
assertCondition($mobMatchedCorrectly, "is_current = true specifically resolved to Mobile Session ID {$mobileSessionId} ('iPhone 15 Pro Max')");

// Test with Desktop token (Session #24: MacBook Pro 16" M3 Max)
$desktopSessionId = $user1SessionIds[23];
$desktopToken = createTestJwt(1, 'user-uuid-1111', 'alice@maxplex.test', 'Alice TV & Mobile User', $desktopSessionId, 'lap_mbp16_024');
$resDesktop = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $desktopToken]);

$deskCurrentCount = 0;
$deskMatchedCorrectly = false;
foreach ($resDesktop['json']['data'] as $dev) {
    if ($dev['is_current'] === true) {
        $deskCurrentCount++;
        if ($dev['id'] === $desktopSessionId && $dev['device_id'] === 'lap_mbp16_024') {
            $deskMatchedCorrectly = true;
        }
    }
}
assertCondition($deskCurrentCount === 1, "Exactly ONE session is marked is_current = true with Desktop token");
assertCondition($deskMatchedCorrectly, "is_current = true specifically resolved to Desktop Session ID {$desktopSessionId} ('MacBook Pro 16\" M3 Max')");

// Test Duplicate device_id Disambiguation
echo "\n3. Testing Duplicate device_id Session Disambiguation:\n";
// Insert two sessions with identical device_id 'shared_living_room_tv'
$db = getTestDb();
$stmt = $db->prepare("
    INSERT INTO user_sessions (user_id, refresh_token_hash, device_id, device_name, os_type, app_version, ip_address, last_active_at, expires_at)
    VALUES (1, 'hash_dup_1', 'shared_living_room_tv', 'Shared Living Room TV Login 1', 'android_tv', '2.4.0', '192.168.1.200', datetime('now', '-2 hours'), datetime('now', '+30 days'))
");
$stmt->execute();
$dupSession1 = (int)$db->lastInsertId();

$stmt = $db->prepare("
    INSERT INTO user_sessions (user_id, refresh_token_hash, device_id, device_name, os_type, app_version, ip_address, last_active_at, expires_at)
    VALUES (1, 'hash_dup_2', 'shared_living_room_tv', 'Shared Living Room TV Login 2', 'android_tv', '2.4.0', '192.168.1.200', datetime('now', '-10 minutes'), datetime('now', '+30 days'))
");
$stmt->execute();
$dupSession2 = (int)$db->lastInsertId();
$db = null;

// Call with JWT for $dupSession2
$dupToken = createTestJwt(1, 'user-uuid-1111', 'alice@maxplex.test', 'Alice TV & Mobile User', $dupSession2, 'shared_living_room_tv');
$resDup = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $dupToken]);

$dup2IsCurrent = false;
$dup1IsCurrent = false;
foreach ($resDup['json']['data'] as $dev) {
    if ($dev['id'] === $dupSession2) {
        $dup2IsCurrent = $dev['is_current'];
    }
    if ($dev['id'] === $dupSession1) {
        $dup1IsCurrent = $dev['is_current'];
    }
}
assertCondition($dup2IsCurrent === true, "Active duplicate session (ID {$dupSession2}) is marked is_current = true");
assertCondition($dup1IsCurrent === false, "Older duplicate session with same device_id (ID {$dupSession1}) is marked is_current = false");

// Cleanup the 2 extra dup sessions for clean state
execDb("DELETE FROM user_sessions WHERE id IN ({$dupSession1}, {$dupSession2})");

// =========================================================================
// SECTION 4: Adversarial Multi-Tenant Cross-User Isolation
// =========================================================================
echo "\n4. Adversarial Cross-User Isolation & Attack Vectors:\n";

// Target User 2's session
$targetBobSessionId = $user2SessionIds[0];
$bobSessionCountBefore = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 2")['c'];
$aliceSessionCountBefore = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 1")['c'];

assertCondition($bobSessionCountBefore === 15, "Bob starts with 15 sessions in DB");
assertCondition($aliceSessionCountBefore === 30, "Alice starts with 30 sessions in DB");

// Attack 1: Alice attempts to delete Bob's session ID
$attack1 = runApiRequest('DELETE', "/api/v1/user/devices/{$targetBobSessionId}", [], ['Authorization' => 'Bearer ' . $desktopToken]);
assertCondition($attack1['json']['status'] === false, "Alice deleting Bob's session returns status false");
assertCondition(str_contains(strtolower($attack1['json']['message']), 'not found'), "Response states session not found (404)");

$bobSessionCountAfter1 = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 2")['c'];
$targetBobStillExists = queryOne("SELECT id FROM user_sessions WHERE id = :id AND user_id = 2", ['id' => $targetBobSessionId]);
assertCondition($bobSessionCountAfter1 === 15, "Bob session count unchanged after Alice deletion attempt (15/15)");
assertCondition($targetBobStillExists !== null, "Target Bob session {$targetBobSessionId} still exists intact in database");

// Attack 2: Bob attempts to delete Alice's TV session ID
$targetAliceSessionId = $tvSessionId;
$bobToken = createTestJwt(2, 'user-uuid-2222', 'bob@maxplex.test', 'Bob Desktop & Work User', $targetBobSessionId, 'bob_device_1');
$attack2 = runApiRequest('DELETE', "/api/v1/user/devices/{$targetAliceSessionId}", [], ['Authorization' => 'Bearer ' . $bobToken]);
assertCondition($attack2['json']['status'] === false, "Bob deleting Alice's TV session returns status false (404)");

$aliceSessionCountAfter2 = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 1")['c'];
$targetAliceStillExists = queryOne("SELECT id FROM user_sessions WHERE id = :id AND user_id = 1", ['id' => $targetAliceSessionId]);
assertCondition($aliceSessionCountAfter2 === 30, "Alice session count unchanged after Bob deletion attempt (30/30)");
assertCondition($targetAliceStillExists !== null, "Target Alice TV session {$targetAliceSessionId} still exists intact in database");

// Attack 3: SQL Injection / Malformed parameters in route
$sqlInjAttack1 = runApiRequest('DELETE', "/api/v1/user/devices/1%20OR%201=1", [], ['Authorization' => 'Bearer ' . $desktopToken]);
assertCondition($sqlInjAttack1['json']['status'] === false, "SQL Injection payload '1 OR 1=1' in session ID returns status false");

$sqlInjAttack2 = runApiRequest('DELETE', "/api/v1/user/devices/'%20OR%20'1'='1", [], ['Authorization' => 'Bearer ' . $desktopToken]);
assertCondition($sqlInjAttack2['json']['status'] === false, "SQL Injection string payload in session ID returns status false");

$totalSessionsInDb = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions")['c'];
assertCondition($totalSessionsInDb === (30 + 15 + 10), "Total database sessions intact across all users after SQLi attack (55 total)");

// =========================================================================
// SECTION 5: `revoke-others` Functionality & Blast Radius Isolation
// =========================================================================
echo "\n5. Testing `revoke-others` Preserving Current Session & User Isolation:\n";

// Alice executes revoke-others using Desktop Token (Session #24: MacBook Pro)
$resRevokeOthers = runApiRequest('POST', '/api/v1/user/devices/revoke-others', [], ['Authorization' => 'Bearer ' . $desktopToken]);
assertCondition($resRevokeOthers['json']['status'] === true, "POST /api/v1/user/devices/revoke-others returns status true");
assertCondition($resRevokeOthers['json']['data']['revoked_count'] === 29, "Revoked count returned is exactly 29 (got: {$resRevokeOthers['json']['data']['revoked_count']})");

// Verify Alice's remaining sessions in DB
$aliceRemainingRows = queryDb("SELECT id, device_name, os_type FROM user_sessions WHERE user_id = 1");
assertCondition(count($aliceRemainingRows) === 1, "Alice has exactly 1 session remaining in DB (got: " . count($aliceRemainingRows) . ")");
assertCondition((int)$aliceRemainingRows[0]['id'] === $desktopSessionId, "Remaining session is Alice Desktop Session #{$desktopSessionId} ('MacBook Pro 16\" M3 Max')");

// Verify Bob (User 2) and Charlie (User 3) were NOT affected at all
$bobRemainingCount = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 2")['c'];
$charlieRemainingCount = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 3")['c'];
assertCondition($bobRemainingCount === 15, "Bob sessions completely unaffected by Alice revoke-others (15/15 remaining)");
assertCondition($charlieRemainingCount === 10, "Charlie sessions completely unaffected by Alice revoke-others (10/10 remaining)");

// Idempotency: Calling revoke-others AGAIN when only 1 session exists
$resRevokeOthersAgain = runApiRequest('POST', '/api/v1/user/devices/revoke-others', [], ['Authorization' => 'Bearer ' . $desktopToken]);
assertCondition($resRevokeOthersAgain['json']['status'] === true, "Idempotent second revoke-others call returns status true");
assertCondition($resRevokeOthersAgain['json']['data']['revoked_count'] === 0, "Second revoke-others call returns revoked_count = 0");
$aliceRemainingAfterAgain = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 1")['c'];
assertCondition($aliceRemainingAfterAgain === 1, "Alice still has exactly 1 active session in DB");

// Now test Bob revoking others with TV session
$bobTvSessionId = $user2SessionIds[4];
$bobTvToken = createTestJwt(2, 'user-uuid-2222', 'bob@maxplex.test', 'Bob Desktop & Work User', $bobTvSessionId, 'bob_device_5');
$resBobRevokeOthers = runApiRequest('POST', '/api/v1/user/devices/revoke-others', [], ['Authorization' => 'Bearer ' . $bobTvToken]);
assertCondition($resBobRevokeOthers['json']['status'] === true, "Bob revoke-others returns status true");
assertCondition($resBobRevokeOthers['json']['data']['revoked_count'] === 14, "Bob revoked_count is exactly 14 (got: {$resBobRevokeOthers['json']['data']['revoked_count']})");
$bobRemainingFinal = queryDb("SELECT id FROM user_sessions WHERE user_id = 2");
assertCondition(count($bobRemainingFinal) === 1 && (int)$bobRemainingFinal[0]['id'] === $bobTvSessionId, "Bob has only session {$bobTvSessionId} remaining");

// =========================================================================
// SECTION 6: Expired Session Filtering
// =========================================================================
echo "\n6. Testing Expired Session Exclusion in Device Listing:\n";

// Insert an expired session for Charlie (User 3)
execDb("
    INSERT INTO user_sessions (user_id, refresh_token_hash, device_id, device_name, os_type, app_version, ip_address, last_active_at, expires_at)
    VALUES (3, 'expired_rth_charlie', 'old_charlie_device', 'Old Expired Phone', 'android', '1.0.0', '1.1.1.1', datetime('now', '-10 days'), datetime('now', '-1 hour'))
");

$charlieCurrentSessionId = $user3SessionIds[0];
$charlieToken = createTestJwt(3, 'user-uuid-3333', 'charlie@maxplex.test', 'Charlie Multi-Platform', $charlieCurrentSessionId, 'charlie_device_1');
$resCharlieDevices = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $charlieToken]);

assertCondition($resCharlieDevices['json']['status'] === true, "Charlie GET /api/v1/user/devices returns status true");
assertCondition($resCharlieDevices['json']['count'] === 10, "Charlie device list excludes expired session (count = 10, despite 11 in DB)");

$foundExpired = false;
foreach ($resCharlieDevices['json']['data'] as $d) {
    if ($d['device_id'] === 'old_charlie_device') {
        $foundExpired = true;
    }
}
assertCondition(!$foundExpired, "Expired session 'old_charlie_device' is omitted from API response");

// =========================================================================
// SECTION 7: Total Logout (`DELETE /api/v1/user/devices`) & Tenant Boundary
// =========================================================================
echo "\n7. Testing Total Remote Logout (DELETE /api/v1/user/devices):\n";

$resCharlieRevokeAll = runApiRequest('DELETE', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $charlieToken]);
assertCondition($resCharlieRevokeAll['json']['status'] === true, "Charlie DELETE /api/v1/user/devices returns status true");
assertCondition($resCharlieRevokeAll['json']['data']['revoked_count'] >= 10, "Charlie revoked all his sessions (count >= 10)");

$charlieDbSessions = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 3")['c'];
assertCondition($charlieDbSessions === 0, "Charlie has 0 sessions in DB after total logout");

$aliceDbSessions = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 1")['c'];
$bobDbSessions = (int)queryOne("SELECT COUNT(*) as c FROM user_sessions WHERE user_id = 2")['c'];
assertCondition($aliceDbSessions === 1, "Alice session intact after Charlie total logout (1 remaining)");
assertCondition($bobDbSessions === 1, "Bob session intact after Charlie total logout (1 remaining)");

// =========================================================================
// SECTION 8: Route Aliases & Security Headers
// =========================================================================
echo "\n8. Testing Route Aliases & Security Auth Guards:\n";

// Test alias /api/v1/auth/devices
$resAliasDevices = runApiRequest('GET', '/api/v1/auth/devices', [], ['Authorization' => 'Bearer ' . $desktopToken]);
assertCondition($resAliasDevices['json']['status'] === true, "Alias GET /api/v1/auth/devices returns status true");
assertCondition($resAliasDevices['json']['count'] === 1, "Alias GET /api/v1/auth/devices returns Alice's 1 active device");

// Test alias /api/v1/auth/devices/revoke-others
$resAliasRevokeOthers = runApiRequest('POST', '/api/v1/auth/devices/revoke-others', [], ['Authorization' => 'Bearer ' . $desktopToken]);
assertCondition($resAliasRevokeOthers['json']['status'] === true, "Alias POST /api/v1/auth/devices/revoke-others returns status true");

// Test Unauthenticated request (missing token)
$unauthRes = runApiRequest('GET', '/api/v1/user/devices');
assertCondition($unauthRes['json']['status'] === false, "Missing Bearer token returns status false");

// Test Invalid / Forged token
$forgedToken = "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOjF9.invalid_signature_here";
$forgedRes = runApiRequest('GET', '/api/v1/user/devices', [], ['Authorization' => 'Bearer ' . $forgedToken]);
assertCondition($forgedRes['json']['status'] === false, "Forged token signature returns status false (401)");

echo "\n========================================================\n";
echo "STRESS TEST RESULTS SUMMARY:\n";
echo "  Total Tests Run: {$totalTests}\n";
echo "  Passed: {$passedTests}\n";
echo "  Failed: {$failedTests}\n";
echo "========================================================\n\n";

if ($failedTests === 0) {
    echo "ALL DEVICE SESSION STRESS TESTS PASSED! (100% PASS RATE)\n";
    exit(0);
} else {
    echo "SOME STRESS TESTS FAILED! (Failures: {$failedTests})\n";
    exit(1);
}
