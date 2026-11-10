<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(string $action, string $targetType, ?int $targetId = null, array $meta = []): void
    {
        try {
            $stmt = Database::connection()->prepare('INSERT INTO audit_logs(actor_role, actor_id, action, target_type, target_id, ip_hash, metadata_json, created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())');
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
            $stmt->execute([
                Auth::role() ?? 'system', Auth::id(), $action, $targetType, $targetId,
                hash('sha256', $ip), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (\Throwable $error) {
            // 审计存储异常不能把已经成功的登录或业务操作变成空白 500 页面。
            ErrorHandler::report($error, 'audit.' . $action);
        }
    }
}
