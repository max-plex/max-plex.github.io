<?php
/**
 * Challenger Adversarial Stress & Verification Test Suite for Milestone 2
 * Focus: Cross-Device Watch Progress Synchronization across 3 Client Tokens (Mobile, TV, Windows),
 * Mathematical Edge Cases (0 duration division guard, negative timestamps, position exceeding duration),
 * Auto-Completion Threshold (90.0%) vs Explicit Boolean Overrides (is_completed: false at 95%, true at 20%),
 * Web Series Multi-Episode State Transitions & Heuristics, Multi-Tenant Isolation, and Rapid Concurrency.
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

$dbPath = __DIR__ . '/test_challenger_m2.sqlite';
if (file_exists($dbPath)) {
    @unlink($dbPath);
}

function getTestDb(): PDO {
    global $dbPath;
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
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
    gc_collect_cycles();
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
        gc_collect_cycles();
        return $count !== false ? (int)$count : 0;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->rowCount();
    $stmt->closeCursor();
    $stmt = null;
    $db = null;
    gc_collect_cycles();
    return $count;
}

// 1. Initialize SQLite Database Schema
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

    CREATE TABLE IF NOT EXISTS watch_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        media_slug VARCHAR(200) NOT NULL,
        media_title VARCHAR(255) NOT NULL,
        media_poster VARCHAR(500) NULL,
        content_type VARCHAR(20) NOT NULL DEFAULT 'movie',
        season_number INTEGER NULL DEFAULT 1,
        episode_number INTEGER NULL DEFAULT 1,
        episode_title VARCHAR(100) NULL,
        playback_time_seconds INTEGER NOT NULL DEFAULT 0,
        duration_seconds INTEGER NOT NULL DEFAULT 0,
        percentage_watched DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        is_completed INTEGER NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (user_id, media_slug, episode_number)
    );

    CREATE TABLE IF NOT EXISTS search_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        query VARCHAR(255) NOT NULL,
        clicked_media_slug VARCHAR(200) NULL,
        searched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
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

    -- Seed 3 Users: Alice (1), Bob (2), Charlie (3)
    INSERT INTO users (id, uuid, name, email, password_hash, auth_provider, is_verified, is_active)
    VALUES 
    (1, 'uuid-alice-100', 'Alice Walker', 'alice@example.com', 'hash_alice', 'email', 1, 1),
    (2, 'uuid-bob-200', 'Bob Smith', 'bob@example.com', 'hash_bob', 'email', 1, 1),
    (3, 'uuid-charlie-300', 'Charlie Davis', 'charlie@example.com', 'hash_charlie', 'email', 1, 1);
");
$initDb = null;
gc_collect_cycles();

// Helper to execute API requests via runner
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

// Test tracking
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

// Alice has 3 distinct client tokens: Mobile, TV, Windows
$aliceMobileToken = JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'uuid-alice-100',
    'email'      => 'alice@example.com',
    'name'       => 'Alice Walker',
    'device_id'  => 'mobile_client_alice_01'
], 3600);

$aliceTvToken = JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'uuid-alice-100',
    'email'      => 'alice@example.com',
    'name'       => 'Alice Walker',
    'device_id'  => 'android_tv_client_alice_02'
], 3600);

$aliceWindowsToken = JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'uuid-alice-100',
    'email'      => 'alice@example.com',
    'name'       => 'Alice Walker',
    'device_id'  => 'windows_desktop_alice_03'
], 3600);

// Bob has a mobile token
$bobToken = JWTService::generateToken([
    'sub'        => 2,
    'uuid'       => 'uuid-bob-200',
    'email'      => 'bob@example.com',
    'name'       => 'Bob Smith',
    'device_id'  => 'mobile_bob_01'
], 3600);

// Charlie has a mobile token
$charlieToken = JWTService::generateToken([
    'sub'        => 3,
    'uuid'       => 'uuid-charlie-300',
    'email'      => 'charlie@example.com',
    'name'       => 'Charlie Davis',
    'device_id'  => 'mobile_charlie_01'
], 3600);

echo "====================================================================\n";
echo "CHALLENGER ADVERSARIAL STRESS TEST SUITE (M2 WATCH SYNC)\n";
echo "====================================================================\n\n";

// =========================================================================
// SECTION 1: CROSS-DEVICE 3-TOKEN SYNCHRONIZATION (Mobile -> TV -> Windows -> Mobile)
// =========================================================================
echo "1. Testing Cross-Device Watch Sync across 3 Client Tokens (Mobile, TV, Windows):\n";

// Step 1: Alice on Mobile starts watching movie "Interstellar 2014" (duration 7200s, watched 1800s = 25.0%)
$resM1 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'interstellar-2014',
    'media_title'           => 'Interstellar',
    'media_poster'          => 'https://img.maxplex.com/interstellar.jpg',
    'content_type'          => 'movie',
    'playback_time_seconds' => 1800,
    'duration_seconds'      => 7200
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);

assertTest(($resM1['json']['status'] ?? false) === true, "Step 1: Alice Mobile syncs progress (1800s / 7200s)");
assertTest((float)($resM1['json']['data']['percentage_watched'] ?? 0) === 25.0, "Step 1: Mobile percentage computed as 25.00%");
assertTest(($resM1['json']['data']['is_completed'] ?? true) === false, "Step 1: is_completed is false");

// Step 2: Alice switches to Android TV client and queries resume
$resTvResume = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=interstellar-2014', [], [
    'Authorization' => 'Bearer ' . $aliceTvToken
]);
assertTest(($resTvResume['json']['status'] ?? false) === true, "Step 2: Android TV queries resume endpoint");
assertTest(($resTvResume['json']['data']['found'] ?? false) === true, "Step 2: TV found = true");
assertTest(($resTvResume['json']['data']['playback_time_seconds'] ?? 0) === 1800, "Step 2: TV receives exact resume position 1800s");
assertTest((float)($resTvResume['json']['data']['percentage_watched'] ?? 0) === 25.0, "Step 2: TV receives exact percentage 25.00%");

// Step 3: Alice watches further on Android TV up to 4500s (62.50%)
$resTvSync = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'interstellar-2014',
    'media_title'           => 'Interstellar',
    'content_type'          => 'movie',
    'playback_time_seconds' => 4500,
    'duration_seconds'      => 7200
], ['Authorization' => 'Bearer ' . $aliceTvToken]);
assertTest(($resTvSync['json']['status'] ?? false) === true, "Step 3: Android TV syncs updated progress (4500s / 7200s)");
assertTest((float)($resTvSync['json']['data']['percentage_watched'] ?? 0) === 62.5, "Step 3: TV percentage updated to 62.50%");

// Step 4: Alice switches to Windows Desktop client and queries resume
$resWinResume = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=interstellar-2014', [], [
    'Authorization' => 'Bearer ' . $aliceWindowsToken
]);
assertTest(($resWinResume['json']['status'] ?? false) === true, "Step 4: Windows Desktop queries resume");
assertTest(($resWinResume['json']['data']['playback_time_seconds'] ?? 0) === 4500, "Step 4: Windows Desktop receives updated 4500s from TV session");
assertTest((float)($resWinResume['json']['data']['percentage_watched'] ?? 0) === 62.5, "Step 4: Windows Desktop receives 62.50%");

// Step 5: Alice finishes movie on Windows Desktop (6600s / 7200s = 91.67% -> auto-completed)
$resWinSync = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'interstellar-2014',
    'media_title'           => 'Interstellar',
    'content_type'          => 'movie',
    'playback_time_seconds' => 6600,
    'duration_seconds'      => 7200
], ['Authorization' => 'Bearer ' . $aliceWindowsToken]);
assertTest(($resWinSync['json']['status'] ?? false) === true, "Step 5: Windows Desktop syncs to 6600s (91.67%)");
assertTest(($resWinSync['json']['data']['is_completed'] ?? false) === true, "Step 5: Windows Desktop sync auto-completes (is_completed = true)");

// Step 6: Alice opens Mobile client again and queries resume
$resMobileResume2 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=interstellar-2014', [], [
    'Authorization' => 'Bearer ' . $aliceMobileToken
]);
assertTest(($resMobileResume2['json']['data']['playback_time_seconds'] ?? 0) === 6600, "Step 6: Mobile client reflects Windows completion position 6600s");
assertTest(($resMobileResume2['json']['data']['is_completed'] ?? false) === true, "Step 6: Mobile client reflects is_completed = true");

// Step 7: Alice rewinds back to 1000s on Windows Desktop with explicit is_completed: false
$resWinRewind = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'interstellar-2014',
    'media_title'           => 'Interstellar',
    'content_type'          => 'movie',
    'playback_time_seconds' => 1000,
    'duration_seconds'      => 7200,
    'is_completed'          => false
], ['Authorization' => 'Bearer ' . $aliceWindowsToken]);
assertTest(($resWinRewind['json']['data']['playback_time_seconds'] ?? 0) === 1000, "Step 7: Windows Desktop rewinds to 1000s");
assertTest(($resWinRewind['json']['data']['is_completed'] ?? true) === false, "Step 7: is_completed is reset to false");

// Step 8: Verify database maintains exactly 1 row for this user and movie (idempotent UPSERT)
$dbRows = queryDb("SELECT * FROM watch_history WHERE user_id = 1 AND media_slug = 'interstellar-2014'");
assertTest(count($dbRows) === 1, "Step 8: Database maintains strictly 1 row via UPSERT across 3 devices");
assertTest((int)$dbRows[0]['playback_time_seconds'] === 1000, "Step 8: Final DB row has playback_time_seconds = 1000");
assertTest((int)$dbRows[0]['is_completed'] === 0, "Step 8: Final DB row has is_completed = 0");


// =========================================================================
// SECTION 2: MATHEMATICAL EDGE CASES & NUMERICAL BOUNDARIES
// =========================================================================
echo "\n2. Testing Mathematical Edge Cases & Numerical Boundaries:\n";

// 2.1 Zero duration (division by zero guard)
$resZeroDur = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'math-zero-dur',
    'media_title'           => 'Zero Duration Video',
    'playback_time_seconds' => 300,
    'duration_seconds'      => 0
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resZeroDur['json']['status'] ?? false) === true, "Zero duration: Request handled gracefully without 500 error or division by zero");
assertTest((float)($resZeroDur['json']['data']['percentage_watched'] ?? -1) === 0.0, "Zero duration: percentage_watched is safely set to 0.0");
assertTest(($resZeroDur['json']['data']['is_completed'] ?? true) === false, "Zero duration: is_completed is false");

// 2.2 Negative duration
$resNegDur = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'math-neg-dur',
    'media_title'           => 'Negative Duration Video',
    'playback_time_seconds' => 300,
    'duration_seconds'      => -500
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resNegDur['json']['status'] ?? false) === true, "Negative duration: Handled gracefully without crash");
assertTest((int)($resNegDur['json']['data']['duration_seconds'] ?? -1) === 0, "Negative duration: Duration clamped to 0");
assertTest((float)($resNegDur['json']['data']['percentage_watched'] ?? -1) === 0.0, "Negative duration: percentage_watched is 0.0");

// 2.3 Negative playback position
$resNegPos = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'math-neg-pos',
    'media_title'           => 'Negative Position Video',
    'playback_time_seconds' => -120,
    'duration_seconds'      => 3600
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resNegPos['json']['status'] ?? false) === true, "Negative playback position: Handled gracefully");
assertTest((int)($resNegPos['json']['data']['playback_time_seconds'] ?? -1) === 0, "Negative playback position: Clamped to 0");
assertTest((float)($resNegPos['json']['data']['percentage_watched'] ?? -1) === 0.0, "Negative playback position: percentage_watched is 0.0");

// 2.4 Playback exceeding duration (clamping to 100%)
$resOverPos = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'math-over-pos',
    'media_title'           => 'Over Position Video',
    'playback_time_seconds' => 9000,
    'duration_seconds'      => 3600
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resOverPos['json']['status'] ?? false) === true, "Playback exceeding duration: Handled successfully");
assertTest((float)($resOverPos['json']['data']['percentage_watched'] ?? 0) === 100.0, "Playback exceeding duration: percentage_watched clamped to 100.0%");
assertTest(($resOverPos['json']['data']['is_completed'] ?? false) === true, "Playback exceeding duration: is_completed is true");

// 2.5 Precision & boundary at exactly 90.00%
// 2700 / 3000 = 90.00%
$resExact90 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'math-exact-90',
    'media_title'           => 'Exact 90 Percent Video',
    'playback_time_seconds' => 2700,
    'duration_seconds'      => 3000
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest((float)($resExact90['json']['data']['percentage_watched'] ?? 0) === 90.0, "Exact 90.0%: percentage_watched is 90.00");
assertTest(($resExact90['json']['data']['is_completed'] ?? false) === true, "Exact 90.0%: is_completed is true");

// 2.6 Boundary just below 90% (89.97%)
// 2699 / 3000 = 89.966...% -> round(89.966..., 2) = 89.97%
$resBelow90 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'math-below-90',
    'media_title'           => 'Below 90 Percent Video',
    'playback_time_seconds' => 2699,
    'duration_seconds'      => 3000
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest((float)($resBelow90['json']['data']['percentage_watched'] ?? 0) < 90.0, "Below 90.0%: percentage_watched is < 90.0");
assertTest(($resBelow90['json']['data']['is_completed'] ?? true) === false, "Below 90.0%: is_completed is false");

// 2.7 Large Integer Handling
$resLarge = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'math-large-ints',
    'media_title'           => 'Large Integer Video',
    'playback_time_seconds' => 86400, // 24 hours
    'duration_seconds'      => 172800 // 48 hours
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resLarge['json']['status'] ?? false) === true, "Large integers: Handled successfully");
assertTest((float)($resLarge['json']['data']['percentage_watched'] ?? 0) === 50.0, "Large integers: 50.00% accurately computed");


// =========================================================================
// SECTION 3: AUTO-COMPLETION (>= 90.0%) VS EXPLICIT BOOLEAN OVERRIDES
// =========================================================================
echo "\n3. Testing Auto-Completion (>= 90.0%) vs Explicit Boolean Overrides:\n";

// 3.1 High progress (95.0% = 3420s / 3600s) with explicit is_completed: false
$resHighFalse = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'override-test-1',
    'media_title'           => 'Override High False',
    'playback_time_seconds' => 3420,
    'duration_seconds'      => 3600,
    'is_completed'          => false
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest((float)($resHighFalse['json']['data']['percentage_watched'] ?? 0) === 95.0, "95% progress calculated correctly");
assertTest(($resHighFalse['json']['data']['is_completed'] ?? true) === false, "Explicit override is_completed: false at 95% overrides auto-completion (remains false)");

// Verify in DB
$dbHighFalse = queryOne("SELECT is_completed, percentage_watched FROM watch_history WHERE user_id = 1 AND media_slug = 'override-test-1'");
assertTest((int)($dbHighFalse['is_completed'] ?? 1) === 0, "Database row confirms is_completed = 0 for 95% with explicit false override");

// 3.2 Low progress (20.0% = 720s / 3600s) with explicit is_completed: true
$resLowTrue = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'override-test-2',
    'media_title'           => 'Override Low True',
    'playback_time_seconds' => 720,
    'duration_seconds'      => 3600,
    'is_completed'          => true
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest((float)($resLowTrue['json']['data']['percentage_watched'] ?? 0) === 20.0, "20% progress calculated correctly");
assertTest(($resLowTrue['json']['data']['is_completed'] ?? false) === true, "Explicit override is_completed: true at 20% marks content completed (is_completed = true)");

// Verify in DB
$dbLowTrue = queryOne("SELECT is_completed, percentage_watched FROM watch_history WHERE user_id = 1 AND media_slug = 'override-test-2'");
assertTest((int)($dbLowTrue['is_completed'] ?? 0) === 1, "Database row confirms is_completed = 1 for 20% with explicit true override");

// 3.3 String boolean overrides ("false", "true", "0", "1")
$resStrFalse = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'override-test-3',
    'media_title'           => 'Override String False',
    'playback_time_seconds' => 3500,
    'duration_seconds'      => 3600,
    'is_completed'          => 'false'
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resStrFalse['json']['data']['is_completed'] ?? true) === false, "String 'false' override at 97.22% sets is_completed = false");

$resStrTrue = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'override-test-4',
    'media_title'           => 'Override String True',
    'playback_time_seconds' => 300,
    'duration_seconds'      => 3600,
    'is_completed'          => 'true'
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resStrTrue['json']['data']['is_completed'] ?? false) === true, "String 'true' override at 8.33% sets is_completed = true");

// 3.4 Missing / Null is_completed falls back to auto-calculation
$resNullBelow = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'override-test-5',
    'media_title'           => 'Override Null Below',
    'playback_time_seconds' => 1800,
    'duration_seconds'      => 3600,
    'is_completed'          => null
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resNullBelow['json']['data']['is_completed'] ?? true) === false, "Null is_completed at 50% auto-calculates to false");

$resNullAbove = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'override-test-6',
    'media_title'           => 'Override Null Above',
    'playback_time_seconds' => 3300,
    'duration_seconds'      => 3600,
    'is_completed'          => null
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resNullAbove['json']['data']['is_completed'] ?? false) === true, "Null is_completed at 91.67% auto-calculates to true");


// =========================================================================
// SECTION 4: WEB SERIES MULTI-EPISODE TRACKING & RESUME HEURISTICS
// =========================================================================
echo "\n4. Testing Web Series Multi-Episode Cross-Device Sync & Resume Heuristics:\n";

// Sync S1E1 on Mobile (100% completed)
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'breaking-bad',
    'media_title'           => 'Breaking Bad',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 1,
    'episode_title'         => 'Pilot',
    'playback_time_seconds' => 3000,
    'duration_seconds'      => 3000
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);

// Sync S1E2 on TV (50% in progress)
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'breaking-bad',
    'media_title'           => 'Breaking Bad',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 2,
    'episode_title'         => "Cat's in the Bag...",
    'playback_time_seconds' => 1500,
    'duration_seconds'      => 3000
], ['Authorization' => 'Bearer ' . $aliceTvToken]);

// Sync S1E3 on Windows (20% in progress)
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'breaking-bad',
    'media_title'           => 'Breaking Bad',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 3,
    'episode_title'         => '...And the Bag\'s in the River',
    'playback_time_seconds' => 600,
    'duration_seconds'      => 3000
], ['Authorization' => 'Bearer ' . $aliceWindowsToken]);

// 4.1 Query Series Resume without episode_number -> Should return S1E3 (most recently active)
$resSeriesGeneral = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=breaking-bad', [], [
    'Authorization' => 'Bearer ' . $aliceMobileToken
]);
assertTest(($resSeriesGeneral['json']['data']['found'] ?? false) === true, "Series general resume found = true");
assertTest(($resSeriesGeneral['json']['data']['episode_number'] ?? 0) === 3, "Series general resume returns latest active episode (Episode 3)");
assertTest(($resSeriesGeneral['json']['data']['playback_time_seconds'] ?? 0) === 600, "Episode 3 resume position is 600s");

// 4.2 Query Specific Episode Resume (Episode 1)
$resEp1 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=breaking-bad&episode_number=1', [], [
    'Authorization' => 'Bearer ' . $aliceTvToken
]);
assertTest(($resEp1['json']['data']['episode_number'] ?? 0) === 1, "Specific episode resume returns Episode 1");
assertTest(($resEp1['json']['data']['is_completed'] ?? false) === true, "Episode 1 is_completed is true");

// 4.3 Query Specific Episode Resume (Episode 2)
$resEp2 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=breaking-bad&episode_number=2', [], [
    'Authorization' => 'Bearer ' . $aliceWindowsToken
]);
assertTest(($resEp2['json']['data']['episode_number'] ?? 0) === 2, "Specific episode resume returns Episode 2");
assertTest(($resEp2['json']['data']['playback_time_seconds'] ?? 0) === 1500, "Episode 2 playback_time_seconds is 1500");

// 4.4 Update S1E2 on Mobile with newer timestamp -> Now S1E2 becomes the most recently updated
sleep(1);
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'breaking-bad',
    'media_title'           => 'Breaking Bad',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 2,
    'playback_time_seconds' => 2000,
    'duration_seconds'      => 3000
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);

$resSeriesGeneralAfterUpdate = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=breaking-bad', [], [
    'Authorization' => 'Bearer ' . $aliceTvToken
]);
assertTest(($resSeriesGeneralAfterUpdate['json']['data']['episode_number'] ?? 0) === 2, "After S1E2 update, series general resume switches to Episode 2");
assertTest(($resSeriesGeneralAfterUpdate['json']['data']['playback_time_seconds'] ?? 0) === 2000, "Episode 2 reflects updated position 2000s");

// 4.5 Series Progress Overview
$resProg = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=breaking-bad', [], [
    'Authorization' => 'Bearer ' . $aliceWindowsToken
]);
assertTest(($resProg['json']['count'] ?? 0) === 3, "Series progress overview returns count = 3");
$progList = $resProg['json']['data'] ?? [];
assertTest(($progList[0]['episode_number'] ?? 0) === 1 && ($progList[0]['is_completed'] ?? false) === true, "Progress list item 1 is Episode 1 (Completed)");
assertTest(($progList[1]['episode_number'] ?? 0) === 2 && (float)($progList[1]['percentage_watched'] ?? 0) === 66.67, "Progress list item 2 is Episode 2 (66.67%)");
assertTest(($progList[2]['episode_number'] ?? 0) === 3 && (float)($progList[2]['percentage_watched'] ?? 0) === 20.0, "Progress list item 3 is Episode 3 (20.0%)");


// =========================================================================
// SECTION 5: MULTI-TENANT ISOLATION & ADVERSARIAL PRIVACY ATTACKS
// =========================================================================
echo "\n5. Testing Multi-Tenant Isolation & Cross-User Privacy Defense:\n";

// User 1 (Alice), User 2 (Bob), and User 3 (Charlie) all watch "the-dark-knight"
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'the-dark-knight',
    'media_title'           => 'The Dark Knight',
    'playback_time_seconds' => 1000,
    'duration_seconds'      => 7200
], ['Authorization' => 'Bearer ' . $aliceMobileToken]);

runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'the-dark-knight',
    'media_title'           => 'The Dark Knight',
    'playback_time_seconds' => 3000,
    'duration_seconds'      => 7200
], ['Authorization' => 'Bearer ' . $bobToken]);

runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'the-dark-knight',
    'media_title'           => 'The Dark Knight',
    'playback_time_seconds' => 5000,
    'duration_seconds'      => 7200
], ['Authorization' => 'Bearer ' . $charlieToken]);

// 5.1 Verify each user reads strictly their own resume position
$resAliceDK = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=the-dark-knight', [], ['Authorization' => 'Bearer ' . $aliceTvToken]);
$resBobDK = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=the-dark-knight', [], ['Authorization' => 'Bearer ' . $bobToken]);
$resCharlieDK = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=the-dark-knight', [], ['Authorization' => 'Bearer ' . $charlieToken]);

assertTest(($resAliceDK['json']['data']['playback_time_seconds'] ?? 0) === 1000, "Alice retrieves strictly her own position (1000s)");
assertTest(($resBobDK['json']['data']['playback_time_seconds'] ?? 0) === 3000, "Bob retrieves strictly his own position (3000s)");
assertTest(($resCharlieDK['json']['data']['playback_time_seconds'] ?? 0) === 5000, "Charlie retrieves strictly his own position (5000s)");

// 5.2 Alice deletes "the-dark-knight"
$delRes = runApiRequest('DELETE', '/api/v1/history/watch?media_slug=the-dark-knight', [], ['Authorization' => 'Bearer ' . $aliceWindowsToken]);
assertTest(($delRes['json']['status'] ?? false) === true, "Alice deletes the-dark-knight");

// Verify Alice resume is now found = false
$resAliceAfterDel = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=the-dark-knight', [], ['Authorization' => 'Bearer ' . $aliceMobileToken]);
assertTest(($resAliceAfterDel['json']['data']['found'] ?? true) === false, "Alice's resume returns found = false after deletion");

// Verify Bob & Charlie are 100% unaffected
$resBobAfterDel = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=the-dark-knight', [], ['Authorization' => 'Bearer ' . $bobToken]);
$resCharlieAfterDel = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=the-dark-knight', [], ['Authorization' => 'Bearer ' . $charlieToken]);
assertTest(($resBobAfterDel['json']['data']['playback_time_seconds'] ?? 0) === 3000, "Bob's watch progress (3000s) completely unharmed by Alice's deletion");
assertTest(($resCharlieAfterDel['json']['data']['playback_time_seconds'] ?? 0) === 5000, "Charlie's watch progress (5000s) completely unharmed by Alice's deletion");


// =========================================================================
// SECTION 6: HIGH-CONCURRENCY RAPID UPSERT STRESS TEST
// =========================================================================
echo "\n6. Testing High-Frequency Concurrent Interleaved Device Updates:\n";

// Rapid sequence across Mobile, TV, and Windows on "rapid-stress-movie"
$movieSlug = 'rapid-stress-movie';
for ($i = 1; $i <= 15; $i++) {
    $token = ($i % 3 === 1) ? $aliceMobileToken : (($i % 3 === 2) ? $aliceTvToken : $aliceWindowsToken);
    $timePos = $i * 200;
    runApiRequest('POST', '/api/v1/history/watch/sync', [
        'media_slug'            => $movieSlug,
        'media_title'           => 'Rapid Stress Movie',
        'playback_time_seconds' => $timePos,
        'duration_seconds'      => 4000
    ], ['Authorization' => 'Bearer ' . $token]);
}

$rapidRows = queryDb("SELECT * FROM watch_history WHERE user_id = 1 AND media_slug = :slug", ['slug' => $movieSlug]);
assertTest(count($rapidRows) === 1, "15 rapid interleaved cross-device updates maintain exactly 1 database row");
assertTest((int)($rapidRows[0]['playback_time_seconds'] ?? 0) === 3000, "Final database state matches exact last sync position (3000s)");
assertTest((float)($rapidRows[0]['percentage_watched'] ?? 0) === 75.0, "Final percentage_watched is 75.00%");


// =========================================================================
// SUMMARY
// =========================================================================
echo "\n====================================================================\n";
echo "CHALLENGER STRESS TEST RESULTS SUMMARY:\n";
echo "  Total Tests Run: {$testsRun}\n";
echo "  Passed: {$testsPassed}\n";
echo "  Failed: {$testsFailed}\n";
echo "====================================================================\n";

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
