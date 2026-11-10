<?php use App\Core\Csrf; use App\Core\View; $q=$question??[];$options=$q['options']??[];$correct=$q['correct']??[];$type=$q['question_type']??'single'; ?>
<div class="page-head"><div><h1><?= $question?'编辑理论题':'新建理论题' ?></h1><p>支持单选、多选、判断和填空；解析可留空，原题顺序用于全题库顺序学习。</p></div><a class="btn" href="/admin/practice-questions">返回题库</a></div>
<form class="card" method="post"><?= Csrf::field() ?>
<div class="form-grid">
<div class="field"><label>题型</label><select name="question_type"><option value="single" <?= $type==='single'?'selected':'' ?>>单选题</option><option value="multiple" <?= $type==='multiple'?'selected':'' ?>>多选题</option><option value="judge" <?= $type==='judge'?'selected':'' ?>>判断题</option><option value="fill" <?= $type==='fill'?'selected':'' ?>>填空题</option></select></div>
<div class="field"><label>状态</label><select name="status"><option value="active">可用</option><option value="draft" <?= ($q['status']??'')==='draft'?'selected':'' ?>>草稿</option><option value="disabled" <?= ($q['status']??'')==='disabled'?'selected':'' ?>>停用</option></select></div>
<div class="field full"><label>标题</label><input name="title" maxlength="180" required value="<?= View::e($q['title']??'') ?>"></div>
<div class="field full"><label>题干</label><textarea name="prompt" required><?= View::e($q['prompt']??'') ?></textarea></div>
</div>

<h3 class="section-title top-gap">单选 / 多选设置</h3><p class="hint">仅单选、多选题使用。通常单选只有一个正确答案；如官方题库原文存在多答案等异常，则按原文保留。</p>
<div class="form-grid">
<?php foreach(['A','B','C','D','E','F']as$letter):?><div class="field"><label>选项 <?= $letter ?></label><div class="toolbar"><input name="option_<?= strtolower($letter) ?>" value="<?= View::e($options[$letter]??'') ?>"><label class="check"><input type="checkbox" name="correct_answers[]" value="<?= $letter ?>" <?= in_array($letter,$correct,true)?'checked':'' ?>>正确</label></div></div><?php endforeach;?>
</div>

<h3 class="section-title top-gap">判断 / 填空设置</h3>
<div class="form-grid">
<div class="field"><label>判断题正确答案</label><select name="judge_answer"><option value="">请选择</option><option value="T" <?= $type==='judge'&&in_array('T',$correct,true)?'selected':'' ?>>对</option><option value="F" <?= $type==='judge'&&in_array('F',$correct,true)?'selected':'' ?>>错</option></select></div>
<div class="field"><label>填空题标准答案</label><input name="fill_answers" value="<?= View::e($type==='fill'?implode('|',$correct):'') ?>" placeholder="多个可接受答案用 | 分隔"></div>
<div class="field full"><label>答案解析（可选）</label><textarea name="explanation" placeholder="题库未提供解析时可留空"><?= View::e($q['explanation']??'') ?></textarea></div>
</div>

<h3 class="section-title top-gap">原题信息</h3>
<div class="form-grid">
<div class="field"><label>原题顺序</label><input type="number" min="1" name="source_order" value="<?= View::e($q['source_order']??'') ?>" placeholder="例如 386"></div>
<div class="field"><label>来源标识</label><input name="source_ref" value="<?= View::e($q['source_ref']??'') ?>" placeholder="例如 题库第 86 页 / 密码学-判断-17"></div>
<div class="field"><label>部分</label><input name="section" value="<?= View::e($q['section']??'') ?>" placeholder="基础题 / 专业题"></div>
<div class="field"><label>章节</label><input name="chapter" value="<?= View::e($q['chapter']??'') ?>" placeholder="密码学 / 标准题"></div>
<div class="field"><label>难度</label><select name="difficulty"><option value="easy" <?= ($q['difficulty']??'')==='easy'?'selected':'' ?>>简单</option><option value="medium" <?= ($q['difficulty']??'medium')==='medium'?'selected':'' ?>>中等</option><option value="hard" <?= ($q['difficulty']??'')==='hard'?'selected':'' ?>>困难</option></select></div>
<div class="field"><label>标签</label><input name="tags" value="<?= View::e($q['tags']??'') ?>"></div>
</div>
<div class="toolbar top-gap"><button class="btn btn-primary">保存理论题</button></div>
</form>
