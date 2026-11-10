<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\View;

abstract class Controller
{
    protected function render(string $view, array $data = []): void
    {
        $data['siteName'] = $this->setting('site_name', (string) Config::get('name'));
        View::render($view, $data);
    }

    protected function setting(string $key, string $default = ''): string
    {
        try {
            $stmt = Database::connection()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
            $stmt->execute([$key]);
            return (string) ($stmt->fetchColumn() ?: $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    protected function requireFields(array $fields): array
    {
        $errors = [];
        foreach ($fields as $field => $label) {
            if (trim((string) ($_POST[$field] ?? '')) === '') $errors[] = "{$label}不能为空。";
        }
        return $errors;
    }

    protected function utc(?string $local): ?string
    {
        if (!$local) return null;
        $dt = new \DateTimeImmutable($local, new \DateTimeZone((string) Config::get('timezone')));
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

