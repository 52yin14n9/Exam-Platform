<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    public static function loginStudent(string $rawKey): bool
    {
        KeyManager::assertConfiguration();
        $digest = KeyManager::digestStudent(trim($rawKey));
        if ($digest === '') return false;
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT u.id, u.display_name, u.student_no, u.session_version FROM student_access_keys k JOIN students u ON u.id=k.student_id WHERE k.key_digest=? AND k.revoked_at IS NULL AND u.status="active" LIMIT 1');
        $stmt->execute([$digest]);
        $user = $stmt->fetch();
        if (!$user) return false;
        $pdo->prepare('UPDATE student_access_keys SET last_used_at=UTC_TIMESTAMP() WHERE key_digest=?')->execute([$digest]);
        self::establish('student', (int) $user['id'], $user['display_name'], $user['student_no'], (int) $user['session_version']);
        return true;
    }

    public static function loginAdmin(string $rawKey): bool
    {
        KeyManager::assertConfiguration();
        $digest = KeyManager::digestAdmin(trim($rawKey));
        if ($digest === '') return false;
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT a.id, a.display_name, a.session_version FROM admin_access_keys k JOIN admins a ON a.id=k.admin_id WHERE k.key_digest=? AND k.revoked_at IS NULL AND a.status="active" LIMIT 1');
        $stmt->execute([$digest]);
        $user = $stmt->fetch();
        if (!$user) return false;
        $pdo->prepare('UPDATE admin_access_keys SET last_used_at=UTC_TIMESTAMP() WHERE key_digest=?')->execute([$digest]);
        self::establish('admin', (int) $user['id'], $user['display_name'], null, (int) $user['session_version']);
        return true;
    }

    private static function establish(string $role, int $id, string $name, ?string $number, int $sessionVersion): void
    {
        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
        $_SESSION['auth'] = [
            'role' => $role, 'id' => $id, 'name' => $name, 'number' => $number,
            'session_version' => $sessionVersion,
            'created_at' => time(), 'last_seen_at' => time(),
        ];
    }

    public static function user(): ?array { return $_SESSION['auth'] ?? null; }
    public static function id(): ?int { return isset($_SESSION['auth']['id']) ? (int) $_SESSION['auth']['id'] : null; }
    public static function role(): ?string { return $_SESSION['auth']['role'] ?? null; }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    private static function clearAuthentication(): void
    {
        unset($_SESSION['auth']);
        if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    }

    public static function requireAdmin(): bool { return self::requireRole('admin', '/login/admin'); }
    public static function requireStudent(): bool { return self::requireRole('student', '/login/student'); }

    private static function requireRole(string $role, string $login): bool
    {
        $auth = self::user();
        if (!$auth || ($auth['role'] ?? null) !== $role || self::expired($auth) || !self::accountStillValid($auth)) {
            self::clearAuthentication();
            Flash::set('warning', '请重新登录。');
            Response::redirect($login);
        }
        $_SESSION['auth']['last_seen_at'] = time();
        return true;
    }

    private static function expired(array $auth): bool
    {
        $idle = (int) Config::get('session.idle_minutes', 60) * 60;
        $absolute = (int) Config::get('session.absolute_hours', 12) * 3600;
        return time() - (int) $auth['last_seen_at'] > $idle || time() - (int) $auth['created_at'] > $absolute;
    }

    private static function accountStillValid(array $auth): bool
    {
        $role = (string) ($auth['role'] ?? '');
        $table = $role === 'admin' ? 'admins' : ($role === 'student' ? 'students' : '');
        if ($table === '') return false;
        $stmt = Database::connection()->prepare("SELECT session_version FROM {$table} WHERE id=? AND status='active'");
        $stmt->execute([(int) ($auth['id'] ?? 0)]);
        $version = $stmt->fetchColumn();
        return $version !== false && (int) $version === (int) ($auth['session_version'] ?? 0);
    }
}
