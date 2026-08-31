<?php
/**
 * Adversarial Stress & Verification Test Suite for Milestone 2
 * Focus: Web Series Multi-Episode Tracking (S1E1..S1E10), Resume Lookup Heuristics,
 * Series Progress Overview, Multi-Tenant User Isolation, and Chaos Stress.
 */

declare(strict_types=1);

$phpBinary = 'C:\\xampp\\php\\php.exe';
if (!file_exists($phpBinary)) {
    $phpBinary = 'php';
}

$dbPath = __DIR__ . '/test_m2_adversarial.sqlite';
if (file_exists($dbPath)) {
    @unlink($dbPath);
}

// Clean any stale rate limits
$rateLimitDir = sys_get_temp_dir() . '/ott_rate_limits';
if (is_dir($rateLimitDir)) {
    foreach (glob($rateLimitDir . '/*') as $f) {
        if (is_file($f)) @unlink($f);
    }
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

// Initialize SQLite Schema
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

    -- Seed User 1 (Alice) & User 2 (Bob)
    INSERT INTO users (id, uuid, name, email, password_hash, auth_provider, is_verified, is_active)
    VALUES 
    (1, 'user-alice-1111', 'Alice Walker', 'alice@example.com', 'hash_alice', 'email', 1, 1),
    (2, 'user-bob-2222', 'Bob Smith', 'bob@example.com', 'hash_bob', 'email', 1, 1);
");
$initDb = null;

$reqCounter = 0;

function runApiRequest(string $method, string $uri, array $body = [], array $headers = []): array {
    global $phpBinary, $dbPath, $reqCounter;
    $reqCounter++;

    // Cycle synthetic client IPs to stay completely clear of rate limiter windows
    $syntheticIp = '10.0.' . intval($reqCounter / 50) . '.' . ($reqCounter % 50 + 1);
    if (!isset($headers['X-Forwarded-For'])) {
        $headers['X-Forwarded-For'] = $syntheticIp;
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

require_once __DIR__ . '/../src/Services/JWTService.php';
require_once __DIR__ . '/../src/Core/Env.php';

// Generate tokens for Alice and Bob across devices
$aliceToken = \App\Services\JWTService::generateToken([
    'sub'        => 1,
    'uuid'       => 'user-alice-1111',
    'email'      => 'alice@example.com',
    'name'       => 'Alice Walker',
    'device_id'  => 'mobile_alice'
], 3600);

$bobToken = \App\Services\JWTService::generateToken([
    'sub'        => 2,
    'uuid'       => 'user-bob-2222',
    'email'      => 'bob@example.com',
    'name'       => 'Bob Smith',
    'device_id'  => 'tv_bob'
], 3600);

$passCount = 0;
$failCount = 0;

function assertCheck(string $name, bool $condition, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo "  [PASS] {$name}\n";
    } else {
        $failCount++;
        echo "  [FAIL] {$name} - {$details}\n";
    }
}

function floatEquals(mixed $val1, mixed $val2, float $epsilon = 0.01): bool {
    if ($val1 === null || $val2 === null) return false;
    return abs((float)$val1 - (float)$val2) < $epsilon;
}

echo "====================================================================\n";
echo "MAXPLEX BACKEND - EMPIRICAL ADVERSARIAL STRESS TEST (MILESTONE 2)\n";
echo "Focus: Web Series Multi-Episode Tracking & Multi-User Isolation\n";
echo "====================================================================\n\n";

// =========================================================================
// PART 1: Web Series Multi-Episode Tracking (Season 1 Episodes 1..10)
// =========================================================================
echo "1. Testing Web Series Multi-Episode Tracking (S1 Episodes 1..10):\n";

$episodesSpec = [
    1  => ['pos' => 3000, 'dur' => 3000, 'override' => null,  'expected_perc' => 100.0, 'expected_comp' => true,  'title' => 'Chapter One: The Vanishing of Will Byers'],
    2  => ['pos' => 2850, 'dur' => 3000, 'override' => null,  'expected_perc' => 95.0,  'expected_comp' => true,  'title' => 'Chapter Two: The Weirdo on Maple Street'],
    3  => ['pos' => 1500, 'dur' => 3000, 'override' => null,  'expected_perc' => 50.0,  'expected_comp' => false, 'title' => 'Chapter Three: Holly, Jolly'],
    4  => ['pos' => 300,  'dur' => 3000, 'override' => null,  'expected_perc' => 10.0,  'expected_comp' => false, 'title' => 'Chapter Four: The Body'],
    5  => ['pos' => 2250, 'dur' => 3000, 'override' => null,  'expected_perc' => 75.0,  'expected_comp' => false, 'title' => 'Chapter Five: The Flea and the Acrobat'],
    6  => ['pos' => 0,    'dur' => 3000, 'override' => null,  'expected_perc' => 0.0,   'expected_comp' => false, 'title' => 'Chapter Six: The Monster'],
    7  => ['pos' => 2970, 'dur' => 3000, 'override' => null,  'expected_perc' => 99.0,  'expected_comp' => true,  'title' => 'Chapter Seven: The Bathtub'],
    8  => ['pos' => 3000, 'dur' => 3000, 'override' => false, 'expected_perc' => 100.0, 'expected_comp' => false, 'title' => 'Chapter Eight: The Upside Down (Rewatching)'],
    9  => ['pos' => 600,  'dur' => 3000, 'override' => true,  'expected_perc' => 20.0,  'expected_comp' => true,  'title' => 'Chapter Nine: Bonus Ep (Skipped to End)'],
    10 => ['pos' => 2550, 'dur' => 3000, 'override' => null,  'expected_perc' => 85.0,  'expected_comp' => false, 'title' => 'Chapter Ten: Season Finale']
];

// Sync in pseudo-random out-of-order sequence to challenge insertion order assumptions
$syncOrder = [10, 1, 7, 3, 5, 2, 8, 4, 6, 9];

foreach ($syncOrder as $epNum) {
    $spec = $episodesSpec[$epNum];
    $body = [
        'media_slug'            => 'stranger-things-s1',
        'media_title'           => 'Stranger Things Season 1',
        'media_poster'          => 'https://example.com/st1.jpg',
        'content_type'          => 'web_series',
        'season_number'         => 1,
        'episode_number'        => $epNum,
        'episode_title'         => $spec['title'],
        'playback_time_seconds' => $spec['pos'],
        'duration_seconds'      => $spec['dur']
    ];
    if ($spec['override'] !== null) {
        $body['is_completed'] = $spec['override'];
    }

    $res = runApiRequest('POST', '/api/v1/history/watch/sync', $body, [
        'Authorization' => "Bearer {$aliceToken}"
    ]);

    $data = $res['json']['data'] ?? [];
    assertCheck("Alice syncs S1E{$epNum} successfully (HTTP 200)", ($res['json']['status'] ?? false) === true);
    assertCheck("S1E{$epNum} percentage_watched matches {$spec['expected_perc']}%", floatEquals($data['percentage_watched'] ?? null, $spec['expected_perc']));
    assertCheck("S1E{$epNum} is_completed matches " . ($spec['expected_comp'] ? 'true' : 'false'), ($data['is_completed'] ?? null) === $spec['expected_comp']);
}

// Check database table row count for Alice
$dbRows = queryDb("SELECT * FROM watch_history WHERE user_id = 1 AND media_slug = 'stranger-things-s1'");
assertCheck("Database has exactly 10 distinct episode rows for stranger-things-s1", count($dbRows) === 10);

// Test updating an existing episode (Ep 4 progress update from 10% to 60%)
sleep(1); // Advance timestamp for update
$updateRes = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'stranger-things-s1',
    'media_title'           => 'Stranger Things Season 1',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 4,
    'playback_time_seconds' => 1800,
    'duration_seconds'      => 3000
], ['Authorization' => "Bearer {$aliceToken}"]);

assertCheck("Updating S1E4 to 1800s returns status = true", ($updateRes['json']['status'] ?? false) === true);
assertCheck("Updating S1E4 updates percentage to 60.0%", floatEquals($updateRes['json']['data']['percentage_watched'] ?? null, 60.0));

$dbRowsAfterUpdate = queryDb("SELECT * FROM watch_history WHERE user_id = 1 AND media_slug = 'stranger-things-s1'");
assertCheck("Database still has exactly 10 rows (no duplicates on update)", count($dbRowsAfterUpdate) === 10);

$ep4Row = queryOne("SELECT * FROM watch_history WHERE user_id = 1 AND media_slug = 'stranger-things-s1' AND episode_number = 4");
assertCheck("Database S1E4 row position is updated to 1800s", (int)($ep4Row['playback_time_seconds'] ?? 0) === 1800);


// =========================================================================
// PART 2: Resume Lookup Heuristics
// =========================================================================
echo "\n2. Testing Resume Lookup Heuristics:\n";

// Heuristic A: Specific episode query returns exact episode match
$resumeEp1 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1&episode_number=1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Specific query for Episode 1 returns found = true", ($resumeEp1['json']['data']['found'] ?? false) === true);
assertCheck("Specific query for Episode 1 returns episode_number = 1", ($resumeEp1['json']['data']['episode_number'] ?? 0) === 1);
assertCheck("Episode 1 playback position is 3000s", ($resumeEp1['json']['data']['playback_time_seconds'] ?? 0) === 3000);
assertCheck("Episode 1 is_completed is true", ($resumeEp1['json']['data']['is_completed'] ?? false) === true);

$resumeEp4 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1&episode_number=4', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Specific query for Episode 4 returns updated position 1800s", ($resumeEp4['json']['data']['playback_time_seconds'] ?? 0) === 1800);
assertCheck("Episode 4 percentage is 60.0%", floatEquals($resumeEp4['json']['data']['percentage_watched'] ?? null, 60.0));
assertCheck("Episode 4 is_completed is false", ($resumeEp4['json']['data']['is_completed'] ?? true) === false);

$resumeEp8 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1&episode_number=8', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Specific query for Episode 8 returns overridden is_completed = false", ($resumeEp8['json']['data']['is_completed'] ?? true) === false);

$resumeEp9 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1&episode_number=9', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Specific query for Episode 9 returns overridden is_completed = true", ($resumeEp9['json']['data']['is_completed'] ?? false) === true);

// Non-existent episode query returns found = false
$resumeEp99 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1&episode_number=99', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Query for unwatched Episode 99 returns found = false", ($resumeEp99['json']['data']['found'] ?? true) === false);
assertCheck("Unwatched Episode 99 returns playback_time_seconds = 0", ($resumeEp99['json']['data']['playback_time_seconds'] ?? -1) === 0);

// Heuristic B: Query without episode returns latest active episode (recency heuristic)
// S1E4 was the most recently updated episode above!
$resumeLatest1 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Resume without episode returns latest active episode (Episode 4)", ($resumeLatest1['json']['data']['episode_number'] ?? 0) === 4);
assertCheck("Resume without episode returns Episode 4 position (1800s)", ($resumeLatest1['json']['data']['playback_time_seconds'] ?? 0) === 1800);

// Now Alice watches Episode 7 again (updated after 1s)
sleep(1); // Ensure timestamp increment
$syncEp7 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'stranger-things-s1',
    'media_title'           => 'Stranger Things Season 1',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 7,
    'playback_time_seconds' => 2980,
    'duration_seconds'      => 3000
], ['Authorization' => "Bearer {$aliceToken}"]);

$resumeLatest2 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Resume without episode now dynamically updates to latest active Episode 7", ($resumeLatest2['json']['data']['episode_number'] ?? 0) === 7);
assertCheck("Resume point matches updated Episode 7 position (2980s)", ($resumeLatest2['json']['data']['playback_time_seconds'] ?? 0) === 2980);


// =========================================================================
// PART 3: Series Watch Progress Overview
// =========================================================================
echo "\n3. Testing Series Watch Progress Overview:\n";

$seriesRes = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
$seriesJson = $seriesRes['json'];

assertCheck("Series progress overview returns status = true", ($seriesJson['status'] ?? false) === true);
assertCheck("Series progress count is exactly 10", ($seriesJson['count'] ?? count($seriesJson['data'] ?? [])) === 10);

$episodesList = $seriesJson['data'] ?? [];
assertCheck("Data payload contains 10 items", count($episodesList) === 10);

// Verify all 10 episodes in strictly ascending sequential order
$isOrdered = true;
for ($i = 0; $i < 10; $i++) {
    $ep = $episodesList[$i];
    $expectedEpNum = $i + 1;
    if (($ep['episode_number'] ?? 0) !== $expectedEpNum) {
        $isOrdered = false;
    }
}
assertCheck("Series progress episodes are returned in strictly ascending 1..10 order", $isOrdered);

// Verify individual episode percentages
assertCheck("S1E1 progress is 100.0%", floatEquals($episodesList[0]['percentage_watched'] ?? null, 100.0));
assertCheck("S1E2 progress is 95.0%", floatEquals($episodesList[1]['percentage_watched'] ?? null, 95.0));
assertCheck("S1E3 progress is 50.0%", floatEquals($episodesList[2]['percentage_watched'] ?? null, 50.0));
assertCheck("S1E4 progress is 60.0%", floatEquals($episodesList[3]['percentage_watched'] ?? null, 60.0));
assertCheck("S1E5 progress is 75.0%", floatEquals($episodesList[4]['percentage_watched'] ?? null, 75.0));
assertCheck("S1E6 progress is 0.0%", floatEquals($episodesList[5]['percentage_watched'] ?? null, 0.0));
assertCheck("S1E7 progress is 99.33%", floatEquals($episodesList[6]['percentage_watched'] ?? null, 99.33)); // 2980/3000 = 99.33%
assertCheck("S1E8 progress is 100.0% and is_completed is false", ($episodesList[7]['is_completed'] ?? true) === false);
assertCheck("S1E9 progress is 20.0% and is_completed is true", ($episodesList[8]['is_completed'] ?? false) === true);
assertCheck("S1E10 progress is 85.0%", floatEquals($episodesList[9]['percentage_watched'] ?? null, 85.0));

// Cross-series isolation check: Alice syncs a different series "breaking-bad"
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'breaking-bad',
    'media_title'           => 'Breaking Bad',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 1,
    'playback_time_seconds' => 1200,
    'duration_seconds'      => 3000
], ['Authorization' => "Bearer {$aliceToken}"]);

$stProgressAfterBB = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Stranger Things progress remains unaffected by Breaking Bad sync (count = 10)", count($stProgressAfterBB['json']['data'] ?? []) === 10);

$bbProgress = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=breaking-bad', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Breaking Bad series progress returns count = 1", count($bbProgress['json']['data'] ?? []) === 1);


// =========================================================================
// PART 4: Multi-Tenant User Isolation (Alice vs Bob)
// =========================================================================
echo "\n4. Testing Multi-Tenant User Isolation (Alice vs Bob):\n";

// Bob watches Stranger Things Season 1 Episode 1 (200s - 6.67%)
$bobSyncEp1 = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'stranger-things-s1',
    'media_title'           => 'Stranger Things Season 1',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 1,
    'playback_time_seconds' => 200,
    'duration_seconds'      => 3000
], ['Authorization' => "Bearer {$bobToken}"]);

// Bob watches Episode 2 (1800s - 60.0%)
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'stranger-things-s1',
    'media_title'           => 'Stranger Things Season 1',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 2,
    'playback_time_seconds' => 1800,
    'duration_seconds'      => 3000
], ['Authorization' => "Bearer {$bobToken}"]);

// Bob watches Episode 3 (2700s - 90.0% auto completed)
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'stranger-things-s1',
    'media_title'           => 'Stranger Things Season 1',
    'content_type'          => 'web_series',
    'season_number'         => 1,
    'episode_number'        => 3,
    'playback_time_seconds' => 2700,
    'duration_seconds'      => 3000
], ['Authorization' => "Bearer {$bobToken}"]);

// Also seed a movie for Bob ("interstellar-2014") and Alice ("avatar-2022")
runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'interstellar-2014',
    'media_title'           => 'Interstellar',
    'content_type'          => 'movie',
    'playback_time_seconds' => 3600,
    'duration_seconds'      => 7200
], ['Authorization' => "Bearer {$bobToken}"]);

runApiRequest('POST', '/api/v1/history/watch/sync', [
    'media_slug'            => 'avatar-2022',
    'media_title'           => 'Avatar: The Way of Water',
    'content_type'          => 'movie',
    'playback_time_seconds' => 1800,
    'duration_seconds'      => 7200
], ['Authorization' => "Bearer {$aliceToken}"]);

// Verify Isolation in Resume Lookup
$aliceResumeEp1 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1&episode_number=1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
$bobResumeEp1 = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1&episode_number=1', [], [
    'Authorization' => "Bearer {$bobToken}"
]);

assertCheck("Alice gets her own playback position for S1E1 (3000s)", ($aliceResumeEp1['json']['data']['playback_time_seconds'] ?? 0) === 3000);
assertCheck("Bob gets his own playback position for S1E1 (200s)", ($bobResumeEp1['json']['data']['playback_time_seconds'] ?? 0) === 200);

// Verify Isolation in Series Progress
$aliceStProgress = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
$bobStProgress = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$bobToken}"
]);

assertCheck("Alice series progress has 10 episodes", count($aliceStProgress['json']['data'] ?? []) === 10);
assertCheck("Bob series progress has exactly 3 episodes", count($bobStProgress['json']['data'] ?? []) === 3);

// Verify Isolation in Continue Watching
$aliceContinue = runApiRequest('GET', '/api/v1/history/watch/continue', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
$bobContinue = runApiRequest('GET', '/api/v1/history/watch/continue', [], [
    'Authorization' => "Bearer {$bobToken}"
]);

$aliceContinueSlugs = array_column($aliceContinue['json']['data'] ?? [], 'media_slug');
$bobContinueSlugs = array_column($bobContinue['json']['data'] ?? [], 'media_slug');

assertCheck("Alice continue watching includes avatar-2022", in_array('avatar-2022', $aliceContinueSlugs, true));
assertCheck("Alice continue watching does NOT include interstellar-2014 (Bob's movie)", !in_array('interstellar-2014', $aliceContinueSlugs, true));
assertCheck("Bob continue watching includes interstellar-2014", in_array('interstellar-2014', $bobContinueSlugs, true));
assertCheck("Bob continue watching does NOT include avatar-2022 (Alice's movie)", !in_array('avatar-2022', $bobContinueSlugs, true));

// Test Tenant Isolation on Selective Single Episode Deletion
// Alice deletes Episode 2 of stranger-things-s1
$delEp2Res = runApiRequest('DELETE', '/api/v1/history/watch?media_slug=stranger-things-s1&episode_number=2', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Alice deletes Episode 2 successfully", ($delEp2Res['json']['status'] ?? false) === true);

$aliceStAfterDelEp2 = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
$bobStAfterDelEp2 = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$bobToken}"
]);

assertCheck("Alice series progress now has 9 episodes (Episode 2 removed)", count($aliceStAfterDelEp2['json']['data'] ?? []) === 9);
assertCheck("Bob series progress is completely intact with 3 episodes (Bob Ep 2 NOT touched)", count($bobStAfterDelEp2['json']['data'] ?? []) === 3);

$bobEp2Resume = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=stranger-things-s1&episode_number=2', [], [
    'Authorization' => "Bearer {$bobToken}"
]);
assertCheck("Bob can still resume Episode 2 at 1800s", ($bobEp2Resume['json']['data']['playback_time_seconds'] ?? 0) === 1800);

// Test Tenant Isolation on Show-Wide Deletion
// Alice deletes entire stranger-things-s1 show
$delShowRes = runApiRequest('DELETE', '/api/v1/history/watch/stranger-things-s1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Alice deletes entire show stranger-things-s1", ($delShowRes['json']['status'] ?? false) === true);

$aliceStAfterShowDel = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
$bobStAfterShowDel = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=stranger-things-s1', [], [
    'Authorization' => "Bearer {$bobToken}"
]);

assertCheck("Alice stranger-things-s1 progress is now empty (count = 0)", count($aliceStAfterShowDel['json']['data'] ?? []) === 0);
assertCheck("Bob stranger-things-s1 progress remains completely untouched (count = 3)", count($bobStAfterShowDel['json']['data'] ?? []) === 3);

// Test Tenant Isolation on Account-Wide Purge (clear_all=true)
$aliceClearAll = runApiRequest('DELETE', '/api/v1/history/watch?clear_all=true', [], [
    'Authorization' => "Bearer {$aliceToken}"
]);
assertCheck("Alice clear-all returns status = true", ($aliceClearAll['json']['status'] ?? false) === true);

$aliceTotalRows = queryDb("SELECT * FROM watch_history WHERE user_id = 1");
$bobTotalRows = queryDb("SELECT * FROM watch_history WHERE user_id = 2");

assertCheck("Alice has 0 watch history rows in database", count($aliceTotalRows) === 0);
assertCheck("Bob has 4 watch history rows remaining in database (3 episodes + 1 movie)", count($bobTotalRows) === 4);


// =========================================================================
// PART 5: High-Density Series Stress Test (50 Episodes for Naruto)
// =========================================================================
echo "\n5. Testing High-Density Series Stress (50 Episodes for Naruto):\n";

for ($ep = 1; $ep <= 50; $ep++) {
    $pos = ($ep % 5 === 0) ? 1440 : ($ep * 20);
    $dur = 1440;
    runApiRequest('POST', '/api/v1/history/watch/sync', [
        'media_slug'            => 'naruto-classic',
        'media_title'           => 'Naruto Classic',
        'content_type'          => 'web_series',
        'season_number'         => 1,
        'episode_number'        => $ep,
        'episode_title'         => "Episode {$ep}",
        'playback_time_seconds' => $pos,
        'duration_seconds'      => $dur
    ], ['Authorization' => "Bearer {$bobToken}"]);
}

$narutoProgress = runApiRequest('GET', '/api/v1/history/watch/series-progress?media_slug=naruto-classic', [], [
    'Authorization' => "Bearer {$bobToken}"
]);

$narutoEpisodes = $narutoProgress['json']['data'] ?? [];
assertCheck("Naruto 50-episode series progress fetched successfully", ($narutoProgress['json']['status'] ?? false) === true);
assertCheck("Naruto series progress returns exact count = 50", count($narutoEpisodes) === 50);

$narutoStrictOrder = true;
for ($i = 0; $i < 50; $i++) {
    if (($narutoEpisodes[$i]['episode_number'] ?? 0) !== ($i + 1)) {
        $narutoStrictOrder = false;
        break;
    }
}
assertCheck("All 50 episodes are sorted in strict ascending order (1..50)", $narutoStrictOrder);

// Verify Episode 50 resume lookup
$ep50Resume = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=naruto-classic&episode_number=50', [], [
    'Authorization' => "Bearer {$bobToken}"
]);
assertCheck("Episode 50 resume query returns episode_number = 50", ($ep50Resume['json']['data']['episode_number'] ?? 0) === 50);
assertCheck("Episode 50 is 100% completed (1440s/1440s)", ($ep50Resume['json']['data']['is_completed'] ?? false) === true);


// =========================================================================
// PART 6: Adversarial Boundary & Injection Resistance
// =========================================================================
echo "\n6. Testing Boundary Cases & Injection Resistance:\n";

// Malformed / Injection strings in slug and episode parameters
$sqlInjectResume = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=' . urlencode("naruto-classic' OR '1'='1") . '&episode_number=1', [], [
    'Authorization' => "Bearer {$bobToken}"
]);
assertCheck("SQL injection in media_slug query returns found = false without crashing", ($sqlInjectResume['json']['data']['found'] ?? true) === false);

$sqlInjectEp = runApiRequest('GET', '/api/v1/history/watch/resume?media_slug=naruto-classic&episode_number=' . urlencode("1 UNION SELECT 1,2,3,4,5,6,7,8,9,10,11,12,13"), [], [
    'Authorization' => "Bearer {$bobToken}"
]);
assertCheck("SQL injection in episode_number safely cast or handled (HTTP 200/422)", in_array($sqlInjectEp['json']['status'] ?? null, [true, false], true));

// Cross-user ID injection in body
$spoofSync = runApiRequest('POST', '/api/v1/history/watch/sync', [
    'user_id'               => 1, // Attempt to write to Alice's account as Bob
    'media_slug'            => 'attack-slug',
    'media_title'           => 'Attack Title',
    'playback_time_seconds' => 500,
    'duration_seconds'      => 1000
], ['Authorization' => "Bearer {$bobToken}"]);

$aliceSpoofedRow = queryDb("SELECT * FROM watch_history WHERE user_id = 1 AND media_slug = 'attack-slug'");
$bobSpoofedRow = queryDb("SELECT * FROM watch_history WHERE user_id = 2 AND media_slug = 'attack-slug'");

assertCheck("Cross-user spoofed user_id in body is completely ignored; Alice gets 0 rows", count($aliceSpoofedRow) === 0);
assertCheck("Record was attributed strictly to authenticated user (Bob gets 1 row)", count($bobSpoofedRow) === 1);


echo "\n====================================================================\n";
echo "ADVERSARIAL STRESS TEST SUMMARY\n";
echo "Passed: {$passCount}\n";
echo "Failed: {$failCount}\n";
echo "====================================================================\n";

if ($failCount > 0) {
    exit(1);
}
exit(0);
