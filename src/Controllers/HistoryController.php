<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Config\Database;
use PDO;

class HistoryController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // ========================================================
    // 1. WATCH HISTORY & RESUME PLAYBACK (CONTINUE WATCHING)
    // ========================================================

    /**
     * POST /api/v1/history/watch/sync
     * Synchronize playback progress across devices
     */
    public function syncWatchProgress(Request $request): void {
        $userId = $request->getUserId();
        $body = $request->getBody();

        $slug = trim((string)($body['media_slug'] ?? ($body['slug'] ?? '')));
        $title = trim((string)($body['media_title'] ?? ($body['title'] ?? '')));
        $poster = !empty($body['media_poster']) ? trim((string)$body['media_poster']) : (!empty($body['poster']) ? trim((string)$body['poster']) : null);
        $type = in_array($body['content_type'] ?? '', ['movie', 'web_series']) ? $body['content_type'] : 'movie';
        $season = max(1, (int)($body['season_number'] ?? ($body['season'] ?? 1)));
        $episode = max(1, (int)($body['episode_number'] ?? ($body['episode'] ?? 1)));
        $epTitle = !empty($body['episode_title']) ? trim((string)$body['episode_title']) : null;
        $playbackPos = max(0, (int)($body['playback_time_seconds'] ?? ($body['playback_time'] ?? 0)));
        $duration = max(0, (int)($body['duration_seconds'] ?? ($body['duration'] ?? 0)));

        if (empty($slug) || empty($title)) {
            Response::error('media_slug and media_title are required', 422);
        }

        $percentage = ($duration > 0) ? min(100.0, max(0.0, round(($playbackPos / $duration) * 100, 2))) : 0.0;

        // Check for explicit boolean override or auto >= 90% threshold
        if (array_key_exists('is_completed', $body) && $body['is_completed'] !== null) {
            $isCompleted = filter_var($body['is_completed'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        } else {
            $isCompleted = ($percentage >= 90.0) ? 1 : 0;
        }

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare("
                INSERT INTO watch_history (
                    user_id, media_slug, media_title, media_poster, content_type,
                    season_number, episode_number, episode_title,
                    playback_time_seconds, duration_seconds, percentage_watched,
                    is_completed, updated_at
                ) VALUES (
                    :uid, :slug, :title, :poster, :type,
                    :season, :ep, :ep_title,
                    :pos, :dur, :perc,
                    :comp, datetime('now')
                ) ON CONFLICT(user_id, media_slug, episode_number) DO UPDATE SET
                    media_title = excluded.media_title,
                    media_poster = excluded.media_poster,
                    content_type = excluded.content_type,
                    season_number = excluded.season_number,
                    episode_title = excluded.episode_title,
                    playback_time_seconds = excluded.playback_time_seconds,
                    duration_seconds = excluded.duration_seconds,
                    percentage_watched = excluded.percentage_watched,
                    is_completed = excluded.is_completed,
                    updated_at = datetime('now')
            ");
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO watch_history (
                    user_id, media_slug, media_title, media_poster, content_type,
                    season_number, episode_number, episode_title,
                    playback_time_seconds, duration_seconds, percentage_watched,
                    is_completed, updated_at
                ) VALUES (
                    :uid, :slug, :title, :poster, :type,
                    :season, :ep, :ep_title,
                    :pos, :dur, :perc,
                    :comp, NOW()
                ) ON DUPLICATE KEY UPDATE
                    media_title = VALUES(media_title),
                    media_poster = VALUES(media_poster),
                    content_type = VALUES(content_type),
                    season_number = VALUES(season_number),
                    episode_title = VALUES(episode_title),
                    playback_time_seconds = VALUES(playback_time_seconds),
                    duration_seconds = VALUES(duration_seconds),
                    percentage_watched = VALUES(percentage_watched),
                    is_completed = VALUES(is_completed),
                    updated_at = NOW()
            ");
        }

        $stmt->execute([
            'uid'      => $userId,
            'slug'     => $slug,
            'title'    => $title,
            'poster'   => $poster,
            'type'     => $type,
            'season'   => $season,
            'ep'       => $episode,
            'ep_title' => $epTitle,
            'pos'      => $playbackPos,
            'dur'      => $duration,
            'perc'     => $percentage,
            'comp'     => $isCompleted
        ]);

        Response::success([
            'media_slug'            => $slug,
            'media_title'           => $title,
            'content_type'          => $type,
            'season_number'         => $season,
            'episode_number'        => $episode,
            'episode_title'         => $epTitle,
            'playback_time_seconds' => $playbackPos,
            'duration_seconds'      => $duration,
            'percentage_watched'    => $percentage,
            'is_completed'          => (bool)$isCompleted,
            'updated_at'            => date('Y-m-d H:i:s')
        ], 'Watch progress synchronized');
    }

    /**
     * GET /api/v1/history/watch/resume
     * Retrieve resume position for a movie or series episode
     */
    public function getResumePoint(Request $request): void {
        $userId = $request->getUserId();
        $slug = trim((string)($request->getQuery('media_slug') ?? ($request->getQuery('slug') ?? ($request->get('media_slug') ?? ($request->get('slug') ?? '')))));
        $epParam = $request->getQuery('episode_number') ?? ($request->getQuery('episode') ?? ($request->get('episode_number') ?? ($request->get('episode') ?? null)));

        if (empty($slug)) {
            Response::error('media_slug is required', 422);
        }

        if ($epParam !== null && $epParam !== '') {
            $episode = max(1, (int)$epParam);
            $stmt = $this->db->prepare("
                SELECT * FROM watch_history 
                WHERE user_id = :uid AND media_slug = :slug AND episode_number = :ep 
                LIMIT 1
            ");
            $stmt->execute(['uid' => $userId, 'slug' => $slug, 'ep' => $episode]);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM watch_history 
                WHERE user_id = :uid AND media_slug = :slug 
                ORDER BY updated_at DESC, id DESC 
                LIMIT 1
            ");
            $stmt->execute(['uid' => $userId, 'slug' => $slug]);
        }

        $row = $stmt->fetch();

        if ($row) {
            Response::success([
                'found'                 => true,
                'id'                    => (int)$row['id'],
                'media_slug'            => $row['media_slug'],
                'media_title'           => $row['media_title'],
                'media_poster'          => $row['media_poster'] ?? null,
                'content_type'          => $row['content_type'] ?? 'movie',
                'season_number'         => (int)($row['season_number'] ?? 1),
                'episode_number'        => (int)($row['episode_number'] ?? 1),
                'episode_title'         => $row['episode_title'] ?? null,
                'playback_time_seconds' => (int)$row['playback_time_seconds'],
                'duration_seconds'      => (int)$row['duration_seconds'],
                'percentage_watched'    => (float)$row['percentage_watched'],
                'is_completed'          => (bool)$row['is_completed'],
                'last_watched_at'       => $row['updated_at'],
                'updated_at'            => $row['updated_at']
            ], 'Resume point retrieved');
        } else {
            Response::success([
                'found'                 => false,
                'media_slug'            => $slug,
                'playback_time_seconds' => 0,
                'duration_seconds'      => 0,
                'percentage_watched'    => 0.0,
                'is_completed'          => false
            ], 'No watch history found');
        }
    }

    /**
     * Alias for getResumePoint
     */
    public function getResumePlayback(Request $request): void {
        $this->getResumePoint($request);
    }

    /**
     * GET /api/v1/history/watch/series-progress
     * Retrieve playback progress across all episodes of a series
     */
    public function getSeriesProgress(Request $request): void {
        $userId = $request->getUserId();
        $slug = trim((string)($request->getQuery('media_slug') ?? ($request->getQuery('slug') ?? ($request->get('media_slug') ?? ($request->get('slug') ?? '')))));

        if (empty($slug)) {
            Response::error('media_slug is required', 422);
        }

        $stmt = $this->db->prepare("
            SELECT id, media_slug, media_title, season_number, episode_number, episode_title,
                   playback_time_seconds, duration_seconds, percentage_watched, is_completed, updated_at
            FROM watch_history
            WHERE user_id = :uid AND media_slug = :slug
            ORDER BY season_number ASC, episode_number ASC
        ");
        $stmt->execute(['uid' => $userId, 'slug' => $slug]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = array_map(function($row) {
            return [
                'season_number'         => (int)($row['season_number'] ?? 1),
                'episode_number'        => (int)($row['episode_number'] ?? 1),
                'episode_title'         => $row['episode_title'] ?? null,
                'playback_time_seconds' => (int)$row['playback_time_seconds'],
                'duration_seconds'      => (int)$row['duration_seconds'],
                'percentage_watched'    => (float)$row['percentage_watched'],
                'is_completed'          => (bool)$row['is_completed'],
                'updated_at'            => $row['updated_at']
            ];
        }, $rows);

        Response::success($data, 'Series progress retrieved');
    }

    /**
     * GET /api/v1/history/watch
     * Retrieve active continue watching items
     */
    public function getContinueWatching(Request $request): void {
        $userId = $request->getUserId();
        $page = max(1, (int)$request->getQuery('page', 1));
        $limit = min(50, max(1, (int)$request->getQuery('limit', 20)));
        $offset = ($page - 1) * $limit;

        $stmt = $this->db->prepare("
            SELECT * FROM watch_history 
            WHERE user_id = :uid AND is_completed = 0 AND percentage_watched > 1
            ORDER BY updated_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = array_map(function($row) {
            return [
                'id'                    => (int)$row['id'],
                'media_slug'            => $row['media_slug'],
                'media_title'           => $row['media_title'],
                'media_poster'          => $row['media_poster'] ?? null,
                'content_type'          => $row['content_type'] ?? 'movie',
                'season_number'         => (int)($row['season_number'] ?? 1),
                'episode_number'        => (int)($row['episode_number'] ?? 1),
                'episode_title'         => $row['episode_title'] ?? null,
                'playback_time_seconds' => (int)$row['playback_time_seconds'],
                'duration_seconds'      => (int)$row['duration_seconds'],
                'percentage_watched'    => (float)$row['percentage_watched'],
                'is_completed'          => (bool)$row['is_completed'],
                'updated_at'            => $row['updated_at']
            ];
        }, $items);

        Response::success($formatted, 'Continue watching list fetched');
    }

    /**
     * DELETE /api/v1/history/watch
     * Remove item(s) or all items from watch history
     */
    public function deleteWatchItem(Request $request): void {
        $userId = $request->getUserId();
        $body = $request->getBody();

        $slug = trim((string)($request->get('media_slug') ?? ($request->get('slug') ?? ($request->getQuery('media_slug') ?? ($request->getQuery('slug') ?? ($body['media_slug'] ?? ($body['slug'] ?? '')))))));
        $rawEp = $request->get('episode_number') ?? ($request->get('episode') ?? ($request->getQuery('episode_number') ?? ($request->getQuery('episode') ?? ($body['episode_number'] ?? ($body['episode'] ?? null)))));
        $episodeNumber = ($rawEp !== null && $rawEp !== '') ? (int)$rawEp : null;

        $clearAll = $request->get('clear_all') ?? ($request->get('all') ?? ($request->getQuery('clear_all') ?? ($request->getQuery('all') ?? ($body['clear_all'] ?? ($body['all'] ?? false)))));
        $isClearAll = in_array(strtolower((string)$clearAll), ['1', 'true', 'yes'], true);

        // Case 1: Specific episode deletion for a series
        if (!empty($slug) && $episodeNumber !== null) {
            $stmt = $this->db->prepare("
                DELETE FROM watch_history 
                WHERE user_id = :uid AND media_slug = :slug AND episode_number = :ep
            ");
            $stmt->execute([
                'uid'  => $userId,
                'slug' => $slug,
                'ep'   => $episodeNumber
            ]);
            $deleted = $stmt->rowCount();
            Response::success(['deleted_count' => $deleted], 'Episode removed from watch history');
        }

        // Case 2: Entire media deletion (all episodes of series or movie)
        if (!empty($slug) && $episodeNumber === null) {
            $stmt = $this->db->prepare("
                DELETE FROM watch_history 
                WHERE user_id = :uid AND media_slug = :slug
            ");
            $stmt->execute([
                'uid'  => $userId,
                'slug' => $slug
            ]);
            $deleted = $stmt->rowCount();
            Response::success(['deleted_count' => $deleted], 'Media watch history deleted');
        }

        // Case 3: Clear all watch history for user
        if (empty($slug) && $isClearAll) {
            $stmt = $this->db->prepare("DELETE FROM watch_history WHERE user_id = :uid");
            $stmt->execute(['uid' => $userId]);
            $deleted = $stmt->rowCount();
            Response::success(['deleted_count' => $deleted], 'All watch history cleared');
        }

        // Case 4: Missing media_slug and clear_all not set
        Response::error('media_slug is required unless all=true is specified', 422);
    }

    // ========================================================
    // 2. SEARCH HISTORY
    // ========================================================
    public function logSearch(Request $request): void {
        $userId = $request->getUserId();
        $body = $request->getBody();
        $query = trim((string)($body['query'] ?? ''));
        $clickedSlug = !empty($body['clicked_media_slug']) ? trim((string)$body['clicked_media_slug']) : null;

        if (!empty($query)) {
            $stmt = $this->db->prepare("
                INSERT INTO search_history (user_id, query, clicked_media_slug)
                VALUES (:uid, :q, :slug)
            ");
            $stmt->execute(['uid' => $userId, 'q' => $query, 'slug' => $clickedSlug]);
        }

        Response::success([], 'Search logged');
    }

    public function getRecentSearches(Request $request): void {
        $userId = $request->getUserId();
        $stmt = $this->db->prepare("
            SELECT query, MAX(searched_at) as last_searched 
            FROM search_history 
            WHERE user_id = :uid 
            GROUP BY query 
            ORDER BY last_searched DESC 
            LIMIT 15
        ");
        $stmt->execute(['uid' => $userId]);
        $searches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success($searches, 'Recent searches fetched');
    }

    // ========================================================
    // 3. DOWNLOAD HISTORY
    // ========================================================
    public function logDownload(Request $request): void {
        $userId = $request->getUserId();
        $body = $request->getBody();

        $slug = trim((string)($body['media_slug'] ?? ''));
        $title = trim((string)($body['media_title'] ?? ''));
        $quality = trim((string)($body['quality'] ?? 'HD'));
        $size = trim((string)($body['file_size'] ?? ''));
        $server = trim((string)($body['download_server'] ?? ''));
        $ep = !empty($body['episode_number']) ? (int)$body['episode_number'] : null;

        if (!empty($slug) && !empty($title)) {
            $stmt = $this->db->prepare("
                INSERT INTO download_history (user_id, media_slug, media_title, episode_number, quality_downloaded, file_size, download_server)
                VALUES (:uid, :slug, :title, :ep, :quality, :size, :srv)
            ");
            $stmt->execute([
                'uid'     => $userId,
                'slug'    => $slug,
                'title'   => $title,
                'ep'      => $ep,
                'quality' => $quality,
                'size'    => $size,
                'srv'     => $server
            ]);
        }

        Response::success([], 'Download logged successfully');
    }

    public function getDownloadHistory(Request $request): void {
        $userId = $request->getUserId();
        $stmt = $this->db->prepare("
            SELECT * FROM download_history 
            WHERE user_id = :uid 
            ORDER BY downloaded_at DESC 
            LIMIT 50
        ");
        $stmt->execute(['uid' => $userId]);
        $downloads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        Response::success($downloads, 'Download history fetched');
    }
}
