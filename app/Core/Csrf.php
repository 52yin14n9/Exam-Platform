<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . View::e(self::token()) . '">';
    }

    public static function verify(): bool
    {
        $sent = (string) ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return isset($_SESSION['_csrf']) && $sent !== '' && hash_equals($_SESSION['_csrf'], $sent);
    }

    public static function middleware(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' || self::verify()) return true;
        http_response_code(419);
        View::render('errors/419', ['title' => '请求已过期']);
        return false;
    }
}

