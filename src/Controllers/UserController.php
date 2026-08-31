<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Config\Database;
use PDO;

class UserController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function getProfile(Request $request): void {
        $user = $request->getAuthUser();
        Response::success($user, 'User profile fetched');
    }

    public function updateProfile(Request $request): void {
        $userId = $request->getUserId();
        $body = $request->getBody();

        $name = trim($body['name'] ?? '');
        $avatarUrl = trim($body['avatar_url'] ?? '');

        $updates = [];
        $params = ['id' => $userId];

        if (!empty($name)) {
            $updates[] = "name = :name";
            $params['name'] = $name;
        }
        if (!empty($avatarUrl)) {
            $updates[] = "avatar_url = :avatar";
            $params['avatar'] = $avatarUrl;
        }

        if (!empty($updates)) {
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }

        $stmt = $this->db->prepare("SELECT id, uuid, name, email, avatar_url, auth_provider FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $updatedUser = $stmt->fetch();

        Response::success($updatedUser, 'Profile updated successfully');
    }

    // ========================================================
    // WATCHLIST / FAVORITES
    // ========================================================
    public function toggleFavorite(Request $request): void {
        $userId = $request->getUserId();
        $body = $request->getBody();

        $slug = trim($body['media_slug'] ?? '');
        $title = trim($body['media_title'] ?? '');
        $poster = trim($body['media_poster'] ?? '');
        $type = in_array($body['content_type'] ?? '', ['movie', 'web_series']) ? $body['content_type'] : 'movie';

        if (empty($slug)) {
            Response::error('media_slug is required', 422);
        }

        // Check if exists
        $stmt = $this->db->prepare("SELECT id FROM user_favorites WHERE user_id = :uid AND media_slug = :slug");
        $stmt->execute(['uid' => $userId, 'slug' => $slug]);
        $existing = $stmt->fetch();

        if ($existing) {
            $del = $this->db->prepare("DELETE FROM user_favorites WHERE id = :id");
            $del->execute(['id' => $existing['id']]);
            Response::success(['is_favorited' => false], 'Removed from watchlist');
        } else {
            $ins = $this->db->prepare("
                INSERT INTO user_favorites (user_id, media_slug, media_title, media_poster, content_type)
                VALUES (:uid, :slug, :title, :poster, :type)
            ");
            $ins->execute(['uid' => $userId, 'slug' => $slug, 'title' => $title, 'poster' => $poster, 'type' => $type]);
            Response::success(['is_favorited' => true], 'Added to watchlist', 201);
        }
    }

    public function getFavorites(Request $request): void {
        $userId = $request->getUserId();
        $stmt = $this->db->prepare("
            SELECT * FROM user_favorites 
            WHERE user_id = :uid 
            ORDER BY created_at DESC
        ");
        $stmt->execute(['uid' => $userId]);
        $favs = $stmt->fetchAll();

        Response::success($favs, 'Watchlist fetched');
    }

    // ========================================================
    // GENRE PREFERENCES & RECOMMENDATIONS
    // ========================================================
    public function logGenreInteraction(Request $request): void {
        $userId = $request->getUserId();
        $body = $request->getBody();
        $genreSlug = trim($body['genre_slug'] ?? '');

        if (!empty($genreSlug)) {
            $stmt = $this->db->prepare("
                INSERT INTO user_genre_preferences (user_id, genre_slug, interaction_score)
                VALUES (:uid, :genre, 1)
                ON DUPLICATE KEY UPDATE
                    interaction_score = interaction_score + 1,
                    last_interacted_at = NOW()
            ");
            $stmt->execute(['uid' => $userId, 'genre' => $genreSlug]);
        }

        Response::success([], 'Genre preference updated');
    }

    public function getTopPreferredGenres(Request $request): void {
        $userId = $request->getUserId();
        $stmt = $this->db->prepare("
            SELECT genre_slug, interaction_score 
            FROM user_genre_preferences 
            WHERE user_id = :uid 
            ORDER BY interaction_score DESC, last_interacted_at DESC 
            LIMIT 5
        ");
        $stmt->execute(['uid' => $userId]);
        $genres = $stmt->fetchAll();

        Response::success($genres, 'User top genres fetched');
    }

    // ========================================================
    // DEVICE SESSION MANAGEMENT
    // ========================================================

    /**
     * List all active device sessions for authenticated user
     * GET /api/v1/user/devices
     */
    public function getDevices(Request $request): void {
        $userId = $request->getUserId();
        if (!$userId) {
            Response::unauthorized('Authentication required');
        }

        $authUser = $request->getAuthUser() ?? [];
        $currentSessionId = isset($authUser['session_id']) ? (int)$authUser['session_id'] : null;
        $currentDeviceId = $authUser['device_id'] ?? ($request->getHeader('X-Device-ID') ?? $request->getQuery('device_id'));

        $stmt = $this->db->prepare("
            SELECT id, device_id, device_name, os_type, app_version, ip_address, last_active_at, expires_at, created_at
            FROM user_sessions
            WHERE user_id = :uid AND expires_at > NOW()
            ORDER BY last_active_at DESC
        ");
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll();

        $devices = [];
        $hasCurrent = false;
        foreach ($rows as $row) {
            $isCurrent = false;
            if ($currentSessionId !== null && $currentSessionId > 0) {
                $isCurrent = ((int)$row['id'] === $currentSessionId);
            } elseif (!empty($currentDeviceId)) {
                $isCurrent = ($row['device_id'] === $currentDeviceId);
            }

            if ($isCurrent) {
                $hasCurrent = true;
            }

            $devices[] = [
                'id'             => (int)$row['id'],
                'device_id'      => (string)$row['device_id'],
                'device_name'    => (string)($row['device_name'] ?: 'Unknown Device'),
                'os_type'        => (string)($row['os_type'] ?: 'android'),
                'app_version'    => (string)($row['app_version'] ?: '1.0.0'),
                'ip_address'     => (string)($row['ip_address'] ?: 'Unknown'),
                'last_active_at' => (string)$row['last_active_at'],
                'is_current'     => (bool)$isCurrent
            ];
        }

        // Fallback: if no session matched is_current, mark first (most recent) as current fallback
        if (!$hasCurrent && count($devices) > 0 && empty($currentSessionId) && empty($currentDeviceId)) {
            $devices[0]['is_current'] = true;
        }

        Response::success($devices, 'Active devices retrieved successfully');
    }

    /**
     * Alias for getDevices
     */
    public function getSessions(Request $request): void {
        $this->getDevices($request);
    }

    /**
     * Revoke specific device session
     * DELETE /api/v1/user/devices/{id}
     */
    public function revokeDevice(Request $request): void {
        $userId = $request->getUserId();
        if (!$userId) {
            Response::unauthorized('Authentication required');
        }

        $sessionId = $request->get('id') ?? $request->getQuery('id') ?? $_GET['id'] ?? null;

        if (empty($sessionId) || !is_numeric($sessionId)) {
            Response::error('Valid session ID is required', 422);
        }

        $stmt = $this->db->prepare("SELECT id FROM user_sessions WHERE id = :id AND user_id = :uid LIMIT 1");
        $stmt->execute(['id' => (int)$sessionId, 'uid' => $userId]);
        $session = $stmt->fetch();

        if (!$session) {
            Response::error('Device session not found or already revoked', 404);
        }

        $del = $this->db->prepare("DELETE FROM user_sessions WHERE id = :id AND user_id = :uid");
        $del->execute(['id' => (int)$sessionId, 'uid' => $userId]);

        Response::success([], 'Session revoked successfully');
    }

    /**
     * Alias for revokeDevice
     */
    public function revokeSession(Request $request): void {
        $this->revokeDevice($request);
    }

    /**
     * Revoke all other sessions except current
     * POST /api/v1/user/devices/revoke-others
     */
    public function revokeOtherDevices(Request $request): void {
        $userId = $request->getUserId();
        if (!$userId) {
            Response::unauthorized('Authentication required');
        }

        $authUser = $request->getAuthUser() ?? [];
        $body = $request->getBody();

        $currentSessionId = isset($authUser['session_id']) && $authUser['session_id'] > 0
            ? (int)$authUser['session_id']
            : (isset($body['session_id']) && is_numeric($body['session_id']) ? (int)$body['session_id'] : null);

        $currentDeviceId = $authUser['device_id'] ?? ($body['device_id'] ?? ($request->getHeader('X-Device-ID') ?? null));

        if ($currentSessionId !== null && $currentSessionId > 0) {
            $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = :uid AND id != :current_id");
            $stmt->execute(['uid' => $userId, 'current_id' => $currentSessionId]);
        } elseif (!empty($currentDeviceId)) {
            $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = :uid AND device_id != :current_did");
            $stmt->execute(['uid' => $userId, 'current_did' => $currentDeviceId]);
        } else {
            // Keep the most recent session
            $stmt = $this->db->prepare("
                DELETE FROM user_sessions 
                WHERE user_id = :uid 
                  AND id NOT IN (
                      SELECT id FROM (
                          SELECT id FROM user_sessions WHERE user_id = :uid2 ORDER BY last_active_at DESC LIMIT 1
                      ) tmp
                  )
            ");
            $stmt->execute(['uid' => $userId, 'uid2' => $userId]);
        }

        $revokedCount = $stmt->rowCount();
        Response::success(['revoked_count' => (int)$revokedCount], 'All other sessions revoked successfully');
    }

    /**
     * Alias for revokeOtherDevices
     */
    public function revokeOtherSessions(Request $request): void {
        $this->revokeOtherDevices($request);
    }

    /**
     * Revoke all sessions for current user (total logout)
     * DELETE /api/v1/user/devices
     */
    public function revokeAllDevices(Request $request): void {
        $userId = $request->getUserId();
        if (!$userId) {
            Response::unauthorized('Authentication required');
        }

        $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);

        $revokedCount = $stmt->rowCount();
        Response::success(['revoked_count' => (int)$revokedCount], 'All sessions revoked successfully');
    }

    /**
     * Alias for revokeAllDevices
     */
    public function revokeAllSessions(Request $request): void {
        $this->revokeAllDevices($request);
    }

    /**
     * Permanent Account & User Data Deletion (Google Play Data Safety Mandate)
     * DELETE /api/v1/user/account
     * POST /api/v1/user/delete-account
     */
    public function deleteAccount(Request $request): void {
        $userId = $request->getUserId();
        if (!$userId) {
            Response::unauthorized('Authentication required');
        }

        // 1. Delete all user sessions
        $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);

        // 2. Delete watch progress
        $stmt = $this->db->prepare("DELETE FROM watch_progress WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);

        // 3. Delete favorites / watchlist
        $stmt = $this->db->prepare("DELETE FROM user_favorites WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);

        // 4. Delete TV pairing codes
        $stmt = $this->db->prepare("DELETE FROM tv_pairing_codes WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);

        // 5. Delete presence
        $stmt = $this->db->prepare("DELETE FROM user_presence WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);

        // 6. Delete user device push records
        $stmt = $this->db->prepare("DELETE FROM user_device WHERE user_id = :uid");
        $stmt->execute(['uid' => $userId]);

        // 7. Delete primary user record
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = :uid");
        $stmt->execute(['uid' => $userId]);

        Response::success([
            'deleted' => true,
            'user_id' => $userId,
            'message' => 'Your MaxPlex user account and all associated personal data have been permanently deleted.'
        ], 'Account and all associated user data deleted permanently');
    }
}
