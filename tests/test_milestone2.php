<?php
/**
 * Milestone 2 Automated Test Suite
 * Tests Cross-Device Watch Progress Sync, Resume Playback, Web Series Tracking,
 * Series Progress Overview, Multi-User Isolation, Input Validation, and History Deletion.
 */

declare(strict_types=1);

$phpBinary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBinary)) {
    $phpBinary = 'php';
}

$dbPath = __DIR__ . '/test_m2.sqlite';
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

    -- Seed Users: User 1 (Alice) & User 2 (Bob)
    INSERT INTO users (id, uuid, name, email, password_hash, auth_provider, is_verified, is_active)
    VALUES 
    (1, 'user-alice-1111', 'Alice Walker', 'alice@example.com', 'hash_alice', 'email', 1, 1),
    (2, 'user-bob-2222', 'Bob Smith', 'bob@example.com', 'hash_bob', 'email', 1, 1);
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

require_once __DIR__ . '/../src/Services/JWTService.php';
require_once __DIR__ . '/../src/Core/Env.php';

// Generate JWT tokens for multi-device & multi-user tests
$aliceMobileToken = \App\Services\JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'user-alice-1111',
    'email'      => 'alice@example.com',
    'name'       => 'Alice Walker',
    'device_id'  => 'mobile_alice_01'
], 3600);

$aliceTvToken = \App\Services\JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'user-alice-1111',
    'email'      => 'alice@example.com',
    'name'       => 'Alice Walker',
    'device_id'  => 'tv_alice_02'
], 3600);

$aliceDesktopToken = \App\Services\JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'user-alice-1111',
    'email'      => 'alice@example.com',
    'name'       => 'Alice Walker',
    'device_id'  => 'desktop_alice_03'
], 3600);

$bobMobileToken = \App\Services\JWTService::generateToken([
    'sub'        => 2,
    'uuid'       => 'user-bob-2222',
    'email'      => 'bob@example.com',
    'name'       => 'Bob Smith',
    'device_id'  => 'mobile_bob_01'
], 3600);

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

echo "====================================================================\n";
echo "MAXPLEX BACKEND - MILESTONE 2 VERIFICATION TEST SUITE\n";
echo "====================================================================\n\n";

// =========================================================================
// CATEGORY 1: Cross-Device Watch Progress & Resume Synchronization
// =========================================================================
echo "1. Testing Cross-Device Watch Progress & Resume Sync:\n";

// Step 1: Alice on Mobile starts watching movie "Avatar: The Way of Water" (1200s / 3600s = 33.33%)
$syncRes1 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'avatar-2022',
    'media_title'           => 'Avatar: The Way of Water',
    'media_poster'          => 'https://example.com/avatar.jpg',
    'content_type'          => 'movie',
    'playback_time_seconds' => 1200,
    'duration_seconds'      => 3600
], ['Authorization' => "Bearer {$aliceMobileToken}"]);

$json1 = $syncRes1['json'];
assertTest("Mobile device syncs movie progress successfully (HTTP 200)", ($json1['status'] ?? false) === true);
assertTest("percentage_watched accurately computed as 33.33", ($json1['data']['percentage_watched'] ?? 0) === 33.33);
assertTest("is_completed is false", ($json1['data']['is_completed'] ?? true) === false);
assertTest("playback_time_seconds matches 1200", ($json1['data']['playback_time_seconds'] ?? 0) === 1200);

// Step 2: Alice opens Android TV client and queries resume point for "avatar-2022"
$resumeRes1 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=avatar-2022', [], [
    'Authorization' => "Bearer {$aliceTvToken}"
]);
$resumeJson1 = $resumeRes1['json'];
assertTest("Android TV fetches resume point with found = true", ($resumeJson1['data']['found'] ?? false) === true);
assertTest("Android TV resumes at exact timestamp 1200 seconds", ($resumeJson1['data']['playback_time_seconds'] ?? 0) === 1200);
assertTest("Android TV receives media_title", ($resumeJson1['data']['media_title'] ?? '') === 'Avatar: The Way of Water');
assertTest("Android TV receives last_watched_at timestamp", !empty($resumeJson1['data']['last_watched_at']));

// Step 3: Alice watches further on Windows Desktop up to 2400s (66.67%)
$syncRes2 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'avatar-2022',
    'media_title'           => 'Avatar: The Way of Water',
    'media_poster'          => 'https://example.com/avatar.jpg',
    'content_type'          => 'movie',
    'playback_time_seconds' => 2400,
    'duration_seconds'      => 3600
], ['Authorization' => "Bearer {$aliceDesktopToken}"]);
$json2 = $syncRes2['json'];
assertTest("Windows Desktop updates progress to 66.67%", ($json2['data']['percentage_watched'] ?? 0) === 66.67);

// Step 4: Verify exactly 1 DB row exists for this user + movie (idempotent UPSERT)
$avatarRows = queryDb("SELECT * FROM watch_history WHERE user_id = 1 AND media_slug = 'avatar-2022'");
assertTest("Database maintains exactly 1 row for movie via UPSERT", count($avatarRows) === 1);
assertTest("Database row reflects updated position 2400s", (int)$avatarRows[0]['playback_time_seconds'] === 2400);

// Step 5: Alice re-checks resume on Mobile -> Gets updated 2400s
$resumeRes2 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=avatar-2022', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Mobile device seamlessly observes updated 2400s resume point", ($resumeRes2['json']['data']['playback_time_seconds'] ?? 0) === 2400);


// =========================================================================
// CATEGORY 2: Completion Rules, Auto-Threshold (>=90%) & Boolean Overrides
// =========================================================================
echo "\n2. Testing Completion Rules, Auto-Threshold & Boolean Overrides:\n";

// Test 2.1: Watch progress at 89.89% (3236s / 3600s) -> Uncompleted
$syncBelow = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'oppenheimer-2023',
    'media_title'           => 'Oppenheimer',
    'playback_time_seconds' => 3236,
    'duration_seconds'      => 3600
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("Progress at 89.89% sets is_completed = false", ($syncBelow['json']['data']['is_completed'] ?? true) === false);
assertTest("percentage_watched is 89.89", ($syncBelow['json']['data']['percentage_watched'] ?? 0) === 89.89);

// Test 2.2: Watch progress at 94.44% (3400s / 3600s) -> Auto-Complete >= 90%
$syncAuto = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'oppenheimer-2023',
    'media_title'           => 'Oppenheimer',
    'playback_time_seconds' => 3400,
    'duration_seconds'      => 3600
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("Progress at 94.44% auto-triggers is_completed = true", ($syncAuto['json']['data']['is_completed'] ?? false) === true);

// Test 2.3: Explicit override is_completed: true at low percentage (600s / 3600s = 16.67%)
$syncManualDone = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'dune-part-two',
    'media_title'           => 'Dune: Part Two',
    'playback_time_seconds' => 600,
    'duration_seconds'      => 3600,
    'is_completed'          => true
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("Explicit is_completed: true override respected at 16.67%", ($syncManualDone['json']['data']['is_completed'] ?? false) === true);

// Test 2.4: Explicit override is_completed: false at high percentage (3550s / 3600s = 98.61%)
$syncManualIncomplete = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'inception-2010',
    'media_title'           => 'Inception',
    'playback_time_seconds' => 3550,
    'duration_seconds'      => 3600,
    'is_completed'          => false
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("Explicit is_completed: false override respected at 98.61%", ($syncManualIncomplete['json']['data']['is_completed'] ?? true) === false);

// Test 2.5: Full 100% watch state
$sync100 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'interstellar-2014',
    'media_title'           => 'Interstellar',
    'playback_time_seconds' => 3600,
    'duration_seconds'      => 3600
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("100% watched sets percentage_watched = 100.0 and is_completed = true", 
    (float)($sync100['json']['data']['percentage_watched'] ?? 0) === 100.0 && ($sync100['json']['data']['is_completed'] ?? false) === true
);

// Test 2.6: Continue Watching feed excludes completed items and includes active uncompleted items
$continueRes = runApiRequest('GET', '/api/v1/history/watch', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
$continueList = $continueRes['json']['data'] ?? [];
$slugsInContinue = array_column($continueList, 'media_slug');
assertTest("Continue watching list fetched successfully", ($continueRes['json']['status'] ?? false) === true);
assertTest("Continue watching includes uncompleted movie (avatar-2022)", in_array('avatar-2022', $slugsInContinue));
assertTest("Continue watching includes manually uncompleted movie (inception-2010)", in_array('inception-2010', $slugsInContinue));
assertTest("Continue watching excludes auto-completed movie (oppenheimer-2023)", !in_array('oppenheimer-2023', $slugsInContinue));
assertTest("Continue watching excludes 100% completed movie (interstellar-2014)", !in_array('interstellar-2014', $slugsInContinue));


// =========================================================================
// CATEGORY 3: Web Series Multi-Episode Tracking & Resume Heuristics
// =========================================================================
echo "\n3. Testing Web Series Multi-Episode Tracking & Resume Heuristics:\n";

// Step 1: Alice watches S1 Episode 1 of "Stranger Things" to completion (2400s / 2400s = 100%)
$seriesEp1 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'stranger-things',
    'media_title'           => 'Stranger Things',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 1,
    'episode_title'         => 'Chapter One: The Vanishing of Will Byers',
    'playback_time_seconds' => 2400,
    'duration_seconds'      => 2400
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("S1E1 synced as completed (100%)", ($seriesEp1['json']['data']['is_completed'] ?? false) === true);

// Step 2: Alice watches S1 Episode 2 partially (1200s / 2400s = 50%)
$seriesEp2 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'stranger-things',
    'media_title'           => 'Stranger Things',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 2,
    'episode_title'         => 'Chapter Two: The Weirdo on Maple Street',
    'playback_time_seconds' => 1200,
    'duration_seconds'      => 2400
], ['Authorization' => "Bearer {$aliceTvToken}"]);
assertTest("S1E2 synced as in-progress (50%)", ($seriesEp2['json']['data']['is_completed'] ?? true) === false);

// Step 3: Query series resume WITHOUT episode_number -> Should return most recently updated episode (Episode 2)
$seriesResumeGeneral = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things', [], [
    'Authorization' => "Bearer {$aliceDesktopToken}"
]);
$resumeEpData = $seriesResumeGeneral['json']['data'] ?? [];
assertTest("Resume heuristic (omitted episode) returns latest episode (Episode 2)", ($resumeEpData['episode_number'] ?? 0) === 2);
assertTest("Resume heuristic returns Episode 2 playback time 1200s", ($resumeEpData['playback_time_seconds'] ?? 0) === 1200);

// Step 4: Query series resume WITH episode_number=1 -> Should return Episode 1
$seriesResumeEp1 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things&episode_number=1', [], [
    'Authorization' => "Bearer {$aliceDesktopToken}"
]);
assertTest("Specific episode resume returns Episode 1", ($seriesResumeEp1['json']['data']['episode_number'] ?? 0) === 1);
assertTest("Episode 1 resume returns is_completed = true", ($seriesResumeEp1['json']['data']['is_completed'] ?? false) === true);

// Step 5: Query series progress overview GET /api/v1/history/watch/series-progress?media_slug=stranger-things
$seriesProgRes = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
$progJson = $seriesProgRes['json'];
assertTest("Series progress overview returns status = true", ($progJson['status'] ?? false) === true);
assertTest("Series progress returns count = 2", ($progJson['count'] ?? 0) === 2);
$epList = $progJson['data'] ?? [];
assertTest("First item is Season 1 Episode 1", ($epList[0]['season_number'] ?? 0) === 1 && ($epList[0]['episode_number'] ?? 0) === 1);
assertTest("First item is 100% completed", (float)($epList[0]['percentage_watched'] ?? 0) === 100.0 && ($epList[0]['is_completed'] ?? false) === true);
assertTest("Second item is Season 1 Episode 2", ($epList[1]['season_number'] ?? 0) === 1 && ($epList[1]['episode_number'] ?? 0) === 2);
assertTest("Second item is 50% in-progress", (float)($epList[1]['percentage_watched'] ?? 0) === 50.0 && ($epList[1]['is_completed'] ?? true) === false);

// Step 6: Query resume for unwatched media
$unwatchedResume = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=never-watched-movie-xyz', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Unwatched media returns found = false", ($unwatchedResume['json']['data']['found'] ?? true) === false);
assertTest("Unwatched media returns playback_time_seconds = 0", ($unwatchedResume['json']['data']['playback_time_seconds'] ?? -1) === 0);

// Step 7: Query series progress for unwatched show
$unwatchedSeries = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=unwatched-series-xyz', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Unwatched series returns empty data array", isset($unwatchedSeries['json']['data']) && is_array($unwatchedSeries['json']['data']) && count($unwatchedSeries['json']['data']) === 0);


// =========================================================================
// CATEGORY 4: Multi-User Isolation & Tenant Boundaries
// =========================================================================
echo "\n4. Testing Multi-User Tenant Isolation:\n";

// Alice syncs "gladiator-2000" at 1800s
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'gladiator-2000',
    'media_title'           => 'Gladiator',
    'playback_time_seconds' => 1800,
    'duration_seconds'      => 3600
], ['Authorization' => "Bearer {$aliceMobileToken}"]);

// Bob syncs "gladiator-2000" at 300s
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'gladiator-2000',
    'media_title'           => 'Gladiator',
    'playback_time_seconds' => 300,
    'duration_seconds'      => 3600
], ['Authorization' => "Bearer {$bobMobileToken}"]);

// Query Alice resume
$aliceGlad = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=gladiator-2000', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
// Query Bob resume
$bobGlad = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=gladiator-2000', [], [
    'Authorization' => "Bearer {$bobMobileToken}"
]);

assertTest("Alice gets her own playback position (1800s)", ($aliceGlad['json']['data']['playback_time_seconds'] ?? 0) === 1800);
assertTest("Bob gets his own playback position (300s)", ($bobGlad['json']['data']['playback_time_seconds'] ?? 0) === 300);

// Alice deletes "gladiator-2000"
$delAlice = runApiRequest('DELETE', '/api/v1/history/watch?media_slug=gladiator-2000', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Alice successfully deletes gladiator-2000", ($delAlice['json']['status'] ?? false) === true);

// Verify Alice resume is now found = false
$alicePostDel = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=gladiator-2000', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Alice resume now returns found = false", ($alicePostDel['json']['data']['found'] ?? true) === false);

// Verify Bob's resume is STILL found = true with 300s (Zero cross-user data corruption)
$bobPostDel = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=gladiator-2000', [], [
    'Authorization' => "Bearer {$bobMobileToken}"
]);
assertTest("Bob's watch progress remains completely intact at 300s after Alice deletion", 
    ($bobPostDel['json']['data']['found'] ?? false) === true && ($bobPostDel['json']['data']['playback_time_seconds'] ?? 0) === 300
);


// =========================================================================
// CATEGORY 5: History Deletion Granularity (Episode, Media, All)
// =========================================================================
echo "\n5. Testing History Deletion Granularity:\n";

// Alice currently has Stranger Things S1E1 and S1E2.
// Test 5.1: Delete single episode (S1E2)
$delEp2 = runApiRequest('DELETE', '/api/v1/history/watch?media_slug=stranger-things&episode_number=2', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Single episode deletion returns status = true", ($delEp2['json']['status'] ?? false) === true);
assertTest("deleted_count is 1", ($delEp2['json']['data']['deleted_count'] ?? 0) === 1);

// Verify S1E1 still remains in series-progress
$progAfterEpDel = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
$remainingEps = $progAfterEpDel['json']['data'] ?? [];
assertTest("Series progress now contains exactly 1 episode", count($remainingEps) === 1);
assertTest("Remaining episode is Episode 1", ($remainingEps[0]['episode_number'] ?? 0) === 1);

// Test 5.2: Delete entire media by slug
$delMedia = runApiRequest('DELETE', '/api/v1/history/watch?media_slug=stranger-things', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Entire media deletion returns status = true", ($delMedia['json']['status'] ?? false) === true);
assertTest("deleted_count is 1 for remaining episode", ($delMedia['json']['data']['deleted_count'] ?? 0) === 1);

$progAfterMediaDel = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Series progress is now completely empty", count($progAfterMediaDel['json']['data'] ?? []) === 0);

// Test 5.3: Bulk clear all history
$delAll = runApiRequest('DELETE', '/api/v1/history/watch?all=true', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Clear all history returns status = true", ($delAll['json']['status'] ?? false) === true);

// Verify Alice continue watching is empty
$continueAlice = runApiRequest('GET', '/api/v1/history/watch', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Alice continue watching is empty after clear-all", count($continueAlice['json']['data'] ?? []) === 0);

// Verify Bob's history is still intact
$bobHistory = queryDb("SELECT * FROM watch_history WHERE user_id = 2");
assertTest("Bob's records remain completely unaffected by Alice's clear-all", count($bobHistory) >= 1);


// =========================================================================
// CATEGORY 6: Input Validation, Security & Mathematical Edge Cases
// =========================================================================
echo "\n6. Testing Input Validation, Security & Mathematical Edge Cases:\n";

// Test 6.1: Missing media_slug on sync -> 422
$valNoSlug = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_title' => 'Missing Slug Movie'
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("Sync without media_slug returns status = false (HTTP 422)", ($valNoSlug['json']['status'] ?? true) === false);

// Test 6.2: Missing media_title on sync -> 422
$valNoTitle = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug' => 'no-title-slug'
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("Sync without media_title returns status = false (HTTP 422)", ($valNoTitle['json']['status'] ?? true) === false);

// Test 6.3: Missing media_slug on resume -> 422
$valResumeNoSlug = runApiRequest('GET', '/api/v1/history/watch/resume', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Resume without media_slug returns status = false (HTTP 422)", ($valResumeNoSlug['json']['status'] ?? true) === false);

// Test 6.4: Missing media_slug on series-progress -> 422
$valProgNoSlug = runApiRequest('GET', '/api/v1/history/watch/series-progress', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("Series progress without media_slug returns status = false (HTTP 422)", ($valProgNoSlug['json']['status'] ?? true) === false);

// Test 6.5: Missing media_slug on DELETE without all/clear_all -> 422
$valDelNoParams = runApiRequest('DELETE', '/api/v1/history/watch', [], [
    'Authorization' => "Bearer {$aliceMobileToken}"
]);
assertTest("DELETE without media_slug or all=true returns status = false (HTTP 422)", ($valDelNoParams['json']['status'] ?? true) === false);

// Test 6.6: Negative playback timestamp clamped to 0
$syncNeg = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'matrix-1999',
    'media_title'           => 'The Matrix',
    'playback_time_seconds' => -150,
    'duration_seconds'      => 3600
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("Negative playback position clamped to 0", ($syncNeg['json']['data']['playback_time_seconds'] ?? -1) === 0);
assertTest("Negative playback percentage is 0.0", (float)($syncNeg['json']['data']['percentage_watched'] ?? -1) === 0.0);

// Test 6.7: Zero duration division guard
$syncZeroDur = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'matrix-1999',
    'media_title'           => 'The Matrix',
    'playback_time_seconds' => 500,
    'duration_seconds'      => 0
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("Zero duration handled safely without error / division by zero", ($syncZeroDur['json']['status'] ?? false) === true);
assertTest("Zero duration percentage is 0.0", (float)($syncZeroDur['json']['data']['percentage_watched'] ?? -1) === 0.0);

// Test 6.8: Playback exceeding duration clamped to 100%
$syncOverDur = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'matrix-1999',
    'media_title'           => 'The Matrix',
    'playback_time_seconds' => 5000,
    'duration_seconds'      => 3600
], ['Authorization' => "Bearer {$aliceMobileToken}"]);
assertTest("Playback exceeding duration caps percentage_watched at 100.0", (float)($syncOverDur['json']['data']['percentage_watched'] ?? 0) === 100.0);
assertTest("Playback exceeding duration sets is_completed = true", ($syncOverDur['json']['data']['is_completed'] ?? false) === true);

// Test 6.9: Unauthenticated calls (Missing Bearer Token) -> 401
$unauthSync = runApiRequest('POST', '/api/v1/history/watch/sync', ['media_slug' => 'test', 'media_title' => 'Test']);
assertTest("Unauthenticated sync call intercepted with 401 Unauthorized", ($unauthSync['json']['status'] ?? true) === false);

$unauthResume = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=test');
assertTest("Unauthenticated resume call intercepted with 401 Unauthorized", ($unauthResume['json']['status'] ?? true) === false);

$unauthProg = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=test');
assertTest("Unauthenticated series-progress call intercepted with 401 Unauthorized", ($unauthProg['json']['status'] ?? true) === false);

$unauthDel = runApiRequest('DELETE', '/api/v1/history/watch?media_slug=test');
assertTest("Unauthenticated delete call intercepted with 401 Unauthorized", ($unauthDel['json']['status'] ?? true) === false);

// Test 6.10: Tampered / Invalid JWT Token -> 401
$tamperedRes = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=test', [], [
    'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.INVALID.TAMPERED'
]);
assertTest("Tampered Bearer token intercepted with 401 Unauthorized", ($tamperedRes['json']['status'] ?? true) === false);


// =========================================================================
// CATEGORY 7: High-Frequency UPSERT State Consistency
// =========================================================================
echo "\n7. Testing High-Frequency UPSERT State Consistency:\n";

// Execute 5 rapid progress updates on "batman-begins"
for ($i = 1; $i <= 5; $i++) {
    $pos = $i * 300;
    runApiRequest('POST', '/api/v1/history/watch/sync', [
        'media_slug'            => 'batman-begins',
        'media_title'           => 'Batman Begins',
        'playback_time_seconds' => $pos,
        'duration_seconds'      => 3600
    ], ['Authorization' => "Bearer {$aliceMobileToken}"]);
}

$batmanRows = queryDb("SELECT * FROM watch_history WHERE user_id = 1 AND media_slug = 'batman-begins'");
assertTest("Rapid syncs maintain exactly 1 database row", count($batmanRows) === 1);
assertTest("Final state matches last position (1500s)", (int)$batmanRows[0]['playback_time_seconds'] === 1500);
assertTest("Final percentage matches 41.67%", (float)$batmanRows[0]['percentage_watched'] === 41.67);


echo "\n====================================================================\n";
echo "MILESTONE 2 TEST SUMMARY\n";
echo "Passed: {$passCount}\n";
echo "Failed: {$failCount}\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
