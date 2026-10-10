<?php
declare(strict_types=1);

namespace App;

class Auth
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    public static function user(): ?array
    {
        self::start();
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $u = self::user();
        return $u !== null && ($u['role'] ?? '') === 'admin';
    }

    public static function login(string $username, string $password): bool
    {
        $stmt = Db::pdo()->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $row['id'],
            'username' => $row['username'],
            'role' => $row['role'],
        ];
        return true;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function refresh(): void
    {
        self::start();
        if (!isset($_SESSION['user'])) {
            return;
        }
        $stmt = Db::pdo()->prepare('SELECT id, username, role FROM users WHERE id = :id');
        $stmt->execute([':id' => $_SESSION['user']['id']]);
        $row = $stmt->fetch();
        if (!$row) {
            self::logout();
            return;
        }
        $_SESSION['user'] = ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => $row['role']];
    }

    public static function requireLogin(): array
    {
        $u = self::user();
        if (!$u) {
            redirect(base_url('index.php?r=login'));
        }
        return $u;
    }

    public static function requireAdmin(): array
    {
        $u = self::requireLogin();
        if (($u['role'] ?? '') !== 'admin') {
            http_response_code(403);
            exit('Forbidden: admin only');
        }
        return $u;
    }

    /** Cek kepemilikan container: admin boleh semua, user hanya miliknya. */
    public static function canAccessContainer(string $dockerId): bool
    {
        $u = self::user();
        if (!$u) {
            return false;
        }
        if (($u['role'] ?? '') === 'admin') {
            return true;
        }
        $stmt = Db::pdo()->prepare('SELECT 1 FROM containers WHERE docker_id = :d AND owner_id = :o');
        $stmt->execute([':d' => $dockerId, ':o' => $u['id']]);
        return (bool) $stmt->fetchColumn();
    }

    /** Log aktivitas, best-effort. */
    public static function log(?int $userId, string $action, string $dockerId = ''): void
    {
        try {
            $stmt = Db::pdo()->prepare('INSERT INTO activity_logs(user_id, action, docker_id) VALUES(:u,:a,:d)');
            $stmt->execute([':u' => $userId, ':a' => substr($action, 0, 255), ':d' => substr($dockerId, 0, 128)]);
        } catch (\Throwable) {
        }
    }
}
