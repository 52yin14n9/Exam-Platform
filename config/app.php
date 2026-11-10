<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'name' => Env::get('APP_NAME', '网络安全学生考核平台'),
    'env' => Env::get('APP_ENV', 'production'),
    'debug' => Env::bool('APP_DEBUG', false),
    'url' => rtrim(Env::get('APP_URL', ''), '/'),
    'timezone' => Env::get('APP_TIMEZONE', 'Asia/Shanghai'),
    'database' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::int('DB_PORT', 3306),
        'database' => Env::get('DB_DATABASE', 'cyber_exam'),
        'username' => Env::get('DB_USERNAME', 'cyber_exam'),
        'password' => Env::get('DB_PASSWORD', ''),
    ],
    'keys' => [
        'student_secret' => Env::get('STUDENT_KEY_SECRET', ''),
        'admin_secret' => Env::get('ADMIN_KEY_SECRET', ''),
    ],
    'session' => [
        'name' => Env::get('SESSION_NAME', 'cyber_exam_session'),
        'idle_minutes' => Env::int('SESSION_IDLE_MINUTES', 60),
        'absolute_hours' => Env::int('SESSION_ABSOLUTE_HOURS', 12),
        'secure_cookie' => strtolower(Env::get('SESSION_SECURE_COOKIE', 'auto')),
        'same_site' => Env::get('SESSION_SAME_SITE', 'Lax'),
    ],
    'upload' => [
        'dir' => dirname(__DIR__) . '/storage/uploads',
        'max_bytes' => Env::int('UPLOAD_MAX_BYTES', 10 * 1024 * 1024),
        'max_pixels' => Env::int('UPLOAD_MAX_PIXELS', 40_000_000),
        'max_per_answer' => Env::int('UPLOAD_MAX_PER_ANSWER', 5),
    ],
];
