<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Config\Database;
use App\Core\Env;
use PDO;

class NotificationController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function registerPushToken(Request $request): void {
        $body = $request->getBody();
        $token = trim($body['fcm_token'] ?? '');
        $deviceId = trim($body['device_id'] ?? '');
        $osType = strtolower(trim($body['os_type'] ?? 'android'));
        $topics = $body['topics'] ?? ['all', 'new_releases'];

        if (empty($token) || empty($deviceId)) {
            Response::error('fcm_token and device_id are required', 422);
        }

        $userId = $request->getUserId(); // Optional (allows guest notification registration)

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->db->prepare("
                INSERT INTO user_device (user_id, fcm_token, device_id, os_type, topics, is_active, updated_at)
                VALUES (:uid, :token, :did, :os, :topics, 1, datetime('now'))
                ON CONFLICT(fcm_token) DO UPDATE SET
                    user_id = excluded.user_id,
                    device_id = excluded.device_id,
                    os_type = excluded.os_type,
                    topics = excluded.topics,
                    is_active = 1,
                    updated_at = datetime('now')
            ");
            $stmt->execute([
                'uid'    => $userId,
                'token'  => $token,
                'did'    => $deviceId,
                'os'     => $osType,
                'topics' => json_encode($topics)
            ]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO user_device (user_id, fcm_token, device_id, os_type, topics, is_active, updated_at)
                VALUES (:uid, :token, :did, :os, :topics, 1, NOW())
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    device_id = VALUES(device_id),
                    os_type = VALUES(os_type),
                    topics = VALUES(topics),
                    is_active = 1,
                    updated_at = NOW()
            ");

            $stmt->execute([
                'uid'    => $userId,
                'token'  => $token,
                'did'    => $deviceId,
                'os'     => $osType,
                'topics' => json_encode($topics)
            ]);
        }

        Response::success([], 'User device and push token registered');
    }

    public function sendBroadcast(Request $request): void {
        $body = $request->getBody();
        $title = trim($body['title'] ?? '');
        $message = trim($body['message'] ?? '');
        $mediaSlug = trim($body['media_slug'] ?? '');
        $imageUrl = trim($body['image_url'] ?? '');

        if (empty($title) || empty($message)) {
            Response::error('title and message are required', 422);
        }

        $serverKey = Env::get('FIREBASE_SERVER_KEY');
        if (empty($serverKey) || $serverKey === 'YOUR_FIREBASE_SERVER_KEY') {
            Response::error('Firebase Server Key not configured in .env', 500);
        }

        // Fetch active tokens
        $stmt = $this->db->query("SELECT fcm_token FROM user_device WHERE is_active = 1 LIMIT 1000");
        $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tokens)) {
            Response::success(['sent_count' => 0], 'No active device tokens found');
        }

        $payload = [
            'registration_ids' => $tokens,
            'notification' => [
                'title' => $title,
                'body'  => $message,
                'image' => $imageUrl,
                'sound' => 'default'
            ],
            'data' => [
                'media_slug' => $mediaSlug,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK'
            ]
        ];

        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: key=' . $serverKey,
            'Content-Type: application/json'
        ]);

        $result = curl_exec($ch);
        curl_close($ch);

        Response::success(json_decode($result, true), 'Broadcast notification sent');
    }
}
