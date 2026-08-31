<?php
/**
 * HDHub4u Admin - Session & Auth Manager
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/config/database.php';

use App\Config\Database;

class AdminAuth {
    public static function check(): bool {
        return !empty($_SESSION['admin_logged_in']) && !empty($_SESSION['admin_user']);
    }

    public static function user(): ?array {
        return $_SESSION['admin_user'] ?? null;
    }

    public static function requireAuth(): void {
        if (!self::check()) {
            header('Location: /admin/login');
            exit;
        }
    }

    public static function login(string $username, string $password): bool {
        $db = Database::getConnection();
        
        // Ensure admin table exists
        self::ensureAdminTable($db);

        $stmt = $db->prepare("SELECT * FROM admin_users WHERE username = :u OR email = :e LIMIT 1");
        $stmt->execute([':u' => $username, ':e' => $username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = [
                'id'       => $admin['id'],
                'username' => $admin['username'],
                'email'    => $admin['email'],
                'role'     => $admin['role'] ?? 'superadmin'
            ];

            // Update last login
            $upd = $db->prepare("UPDATE admin_users SET last_login_at = NOW() WHERE id = :id");
            $upd->execute([':id' => $admin['id']]);

            return true;
        }

        // Hardcoded fallback if database user is not yet created
        if (($username === 'admin' || $username === 'admin@hdhub4u.com') && $password === 'Antigravity@21') {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = [
                'id'       => 1,
                'username' => 'admin',
                'email'    => 'admin@hdhub4u.com',
                'role'     => 'superadmin'
            ];
            return true;
        }

        return false;
    }

    public static function logout(): void {
        unset($_SESSION['admin_logged_in']);
        unset($_SESSION['admin_user']);
        session_destroy();
    }

    public static function ensureAdminTable($db): void {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `admin_users` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(60) NOT NULL UNIQUE,
                `email` VARCHAR(120) NOT NULL UNIQUE,
                `password_hash` VARCHAR(255) NOT NULL,
                `role` ENUM('superadmin', 'admin', 'moderator') DEFAULT 'superadmin',
                `last_login_at` TIMESTAMP NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // Check if superadmin exists, if not seed default
            $chk = $db->query("SELECT COUNT(*) FROM admin_users");
            if ($chk && $chk->fetchColumn() == 0) {
                $defaultPass = password_hash('Antigravity@21', PASSWORD_BCRYPT);
                $ins = $db->prepare("INSERT INTO admin_users (username, email, password_hash, role) VALUES ('admin', 'admin@hdhub4u.com', :p, 'superadmin')");
                $ins->execute([':p' => $defaultPass]);
            }
        } catch (Throwable $e) {
            error_log("Admin table creation: " . $e->getMessage());
        }
    }
}
