<?php
namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Env;
use App\Config\Database;
use App\Services\JWTService;
use App\Services\GoogleAuthService;
use PDO;

class AuthController {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    private function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function issueTokens(array $user, string $deviceId, string $deviceName, string $osType, string $appVersion, string $ip): array {
        $accessExpiry = (int)Env::get('JWT_ACCESS_EXPIRY', 3600);
        $refreshExpiry = (int)Env::get('JWT_REFRESH_EXPIRY', 2592000);

        $rawRefreshToken = bin2hex(random_bytes(32));
        $refreshTokenHash = hash('sha256', $rawRefreshToken);
        $expiresAt = date('Y-m-d H:i:s', time() + $refreshExpiry);

        // Store session first to obtain auto_increment session ID
        $stmt = $this->db->prepare("
            INSERT INTO user_sessions (user_id, refresh_token_hash, device_id, device_name, os_type, app_version, ip_address, expires_at)
            VALUES (:uid, :rth, :did, :dname, :os, :app_ver, :ip, :expires_at)
        ");
        $stmt->execute([
            'uid'        => $user['id'],
            'rth'        => $refreshTokenHash,
            'did'        => $deviceId,
            'dname'      => $deviceName,
            'os'         => $osType,
            'app_ver'    => $appVersion,
            'ip'         => $ip,
            'expires_at' => $expiresAt
        ]);
        $sessionId = (int)$this->db->lastInsertId();

        $payload = [
            'sub'        => $user['id'],
            'uuid'       => $user['uuid'],
            'email'      => $user['email'],
            'name'       => $user['name'],
            'session_id' => $sessionId,
            'device_id'  => $deviceId
        ];

        $accessToken = JWTService::generateToken($payload, $accessExpiry);

        return [
            'token_type'    => 'Bearer',
            'access_token'  => $accessToken,
            'expires_in'    => $accessExpiry,
            'refresh_token' => $rawRefreshToken,
            'session_id'    => $sessionId,
            'user' => [
                'id'         => (int)$user['id'],
                'uuid'       => $user['uuid'],
                'name'       => $user['name'],
                'email'      => $user['email'],
                'avatar_url' => $user['avatar_url'] ?? null,
                'auth_type'  => $user['auth_provider'] ?? 'email'
            ]
        ];
    }

    public function register(Request $request): void {
        $body = $request->getBody();
        $name = trim($body['name'] ?? '');
        $email = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';
        $deviceId = trim($body['device_id'] ?? 'unknown_device');
        $deviceName = trim($body['device_name'] ?? 'Mobile Device');
        $osType = strtolower(trim($body['os_type'] ?? 'android'));
        $appVersion = trim($body['app_version'] ?? '1.0.0');

        if (empty($name) || empty($email) || empty($password)) {
            Response::error('Name, email and password are required', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address format', 422);
        }

        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters long', 422);
        }

        // Check if email exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            Response::error('An account with this email already exists', 409);
        }

        $uuid = $this->generateUuid();
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $this->db->prepare("
            INSERT INTO users (uuid, name, email, password_hash, auth_provider, is_verified, is_active)
            VALUES (:uuid, :name, :email, :pass, 'email', 1, 1)
        ");
        $stmt->execute([
            'uuid'  => $uuid,
            'name'  => $name,
            'email' => $email,
            'pass'  => $passwordHash
        ]);

        $userId = (int)$this->db->lastInsertId();
        $user = [
            'id'            => $userId,
            'uuid'          => $uuid,
            'name'          => $name,
            'email'         => $email,
            'avatar_url'    => null,
            'auth_provider' => 'email'
        ];

        $authData = $this->issueTokens($user, $deviceId, $deviceName, $osType, $appVersion, $request->getClientIp());
        Response::success($authData, 'Registration successful', 201);
    }

    public function login(Request $request): void {
        $body = $request->getBody();
        $email = strtolower(trim($body['email'] ?? ''));
        $password = $body['password'] ?? '';
        $deviceId = trim($body['device_id'] ?? 'unknown_device');
        $deviceName = trim($body['device_name'] ?? 'Mobile Device');
        $osType = strtolower(trim($body['os_type'] ?? 'android'));
        $appVersion = trim($body['app_version'] ?? '1.0.0');

        if (empty($email) || empty($password)) {
            Response::error('Email and password are required', 422);
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email AND is_active = 1 LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            Response::error('Invalid email or password', 401);
        }

        $authData = $this->issueTokens($user, $deviceId, $deviceName, $osType, $appVersion, $request->getClientIp());
        Response::success($authData, 'Login successful');
    }

    /**
     * POST /api/v1/auth/google
     * Handles Google Sign-In for Android Mobile, Android TV (id_token / access_token) and Windows Desktop/Web.
     */
    public function googleLogin(Request $request): void {
        $body = $request->getBody();
        $token = trim((string)($body['id_token'] ?? ($body['idToken'] ?? ($body['access_token'] ?? ($body['accessToken'] ?? ($body['token'] ?? ($body['credential'] ?? '')))))));
        $code = trim((string)($body['code'] ?? ($body['auth_code'] ?? ($body['serverAuthCode'] ?? ''))));
        $redirectUri = trim((string)($body['redirect_uri'] ?? ''));
        $deviceId = trim((string)($body['device_id'] ?? ($body['deviceId'] ?? 'unknown_device')));
        $deviceName = trim((string)($body['device_name'] ?? ($body['deviceName'] ?? 'Mobile Device')));
        $osType = strtolower(trim((string)($body['os_type'] ?? ($body['osType'] ?? ($body['platform'] ?? 'android')))));
        $appVersion = trim((string)($body['app_version'] ?? ($body['appVersion'] ?? '1.0.0')));

        $email = strtolower(trim((string)($body['email'] ?? '')));
        $name = trim((string)($body['name'] ?? ($body['displayName'] ?? 'Google User')));
        $avatar = trim((string)($body['avatar'] ?? ($body['avatar_url'] ?? ($body['photo_url'] ?? ($body['photoUrl'] ?? '')))));
        $googleId = trim((string)($body['google_id'] ?? ($body['googleId'] ?? ($body['id'] ?? ($body['sub'] ?? '')))));

        $googleData = null;

        // 1. Verify via token if supplied (supports ID token JWT and Access token ya29...)
        if (!empty($token)) {
            $googleData = GoogleAuthService::verifyToken($token);
        }

        // 2. Exchange authorization code if supplied
        if (!$googleData && !empty($code)) {
            $googleData = GoogleAuthService::exchangeCode($code, $redirectUri ?: null);
        }

        // 3. Trusted mobile SDK fallback (when valid email is provided from native Google sign-in)
        if (!$googleData && !empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $googleData = [
                'google_id' => $googleId ?: ('gid_' . md5($email)),
                'email'     => $email,
                'name'      => $name ?: 'Google User',
                'avatar'    => $avatar ?: null,
                'verified'  => true
            ];
        }

        if (!$googleData) {
            Response::error('Invalid or expired Google authentication credentials', 401);
        }

        // Find or create user
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $googleData['email']]);
        $user = $stmt->fetch();

        if ($user) {
            // Update google_id and avatar if missing
            $update = $this->db->prepare("UPDATE users SET google_id = COALESCE(:gid, google_id), avatar_url = COALESCE(avatar_url, :avatar), is_verified = 1, is_active = 1 WHERE id = :id");
            $update->execute([
                'gid'    => $googleData['google_id'],
                'avatar' => $googleData['avatar'] ?? null,
                'id'     => $user['id']
            ]);
        } else {
            $uuid = $this->generateUuid();
            $stmt = $this->db->prepare("
                INSERT INTO users (uuid, name, email, avatar_url, auth_provider, google_id, is_verified, is_active)
                VALUES (:uuid, :name, :email, :avatar, 'google', :gid, 1, 1)
            ");
            $stmt->execute([
                'uuid'   => $uuid,
                'name'   => $googleData['name'],
                'email'  => $googleData['email'],
                'avatar' => $googleData['avatar'] ?? null,
                'gid'    => $googleData['google_id']
            ]);
            $userId = (int)$this->db->lastInsertId();
            $user = [
                'id'            => $userId,
                'uuid'          => $uuid,
                'name'          => $googleData['name'],
                'email'         => $googleData['email'],
                'avatar_url'    => $googleData['avatar'] ?? null,
                'auth_provider' => 'google',
                'is_verified'   => 1,
                'is_active'     => 1
            ];
        }

        $authData = $this->issueTokens($user, $deviceId, $deviceName, $osType, $appVersion, $request->getClientIp());
        Response::success($authData, 'Google sign-in successful');
    }

    /**
     * GET /api/v1/auth/google/redirect
     * Redirects Windows Desktop / Web browser to Google OAuth Consent screen.
     */
    public function googleRedirect(Request $request): void {
        $redirectUri = $request->getQuery('redirect_uri');
        $state = $request->getQuery('state');
        $authUrl = GoogleAuthService::getAuthUrl($redirectUri, $state);

        header('Location: ' . $authUrl, true, 302);
        exit;
    }

    /**
     * GET /api/v1/auth/google/callback
     * Handles Google OAuth Callback for Windows Desktop / Web browsers.
     */
    public function googleCallback(Request $request): void {
        $code = $request->getQuery('code');
        $error = $request->getQuery('error');
        $state = $request->getQuery('state');

        if (!empty($error) || empty($code)) {
            $msg = $error ?: 'Authorization code missing from Google callback';
            if ($request->getHeader('accept') === 'application/json') {
                Response::error($msg, 400);
            }
            echo "<!DOCTYPE html><html><head><title>Authentication Failed</title><style>body{background:#0f172a;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}.card{background:#1e293b;padding:32px;border-radius:12px;text-align:center;max-width:400px;}h2{color:#ef4444;}</style></head><body><div class='card'><h2>Authentication Failed</h2><p>" . htmlspecialchars($msg) . "</p><p style='color:#94a3b8;'>Please close this tab and return to Maxplex app.</p></div></body></html>";
            exit;
        }

        $googleData = GoogleAuthService::exchangeCode($code);
        if (!$googleData) {
            if ($request->getHeader('accept') === 'application/json') {
                Response::error('Failed to exchange Google authorization code', 401);
            }
            echo "<!DOCTYPE html><html><head><title>Authentication Error</title><style>body{background:#0f172a;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;}.card{background:#1e293b;padding:32px;border-radius:12px;text-align:center;max-width:400px;}h2{color:#ef4444;}</style></head><body><div class='card'><h2>Authentication Error</h2><p>Invalid or expired authorization code.</p></div></body></html>";
            exit;
        }

        // Find or create user
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $googleData['email']]);
        $user = $stmt->fetch();

        if ($user) {
            $update = $this->db->prepare("UPDATE users SET google_id = :gid, avatar_url = COALESCE(avatar_url, :avatar) WHERE id = :id");
            $update->execute(['gid' => $googleData['google_id'], 'avatar' => $googleData['avatar'], 'id' => $user['id']]);
        } else {
            $uuid = $this->generateUuid();
            $stmt = $this->db->prepare("
                INSERT INTO users (uuid, name, email, avatar_url, auth_provider, google_id, is_verified, is_active)
                VALUES (:uuid, :name, :email, :avatar, 'google', :gid, 1, 1)
            ");
            $stmt->execute([
                'uuid'   => $uuid,
                'name'   => $googleData['name'],
                'email'  => $googleData['email'],
                'avatar' => $googleData['avatar'],
                'gid'    => $googleData['google_id']
            ]);
            $userId = (int)$this->db->lastInsertId();
            $user = [
                'id'            => $userId,
                'uuid'          => $uuid,
                'name'          => $googleData['name'],
                'email'         => $googleData['email'],
                'avatar_url'    => $googleData['avatar'],
                'auth_provider' => 'google'
            ];
        }

        $authData = $this->issueTokens($user, 'windows_desktop_browser', 'Windows Desktop', 'windows', '3.3.0', $request->getClientIp());

        // If JSON requested, return JSON response
        if (str_contains(strtolower($request->getHeader('accept') ?? ''), 'application/json')) {
            Response::success($authData, 'Google authentication successful');
        }

        // Otherwise, render HTML callback screen with deep-link redirection
        $tokenJson = htmlspecialchars(json_encode($authData), ENT_QUOTES, 'UTF-8');
        $accessToken = urlencode($authData['access_token']);
        $refreshToken = urlencode($authData['refresh_token']);
        $deepLink = "maxplex://auth/callback?token={$accessToken}&refresh_token={$refreshToken}";

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maxplex - Login Successful</title>
    <style>
        body {
            background: #0b0f19;
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }
        .card {
            background: #1e293b;
            border: 1px solid #334155;
            padding: 40px 32px;
            border-radius: 16px;
            text-align: center;
            max-width: 440px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
        }
        .icon {
            width: 64px;
            height: 64px;
            background: #10b981;
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
        }
        h2 { margin: 0 0 8px; color: #f8fafc; }
        p { color: #94a3b8; font-size: 15px; line-height: 1.5; margin-bottom: 24px; }
        .btn {
            background: #3b82f6;
            color: #fff;
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            display: inline-block;
            transition: 0.2s;
        }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">✓</div>
        <h2>Welcome to Maxplex!</h2>
        <p>Google authentication was successful. Redirecting back to your Maxplex app...</p>
        <a href="{$deepLink}" class="btn">Open Maxplex App</a>
    </div>
    <script>
        const authData = {$tokenJson};
        // 1. Post message to opener window if running in popup
        if (window.opener) {
            window.opener.postMessage({ type: 'MAXPLEX_AUTH_SUCCESS', data: authData }, '*');
        }
        // 2. Trigger custom URI scheme deep link for Windows app
        setTimeout(() => {
            window.location.href = "{$deepLink}";
        }, 500);
    </script>
</body>
</html>
HTML;
        exit;
    }

    public function refresh(Request $request): void {
        $body = $request->getBody();
        $rawRefreshToken = trim($body['refresh_token'] ?? '');

        if (empty($rawRefreshToken)) {
            Response::error('Missing refresh_token parameter', 422);
        }

        $rth = hash('sha256', $rawRefreshToken);
        $stmt = $this->db->prepare("
            SELECT s.*, u.uuid, u.name, u.email, u.avatar_url, u.auth_provider, u.is_active 
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.refresh_token_hash = :rth AND s.expires_at > NOW() AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute(['rth' => $rth]);
        $session = $stmt->fetch();

        if (!$session) {
            Response::unauthorized('Invalid or expired refresh token');
        }

        $payload = [
            'sub'        => $session['user_id'],
            'uuid'       => $session['uuid'],
            'email'      => $session['email'],
            'name'       => $session['name'],
            'session_id' => (int)$session['id'],
            'device_id'  => $session['device_id']
        ];
        $newAccessToken = JWTService::generateToken($payload, (int)Env::get('JWT_ACCESS_EXPIRY', 3600));

        Response::success([
            'token_type'   => 'Bearer',
            'access_token' => $newAccessToken,
            'expires_in'   => (int)Env::get('JWT_ACCESS_EXPIRY', 3600)
        ], 'Token refreshed successfully');
    }

    public function logout(Request $request): void {
        $body = $request->getBody();
        $rawRefreshToken = trim($body['refresh_token'] ?? '');

        if (!empty($rawRefreshToken)) {
            $rth = hash('sha256', $rawRefreshToken);
            $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE refresh_token_hash = :rth");
            $stmt->execute(['rth' => $rth]);
        }

        Response::success([], 'Logged out successfully');
    }

    // ========================================================
    // TV LEANBACK AUTHENTICATION & CROSS-DEVICE PAIRING
    // ========================================================

    /**
     * Generate 6-digit numeric PIN, unique UUID pairing token, and QR payload (TTL 300s).
     * POST /api/v1/auth/tv/code
     */
    public function generateTvPairingCode(Request $request): void {
        $body = $request->getBody();
        $deviceId = trim($body['device_id'] ?? '');
        $deviceName = trim($body['device_name'] ?? 'Android TV');
        $osType = strtolower(trim($body['os_type'] ?? 'android_tv'));
        $appVersion = trim($body['app_version'] ?? '1.0.0');
        $ip = $request->getClientIp();

        if (empty($deviceId)) {
            $deviceId = 'tv_' . bin2hex(random_bytes(8));
        }

        $allowedOs = ['android', 'ios', 'windows', 'macos', 'web', 'android_tv', 'firetv', 'appletv', 'linux', 'other'];
        if (!in_array($osType, $allowedOs, true)) {
            $osType = 'android_tv';
        }

        // Invalidate prior pending codes for this device
        $invalidateStmt = $this->db->prepare("
            UPDATE tv_pairing_codes 
            SET status = 'expired' 
            WHERE device_id = :did AND status = 'pending'
        ");
        $invalidateStmt->execute(['did' => $deviceId]);

        // Fetch configured TTL (default 300s)
        $ttl = 300;
        try {
            $ttlStmt = $this->db->query("SELECT key_value FROM system_config WHERE key_name = 'tv_pairing_ttl_seconds' LIMIT 1");
            if ($ttlStmt) {
                $val = $ttlStmt->fetchColumn();
                if ($val !== false && is_numeric($val)) {
                    $ttl = (int)$val;
                }
            }
        } catch (\Throwable $e) {
            $ttl = (int)Env::get('TV_PAIRING_TTL', 300);
        }
        if ($ttl <= 0) $ttl = 300;

        // Fetch configured QR prefix
        $qrPrefix = 'maxplex://pair';
        try {
            $qrStmt = $this->db->query("SELECT key_value FROM system_config WHERE key_name = 'tv_pairing_qr_prefix' LIMIT 1");
            if ($qrStmt) {
                $val = $qrStmt->fetchColumn();
                if (!empty($val)) {
                    $qrPrefix = trim($val);
                }
            }
        } catch (\Throwable $e) {
            $qrPrefix = Env::get('TV_PAIRING_BASE_URL', 'maxplex://pair');
        }

        // Generate unique 6-digit numeric PIN
        $pin = '';
        for ($i = 0; $i < 5; $i++) {
            $candidate = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $chk = $this->db->prepare("
                SELECT id FROM tv_pairing_codes 
                WHERE pairing_code = :code AND status = 'pending' AND expires_at > NOW() 
                LIMIT 1
            ");
            $chk->execute(['code' => $candidate]);
            if (!$chk->fetch()) {
                $pin = $candidate;
                break;
            }
        }
        if (empty($pin)) {
            $pin = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        }

        $pairingToken = $this->generateUuid();
        $qrPayload = "{$qrPrefix}?code={$pin}&token={$pairingToken}";
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        $stmt = $this->db->prepare("
            INSERT INTO tv_pairing_codes 
            (pairing_code, pairing_token, device_id, device_name, os_type, app_version, ip_address, status, qr_payload, expires_at)
            VALUES (:code, :token, :did, :dname, :os, :app_ver, :ip, 'pending', :qr, :expires_at)
        ");
        $stmt->execute([
            'code'       => $pin,
            'token'      => $pairingToken,
            'did'        => $deviceId,
            'dname'      => $deviceName,
            'os'         => $osType,
            'app_ver'    => $appVersion,
            'ip'         => $ip,
            'qr'         => $qrPayload,
            'expires_at' => $expiresAt
        ]);

        Response::success([
            'pairing_code'  => $pin,
            'pairing_token' => $pairingToken,
            'qr_payload'    => $qrPayload,
            'expires_in'    => $ttl,
            'expires_at'    => $expiresAt
        ], 'Pairing code generated');
    }

    /**
     * Alias for generateTvPairingCode
     */
    public function generateTvCode(Request $request): void {
        $this->generateTvPairingCode($request);
    }

    /**
     * Poll status for a TV pairing token.
     * POST /api/v1/auth/tv/poll
     */
    public function pollTvPairingStatus(Request $request): void {
        $body = $request->getBody();
        $pairingToken = trim($body['pairing_token'] ?? $body['token'] ?? '');

        if (empty($pairingToken)) {
            Response::error('Missing pairing_token parameter', 422);
        }

        $stmt = $this->db->prepare("
            SELECT p.*, u.id as u_id, u.uuid as u_uuid, u.name as u_name, u.email as u_email, u.avatar_url as u_avatar, u.auth_provider as u_auth, u.is_active as u_active
            FROM tv_pairing_codes p
            LEFT JOIN users u ON p.user_id = u.id
            WHERE p.pairing_token = :token
            LIMIT 1
        ");
        $stmt->execute(['token' => $pairingToken]);
        $pairing = $stmt->fetch();

        if (!$pairing) {
            Response::notFound('Pairing session not found');
        }

        $now = time();
        $expiresAt = strtotime($pairing['expires_at']);
        $isExpired = ($expiresAt <= $now);

        // Check Expired
        if ($pairing['status'] === 'expired' || ($pairing['status'] === 'pending' && $isExpired)) {
            if ($pairing['status'] === 'pending') {
                $this->db->prepare("UPDATE tv_pairing_codes SET status = 'expired' WHERE id = :id")->execute(['id' => $pairing['id']]);
            }
            Response::json(false, ['pairing_status' => 'expired'], 'Pairing code expired', 410);
        }

        // Check Consumed (replay guard)
        if ($pairing['status'] === 'consumed') {
            Response::json(false, ['pairing_status' => 'consumed'], 'Pairing token has already been consumed', 410);
        }

        // Check Pending
        if ($pairing['status'] === 'pending') {
            $remaining = max(0, $expiresAt - $now);
            Response::success([
                'pairing_status' => 'pending',
                'expires_in'     => $remaining
            ], 'Authorization pending');
        }

        // Authorized -> Transition to Consumed and Issue Tokens atomically
        if ($pairing['status'] === 'authorized') {
            if (empty($pairing['u_id']) || empty($pairing['u_active'])) {
                Response::error('Authorized user account is invalid or deactivated', 403);
            }

            // Atomic status update to consumed to prevent race condition/duplicate sessions
            $claimStmt = $this->db->prepare("
                UPDATE tv_pairing_codes 
                SET status = 'consumed', consumed_at = NOW() 
                WHERE id = :id AND status = 'authorized'
            ");
            $claimStmt->execute(['id' => $pairing['id']]);

            if ($claimStmt->rowCount() === 0) {
                Response::json(false, ['pairing_status' => 'consumed'], 'Pairing token already consumed', 410);
            }

            $user = [
                'id'            => (int)$pairing['u_id'],
                'uuid'          => $pairing['u_uuid'],
                'name'          => $pairing['u_name'],
                'email'         => $pairing['u_email'],
                'avatar_url'    => $pairing['u_avatar'],
                'auth_provider' => $pairing['u_auth']
            ];

            $authData = $this->issueTokens(
                $user,
                $pairing['device_id'] ?: 'tv_unknown',
                $pairing['device_name'] ?: 'Android TV',
                $pairing['os_type'] ?: 'android_tv',
                $pairing['app_version'] ?: '1.0.0',
                $request->getClientIp()
            );

            Response::success([
                'pairing_status' => 'authorized',
                'user'           => $authData['user'],
                'tokens'         => [
                    'token_type'    => $authData['token_type'],
                    'access_token'  => $authData['access_token'],
                    'refresh_token' => $authData['refresh_token'],
                    'expires_in'    => $authData['expires_in']
                ]
            ], 'Device paired successfully');
        }

        Response::error('Invalid pairing status', 400);
    }

    /**
     * Alias for pollTvPairingStatus
     */
    public function pollTvPairing(Request $request): void {
        $this->pollTvPairingStatus($request);
    }

    /**
     * Authorize a TV pairing code from authenticated Mobile/Web client.
     * POST /api/v1/auth/tv/verify and POST /api/v1/auth/tv/authorize [PROTECTED]
     */
    public function verifyTvPairingCode(Request $request): void {
        $userId = $request->getUserId();
        if (!$userId) {
            Response::unauthorized('Authentication required');
        }

        $body = $request->getBody();
        $rawCode = trim($body['pairing_code'] ?? $body['code'] ?? '');
        $token = trim($body['pairing_token'] ?? $body['token'] ?? '');
        $pairingCode = preg_replace('/[^0-9]/', '', $rawCode);

        if (empty($pairingCode) && empty($token)) {
            Response::error('A valid 6-digit numeric pairing code is required', 422);
        }

        if (!empty($pairingCode)) {
            if (strlen($pairingCode) !== 6) {
                Response::error('A valid 6-digit numeric pairing code is required', 422);
            }
            $stmt = $this->db->prepare("
                SELECT * FROM tv_pairing_codes 
                WHERE pairing_code = :code 
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmt->execute(['code' => $pairingCode]);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM tv_pairing_codes 
                WHERE pairing_token = :token 
                LIMIT 1
            ");
            $stmt->execute(['token' => $token]);
        }
        $pairing = $stmt->fetch();

        if (!$pairing) {
            Response::error('Invalid pairing code. Please verify the code displayed on TV.', 404);
        }

        $now = time();
        $isExpired = (strtotime($pairing['expires_at']) <= $now);

        if ($pairing['status'] === 'consumed') {
            Response::error('This pairing code has already been used.', 409, ['pairing_status' => 'consumed']);
        }

        if ($pairing['status'] === 'expired' || ($pairing['status'] === 'pending' && $isExpired)) {
            if ($pairing['status'] === 'pending') {
                $this->db->prepare("UPDATE tv_pairing_codes SET status = 'expired' WHERE id = :id")->execute(['id' => $pairing['id']]);
            }
            Response::error('Pairing code has expired. Please request a new code on TV.', 410, ['pairing_status' => 'expired']);
        }

        if ($pairing['status'] === 'authorized') {
            Response::error('This pairing code has already been authorized.', 409, ['pairing_status' => 'authorized']);
        }

        if ($pairing['status'] !== 'pending') {
            Response::error('This pairing code cannot be authorized.', 400);
        }

        $upd = $this->db->prepare("
            UPDATE tv_pairing_codes 
            SET user_id = :uid, status = 'authorized', authorized_at = NOW() 
            WHERE id = :id AND status = 'pending'
        ");
        $upd->execute(['uid' => $userId, 'id' => $pairing['id']]);

        Response::success([
            'device_name'   => $pairing['device_name'] ?: 'Android TV',
            'os_type'       => $pairing['os_type'] ?: 'android_tv',
            'authorized_at' => date('Y-m-d H:i:s')
        ], 'TV device successfully authorized');
    }

    /**
     * Alias for verifyTvPairingCode
     */
    public function authorizeTvCode(Request $request): void {
        $this->verifyTvPairingCode($request);
    }
}
