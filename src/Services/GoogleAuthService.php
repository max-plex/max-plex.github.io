<?php
namespace App\Services;

use App\Core\Env;

class GoogleAuthService {
    /**
     * Verify Google Token (Supports both ID Token JWT and Access Token ya29...)
     */
    public static function verifyIdToken(string $idToken): ?array {
        return self::verifyToken($idToken);
    }

    public static function verifyToken(string $token): ?array {
        if (empty($token)) return null;

        // 1. Try ID Token verification (JWT format)
        $idUrl = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($token);
        $ch = curl_init($idUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            if (!empty($data['email'])) {
                return [
                    'google_id' => $data['sub'] ?? ($data['user_id'] ?? 'gid_' . md5($data['email'])),
                    'email'     => strtolower(trim($data['email'])),
                    'name'      => $data['name'] ?? ($data['given_name'] ?? 'User'),
                    'avatar'    => $data['picture'] ?? null,
                    'verified'  => !empty($data['email_verified'])
                ];
            }
        }

        // 2. Try Access Token verification (oauth2.googleapis.com/tokeninfo?access_token=...)
        $accessUrl = "https://oauth2.googleapis.com/tokeninfo?access_token=" . urlencode($token);
        $ch = curl_init($accessUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            if (!empty($data['email'])) {
                return [
                    'google_id' => $data['sub'] ?? ($data['user_id'] ?? 'gid_' . md5($data['email'])),
                    'email'     => strtolower(trim($data['email'])),
                    'name'      => $data['name'] ?? ($data['given_name'] ?? 'User'),
                    'avatar'    => $data['picture'] ?? null,
                    'verified'  => !empty($data['email_verified'])
                ];
            }
        }

        // 3. Try UserInfo API with Bearer token
        $ch = curl_init("https://www.googleapis.com/oauth2/v3/userinfo");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response)) {
            $data = json_decode($response, true);
            if (!empty($data['email'])) {
                return [
                    'google_id' => $data['sub'] ?? ($data['user_id'] ?? 'gid_' . md5($data['email'])),
                    'email'     => strtolower(trim($data['email'])),
                    'name'      => $data['name'] ?? ($data['given_name'] ?? 'User'),
                    'avatar'    => $data['picture'] ?? null,
                    'verified'  => !empty($data['email_verified'])
                ];
            }
        }

        return null;
    }

    /**
     * Generate Google OAuth 2.0 Authorization URL for Windows Desktop & Web
     */
    public static function getAuthUrl(?string $redirectUri = null, ?string $state = null): string {
        $clientId = Env::get('GOOGLE_CLIENT_ID', '');
        $defaultCallback = rtrim(Env::get('APP_URL', 'https://mov.aimacademycbse.com'), '/') . '/api/v1/auth/google/callback';
        $callback = $redirectUri ?: $defaultCallback;

        $params = [
            'client_id'             => $clientId,
            'redirect_uri'          => $callback,
            'response_type'         => 'code',
            'scope'                 => 'openid email profile',
            'access_type'           => 'offline',
            'include_granted_scopes'=> 'true',
            'prompt'                => 'select_account'
        ];

        if (!empty($state)) {
            $params['state'] = $state;
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Exchange OAuth Authorization Code for User Profile (used for Windows Desktop / Web Callback)
     */
    public static function exchangeCode(string $code, ?string $redirectUri = null): ?array {
        if (empty($code)) return null;

        $clientId = Env::get('GOOGLE_CLIENT_ID', '');
        $clientSecret = Env::get('GOOGLE_CLIENT_SECRET', '');
        $defaultCallback = rtrim(Env::get('APP_URL', 'https://mov.aimacademycbse.com'), '/') . '/api/v1/auth/google/callback';
        $callback = $redirectUri ?: $defaultCallback;

        if (empty($clientId) || str_contains($clientId, 'YOUR_GOOGLE_OAUTH') || empty($clientSecret)) {
            error_log('[GoogleAuthService] Missing GOOGLE_CLIENT_ID or GOOGLE_CLIENT_SECRET in .env');
            return null;
        }

        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $callback,
            'grant_type'    => 'authorization_code'
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            error_log("[GoogleAuthService] Token exchange failed with HTTP $httpCode: $response");
            return null;
        }

        $tokenData = json_decode($response, true);
        $idToken = $tokenData['id_token'] ?? null;
        $accessToken = $tokenData['access_token'] ?? null;

        // If ID token is present, verify and extract profile
        if (!empty($idToken)) {
            $profile = self::verifyIdToken($idToken);
            if ($profile) return $profile;
        }

        // Fallback: Fetch user info using access token
        if (!empty($accessToken)) {
            $userUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
            $ch = curl_init($userUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $userRaw = curl_exec($ch);
            curl_close($ch);

            $userData = json_decode($userRaw, true);
            if (!empty($userData['sub']) && !empty($userData['email'])) {
                return [
                    'google_id' => $userData['sub'],
                    'email'     => strtolower(trim($userData['email'])),
                    'name'      => $userData['name'] ?? ($userData['given_name'] ?? 'User'),
                    'avatar'    => $userData['picture'] ?? null,
                    'verified'  => !empty($userData['email_verified'])
                ];
            }
        }

        return null;
    }
}
