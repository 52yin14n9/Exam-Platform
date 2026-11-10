<?php
declare(strict_types=1);

namespace App\Core;

final class Flash
{
    public static function set(string $type, string $message): void { $_SESSION['_flash'][] = compact('type', 'message'); }
    public static function pull(): array { $items = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $items; }
}

