<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Config\Database;
use PDO;

class HeartbeatController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function ping(Request $request): void {
        $body = $request->getBody();
        $deviceId = trim($body['device_id'] ?? '');
        $screen = trim($body['current_screen'] ?? 'home');
        $mediaSlug = !empty($body['current_media_slug']) ? trim($body['current_media_slug']) : null;
        $mediaTitle = !empty($body['current_media_title']) ? trim($body['current_media_title']) : null;
        $playbackPos = (int)($body['current_playback_pos'] ?? 0);
        $sessionId = trim($body['session_id'] ?? '');

        if (empty($deviceId)) {
            Response::error('Missing device_id in heartbeat', 422);
        }

        $userId = $request->getUserId(); // Extracted from AuthMiddleware if token provided

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare("
                INSERT INTO app_heartbeats (user_id, device_id, session_id, current_screen, current_media_slug, current_media_title, current_playback_pos, ip_address, last_ping_at)
                VALUES (:uid, :did, :sid, :screen, :slug, :title, :pos, :ip, datetime('now'))
                ON CONFLICT(device_id) DO UPDATE SET
                    user_id = excluded.user_id,
                    session_id = excluded.session_id,
                    current_screen = excluded.current_screen,
                    current_media_slug = excluded.current_media_slug,
                    current_media_title = excluded.current_media_title,
                    current_playback_pos = excluded.current_playback_pos,
                    ip_address = excluded.ip_address,
                    last_ping_at = datetime('now')
            ");
            $stmt->execute([
                'uid'    => $userId,
                'did'    => $deviceId,
                'sid'    => $sessionId,
                'screen' => $screen,
                'slug'   => $mediaSlug,
                'title'  => $mediaTitle,
                'pos'    => $playbackPos,
                'ip'     => $request->getClientIp()
            ]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO app_heartbeats (user_id, device_id, session_id, current_screen, current_media_slug, current_media_title, current_playback_pos, ip_address, last_ping_at)
                VALUES (:uid, :did, :sid, :screen, :slug, :title, :pos, :ip, NOW())
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    session_id = VALUES(session_id),
                    current_screen = VALUES(current_screen),
                    current_media_slug = VALUES(current_media_slug),
                    current_media_title = VALUES(current_media_title),
                    current_playback_pos = VALUES(current_playback_pos),
                    ip_address = VALUES(ip_address),
                    last_ping_at = NOW()
            ");

            $stmt->execute([
                'uid'    => $userId,
                'did'    => $deviceId,
                'sid'    => $sessionId,
                'screen' => $screen,
                'slug'   => $mediaSlug,
                'title'  => $mediaTitle,
                'pos'    => $playbackPos,
                'ip'     => $request->getClientIp()
            ]);
        }

        Response::success([
            'ack'       => true,
            'timestamp' => time()
        ], 'Heartbeat received');
    }

    public function getActiveUsersStats(Request $request): void {
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->query("
                SELECT 
                    COUNT(*) as total_active_devices,
                    COUNT(DISTINCT user_id) as total_active_registered_users,
                    COUNT(CASE WHEN current_media_slug IS NOT NULL THEN 1 END) as currently_watching_count
                FROM app_heartbeats 
                WHERE last_ping_at >= datetime('now', '-2 minutes')
            ");
        } else {
            $stmt = $this->db->query("
                SELECT 
                    COUNT(*) as total_active_devices,
                    COUNT(DISTINCT user_id) as total_active_registered_users,
                    COUNT(CASE WHEN current_media_slug IS NOT NULL THEN 1 END) as currently_watching_count
                FROM app_heartbeats 
                WHERE last_ping_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
            ");
        }
        $stats = $stmt ? $stmt->fetch() : [
            'total_active_devices' => 0,
            'total_active_registered_users' => 0,
            'currently_watching_count' => 0
        ];

        Response::success($stats, 'Live presence stats fetched');
    }
}
