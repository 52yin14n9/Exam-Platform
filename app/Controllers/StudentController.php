<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Core\Response;
use App\Core\UploadService;
use PDO;

final class StudentController extends Controller
{
    public function dashboard(): void
    {
        $pdo=Database::connection();$studentId=Auth::id();
        $a=$pdo->prepare('SELECT a.*,e.title,e.description,e.opens_at,e.closes_at,e.duration_minutes,e.status exam_status,e.results_released_at FROM assessment_assignments a JOIN assessment_exams e ON e.id=a.exam_id WHERE a.student_id=? AND e.status IN("published","closed") ORDER BY e.id DESC');$a->execute([$studentId]);$assessments=$a->fetchAll();
        $p=$pdo->prepare('SELECT p.*,COUNT(a.id) attempt_count,MAX(a.score_percent) best_score FROM practice_sets p LEFT JOIN practice_attempts a ON a.practice_set_id=p.id AND a.student_id=? WHERE p.status="open" AND (p.opens_at IS NULL OR p.opens_at<=UTC_TIMESTAMP()) AND (p.closes_at IS NULL OR p.closes_at>=UTC_TIMESTAMP()) GROUP BY p.id ORDER BY p.id DESC');$p->execute([$studentId]);$practiceSets=$p->fetchAll();
        $history=$pdo->prepare('SELECT a.*,p.title FROM practice_attempts a JOIN practice_sets p ON p.id=a.practice_set_id WHERE a.student_id=? AND a.status="submitted" ORDER BY a.id DESC LIMIT 10');$history->execute([$studentId]);

        $studyStats=['total'=>0,'completed'=>0,'wrong'=>0,'favorite'=>0,'review'=>0,'due'=>0];
        $studyStats['total']=(int)$pdo->query('SELECT COUNT(*) FROM practice_questions WHERE status="active"')->fetchColumn();
        $stats=$pdo->prepare('SELECT SUM(attempt_count>0) completed,SUM(wrong_count>0) wrong,SUM(favorite=1) favorite,SUM(needs_review=1) review,SUM(next_review_at IS NOT NULL AND next_review_at<=UTC_TIMESTAMP()) due FROM student_question_progress WHERE student_id=?');$stats->execute([$studentId]);
        if($row=$stats->fetch()){foreach(['completed','wrong','favorite','review','due']as$key)$studyStats[$key]=(int)($row[$key]??0);}
        $current=$pdo->prepare('SELECT * FROM study_rounds WHERE student_id=? AND status="in_progress" ORDER BY round_number DESC LIMIT 1');$current->execute([$studentId]);$currentRound=$current->fetch()?:null;
        $rounds=$pdo->prepare('SELECT * FROM study_rounds WHERE student_id=? ORDER BY round_number DESC LIMIT 6');$rounds->execute([$studentId]);$studyRounds=$rounds->fetchAll();
        $chapters=$pdo->prepare('SELECT COALESCE(q.section,"未分类") section,COALESCE(q.chapter,"未分类") chapter,COUNT(*) total,SUM(CASE WHEN p.attempt_count>0 THEN 1 ELSE 0 END) completed FROM practice_questions q LEFT JOIN student_question_progress p ON p.question_id=q.id AND p.student_id=? WHERE q.status="active" GROUP BY q.section,q.chapter ORDER BY MIN(COALESCE(q.source_order,4294967295)),q.chapter');$chapters->execute([$studentId]);$chapterProgress=$chapters->fetchAll();
        $this->render('student/dashboard',['title'=>'学习中心','assessments'=>$assessments,'practiceSets'=>$practiceSets,'practiceHistory'=>$history->fetchAll(),'studyStats'=>$studyStats,'currentRound'=>$currentRound,'studyRounds'=>$studyRounds,'chapterProgress'=>$chapterProgress]);
    }

    public function startAssessment(string $id): void
    {
        $assignmentId=(int)$id;$pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT a.*,e.opens_at,e.closes_at,e.status exam_status FROM assessment_assignments a JOIN assessment_exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=?');$stmt->execute([$assignmentId,Auth::id()]);$a=$stmt->fetch();
        if(!$a||!in_array($a['exam_status'],['published','closed'],true)){Flash::set('error','考试不存在或不可用。');Response::redirect('/student');}
        if($a['opens_at']&&strtotime($a['opens_at'].' UTC')>time()){Flash::set('warning','考试尚未开放。');Response::redirect('/student');}
        if($a['closes_at']&&strtotime($a['closes_at'].' UTC')<time()&&$a['status']==='assigned'){Flash::set('warning','考试已经结束。');Response::redirect('/student');}
        if(in_array($a['status'],['submitted','graded'],true))Response::redirect("/student/assessment/{$assignmentId}/result");
        Database::transaction(function(PDO $pdo)use($a,$assignmentId){
            $pdo->prepare('UPDATE assessment_assignments SET status="in_progress",started_at=COALESCE(started_at,UTC_TIMESTAMP()) WHERE id=? AND status IN("assigned","returned")')->execute([$assignmentId]);
            $pdo->prepare('INSERT IGNORE INTO assessment_answers(assignment_id,exam_question_id,answer_text) SELECT ?,id,"" FROM assessment_exam_questions WHERE exam_id=?')->execute([$assignmentId,$a['exam_id']]);
        });
        Audit::log('assessment.started','assessment_assignment',$assignmentId);Response::redirect("/student/assessment/{$assignmentId}");
    }

    public function assessment(string $id): void
    {
        [$assignment,$questions,$uploads]=$this->assessmentData((int)$id);
        if(!$assignment){http_response_code(404);$this->render('errors/404',['title'=>'答卷不存在']);return;}
        if($assignment['status']==='assigned'){Flash::set('warning','请从学习中心开始考试。');Response::redirect('/student');}
        if(in_array($assignment['status'],['submitted','graded'],true))Response::redirect("/student/assessment/{$id}/result");
        if($this->deadlinePassed($assignment)){ $this->submitAssessment($id,true); return; }
        $this->render('student/assessment',['title'=>$assignment['exam_title'],'assignment'=>$assignment,'questions'=>$questions,'uploads'=>$uploads]);
    }

    private function assessmentData(int $id): array
    {
        $pdo=Database::connection();$stmt=$pdo->prepare('SELECT a.*,e.title exam_title,e.description,e.opens_at,e.closes_at,e.duration_minutes,e.answer_release,e.answers_released_at,e.results_released_at FROM assessment_assignments a JOIN assessment_exams e ON e.id=a.exam_id WHERE a.id=? AND a.student_id=?');$stmt->execute([$id,Auth::id()]);$assignment=$stmt->fetch();if(!$assignment)return[null,[],[]];
        $q=$pdo->prepare('SELECT q.*,ans.id answer_id,ans.answer_text,ans.score answer_score,ans.feedback FROM assessment_exam_questions q JOIN assessment_answers ans ON ans.exam_question_id=q.id AND ans.assignment_id=? WHERE q.exam_id=? ORDER BY q.display_order');$q->execute([$id,$assignment['exam_id']]);$questions=$q->fetchAll();$uploads=[];$ids=array_column($questions,'answer_id');
        if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$u=$pdo->prepare("SELECT * FROM answer_uploads WHERE answer_id IN ({$in}) ORDER BY id");$u->execute($ids);foreach($u->fetchAll()as$row)$uploads[$row['answer_id']][]=$row;}
        return[$assignment,$questions,$uploads];
    }

    private function deadlinePassed(array $assignment): bool
    {
        $deadlines=[];if($assignment['closes_at'])$deadlines[]=strtotime($assignment['closes_at'].' UTC');if($assignment['duration_minutes']&&$assignment['started_at'])$deadlines[]=strtotime($assignment['started_at'].' UTC')+(int)$assignment['duration_minutes']*60;
        return $deadlines&&time()>=min($deadlines);
    }

    public function saveAssessment(string $id): void
    {
        $assignmentId=(int)$id;[$assignment,$questions]=$this->assessmentData($assignmentId);
        if(!$assignment||!in_array($assignment['status'],['in_progress','returned'],true)||$this->deadlinePassed($assignment))Response::json(['ok'=>false,'message'=>'答卷已锁定或已到期。'],409);
        $allowed=array_map('intval',array_column($questions,'id'));$answers=(array)($_POST['answers']??[]);$pdo=Database::connection();$stmt=$pdo->prepare('UPDATE assessment_answers SET answer_text=?,saved_at=UTC_TIMESTAMP() WHERE assignment_id=? AND exam_question_id=?');
        foreach($answers as$qid=>$text){$qid=(int)$qid;if(in_array($qid,$allowed,true))$stmt->execute([mb_substr(trim((string)$text),0,50000),$assignmentId,$qid]);}
        if(isset($_FILES['screenshot'])&&($_FILES['screenshot']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
            $questionId=(int)($_POST['upload_question_id']??0);$question=null;foreach($questions as$q)if((int)$q['id']===$questionId)$question=$q;
            if(!$question||$question['question_type']!=='practical')Response::json(['ok'=>false,'message'=>'上传目标无效。'],422);
            $count=$pdo->prepare('SELECT COUNT(*) FROM answer_uploads WHERE answer_id=?');$count->execute([$question['answer_id']]);if((int)$count->fetchColumn()>=(int)$question['screenshot_max'])Response::json(['ok'=>false,'message'=>'该题截图数量已达上限。'],422);
            try{
                $meta=UploadService::store($_FILES['screenshot'],(int)$question['answer_id']);
                try {
                    $pdo->prepare('INSERT INTO answer_uploads(answer_id,original_name,storage_path,mime_type,size_bytes,sha256,width,height) VALUES(?,?,?,?,?,?,?,?)')->execute([(int)$question['answer_id'],$meta['original_name'],$meta['storage_path'],$meta['mime_type'],$meta['size_bytes'],$meta['sha256'],$meta['width'],$meta['height']]);
                } catch (\Throwable $error) {
                    $stored=rtrim((string)Config::get('upload.dir'),'/').'/'.$meta['storage_path'];
                    if(is_file($stored))@unlink($stored);
                    throw $error;
                }
            }catch(\RuntimeException$e){Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}catch(\Throwable$e){ErrorHandler::report($e,'upload.metadata');Response::json(['ok'=>false,'message'=>'图片保存失败，请稍后重试。'],500);}
        }
        Response::json(['ok'=>true,'saved_at'=>date('H:i:s')]);
    }

    public function deleteUpload(string $id): void
    {
        $pdo=Database::connection();$stmt=$pdo->prepare('SELECT u.*,a.id assignment_id FROM answer_uploads u JOIN assessment_answers ans ON ans.id=u.answer_id JOIN assessment_assignments a ON a.id=ans.assignment_id WHERE u.id=? AND a.student_id=? AND a.status IN("in_progress","returned")');$stmt->execute([(int)$id,Auth::id()]);$upload=$stmt->fetch();
        if($upload){$path=rtrim((string)Config::get('upload.dir'),'/').'/'.$upload['storage_path'];if(is_file($path))unlink($path);$pdo->prepare('DELETE FROM answer_uploads WHERE id=?')->execute([(int)$id]);}
        Response::redirect($upload?'/student/assessment/'.(int)$upload['assignment_id']:'/student');
    }

    public function submitAssessment(string $id,bool $automatic=false): void
    {
        $assignmentId=(int)$id;[$assignment,$questions,$uploads]=$this->assessmentData($assignmentId);
        if(!$assignment||!in_array($assignment['status'],['in_progress','returned'],true)){if($automatic)Response::redirect('/student');Flash::set('error','答卷无法提交。');Response::redirect('/student');}
        if($this->deadlinePassed($assignment))$automatic=true;
        if(!$automatic&&!empty($_POST['answers'])&&is_array($_POST['answers'])){$allowed=array_map('intval',array_column($questions,'id'));$save=Database::connection()->prepare('UPDATE assessment_answers SET answer_text=?,saved_at=UTC_TIMESTAMP() WHERE assignment_id=? AND exam_question_id=?');foreach($_POST['answers']as$qid=>$text){$qid=(int)$qid;if(in_array($qid,$allowed,true))$save->execute([mb_substr(trim((string)$text),0,50000),$assignmentId,$qid]);}}
        if(!$automatic){foreach($questions as$q){if($q['question_type']==='practical'&&count($uploads[$q['answer_id']]??[])<(int)$q['screenshot_min']){Flash::set('error',"第 {$q['display_order']} 题截图数量不足。");Response::redirect("/student/assessment/{$assignmentId}");}}}
        Database::connection()->prepare('UPDATE assessment_assignments SET status="submitted",submitted_at=UTC_TIMESTAMP() WHERE id=? AND student_id=?')->execute([$assignmentId,Auth::id()]);Audit::log($automatic?'assessment.auto_submitted':'assessment.submitted','assessment_assignment',$assignmentId);Flash::set('success',$automatic?'考试到期，系统已自动提交已保存内容。':'试卷提交成功。');Response::redirect("/student/assessment/{$assignmentId}/result");
    }

    public function assessmentResult(string $id): void
    {
        [$assignment,$questions,$uploads]=$this->assessmentData((int)$id);if(!$assignment){http_response_code(404);return;}
        if(!in_array($assignment['status'],['submitted','graded'],true)){
            if(in_array($assignment['status'],['in_progress','returned'],true))Response::redirect("/student/assessment/{$id}");
            Flash::set('warning','请先开始并提交考试。');Response::redirect('/student');
        }
        $answersVisible=$assignment['answer_release']==='immediate'||$assignment['answers_released_at']||($assignment['answer_release']==='after_close'&&$assignment['closes_at']&&strtotime($assignment['closes_at'].' UTC')<=time());
        $resultsVisible=(bool)$assignment['results_released_at'];
        $this->render('student/assessment_result',['title'=>'提交结果','assignment'=>$assignment,'questions'=>$questions,'uploads'=>$uploads,'answersVisible'=>$answersVisible,'resultsVisible'=>$resultsVisible]);
    }

    public function startPractice(string $id): void
    {
        $setId=(int)$id;$mode=in_array($_POST['question_mode']??'', ['sequential','random'],true)?$_POST['question_mode']:'random';$pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT * FROM practice_sets WHERE id=? AND status="open" AND (opens_at IS NULL OR opens_at<=UTC_TIMESTAMP()) AND (closes_at IS NULL OR closes_at>=UTC_TIMESTAMP())');$stmt->execute([$setId]);$set=$stmt->fetch();if(!$set){Flash::set('error','该练习当前未开放。');Response::redirect('/student');}
        $types=explode(',',$set['allowed_types']);$in=implode(',',array_fill(0,count($types),'?'));$where="status='active' AND question_type IN ({$in})";$params=$types;if($set['tags_filter']){$where.=' AND tags LIKE ?';$params[]='%'.$set['tags_filter'].'%';}$order=$mode==='random'?'RAND()':'id ASC';$q=$pdo->prepare("SELECT * FROM practice_questions WHERE {$where} ORDER BY {$order} LIMIT ".(int)$set['question_count']);$q->execute($params);$questions=$q->fetchAll();
        if(count($questions)<(int)$set['question_count']){Flash::set('error','当前可用题量不足，请联系管理员。');Response::redirect('/student');}
        $attemptId=Database::transaction(function(PDO$pdo)use($set,$mode,$questions){$pdo->prepare('INSERT INTO practice_attempts(practice_set_id,student_id,question_mode,total_count) VALUES(?,?,?,?)')->execute([$set['id'],Auth::id(),$mode,count($questions)]);$attemptId=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO practice_attempt_questions(attempt_id,source_question_id,display_order,question_type,title,prompt,options_json,correct_answers_json,explanation) VALUES(?,?,?,?,?,?,?,?,?)');foreach($questions as$i=>$q)$ins->execute([$attemptId,$q['id'],$i+1,$q['question_type'],$q['title'],$q['prompt'],$q['options_json'],$q['correct_answers_json'],$q['explanation']]);return$attemptId;});
        Audit::log('practice.started','practice_attempt',$attemptId,['mode'=>$mode]);Response::redirect("/student/practice-attempt/{$attemptId}");
    }

    public function practice(string $id): void
    {
        [$attempt,$questions]=$this->practiceData((int)$id);if(!$attempt){http_response_code(404);$this->render('errors/404',['title'=>'练习不存在']);return;}if($attempt['status']==='submitted')Response::redirect("/student/practice-attempt/{$id}/result");
        $this->render('student/practice',['title'=>$attempt['set_title'],'attempt'=>$attempt,'questions'=>$questions]);
    }

    private function practiceData(int$id):array
    {
        $pdo=Database::connection();$stmt=$pdo->prepare('SELECT a.*,p.title set_title,p.description,p.status set_status,p.closes_at FROM practice_attempts a JOIN practice_sets p ON p.id=a.practice_set_id WHERE a.id=? AND a.student_id=?');$stmt->execute([$id,Auth::id()]);$attempt=$stmt->fetch();if(!$attempt)return[null,[]];$q=$pdo->prepare('SELECT q.*,a.selected_answers_json,a.is_correct FROM practice_attempt_questions q LEFT JOIN practice_attempt_answers a ON a.attempt_question_id=q.id WHERE q.attempt_id=? ORDER BY q.display_order');$q->execute([$id]);return[$attempt,$q->fetchAll()];
    }

    public function submitPractice(string $id): void
    {
        $attemptId=(int)$id;[$attempt,$questions]=$this->practiceData($attemptId);if(!$attempt||$attempt['status']!=='in_progress'){Flash::set('error','练习无法提交。');Response::redirect('/student');}
        $selected=(array)($_POST['selected']??[]);$fills=(array)($_POST['fill']??[]);$correct=0;
        try{$percent=Database::transaction(function(PDO$pdo)use($questions,$selected,$fills,$attemptId,&$correct){$lock=$pdo->prepare('SELECT status FROM practice_attempts WHERE id=? AND student_id=? FOR UPDATE');$lock->execute([$attemptId,Auth::id()]);if($lock->fetchColumn()!=='in_progress')throw new \RuntimeException('attempt_already_submitted');$ins=$pdo->prepare('INSERT INTO practice_attempt_answers(attempt_question_id,selected_answers_json,is_correct) VALUES(?,?,?)');foreach($questions as$q){$expected=json_decode($q['correct_answers_json'],true)?:[];$answer=[];$ok=false;if($q['question_type']==='fill'){$text=trim((string)($fills[$q['id']]??''));$answer=[$text];$norm=static fn(string$v):string=>mb_strtolower((string)preg_replace('/\s+/u','',trim($v)));$ok=$text!==''&&in_array($norm($text),array_map($norm,$expected),true);}else{$valid=array_keys(json_decode($q['options_json'],true)?:[]);$answer=array_values(array_unique(array_intersect((array)($selected[$q['id']]??[]),$valid)));sort($answer);sort($expected);$ok=$answer===$expected;}if($ok)$correct++;$ins->execute([$q['id'],json_encode($answer,JSON_UNESCAPED_UNICODE),$ok?1:0]);}$percent=count($questions)?round($correct/count($questions)*100,2):0;$pdo->prepare('UPDATE practice_attempts SET status="submitted",correct_count=?,score_percent=?,submitted_at=UTC_TIMESTAMP() WHERE id=? AND status="in_progress"')->execute([$correct,$percent,$attemptId]);return$percent;});}catch(\RuntimeException$e){if($e->getMessage()==='attempt_already_submitted')Response::redirect("/student/practice-attempt/{$attemptId}/result");throw$e;}
        Audit::log('practice.submitted','practice_attempt',$attemptId,['score'=>$percent]);Response::redirect("/student/practice-attempt/{$attemptId}/result");
    }

    public function practiceResult(string $id): void
    {
        [$attempt,$questions]=$this->practiceData((int)$id);if(!$attempt||$attempt['status']!=='submitted'){http_response_code(404);return;}$this->render('student/practice_result',['title'=>'练习结果','attempt'=>$attempt,'questions'=>$questions]);
    }

    public function startStudy(): void
    {
        $pdo=Database::connection();$studentId=Auth::id();
        $existing=$pdo->prepare('SELECT id FROM study_rounds WHERE student_id=? AND status="in_progress" ORDER BY round_number DESC LIMIT 1');$existing->execute([$studentId]);
        if($id=$existing->fetchColumn())Response::redirect('/student/study/'.(int)$id);
        $questions=$pdo->query('SELECT * FROM practice_questions WHERE status="active" ORDER BY COALESCE(source_order,4294967295),id')->fetchAll();
        if(!$questions){Flash::set('warning','当前理论题库为空，请联系管理员导入题目。');Response::redirect('/student');}
        $roundId=Database::transaction(function(PDO$pdo)use($studentId,$questions){$lock=$pdo->prepare('SELECT id FROM students WHERE id=? FOR UPDATE');$lock->execute([$studentId]);$active=$pdo->prepare('SELECT id FROM study_rounds WHERE student_id=? AND status="in_progress" ORDER BY round_number DESC LIMIT 1');$active->execute([$studentId]);if($activeId=$active->fetchColumn())return(int)$activeId;$n=$pdo->prepare('SELECT COALESCE(MAX(round_number),0)+1 FROM study_rounds WHERE student_id=?');$n->execute([$studentId]);$roundNumber=(int)$n->fetchColumn();$pdo->prepare('INSERT INTO study_rounds(student_id,round_number,total_count) VALUES(?,?,?)')->execute([$studentId,$roundNumber,count($questions)]);$roundId=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO study_round_items(round_id,source_question_id,display_order,source_order,section,chapter,question_type,title,prompt,options_json,correct_answers_json,explanation) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');foreach($questions as$i=>$q)$ins->execute([$roundId,$q['id'],$i+1,$q['source_order'],$q['section'],$q['chapter'],$q['question_type'],$q['title'],$q['prompt'],$q['options_json'],$q['correct_answers_json'],$q['explanation']]);return$roundId;});
        Audit::log('study_round.started','study_round',$roundId,['total'=>count($questions)]);Response::redirect('/student/study/'.$roundId);
    }

    public function study(string $id): void
    {
        $roundId=(int)$id;$pdo=Database::connection();$r=$pdo->prepare('SELECT * FROM study_rounds WHERE id=? AND student_id=?');$r->execute([$roundId,Auth::id()]);$round=$r->fetch();if(!$round){http_response_code(404);$this->render('errors/404',['title'=>'学习轮次不存在']);return;}
        $position=max(0,(int)($_GET['n']??0));
        $next=$pdo->prepare('SELECT display_order FROM study_round_items WHERE round_id=? AND is_correct IS NULL ORDER BY display_order LIMIT 1');$next->execute([$roundId]);$nextPosition=(int)($next->fetchColumn()?:0);
        if($position<1)$position=$nextPosition?:$round['total_count'];
        if($round['status']==='in_progress'&&$nextPosition>0&&$position>$nextPosition)$position=$nextPosition;
        $position=min(max(1,$position),(int)$round['total_count']);
        $q=$pdo->prepare('SELECT i.*,p.favorite,p.needs_review,p.wrong_count,p.attempt_count FROM study_round_items i LEFT JOIN student_question_progress p ON p.question_id=i.source_question_id AND p.student_id=? WHERE i.round_id=? AND i.display_order=?');$q->execute([Auth::id(),$roundId,$position]);$question=$q->fetch();
        if(!$question){http_response_code(404);$this->render('errors/404',['title'=>'题目不存在']);return;}
        $this->render('student/study',['title'=>'顺序学习','round'=>$round,'question'=>$question,'position'=>$position]);
    }

    public function answerStudy(string $id,string $itemId): void
    {
        $roundId=(int)$id;$itemId=(int)$itemId;$studentId=Auth::id();$pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT i.*,r.status round_status,r.student_id FROM study_round_items i JOIN study_rounds r ON r.id=i.round_id WHERE i.id=? AND i.round_id=?');$stmt->execute([$itemId,$roundId]);$q=$stmt->fetch();
        if(!$q||((int)$q['student_id'])!==$studentId||$q['round_status']!=='in_progress'){Flash::set('error','该学习题目当前不可提交。');Response::redirect('/student');}
        $pos=(int)$q['display_order'];$url='/student/study/'.$roundId.'?n='.$pos;
        if($q['is_correct']!==null)Response::redirect($url.'&feedback=1');

        if($q['question_type']==='fill'&&$q['answered_at']===null){
            $text=trim((string)($_POST['answer_text']??''));if($text===''){Flash::set('warning','请先填写你的答案。');Response::redirect($url);}
            $u=$pdo->prepare('UPDATE study_round_items SET answer_json=?,answered_at=UTC_TIMESTAMP() WHERE id=? AND round_id=? AND is_correct IS NULL AND answered_at IS NULL');$u->execute([json_encode([$text],JSON_UNESCAPED_UNICODE),$itemId,$roundId]);Response::redirect($url.'&feedback=1');
        }

        if($q['question_type']==='fill'){
            $self=$_POST['self_result']??'';if(!in_array($self,['correct','review'],true)){Flash::set('warning','查看标准答案后，请选择“我答对了”或“需要复习”。');Response::redirect($url.'&feedback=1');}
            $ok=$self==='correct';$answer=json_decode($q['answer_json']??'[]',true)?:[];
        }else{
            $options=json_decode($q['options_json'],true)?:[];$valid=array_keys($options);$answer=array_values(array_unique(array_intersect((array)($_POST['answer']??[]),$valid)));if(!$answer){Flash::set('warning','请先选择答案。');Response::redirect($url);}sort($answer);$expected=json_decode($q['correct_answers_json'],true)?:[];sort($expected);$ok=$answer===$expected;
        }

        $saved=Database::transaction(function(PDO$pdo)use($roundId,$itemId,$studentId,$q,$answer,$ok){
            $lock=$pdo->prepare('SELECT i.is_correct,r.status FROM study_round_items i JOIN study_rounds r ON r.id=i.round_id WHERE i.id=? AND i.round_id=? AND r.student_id=? FOR UPDATE');$lock->execute([$itemId,$roundId,$studentId]);$state=$lock->fetch();if(!$state||$state['status']!=='in_progress'||$state['is_correct']!==null)return false;
            if($q['question_type']==='fill')$pdo->prepare('UPDATE study_round_items SET is_correct=?,self_reviewed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$ok?1:0,$itemId]);
            else$pdo->prepare('UPDATE study_round_items SET answer_json=?,is_correct=?,answered_at=UTC_TIMESTAMP() WHERE id=?')->execute([json_encode($answer,JSON_UNESCAPED_UNICODE),$ok?1:0,$itemId]);
            $this->recordQuestionProgress($pdo,$studentId,(int)$q['source_question_id'],$answer,$ok,'study',$itemId);$this->advanceRound($pdo,$roundId,$ok);return true;
        });
        Response::redirect($url.'&feedback=1');
    }

    private function advanceRound(PDO $pdo,int $roundId,bool $correct): void
    {
        $pdo->prepare('UPDATE study_rounds SET completed_count=completed_count+1,correct_count=correct_count+? WHERE id=? AND status="in_progress"')->execute([$correct?1:0,$roundId]);
        $s=$pdo->prepare('SELECT completed_count,total_count FROM study_rounds WHERE id=?');$s->execute([$roundId]);$row=$s->fetch();if($row&&(int)$row['completed_count']>=(int)$row['total_count'])$pdo->prepare('UPDATE study_rounds SET status="completed",completed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$roundId]);
    }

    private function recordQuestionProgress(PDO $pdo,int $studentId,int $questionId,array $answer,bool $correct,string $context,?int $roundItemId=null): void
    {
        if($questionId<1)return;
        $s=$pdo->prepare('SELECT * FROM student_question_progress WHERE student_id=? AND question_id=? FOR UPDATE');$s->execute([$studentId,$questionId]);$current=$s->fetch();$stage=(int)($current['review_stage']??0);$hadWrong=(int)($current['wrong_count']??0)>0;$needs=(int)($current['needs_review']??0)===1;$next=null;
        if(!$correct){$stage=0;$needs=true;$next=gmdate('Y-m-d H:i:s',time()+86400);}elseif($hadWrong||$needs){$stage=min(4,$stage+1);$days=[1=>3,2=>7,3=>15,4=>0][$stage]??0;if($days>0){$needs=true;$next=gmdate('Y-m-d H:i:s',time()+$days*86400);}else{$needs=false;$next=null;}}
        $json=json_encode(array_values($answer),JSON_UNESCAPED_UNICODE);
        if($current){$sql='UPDATE student_question_progress SET attempt_count=attempt_count+1,correct_count=correct_count+?,wrong_count=wrong_count+?,last_answer_json=?,last_is_correct=?,needs_review=?,review_stage=?,first_wrong_at=CASE WHEN ?=0 THEN COALESCE(first_wrong_at,UTC_TIMESTAMP()) ELSE first_wrong_at END,last_answered_at=UTC_TIMESTAMP(),next_review_at=? WHERE id=?';$pdo->prepare($sql)->execute([$correct?1:0,$correct?0:1,$json,$correct?1:0,$needs?1:0,$stage,$correct?1:0,$next,$current['id']]);}
        else{$pdo->prepare('INSERT INTO student_question_progress(student_id,question_id,attempt_count,correct_count,wrong_count,last_answer_json,last_is_correct,needs_review,review_stage,first_wrong_at,last_answered_at,next_review_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?)')->execute([$studentId,$questionId,1,$correct?1:0,$correct?0:1,$json,$correct?1:0,$needs?1:0,$stage,$correct?null:gmdate('Y-m-d H:i:s'),$next]);}
        $pdo->prepare('INSERT INTO student_question_answers(student_id,question_id,round_item_id,context,answer_json,is_correct) VALUES(?,?,?,?,?,?)')->execute([$studentId,$questionId,$roundItemId,$context,$json,$correct?1:0]);
    }

    public function toggleFavorite(string $id): void
    {
        $this->toggleProgressFlag((int)$id,'favorite');
    }

    public function toggleReviewFlag(string $id): void
    {
        $this->toggleProgressFlag((int)$id,'needs_review');
    }

    private function toggleProgressFlag(int $questionId,string $field): void
    {
        if(!in_array($field,['favorite','needs_review'],true))Response::redirect('/student');$pdo=Database::connection();$exists=$pdo->prepare('SELECT id FROM practice_questions WHERE id=? AND status="active"');$exists->execute([$questionId]);if(!$exists->fetchColumn()){Flash::set('error','题目不存在。');Response::redirect('/student');}
        Database::transaction(function(PDO$pdo)use($questionId,$field){$s=$pdo->prepare('SELECT id,'.$field.' flag FROM student_question_progress WHERE student_id=? AND question_id=? FOR UPDATE');$s->execute([Auth::id(),$questionId]);$row=$s->fetch();if($row){$new=((int)$row['flag'])===1?0:1;if($field==='needs_review'&&$new===0)$pdo->prepare('UPDATE student_question_progress SET needs_review=0,next_review_at=NULL,review_stage=0 WHERE id=?')->execute([$row['id']]);else$pdo->prepare('UPDATE student_question_progress SET '.$field.'=? WHERE id=?')->execute([$new,$row['id']]);}else$pdo->prepare('INSERT INTO student_question_progress(student_id,question_id,'.$field.') VALUES(?,?,1)')->execute([Auth::id(),$questionId]);});
        $return=(string)($_POST['return_to']??'/student/review');if(!str_starts_with($return,'/student/'))$return='/student';Response::redirect($return);
    }

    public function reviewCenter(): void
    {
        $pdo=Database::connection();$s=$pdo->prepare('SELECT SUM(wrong_count>0) wrong,SUM(wrong_count>0 AND (last_is_correct=0 OR needs_review=1)) unmastered,SUM(needs_review=1) review,SUM(favorite=1) favorite,SUM(next_review_at IS NOT NULL AND next_review_at<=UTC_TIMESTAMP()) due FROM student_question_progress WHERE student_id=?');$s->execute([Auth::id()]);$counts=$s->fetch()?:[];$this->render('student/review_center',['title'=>'我的复习','counts'=>$counts]);
    }

    private function reviewCondition(string $mode): string
    {
        return ['unmastered'=>'p.wrong_count>0 AND (p.last_is_correct=0 OR p.needs_review=1)','wrong'=>'p.wrong_count>0','review'=>'p.needs_review=1','favorite'=>'p.favorite=1','due'=>'p.next_review_at IS NOT NULL AND p.next_review_at<=UTC_TIMESTAMP()'][$mode]??'p.wrong_count>0 AND (p.last_is_correct=0 OR p.needs_review=1)';
    }

    public function review(): void
    {
        $mode=in_array($_GET['mode']??'', ['unmastered','wrong','review','favorite','due'],true)?$_GET['mode']:'unmastered';
        $position=max(1,(int)($_GET['n']??1));
        $where=$this->reviewCondition($mode);$pdo=Database::connection();
        $count=$pdo->prepare("SELECT COUNT(*) FROM student_question_progress p JOIN practice_questions q ON q.id=p.question_id WHERE p.student_id=? AND q.status='active' AND {$where}");$count->execute([Auth::id()]);$total=(int)$count->fetchColumn();
        $question=null;$latest=null;
        $feedback=(($_GET['feedback']??'')==='1');$feedbackQuestionId=(int)($_GET['qid']??0);
        if($feedback&&$feedbackQuestionId>0){$q=$pdo->prepare('SELECT q.*,p.favorite,p.needs_review,p.wrong_count,p.attempt_count,p.correct_count FROM student_question_progress p JOIN practice_questions q ON q.id=p.question_id WHERE p.student_id=? AND q.id=?');$q->execute([Auth::id(),$feedbackQuestionId]);$question=$q->fetch()?:null;if($question){$l=$pdo->prepare('SELECT * FROM student_question_answers WHERE student_id=? AND question_id=? ORDER BY id DESC LIMIT 1');$l->execute([Auth::id(),$question['id']]);$latest=$l->fetch()?:null;}}
        elseif($total>0){$position=min($position,$total);$offset=$position-1;$q=$pdo->prepare("SELECT q.*,p.favorite,p.needs_review,p.wrong_count,p.attempt_count,p.correct_count FROM student_question_progress p JOIN practice_questions q ON q.id=p.question_id WHERE p.student_id=? AND q.status='active' AND {$where} ORDER BY COALESCE(q.source_order,4294967295),q.id LIMIT 1 OFFSET {$offset}");$q->execute([Auth::id()]);$question=$q->fetch()?:null;}
        $nextPosition=max(0,(int)($_GET['next']??0));$this->render('student/review_question',['title'=>'复习','mode'=>$mode,'question'=>$question,'position'=>$position,'total'=>$total,'latest'=>$latest,'nextPosition'=>$nextPosition]);
    }

    public function answerReview(string $id): void
    {
        $questionId=(int)$id;$mode=in_array($_POST['mode']??'', ['unmastered','wrong','review','favorite','due'],true)?$_POST['mode']:'unmastered';$position=max(1,(int)($_POST['position']??1));$pdo=Database::connection();$q=$pdo->prepare('SELECT * FROM practice_questions WHERE id=? AND status="active"');$q->execute([$questionId]);$question=$q->fetch();if(!$question){Flash::set('error','题目不存在。');Response::redirect('/student/review-center');}
        if($question['question_type']==='fill'){$self=$_POST['self_result']??'';if(!in_array($self,['correct','review'],true)){Flash::set('warning','请查看标准答案后进行自评。');Response::redirect('/student/review?mode='.$mode.'&n='.$position);} $answer=[trim((string)($_POST['answer_text']??'自评'))];$ok=$self==='correct';}
        else{$valid=array_keys(json_decode($question['options_json'],true)?:[]);$answer=array_values(array_unique(array_intersect((array)($_POST['answer']??[]),$valid)));if(!$answer){Flash::set('warning','请先选择答案。');Response::redirect('/student/review?mode='.$mode.'&n='.$position);}sort($answer);$expected=json_decode($question['correct_answers_json'],true)?:[];sort($expected);$ok=$answer===$expected;}
        Database::transaction(function(PDO$pdo)use($questionId,$answer,$ok){$this->recordQuestionProgress($pdo,Auth::id(),$questionId,$answer,$ok,'review',null);});
        $where=$this->reviewCondition($mode);$count=$pdo->prepare("SELECT COUNT(*) FROM student_question_progress p JOIN practice_questions q ON q.id=p.question_id WHERE p.student_id=? AND q.status='active' AND {$where}");$count->execute([Auth::id()]);$remaining=(int)$count->fetchColumn();$still=$pdo->prepare("SELECT COUNT(*) FROM student_question_progress p JOIN practice_questions q ON q.id=p.question_id WHERE p.student_id=? AND q.id=? AND q.status='active' AND {$where}");$still->execute([Auth::id(),$questionId]);$stillMatches=(int)$still->fetchColumn()>0;$next=0;if($remaining>0){if($stillMatches){if($position<$remaining)$next=$position+1;}elseif($position<=$remaining)$next=$position;}
        Response::redirect('/student/review?mode='.$mode.'&n='.$position.'&feedback=1&qid='.$questionId.'&next='.$next);
    }

    public function image(string $id): void
    {
        $pdo=Database::connection();$sql='SELECT u.* FROM answer_uploads u JOIN assessment_answers ans ON ans.id=u.answer_id JOIN assessment_assignments a ON a.id=ans.assignment_id WHERE u.id=?';$params=[(int)$id];if(Auth::role()==='student'){$sql.=' AND a.student_id=?';$params[]=Auth::id();}elseif(Auth::role()!=='admin'){http_response_code(403);return;}$stmt=$pdo->prepare($sql);$stmt->execute($params);$file=$stmt->fetch();if(!$file){http_response_code(404);return;}$base=realpath((string)Config::get('upload.dir'));$path=realpath((string)Config::get('upload.dir').'/'.$file['storage_path']);if(!$base||!$path||!str_starts_with($path,$base.DIRECTORY_SEPARATOR)){http_response_code(404);return;}header('Content-Type: '.$file['mime_type']);header('Content-Length: '.filesize($path));header('Cache-Control: private, no-store');header('Content-Disposition: inline; filename="evidence.'.pathinfo($path,PATHINFO_EXTENSION).'"');readfile($path);exit;
    }
}
