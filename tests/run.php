<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\KeyManager;

if (!defined('BASE_PATH')) require dirname(__DIR__) . '/app/bootstrap.php';

$failures = 0;
$test = static function (string $name, bool $condition) use (&$failures): void {
    if (!$condition) $failures++;
    echo sprintf("[%s] %s\n", $condition ? '通过' : '失败', $name);
};

try {
    KeyManager::assertConfiguration();
    $test('Key Secret 配置有效且相互独立', true);
} catch (Throwable) {
    $test('Key Secret 配置有效且相互独立', false);
}

$student = KeyManager::generateStudent();
$admin = KeyManager::generateAdmin();
$test('学生 Key 格式和长度', strlen($student) === 48 && KeyManager::validStudentFormat($student));
$test('管理员 Key 格式和长度', strlen($admin) === 69 && KeyManager::validAdminFormat($admin));
$test('学生算法拒绝管理员 Key', KeyManager::digestStudent($admin) === '');
$test('管理员算法拒绝学生 Key', KeyManager::digestAdmin($student) === '');
$test('学生摘要为 SHA-256 长度', strlen(KeyManager::digestStudent($student)) === 64);
$test('管理员摘要为 SHA-512 长度', strlen(KeyManager::digestAdmin($admin)) === 128);

$routes = (string) file_get_contents(BASE_PATH . '/public/index.php');
preg_match_all('/\$router->post\([^;]+;/', $routes, $matches);
$unprotected = array_filter($matches[0] ?? [], static fn(string $route): bool => !str_contains($route, '$csrf'));
$test('所有 POST 路由启用 CSRF', $unprotected === []);
$test('管理员权限中间件仍存在', str_contains($routes, '[$admin]') && str_contains($routes, '[$csrf, $admin]'));
$test('学生权限中间件仍存在', str_contains($routes, '[$student]') && str_contains($routes, '[$csrf, $student]'));

$authCode = (string) file_get_contents(BASE_PATH . '/app/Core/Auth.php');
$migration = (string) file_get_contents(BASE_PATH . '/database/migrations/002_login_audit_index.sql');
$test('受保护请求校验账号会话版本', str_contains($authCode, 'accountStillValid') && str_contains($authCode, "status='active'"));
$test('数据库迁移包含管理员和学生会话版本', substr_count($migration, 'session_version') >= 2);

$login = (string) file_get_contents(BASE_PATH . '/app/Views/auth/login.php');
$test('登录表单字段与控制器一致', str_contains($login, 'name="access_key"'));

$studyMigration = (string) file_get_contents(BASE_PATH . '/database/migrations/003_study_mode.sql');
$test('顺序学习迁移包含轮次、进度与历史表', str_contains($studyMigration, 'study_rounds') && str_contains($studyMigration, 'student_question_progress') && str_contains($studyMigration, 'student_question_answers'));
$test('理论题型扩展包含判断和填空', str_contains($studyMigration, "'judge'") && str_contains($studyMigration, "'fill'"));
$test('顺序学习与复习路由存在', str_contains($routes, "'/student/study/{id}'") && str_contains($routes, "'/student/review-center'") && str_contains($routes, "'/student/review'"));

$template = BASE_PATH . '/database/practice_questions_template.csv';
$test('理论题 CSV 模板存在', is_readable($template));

echo $failures === 0 ? "全部自检通过。\n" : "自检失败 {$failures} 项。\n";
if ($failures > 0) exit(1);
