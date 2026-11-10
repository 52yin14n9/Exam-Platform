<?php use App\Core\Csrf; use App\Core\View; $keyLength = $role === 'admin' ? 69 : 48; ?>
<section class="login-shell">
  <div class="login-art">
    <div class="eyebrow">Secure Assessment Workspace</div>
    <h1><?= View::e($siteName) ?></h1>
    <p>面向网络安全教学的私有考核空间。Key 仅用于建立安全会话，系统不会在页面、URL 或日志中回显完整凭证。</p>
  </div>
  <div class="login-form">
    <span class="badge"><?= $role === 'admin' ? '管理员入口' : '学生入口' ?></span>
    <h2><?= View::e($title) ?></h2>
    <p class="muted"><?= $role === 'admin' ? '管理员 Key 使用独立算法和独立验证通道。' : '请输入教师单独发放给你的学生 Key。' ?></p>
    <form method="post" action="/login/<?= View::e($role) ?>">
      <?= Csrf::field() ?>
      <div class="field"><label for="access_key">访问 Key</label><input id="access_key" class="code" name="access_key" type="password" autocomplete="off" spellcheck="false" minlength="<?= $keyLength ?>" maxlength="<?= $keyLength ?>" required autofocus></div>
      <button class="btn btn-primary" type="submit">安全登录</button>
    </form>
    <div class="divider"></div>
    <a href="/login/<?= $role === 'admin' ? 'student' : 'admin' ?>">切换到<?= $role === 'admin' ? '学生' : '管理员' ?>入口</a>
  </div>
</section>
