<?php
declare(strict_types=1);

namespace App\Core;

/** 管理员与学生必须使用不同随机长度、前缀、HMAC 算法、密钥和数据表。 */
final class KeyManager
{
    public static function generateStudent(): string
    {
        return 'stu1_' . self::base64Url(random_bytes(32));
    }

    public static function generateAdmin(): string
    {
        return 'adm2_' . self::base64Url(random_bytes(48));
    }

    public static function digestStudent(string $key): string
    {
        if (!self::validStudentFormat($key)) return '';
        return hash_hmac('sha256', $key, (string) Config::get('keys.student_secret'));
    }

    public static function digestAdmin(string $key): string
    {
        if (!self::validAdminFormat($key)) return '';
        return hash_hmac('sha512', $key, (string) Config::get('keys.admin_secret'));
    }

    public static function validStudentFormat(string $key): bool
    {
        return preg_match('/^stu1_[A-Za-z0-9_-]{43}$/D', $key) === 1;
    }

    public static function validAdminFormat(string $key): bool
    {
        return preg_match('/^adm2_[A-Za-z0-9_-]{64}$/D', $key) === 1;
    }

    public static function assertConfiguration(): void
    {
        $student = (string) Config::get('keys.student_secret');
        $admin = (string) Config::get('keys.admin_secret');
        if (strlen($student) < 32 || strlen($admin) < 64 || hash_equals($student, $admin)) {
            throw new \RuntimeException('ADMIN_KEY_SECRET 与 STUDENT_KEY_SECRET 必须分别设置，长度至少为 64/32 字节且不能相同。');
        }
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
