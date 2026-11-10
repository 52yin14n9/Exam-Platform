<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\View;
$auth = Auth::user();
$pageTitle = isset($title) ? $title . ' · ' . ($siteName ?? '安全考核平台') : ($siteName ?? '安全考核平台');
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title><?= View::e($pageTitle) ?></title>
  <link rel="stylesheet" href="/assets/app.css">
  <link rel="stylesheet" href="/assets/utilities.css">
</head>
<body class="<?= $auth ? 'has-shell' : 'auth-page' ?>">
<?php if ($auth): ?>
  <header class="topbar">
    <a class="brand" href="<?= $auth['role'] === 'admin' ? '/admin' : '/student' ?>">
      <span class="brand-mark">NX</span><span><?= View::e($siteName ?? '安全考核平台') ?></span>
    </a>
    <div class="topbar-user">
      <span class="role-pill"><?= $auth['role'] === 'admin' ? '管理员' : '学生' ?></span>
      <strong><?= View::e($auth['name']) ?></strong>
      <form method="post" action="/logout"><?= Csrf::field() ?><button class="link-button" type="submit">退出</button></form>
    </div>
  </header>
  <aside class="sidebar">
    <?php if ($auth['role'] === 'admin'): ?>
      <a href="/admin">概览</a><a href="/admin/students">学生与 Key</a><a href="/admin/assessment-questions">考核题库</a><a href="/admin/exams">考核考试</a><a href="/admin/grading">人工阅卷</a><a href="/admin/practice-questions">理论刷题题库</a><a href="/admin/practice-sets">模拟练习开放</a><a href="/admin/settings">网站设置</a>
    <?php else: ?>
      <a href="/student">学习中心</a><a href="/student/review-center">我的复习</a>
    <?php endif; ?>
  </aside>
<?php endif; ?>
<main class="<?= $auth ? 'main' : 'auth-main' ?>">
  <?php foreach (Flash::pull() as $flash): ?><div class="flash flash-<?= View::e($flash['type']) ?>" role="status"><?= View::e($flash['message']) ?></div><?php endforeach; ?>
  <?= $content ?>
</main>
<script src="/assets/app.js" defer></script>
</body>
</html>
