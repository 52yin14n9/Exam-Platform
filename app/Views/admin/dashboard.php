<?php use App\Core\View; ?>
<div class="page-head"><div><div class="eyebrow">Administration</div><h1>管理概览</h1><p>两个题库、考核进度与待阅卷状态集中查看。</p></div></div>
<section class="grid grid-4">
  <article class="card stat"><strong><?= $stats['students'] ?></strong><span>有效学生</span></article>
  <article class="card stat"><strong><?= $stats['assessmentQuestions'] ?></strong><span>考核题库题目</span></article>
  <article class="card stat"><strong><?= $stats['practiceQuestions'] ?></strong><span>选择题题库题目</span></article>
  <article class="card stat"><strong><?= $stats['pendingGrades'] ?></strong><span>待人工阅卷</span></article>
</section>
<section class="grid grid-2 top-gap">
  <article class="card"><h2 class="section-title">考核工作台</h2><p class="muted">主观题与靶场实践题，学生提交后由你逐题评分。</p><div class="toolbar"><a class="btn btn-primary" href="/admin/assessment-questions/create">新建考核题</a><a class="btn" href="/admin/exams">创建考试</a><a class="btn" href="/admin/grading">开始阅卷</a></div></article>
  <article class="card"><h2 class="section-title">选择题刷题库</h2><p class="muted">支持单选、多选、CSV 导入、顺序练习和随机练习。</p><div class="toolbar"><a class="btn btn-primary" href="/admin/practice-questions/create">新建选择题</a><a class="btn" href="/admin/practice-sets">开放刷题</a></div></article>
</section>
<section class="card"><h2 class="section-title">最近操作</h2><?php if(!$recent):?><div class="empty">暂无操作记录</div><?php else:?><div class="table-wrap"><table><thead><tr><th>时间</th><th>操作者</th><th>动作</th><th>对象</th></tr></thead><tbody><?php foreach($recent as $row):?><tr><td><?= View::e($row['created_at']) ?></td><td><?= View::e($row['actor_name']) ?></td><td class="code"><?= View::e($row['action']) ?></td><td><?= View::e($row['target_type']) ?> #<?= View::e($row['target_id']) ?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
