<?php
use App\Core\Router;
use App\Core\Request;
use App\Middleware\CorsMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\AuthMiddleware;
use App\Controllers\AuthController;
use App\Controllers\UserController;
use App\Controllers\HeartbeatController;
use App\Controllers\HistoryController;
use App\Controllers\MediaController;
use App\Controllers\StreamController;
use App\Controllers\DownloadController;
use App\Controllers\NotificationController;
use App\Controllers\ConfigController;

$router = new Router();

// Global Middlewares (CORS & Anti-DDoS Rate Limiting)
$router->addGlobalMiddleware(CorsMiddleware::class);
$router->addGlobalMiddleware(RateLimitMiddleware::class);

// ========================================================
// 1. AUTHENTICATION ROUTES (/api/v1/auth/*)
// ========================================================
$router->post('/api/v1/auth/register',         [AuthController::class, 'register']);
$router->post('/api/v1/auth/login',            [AuthController::class, 'login']);
$router->post('/api/v1/auth/google',           [AuthController::class, 'googleLogin']);
$router->get('/api/v1/auth/google/redirect',   [AuthController::class, 'googleRedirect']);
$router->get('/api/v1/auth/google/callback',   [AuthController::class, 'googleCallback']);
$router->post('/api/v1/auth/refresh',          [AuthController::class, 'refresh']);
$router->post('/api/v1/auth/logout',           [AuthController::class, 'logout']);

// TV Leanback & Cross-Device Pairing (/api/v1/auth/tv/*)
$router->post('/api/v1/auth/tv/code',      [AuthController::class, 'generateTvPairingCode']);
$router->post('/api/v1/auth/tv/poll',      [AuthController::class, 'pollTvPairingStatus']);
$router->post('/api/v1/auth/tv/verify',    [AuthController::class, 'verifyTvPairingCode'], [AuthMiddleware::class]);
$router->post('/api/v1/auth/tv/authorize', [AuthController::class, 'verifyTvPairingCode'], [AuthMiddleware::class]);

// ========================================================
// 2. LIVE PRESENCE & HEARTBEAT (/api/v1/presence/*)
// ========================================================
$router->post('/api/v1/presence/heartbeat', [HeartbeatController::class, 'ping']);
$router->get('/api/v1/presence/stats',      [HeartbeatController::class, 'getActiveUsersStats']);

// ========================================================
// 3. USER PROFILE, PREFERENCES & DEVICES (/api/v1/user/*) [PROTECTED]
// ========================================================
$router->get('/api/v1/user/profile',          [UserController::class, 'getProfile'], [AuthMiddleware::class]);
$router->post('/api/v1/user/profile/update',  [UserController::class, 'updateProfile'], [AuthMiddleware::class]);
$router->get('/api/v1/user/favorites',        [UserController::class, 'getFavorites'], [AuthMiddleware::class]);
$router->post('/api/v1/user/favorites/toggle', [UserController::class, 'toggleFavorite'], [AuthMiddleware::class]);
$router->post('/api/v1/user/genre/interact',  [UserController::class, 'logGenreInteraction'], [AuthMiddleware::class]);
$router->get('/api/v1/user/genre/top',        [UserController::class, 'getTopPreferredGenres'], [AuthMiddleware::class]);

// Multi-Device Session Management (/api/v1/user/devices/* & aliases) [PROTECTED]
$router->get('/api/v1/user/devices',                [UserController::class, 'getDevices'], [AuthMiddleware::class]);
$router->post('/api/v1/user/devices/revoke-others', [UserController::class, 'revokeOtherDevices'], [AuthMiddleware::class]);
$router->delete('/api/v1/user/devices/{id}',        [UserController::class, 'revokeDevice'], [AuthMiddleware::class]);
$router->delete('/api/v1/user/devices',             [UserController::class, 'revokeAllDevices'], [AuthMiddleware::class]);

// Account & Personal Data Deletion (Google Play Data Safety Policy) [PROTECTED]
$router->delete('/api/v1/user/account',             [UserController::class, 'deleteAccount'], [AuthMiddleware::class]);
$router->post('/api/v1/user/delete-account',        [UserController::class, 'deleteAccount'], [AuthMiddleware::class]);
$router->post('/api/v1/user/delete-data',           [UserController::class, 'deleteAccount'], [AuthMiddleware::class]);

// Device Session Aliases (/api/v1/auth/devices/*) [PROTECTED]
$router->get('/api/v1/auth/devices',                [UserController::class, 'getDevices'], [AuthMiddleware::class]);
$router->post('/api/v1/auth/devices/revoke-others', [UserController::class, 'revokeOtherDevices'], [AuthMiddleware::class]);
$router->delete('/api/v1/auth/devices/{id}',        [UserController::class, 'revokeDevice'], [AuthMiddleware::class]);
$router->delete('/api/v1/auth/devices',             [UserController::class, 'revokeAllDevices'], [AuthMiddleware::class]);

// ========================================================
// 4. WATCH HISTORY & RESUME PLAYBACK (/api/v1/history/*) [PROTECTED]
// ========================================================
$router->post('/api/v1/history/watch/sync',            [HistoryController::class, 'syncWatchProgress'], [AuthMiddleware::class]);
$router->get('/api/v1/history/watch/resume',           [HistoryController::class, 'getResumePoint'], [AuthMiddleware::class]);
$router->get('/api/v1/history/watch/series-progress',  [HistoryController::class, 'getSeriesProgress'], [AuthMiddleware::class]);
$router->get('/api/v1/history/watch',                  [HistoryController::class, 'getContinueWatching'], [AuthMiddleware::class]);
$router->get('/api/v1/history/watch/continue',         [HistoryController::class, 'getContinueWatching'], [AuthMiddleware::class]);
$router->delete('/api/v1/history/watch/{slug}',        [HistoryController::class, 'deleteWatchItem'], [AuthMiddleware::class]);
$router->delete('/api/v1/history/watch',               [HistoryController::class, 'deleteWatchItem'], [AuthMiddleware::class]);
$router->post('/api/v1/history/search/log',            [HistoryController::class, 'logSearch'], [AuthMiddleware::class]);
$router->get('/api/v1/history/search',                 [HistoryController::class, 'getRecentSearches'], [AuthMiddleware::class]);
$router->post('/api/v1/history/download/log',          [HistoryController::class, 'logDownload'], [AuthMiddleware::class]);
$router->get('/api/v1/history/downloads',              [HistoryController::class, 'getDownloadHistory'], [AuthMiddleware::class]);

// ========================================================
// 5. MEDIA & CATALOG ENGINE (/api/v1/media/*) [PROTECTED - OPTION B]
// ========================================================
$router->get('/api/v1/media/home',         [MediaController::class, 'getHome'], [AuthMiddleware::class]);
$router->get('/api/v1/media/k-drama',      [MediaController::class, 'getKDramaFeed'], [AuthMiddleware::class]);
$router->get('/api/v1/media/vegamovies',   [MediaController::class, 'getVegaMoviesFeed'], [AuthMiddleware::class]);
$router->get('/api/v1/media/vega',         [MediaController::class, 'getVegaMoviesFeed'], [AuthMiddleware::class]);
$router->get('/api/v1/media/search',       [MediaController::class, 'search'], [AuthMiddleware::class]);
$router->get('/api/v1/media/categories',   [MediaController::class, 'getCategories'], [AuthMiddleware::class]);
$router->get('/api/v1/media/category',     [MediaController::class, 'getCategoryFeed'], [AuthMiddleware::class]);
$router->get('/api/v1/media/details',      [MediaController::class, 'getDetails'], [AuthMiddleware::class]);
$router->get('/api/v1/media/stream',       [StreamController::class, 'getStream'], [AuthMiddleware::class]);
$router->get('/api/v1/media/proxy_stream', [StreamController::class, 'proxyStream'], [AuthMiddleware::class]);
$router->get('/api/v1/media/proxy-stream', [StreamController::class, 'proxyStream'], [AuthMiddleware::class]);
$router->get('/api/proxy_stream',          [StreamController::class, 'proxyStream'], [AuthMiddleware::class]);
$router->get('/api/v1/media/play',         [StreamController::class, 'proxyStream'], [AuthMiddleware::class]);
$router->get('/api/v1/media/source',       [StreamController::class, 'proxyStream'], [AuthMiddleware::class]);
$router->get('/api/v1/media/download',     [DownloadController::class, 'resolveDownload'], [AuthMiddleware::class]);

// ========================================================
// 6. PUSH NOTIFICATIONS & DYNAMIC CONFIG (/api/v1/system/*)
// ========================================================
$router->post('/api/v1/system/push-token',  [NotificationController::class, 'registerPushToken']);
$router->post('/api/v1/system/broadcast',   [NotificationController::class, 'sendBroadcast']);
$router->get('/api/v1/system/config',       [ConfigController::class, 'getAppConfig']);
$router->post('/api/v1/system/config',      [ConfigController::class, 'updateConfigKey']);
$router->get('/api/v1/system/check-update', [ConfigController::class, 'checkUpdate']);
$router->get('/api/v1/app/update',          [ConfigController::class, 'checkUpdate']);
$router->get('/api/v1/system/debug-scrape', [ConfigController::class, 'debugScrape']);

return $router;
