<?php use App\Core\View; ?>
<section class="card error-card">
  <h1>系统暂时不可用</h1>
  <p>请求未能完成，请稍后重试或联系管理员查看服务器日志。</p>
  <p class="muted">错误编号：<code><?= View::e($requestId ?? 'unknown') ?></code></p>
</section>
