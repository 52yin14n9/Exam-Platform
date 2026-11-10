<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Core\KeyManager;
use App\Core\Response;

final class AuthController extends Controller
{
    public function studentForm(): void
    {
        if (Auth::role() === 'student') Response::redirect('/student');
        $this->render('auth/login', ['title' => '学生登录', 'role' => 'student']);
    }

    public function adminForm(): void
    {
        if (Auth::role() === 'admin') Response::redirect('/admin');
        $this->render('auth/login', ['title' => '管理员登录', 'role' => 'admin']);
    }

    public function studentLogin(): void { $this->attempt('student'); }
    public function adminLogin(): void { $this->attempt('admin'); }

    private function attempt(string $role): void
    {
        if ($this->blocked($role)) {
            Flash::set('error', '尝试次数过多，请 10 分钟后再试。');
            Response::redirect('/login/' . $role);
        }
        $key = trim((string) ($_POST['access_key'] ?? ''));
        $validFormat = $role === 'admin' ? KeyManager::validAdminFormat($key) : KeyManager::validStudentFormat($key);
        if (!$validFormat) {
            Audit::log('login.failed.' . $role, 'access_key', null, ['reason' => 'format']);
            Flash::set('error', 'Key 无效、已撤销或账号已停用。');
            Response::redirect('/login/' . $role);
        }
        $ok = $role === 'admin' ? Auth::loginAdmin($key) : Auth::loginStudent($key);
        if (!$ok) {
            Audit::log('login.failed.' . $role, 'access_key', null, ['hint' => substr($key, -4)]);
            Flash::set('error', 'Key 无效、已撤销或账号已停用。');
            Response::redirect('/login/' . $role);
        }
        Audit::log('login.success.' . $role, 'user', Auth::id());
        Response::redirect($role === 'admin' ? '/admin' : '/student');
    }

    private function blocked(string $role): bool
    {
        try {
            $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM audit_logs WHERE ip_hash=? AND action=? AND created_at > UTC_TIMESTAMP() - INTERVAL 10 MINUTE');
            $stmt->execute([$ipHash, 'login.failed.' . $role]);
            return (int) $stmt->fetchColumn() >= 8;
        } catch (\Throwable $error) {
            ErrorHandler::report($error, 'login.throttle');
            return false;
        }
    }

    public function logout(): void
    {
        Audit::log('logout', 'user', Auth::id());
        $role = Auth::role();
        Auth::logout();
        Response::redirect($role === 'admin' ? '/login/admin' : '/login/student');
    }
}
