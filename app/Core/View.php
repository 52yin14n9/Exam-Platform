<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $data = [], string $layout = 'layouts/app'): void
    {
        $base = dirname(__DIR__) . '/Views/';
        $viewFile = $base . $view . '.php';
        if (!is_file($viewFile)) throw new \RuntimeException("View not found: {$view}");
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = ob_get_clean();
        require $base . $layout . '.php';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

