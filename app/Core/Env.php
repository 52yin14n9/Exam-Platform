<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (getenv($key) !== false) {
                continue;
            }
            if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return $value === false ? $default : (string) $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = getenv($key);
        return $value === false ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = getenv($key);
        if ($value === false) return $default;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}

