<?php
declare(strict_types=1);

namespace App\Core;

final class ErrorHandler
{
    public static function register(): void
    {
        set_exception_handler([self::class, 'handle']);
        register_shutdown_function([self::class, 'handleFatal']);
    }

    public static function report(\Throwable $error, string $context = 'application'): string
    {
        $requestId = bin2hex(random_bytes(6));
        $line = sprintf(
            "[%s] [%s] [%s] %s: %s in %s:%d\n%s\n",
            gmdate('c'), $requestId, $context, $error::class, $error->getMessage(),
            $error->getFile(), $error->getLine(), $error->getTraceAsString()
        );
        $dir = defined('BASE_PATH') ? BASE_PATH . '/storage/logs' : null;
        if ($dir && (is_dir($dir) || @mkdir($dir, 0750, true)) && @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX) !== false) {
            return $requestId;
        }
        error_log(trim($line));
        return $requestId;
    }

    public static function handle(\Throwable $error): never
    {
        $requestId = self::report($error);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "运行失败：{$error->getMessage()}\n错误编号：{$requestId}\n");
            exit(1);
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        try {
            View::render('errors/500', ['title' => '系统暂时不可用', 'requestId' => $requestId]);
        } catch (\Throwable) {
            echo '<!doctype html><meta charset="utf-8"><title>系统暂时不可用</title><h1>系统暂时不可用</h1><p>错误编号：'
                . htmlspecialchars($requestId, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        exit(1);
    }

    public static function handleFatal(): void
    {
        $last = error_get_last();
        if (!$last || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        self::report(new \ErrorException($last['message'], 0, $last['type'], $last['file'], $last['line']), 'fatal');
    }
}
