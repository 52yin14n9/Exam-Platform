<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $values = [];

    public static function set(array $values): void { self::$values = $values; }

    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::$values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return $default;
            $value = $value[$segment];
        }
        return $value;
    }
}

