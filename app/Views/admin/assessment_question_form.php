<?php use App\Core\Csrf; use App\Core\View; $q=$question??[]; ?>
<div class="page-head"><div><h1><?= $question?'编辑考核题':'新建考核题' ?></h1><p>每道正式题目必须包含参考答案。</p></div><a class="btn" href="/admin/assessment-questions">返回题库</a></div>
<form class="card" method="post"><?= Csrf::field() ?><div class="form-grid">
  <div class="field"><label>题型</label><select name="question_type" data-toggle-target="#practical-fields" data-toggle-value="practical"><option value="subjective" <?= ($q['question_type']??'')==='subjective'?'selected':'' ?>>理论主观题</option><option value="practical" <?= ($q['question_type']??'')==='practical'?'selected':'' ?>>靶场实践题</option></select></div>
  <div class="field"><label>状态</label><select name="status"><option value="draft">草稿</option><option value="active" <?= ($q['status']??'')==='active'?'selected':'' ?>>可用</option><option value="disabled" <?= ($q['status']??'')==='disabled'?'selected':'' ?>>停用</option></select></div>
  <div class="field full"><label>题目标题</label><input name="title" required maxlength="180" value="<?= View::e($q['title']??'') ?>"></div>
  <div class="field full"><label>题干</label><textarea name="prompt" required><?= View::e($q['prompt']??'') ?></textarea></div>
  <div class="field full"><label>参考答案</label><textarea name="reference_answer" required><?= View::e($q['reference_answer']??'') ?></textarea></div>
  <div class="field"><label>默认分值</label><input name="default_score" type="number" min="0.5" step="0.5" required value="<?= View::e($q['default_score']??10) ?>"></div>
  <div class="field"><label>难度</label><select name="difficulty"><option value="easy">简单</option><option value="medium" <?= ($q['difficulty']??'medium')==='medium'?'selected':'' ?>>中等</option><option value="hard" <?= ($q['difficulty']??'')==='hard'?'selected':'' ?>>困难</option></select></div>
  <div class="field full"><label>标签</label><input name="tags" value="<?= View::e($q['tags']??'') ?>" placeholder="Web, 渗透测试, Linux"></div>
  <div class="full grid grid-3" id="practical-fields"><div class="field"><label>靶场网址</label><input name="target_url" type="url" value="<?= View::e($q['target_url']??'') ?>" placeholder="https://lab.example.com"></div><div class="field"><label>最少截图数</label><input name="screenshot_min" type="number" min="0" max="5" value="<?= View::e($q['screenshot_min']??1) ?>"></div><div class="field"><label>最多截图数</label><input name="screenshot_max" type="number" min="1" max="5" value="<?= View::e($q['screenshot_max']??5) ?>"></div></div>
</div><div class="toolbar top-gap"><button class="btn btn-primary">保存题目</button></div></form>
