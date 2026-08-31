<?php
namespace App\Services;

use App\Core\Env;

class JWTService {
    private static function getSecret(): string {
        return Env::get('JWT_SECRET', 'default_ott_secure_jwt_secret_key_2026_xyz_!');
    }

    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    public static function generateToken(array $payload, int $expirySeconds = 3600): string {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        
        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $expirySeconds;

        $encodedHeader = self::base64UrlEncode(json_encode($header));
        $encodedPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", self::getSecret(), true);
        $encodedSignature = self::base64UrlEncode($signature);

        return "{$encodedHeader}.{$encodedPayload}.{$encodedSignature}";
    }

    public static function verifyToken(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $expectedSignature = self::base64UrlEncode(hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", self::getSecret(), true));

        if (!hash_equals($expectedSignature, $encodedSignature)) {
            return null; // Invalid signature
        }

        $payload = json_decode(self::base64UrlDecode($encodedPayload), true);
        if (!is_array($payload) || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null; // Expired or malformed
        }

        return $payload;
    }
}
