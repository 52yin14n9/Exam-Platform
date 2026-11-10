<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Env;
use App\Core\ErrorHandler;
use App\Core\KeyManager;

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

Env::load(BASE_PATH . '/.env');
Config::set(require BASE_PATH . '/config/app.php');
ErrorHandler::register();
date_default_timezone_set((string) Config::get('timezone', 'Asia/Shanghai'));

if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', Config::get('debug') ? '1' : '0');
    error_reporting(E_ALL);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_name((string) Config::get('session.name'));
    $secureSetting = (string) Config::get('session.secure_cookie', 'auto');
    $secureCookie = match ($secureSetting) {
        'true', '1', 'yes', 'on' => true,
        'false', '0', 'no', 'off' => false,
        default => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
    };
    $sameSite = ucfirst(strtolower((string) Config::get('session.same_site', 'Lax')));
    if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) $sameSite = 'Lax';
    if ($sameSite === 'None' && !$secureCookie) $sameSite = 'Lax';
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $secureCookie,
        'httponly' => true, 'samesite' => $sameSite,
    ]);
    if (!session_start()) throw new \RuntimeException('PHP Session 启动失败，请检查 session.save_path 权限。');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
}

if (PHP_SAPI !== 'cli') {
    KeyManager::assertConfiguration();
}
