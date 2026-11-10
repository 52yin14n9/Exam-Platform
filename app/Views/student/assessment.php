<?php use App\Core\Csrf; use App\Core\View; ?>
<div class="page-head"><div><h1><?= View::e($assignment['exam_title']) ?></h1><p><?= View::e($assignment['description']) ?></p><div class="exam-meta"><span>开始：<?= View::e($assignment['started_at']) ?></span><span>截止：<?= View::e($assignment['closes_at']??'不限') ?></span></div></div></div>
<form method="post" action="/student/assessment/<?= $assignment['id'] ?>/save" enctype="multipart/form-data" data-autosave>
<?= Csrf::field() ?><input type="hidden" name="upload_question_id" value="">
<?php foreach($questions as$q):?>
<article class="card question-card">
  <div class="question-number">第 <?= $q['display_order'] ?> 题 · <?= $q['question_type']==='practical'?'实践题':'主观题' ?> · <?= View::e($q['score']) ?> 分</div>
  <h3><?= View::e($q['title']) ?></h3><div class="prompt"><?= View::e($q['prompt']) ?></div>
  <?php if($q['target_url']):?><p><a class="btn btn-ghost" href="<?= View::e($q['target_url']) ?>" target="_blank" rel="noopener noreferrer">打开靶场</a></p><?php endif;?>
  <div class="field top-gap"><label>你的答案</label><textarea name="answers[<?= $q['id'] ?>]" placeholder="请输入答题思路、命令、步骤或结果…"><?= View::e($q['answer_text']) ?></textarea></div>
  <?php if($q['question_type']==='practical'):?>
    <div class="divider"></div><div class="field"><label>实践截图（<?= (int)$q['screenshot_min'] ?>～<?= (int)$q['screenshot_max'] ?> 张，仅 JPG/PNG/GIF）</label><input type="file" accept="image/jpeg,image/png,image/gif" data-upload-question="<?= $q['id'] ?>"></div>
    <?php if(!empty($uploads[$q['answer_id']])):?><div class="evidence-grid top-gap"><?php foreach($uploads[$q['answer_id']]as$u):?><div class="evidence"><a href="/evidence/<?= $u['id'] ?>" target="_blank"><img src="/evidence/<?= $u['id'] ?>" alt="已上传截图"></a><button class="btn btn-sm btn-danger" type="submit" formaction="/student/uploads/<?= $u['id'] ?>/delete" formmethod="post" data-confirm-button="确定删除这张截图？">删除</button></div><?php endforeach;?></div><?php endif;?>
  <?php endif;?>
</article>
<?php endforeach;?>
<div class="sticky-actions"><span data-save-status class="muted">系统每 15 秒自动保存</span><div class="toolbar"><button type="button" class="btn" data-save-now>立即保存</button><button class="btn btn-primary" type="submit" formaction="/student/assessment/<?= $assignment['id'] ?>/submit" formmethod="post" data-confirm-button="提交后不能自行修改，确定提交整份试卷？">最终提交</button></div></div>
</form>
