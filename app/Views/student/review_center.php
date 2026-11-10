<?php use App\Core\View; $c=$counts??[]; ?>
<div class="page-head"><div><h1>我的复习</h1><p>错题历史永久保留；“未掌握”和“待复习”会随着后续复习动态变化。</p></div><a class="btn" href="/student">返回学习中心</a></div>
<section class="grid grid-3">
<a class="card review-entry" href="/student/review?mode=unmastered"><strong><?= (int)($c['unmastered']??0) ?></strong><h3>未掌握错题</h3><p>最近仍答错或仍处于复习队列。</p></a>
<a class="card review-entry" href="/student/review?mode=wrong"><strong><?= (int)($c['wrong']??0) ?></strong><h3>全部历史错题</h3><p>曾经答错过的题，答对后也不会消失。</p></a>
<a class="card review-entry" href="/student/review?mode=review"><strong><?= (int)($c['review']??0) ?></strong><h3>待复习</h3><p>系统自动安排或你主动标记的题。</p></a>
<a class="card review-entry" href="/student/review?mode=due"><strong><?= (int)($c['due']??0) ?></strong><h3>今日到期</h3><p>按照错题后的复习节奏，到今天应再次看的题。</p></a>
<a class="card review-entry" href="/student/review?mode=favorite"><strong><?= (int)($c['favorite']??0) ?></strong><h3>收藏题</h3><p>你主动收藏的重点、典型题。</p></a>
</section>
