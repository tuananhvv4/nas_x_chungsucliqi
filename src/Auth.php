<?php

declare(strict_types=1);

namespace App;

final class Auth
{
    private static ?array $user = null;

    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        self::$user = Database::fetch('SELECT id, name, email, role, created_at FROM users WHERE id = ? AND is_active = 1', [$id]);
        return self::$user;
    }

    public static function requireUser(): array
    {
        $user = self::user();
        if (!$user) {
            Support::redirect('/login');
        }
        return $user;
    }

    public static function login(string $email, string $password): bool
    {
        $user = Database::fetch('SELECT * FROM users WHERE email = ? AND is_active = 1', [mb_strtolower(trim($email))]);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        self::$user = null;
        Database::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$user = null;
    }
}

