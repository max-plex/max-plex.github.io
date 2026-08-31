<?php
namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\JWTService;
use App\Config\Database;
use PDO;

class AuthMiddleware {
    public function handle(Request $request): void {
        $token = $request->getBearerToken();

        if (!$token) {
            Response::unauthorized("Missing Authorization Bearer token");
        }

        $payload = JWTService::verifyToken($token);
        if (!$payload || empty($payload['sub'])) {
            Response::unauthorized("Invalid or expired JWT token");
        }

        $userId = (int)$payload['sub'];

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, uuid, name, email, avatar_url, auth_provider, is_active FROM users WHERE id = :id AND is_active = 1 LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::unauthorized("User account is inactive or no longer exists");
        }

        $user['session_id'] = isset($payload['session_id']) ? (int)$payload['session_id'] : null;
        $user['device_id'] = isset($payload['device_id']) ? (string)$payload['device_id'] : null;

        $request->setAuthUser($user);
    }
}
