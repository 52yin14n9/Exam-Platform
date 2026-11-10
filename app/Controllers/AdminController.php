<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\KeyManager;
use App\Core\Response;
use PDO;

final class AdminController extends Controller
{
    public function dashboard(): void
    {
        $pdo = Database::connection();
        $stats = [
            'students' => (int) $pdo->query('SELECT COUNT(*) FROM students WHERE status="active"')->fetchColumn(),
            'assessmentQuestions' => (int) $pdo->query('SELECT COUNT(*) FROM assessment_questions WHERE status="active"')->fetchColumn(),
            'practiceQuestions' => (int) $pdo->query('SELECT COUNT(*) FROM practice_questions WHERE status="active"')->fetchColumn(),
            'pendingGrades' => (int) $pdo->query('SELECT COUNT(*) FROM assessment_assignments WHERE status="submitted"')->fetchColumn(),
        ];
        $recent = $pdo->query('SELECT l.*, COALESCE(a.display_name,s.display_name,"系统") actor_name FROM audit_logs l LEFT JOIN admins a ON l.actor_role="admin" AND a.id=l.actor_id LEFT JOIN students s ON l.actor_role="student" AND s.id=l.actor_id ORDER BY l.id DESC LIMIT 12')->fetchAll();
        $this->render('admin/dashboard', compact('stats', 'recent') + ['title' => '管理概览']);
    }

    public function students(): void
    {
        $rows = Database::connection()->query('SELECT s.*, k.key_hint, k.created_at key_created_at, k.last_used_at FROM students s LEFT JOIN student_access_keys k ON k.student_id=s.id AND k.revoked_at IS NULL ORDER BY s.id')->fetchAll();
        $oneTimeKey = $_SESSION['one_time_key'] ?? null;
        unset($_SESSION['one_time_key']);
        $this->render('admin/students', ['title' => '学生与 Key', 'students' => $rows, 'oneTimeKey' => $oneTimeKey]);
    }

    public function createStudent(): void
    {
        KeyManager::assertConfiguration();
        $errors = $this->requireFields(['student_no' => '学生编号', 'display_name' => '学生姓名']);
        if ($errors) { Flash::set('error', implode(' ', $errors)); Response::redirect('/admin/students'); }
        $pdo = Database::connection();
        $key = KeyManager::generateStudent();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO students(student_no,display_name,note) VALUES(?,?,?)')->execute([
                trim((string) $_POST['student_no']), trim((string) $_POST['display_name']), trim((string) ($_POST['note'] ?? '')) ?: null,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO student_access_keys(student_id,key_digest,key_hint) VALUES(?,?,?)')->execute([$id, KeyManager::digestStudent($key), substr($key, -8)]);
            $pdo->commit();
            $_SESSION['one_time_key'] = ['name' => trim((string) $_POST['display_name']), 'key' => $key];
            Audit::log('student.created', 'student', $id);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Flash::set('error', str_contains($e->getMessage(), 'Duplicate') ? '学生编号已存在。' : '创建学生失败。');
        }
        Response::redirect('/admin/students');
    }

    public function rotateStudentKey(string $id): void
    {
        KeyManager::assertConfiguration();
        $studentId = (int) $id;
        $pdo = Database::connection();
        $student = $pdo->prepare('SELECT display_name FROM students WHERE id=?');
        $student->execute([$studentId]);
        $name = $student->fetchColumn();
        if (!$name) { Flash::set('error', '学生不存在。'); Response::redirect('/admin/students'); }
        $key = KeyManager::generateStudent();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE student_access_keys SET revoked_at=UTC_TIMESTAMP() WHERE student_id=? AND revoked_at IS NULL')->execute([$studentId]);
            $pdo->prepare('UPDATE students SET session_version=session_version+1 WHERE id=?')->execute([$studentId]);
            $pdo->prepare('INSERT INTO student_access_keys(student_id,key_digest,key_hint) VALUES(?,?,?)')->execute([$studentId, KeyManager::digestStudent($key), substr($key, -8)]);
            $pdo->commit();
            $_SESSION['one_time_key'] = ['name' => $name, 'key' => $key];
            Audit::log('student.key_rotated', 'student', $studentId);
        } catch (\Throwable $e) {
            $pdo->rollBack(); throw $e;
        }
        Response::redirect('/admin/students');
    }

    public function toggleStudent(string $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare('UPDATE students SET status=IF(status="active","disabled","active"),session_version=session_version+1 WHERE id=?')->execute([(int) $id]);
        Audit::log('student.status_toggled', 'student', (int) $id);
        Response::redirect('/admin/students');
    }

    public function assessmentQuestions(): void
    {
        $status = in_array($_GET['status'] ?? '', ['draft','active','disabled'], true) ? $_GET['status'] : '';
        $sql = 'SELECT * FROM assessment_questions' . ($status ? ' WHERE status=?' : '') . ' ORDER BY id DESC';
        $stmt = Database::connection()->prepare($sql); $stmt->execute($status ? [$status] : []);
        $this->render('admin/assessment_questions', ['title' => '考核题库', 'questions' => $stmt->fetchAll(), 'status' => $status]);
    }

    public function assessmentQuestionForm(?string $id = null): void
    {
        $question = null;
        if ($id !== null) {
            $stmt = Database::connection()->prepare('SELECT * FROM assessment_questions WHERE id=?');
            $stmt->execute([(int) $id]); $question = $stmt->fetch();
        }
        $this->render('admin/assessment_question_form', ['title' => $question ? '编辑考核题' : '新建考核题', 'question' => $question]);
    }

    public function saveAssessmentQuestion(?string $id = null): void
    {
        $errors = $this->requireFields(['title'=>'题目标题','prompt'=>'题干','reference_answer'=>'参考答案','default_score'=>'默认分值']);
        $type = in_array($_POST['question_type'] ?? '', ['subjective','practical'], true) ? $_POST['question_type'] : 'subjective';
        $score = (float) ($_POST['default_score'] ?? 0);
        if ($score <= 0) $errors[] = '分值必须大于 0。';
        $url = trim((string) ($_POST['target_url'] ?? ''));
        if ($type === 'practical' && (!$url || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true))) $errors[] = '实践题必须填写有效的 HTTP/HTTPS 靶场网址。';
        $min = max(0, min(5, (int) ($_POST['screenshot_min'] ?? 0)));
        $max = max($min, min(5, (int) ($_POST['screenshot_max'] ?? 0)));
        if ($type === 'practical' && $max < 1) $errors[] = '实践题至少允许上传 1 张截图。';
        if ($errors) { Flash::set('error', implode(' ', $errors)); Response::redirect($id ? "/admin/assessment-questions/{$id}/edit" : '/admin/assessment-questions/create'); }
        $values = [
            $type, trim((string) $_POST['title']), trim((string) $_POST['prompt']), trim((string) $_POST['reference_answer']), $score,
            in_array($_POST['difficulty'] ?? '', ['easy','medium','hard'], true) ? $_POST['difficulty'] : 'medium',
            trim((string) ($_POST['tags'] ?? '')) ?: null, $type === 'practical' ? $url : null,
            $type === 'practical' ? $min : 0, $type === 'practical' ? $max : 0,
            in_array($_POST['status'] ?? '', ['draft','active','disabled'], true) ? $_POST['status'] : 'draft',
        ];
        $pdo = Database::connection();
        if ($id) {
            $values[] = (int) $id;
            $pdo->prepare('UPDATE assessment_questions SET question_type=?,title=?,prompt=?,reference_answer=?,default_score=?,difficulty=?,tags=?,target_url=?,screenshot_min=?,screenshot_max=?,status=?,version=version+1 WHERE id=?')->execute($values);
            Audit::log('assessment_question.updated', 'assessment_question', (int) $id);
        } else {
            $values[] = Auth::id();
            $pdo->prepare('INSERT INTO assessment_questions(question_type,title,prompt,reference_answer,default_score,difficulty,tags,target_url,screenshot_min,screenshot_max,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
            $id = (string) $pdo->lastInsertId();
            Audit::log('assessment_question.created', 'assessment_question', (int) $id);
        }
        Flash::set('success', '考核题已保存。');
        Response::redirect('/admin/assessment-questions');
    }

    public function practiceQuestions(): void
    {
        $pdo = Database::connection();
        $rows = $pdo->query('SELECT * FROM practice_questions ORDER BY COALESCE(source_order, 4294967295), id')->fetchAll();
        $counts = $pdo->query('SELECT question_type,COUNT(*) c FROM practice_questions WHERE status="active" GROUP BY question_type')->fetchAll();
        $this->render('admin/practice_questions', ['title' => '理论刷题题库', 'questions' => $rows, 'counts' => $counts]);
    }

    public function practiceQuestionForm(?string $id = null): void
    {
        $question = null;
        if ($id !== null) {
            $stmt = Database::connection()->prepare('SELECT * FROM practice_questions WHERE id=?'); $stmt->execute([(int) $id]);
            $question = $stmt->fetch();
            if ($question) { $question['options'] = json_decode($question['options_json'], true); $question['correct'] = json_decode($question['correct_answers_json'], true); }
        }
        $this->render('admin/practice_question_form', ['title' => $question ? '编辑理论题' : '新建理论题', 'question' => $question]);
    }

    public function savePracticeQuestion(?string $id = null): void
    {
        [$payload, $errors] = $this->practiceQuestionPayload($_POST);
        if ($errors) { Flash::set('error', implode(' ', $errors)); Response::redirect($id ? "/admin/practice-questions/{$id}/edit" : '/admin/practice-questions/create'); }
        $pdo = Database::connection();
        $values = [
            $payload['type'],$payload['title'],$payload['prompt'],json_encode($payload['options'],JSON_UNESCAPED_UNICODE),
            json_encode($payload['correct'],JSON_UNESCAPED_UNICODE),$payload['explanation'],$payload['difficulty'],$payload['tags'],$payload['status'],
            $payload['source_ref'],$payload['source_order'],$payload['section'],$payload['chapter'],
        ];
        if ($id) {
            $values[] = (int) $id;
            $pdo->prepare('UPDATE practice_questions SET question_type=?,title=?,prompt=?,options_json=?,correct_answers_json=?,explanation=?,difficulty=?,tags=?,status=?,source_ref=?,source_order=?,section=?,chapter=? WHERE id=?')->execute($values);
            Audit::log('practice_question.updated', 'practice_question', (int) $id);
        } else {
            $values[] = Auth::id();
            $pdo->prepare('INSERT INTO practice_questions(question_type,title,prompt,options_json,correct_answers_json,explanation,difficulty,tags,status,source_ref,source_order,section,chapter,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($values);
            Audit::log('practice_question.created', 'practice_question', (int) $pdo->lastInsertId());
        }
        Flash::set('success', '理论题已保存。');
        Response::redirect('/admin/practice-questions');
    }

    private function practiceQuestionPayload(array $data): array
    {
        $errors = [];
        $type = in_array($data['question_type'] ?? '', ['single','multiple','judge','fill'], true) ? $data['question_type'] : '';
        $title = trim((string) ($data['title'] ?? ''));
        $prompt = trim((string) ($data['prompt'] ?? ''));
        $explanation = trim((string) ($data['explanation'] ?? '')) ?: null;
        if (!$type || !$title || !$prompt) $errors[] = '题型、标题和题干不能为空。';

        $options = [];
        $correct = [];
        if (in_array($type, ['single','multiple'], true)) {
            foreach (['A','B','C','D','E','F'] as $letter) {
                $value = trim((string) ($data['option_' . strtolower($letter)] ?? ''));
                if ($value !== '') $options[$letter] = $value;
            }
            if (count($options) < 2) $errors[] = '单选/多选题至少填写两个选项。';
            $correct = array_values(array_unique(array_filter((array) ($data['correct_answers'] ?? []), fn($v) => isset($options[$v]))));
            sort($correct);
            if (!$correct) $errors[] = '至少选择一个正确答案。';
        } elseif ($type === 'judge') {
            $options = ['T'=>'对','F'=>'错'];
            $raw = (array) ($data['correct_answers'] ?? []);
            if (isset($data['judge_answer']) && trim((string)$data['judge_answer']) !== '') $raw = [(string)$data['judge_answer']];
            $map = ['T'=>'T','TRUE'=>'T','对'=>'T','正确'=>'T','F'=>'F','FALSE'=>'F','错'=>'F','错误'=>'F'];
            foreach ($raw as $value) {
                $key = strtoupper(trim((string)$value));
                if (isset($map[$key])) $correct[] = $map[$key];
                elseif (isset($map[trim((string)$value)])) $correct[] = $map[trim((string)$value)];
            }
            $correct = array_values(array_unique($correct));
            if (count($correct) !== 1) $errors[] = '判断题必须设置“对”或“错”作为唯一正确答案。';
        } elseif ($type === 'fill') {
            $raw = $data['fill_answers'] ?? ($data['correct_answers'] ?? []);
            if (is_string($raw)) $raw = preg_split('/[|｜]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $correct = array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), (array)$raw), static fn($v) => $v !== '')));
            // 允许空标准答案：部分官方题库原文存在题干但未给出答案，系统会明确提示。
        }

        $sourceOrder = trim((string)($data['source_order'] ?? ''));
        if ($sourceOrder !== '' && (!ctype_digit($sourceOrder) || (int)$sourceOrder < 1)) $errors[] = '原题顺序必须为正整数。';
        return [[
            'type'=>$type,'title'=>$title,'prompt'=>$prompt,'options'=>$options,'correct'=>$correct,'explanation'=>$explanation,
            'difficulty'=>in_array($data['difficulty'] ?? '', ['easy','medium','hard'], true) ? $data['difficulty'] : 'medium',
            'tags'=>trim((string) ($data['tags'] ?? '')) ?: null,
            'status'=>in_array($data['status'] ?? '', ['draft','active','disabled'], true) ? $data['status'] : 'active',
            'source_ref'=>trim((string)($data['source_ref'] ?? '')) ?: null,
            'source_order'=>$sourceOrder === '' ? null : (int)$sourceOrder,
            'section'=>trim((string)($data['section'] ?? '')) ?: null,
            'chapter'=>trim((string)($data['chapter'] ?? '')) ?: null,
        ], $errors];
    }

    public function importPracticeQuestions(): void
    {
        $file = $_FILES['csv_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            Flash::set('error', '请选择 CSV 文件。'); Response::redirect('/admin/practice-questions');
        }
        if (($file['size'] ?? 0) > 10 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) {
            Flash::set('error', 'CSV 文件无效或超过 10 MB。'); Response::redirect('/admin/practice-questions');
        }
        $handle = fopen($file['tmp_name'], 'rb');
        $header = fgetcsv($handle);
        if ($header) $header[0] = ltrim((string) $header[0], "\xEF\xBB\xBF");
        $legacy = ['type','title','prompt','option_a','option_b','option_c','option_d','option_e','option_f','correct_answers','explanation','difficulty','tags'];
        $enhanced = array_merge($legacy, ['source_ref','source_order','section','chapter']);
        $normalized = $header ? array_map('strtolower', array_map('trim', $header)) : [];
        if ($normalized !== $legacy && $normalized !== $enhanced) {
            fclose($handle); Flash::set('error', 'CSV 表头不符合模板。请使用旧版 13 列或新版 17 列模板。'); Response::redirect('/admin/practice-questions');
        }
        $expected = $normalized;
        $pdo = Database::connection(); $inserted = 0; $skipped = 0; $line = 1;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO practice_questions(question_type,title,prompt,options_json,correct_answers_json,explanation,difficulty,tags,status,source_ref,source_order,section,chapter,created_by) VALUES(?,?,?,?,?,?,?,?,"active",?,?,?,?,?)');
            $duplicate = $pdo->prepare('SELECT id FROM practice_questions WHERE source_ref=? LIMIT 1');
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (count($row) !== count($expected)) { $skipped++; continue; }
                $data = array_combine($expected, $row);
                $data['question_type'] = strtolower(trim((string)$data['type']));
                if ($data['question_type'] === 'fill') {
                    $data['fill_answers'] = (string)$data['correct_answers'];
                    $data['correct_answers'] = [];
                } else {
                    $data['correct_answers'] = preg_split('/[|,，;；\s]+/u', strtoupper(trim((string)$data['correct_answers'])), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                }
                [$payload, $errors] = $this->practiceQuestionPayload($data);
                if ($errors) { $skipped++; continue; }
                $sourceRef = $payload['source_ref'] ?: "CSV:{$line}";
                if ($payload['source_ref']) { $duplicate->execute([$sourceRef]); if ($duplicate->fetchColumn()) { $skipped++; continue; } }
                $stmt->execute([$payload['type'],$payload['title'],$payload['prompt'],json_encode($payload['options'],JSON_UNESCAPED_UNICODE),json_encode($payload['correct'],JSON_UNESCAPED_UNICODE),$payload['explanation'],$payload['difficulty'],$payload['tags'],$sourceRef,$payload['source_order'],$payload['section'],$payload['chapter'],Auth::id()]);
                $inserted++;
            }
            fclose($handle); $pdo->commit();
            Audit::log('practice_questions.imported', 'practice_question', null, compact('inserted','skipped'));
            Flash::set('success', "导入 {$inserted} 道理论题，跳过 {$skipped} 行。已支持单选、多选、判断和填空。 ");
        } catch (\Throwable $e) {
            if (is_resource($handle)) fclose($handle);
            if ($pdo->inTransaction()) $pdo->rollBack();
            ErrorHandler::report($e, 'practice.import');
            Flash::set('error', '导入失败，未写入任何题目。');
        }
        Response::redirect('/admin/practice-questions');
    }

    public function exams(): void
    {
        $pdo = Database::connection();
        $exams = $pdo->query('SELECT e.*, COUNT(DISTINCT q.id) question_count, COUNT(DISTINCT a.id) student_count, COUNT(DISTINCT CASE WHEN a.status="submitted" THEN a.id END) submitted_count FROM assessment_exams e LEFT JOIN assessment_exam_questions q ON q.exam_id=e.id LEFT JOIN assessment_assignments a ON a.exam_id=e.id GROUP BY e.id ORDER BY e.id DESC')->fetchAll();
        $questions = $pdo->query('SELECT id,title,question_type,default_score,difficulty,tags FROM assessment_questions WHERE status="active" ORDER BY id DESC')->fetchAll();
        $this->render('admin/exams', ['title'=>'考核考试','exams'=>$exams,'questions'=>$questions]);
    }

    public function createExam(): void
    {
        $errors = $this->requireFields(['title'=>'考试名称']);
        $mode = in_array($_POST['compose_mode'] ?? '', ['manual','random'], true) ? $_POST['compose_mode'] : 'manual';
        $manual = array_values(array_unique(array_map('intval', (array) ($_POST['question_ids'] ?? []))));
        $subjective = max(0, (int) ($_POST['subjective_count'] ?? 0));
        $practical = max(0, (int) ($_POST['practical_count'] ?? 0));
        if ($mode === 'manual' && !$manual) $errors[] = '手工组卷至少选择一道题。';
        if ($mode === 'random' && ($subjective + $practical) < 1) $errors[] = '随机组卷题量必须大于 0。';
        if (($_POST['answer_release'] ?? '') === 'after_close' && empty($_POST['closes_at'])) $errors[] = '选择“统一结束后展示答案”时必须设置截止时间。';
        if (!empty($_POST['opens_at']) && !empty($_POST['closes_at']) && strtotime((string) $_POST['opens_at']) >= strtotime((string) $_POST['closes_at'])) $errors[] = '考试截止时间必须晚于开放时间。';
        if ($errors) { Flash::set('error', implode(' ', $errors)); Response::redirect('/admin/exams'); }
        $rules = $mode === 'manual' ? ['manual_ids'=>$manual] : ['subjective'=>$subjective,'practical'=>$practical,'difficulty'=>$_POST['difficulty'] ?? 'all','tags'=>trim((string) ($_POST['tags_filter'] ?? ''))];
        Database::connection()->prepare('INSERT INTO assessment_exams(title,description,compose_mode,random_rules_json,opens_at,closes_at,duration_minutes,answer_release,result_release,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([
            trim((string) $_POST['title']), trim((string) ($_POST['description'] ?? '')) ?: null, $mode, json_encode($rules,JSON_UNESCAPED_UNICODE),
            $this->utc($_POST['opens_at'] ?? null), $this->utc($_POST['closes_at'] ?? null), ((int) ($_POST['duration_minutes'] ?? 0)) ?: null,
            in_array($_POST['answer_release'] ?? '', ['immediate','after_close','manual'], true) ? $_POST['answer_release'] : 'after_close',
            in_array($_POST['result_release'] ?? '', ['manual','after_graded'], true) ? $_POST['result_release'] : 'manual', Auth::id(),
        ]);
        $id = (int) Database::connection()->lastInsertId(); Audit::log('exam.created','assessment_exam',$id);
        Flash::set('success','考试草稿已创建，请确认后发布。'); Response::redirect('/admin/exams');
    }

    public function publishExam(string $id): void
    {
        $examId = (int) $id; $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM assessment_exams WHERE id=? AND status="draft"'); $stmt->execute([$examId]); $exam = $stmt->fetch();
        if (!$exam) { Flash::set('error','只有草稿考试可以发布。'); Response::redirect('/admin/exams'); }
        $rules = json_decode($exam['random_rules_json'], true) ?: [];
        if ($exam['compose_mode'] === 'manual') {
            $ids = array_values(array_filter(array_map('intval', $rules['manual_ids'] ?? [])));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $q = $pdo->prepare("SELECT * FROM assessment_questions WHERE status='active' AND id IN ({$in}) ORDER BY FIELD(id,{$in})");
            $q->execute(array_merge($ids, $ids)); $questions = $q->fetchAll();
            if (count($questions) !== count($ids)) { Flash::set('error','部分手工选择的题目已停用，无法发布。'); Response::redirect('/admin/exams'); }
        } else {
            $questions = [];
            foreach (['subjective','practical'] as $type) {
                $count = max(0, (int) ($rules[$type] ?? 0)); if (!$count) continue;
                $params = [$type]; $where = 'status="active" AND question_type=?';
                if (($rules['difficulty'] ?? 'all') !== 'all') { $where .= ' AND difficulty=?'; $params[] = $rules['difficulty']; }
                if (!empty($rules['tags'])) { $where .= ' AND tags LIKE ?'; $params[] = '%' . $rules['tags'] . '%'; }
                $q = $pdo->prepare("SELECT * FROM assessment_questions WHERE {$where} ORDER BY RAND() LIMIT {$count}"); $q->execute($params);
                $picked = $q->fetchAll(); if (count($picked) !== $count) { Flash::set('error',"{$type} 题量不足，无法发布。"); Response::redirect('/admin/exams'); }
                $questions = array_merge($questions, $picked);
            }
        }
        if (!$questions) { Flash::set('error','没有可用于发布的题目。'); Response::redirect('/admin/exams'); }
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO assessment_exam_questions(exam_id,source_question_id,display_order,question_type,title,prompt,reference_answer,score,target_url,screenshot_min,screenshot_max) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($questions as $i => $q) $insert->execute([$examId,$q['id'],$i+1,$q['question_type'],$q['title'],$q['prompt'],$q['reference_answer'],$q['default_score'],$q['target_url'],$q['screenshot_min'],$q['screenshot_max']]);
            $pdo->prepare('INSERT INTO assessment_assignments(exam_id,student_id) SELECT ?,id FROM students WHERE status="active"')->execute([$examId]);
            $pdo->prepare('UPDATE assessment_exams SET status="published" WHERE id=?')->execute([$examId]);
            $pdo->commit(); Audit::log('exam.published','assessment_exam',$examId,['questions'=>count($questions)]);
            Flash::set('success','考试已发布，并已为所有有效学生创建答卷。');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack(); Flash::set('error','考试发布失败。');
        }
        Response::redirect('/admin/exams');
    }

    public function releaseExam(string $id): void
    {
        $type = ($_POST['release'] ?? '') === 'answers' ? 'answers' : 'results';
        $column = $type === 'answers' ? 'answers_released_at' : 'results_released_at';
        $stmt=Database::connection()->prepare("UPDATE assessment_exams SET {$column}=UTC_TIMESTAMP() WHERE id=? AND status IN('published','closed')");$stmt->execute([(int) $id]);
        if($stmt->rowCount()===0){Flash::set('error','考试不存在或尚未发布。');Response::redirect('/admin/exams');}
        Audit::log('exam.' . $type . '_released','assessment_exam',(int) $id);
        Flash::set('success',$type === 'answers' ? '参考答案已发布。' : '成绩已发布。'); Response::redirect('/admin/exams');
    }

    public function grading(): void
    {
        $rows = Database::connection()->query('SELECT a.*, e.title exam_title, s.display_name student_name, s.student_no FROM assessment_assignments a JOIN assessment_exams e ON e.id=a.exam_id JOIN students s ON s.id=a.student_id WHERE a.status IN("submitted","graded","returned") ORDER BY a.submitted_at DESC')->fetchAll();
        $this->render('admin/grading', ['title'=>'人工阅卷','assignments'=>$rows]);
    }

    public function gradeForm(string $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT a.*,e.title exam_title,s.display_name student_name,s.student_no FROM assessment_assignments a JOIN assessment_exams e ON e.id=a.exam_id JOIN students s ON s.id=a.student_id WHERE a.id=?'); $stmt->execute([(int) $id]); $assignment=$stmt->fetch();
        if (!$assignment) { http_response_code(404); return; }
        $q = $pdo->prepare('SELECT q.*,ans.id answer_id,ans.answer_text,ans.score answer_score,ans.feedback,ans.review_status FROM assessment_exam_questions q LEFT JOIN assessment_answers ans ON ans.exam_question_id=q.id AND ans.assignment_id=? WHERE q.exam_id=? ORDER BY q.display_order'); $q->execute([(int)$id,$assignment['exam_id']]);
        $questions=$q->fetchAll();
        $uploads=[];
        if ($questions) {
            $answerIds=array_values(array_filter(array_column($questions,'answer_id')));
            if ($answerIds) { $in=implode(',',array_fill(0,count($answerIds),'?')); $u=$pdo->prepare("SELECT * FROM answer_uploads WHERE answer_id IN ({$in}) ORDER BY id"); $u->execute($answerIds); foreach($u->fetchAll() as $row)$uploads[$row['answer_id']][]=$row; }
        }
        $this->render('admin/grade_form',['title'=>'人工阅卷','assignment'=>$assignment,'questions'=>$questions,'uploads'=>$uploads]);
    }

    public function saveGrade(string $id): void
    {
        $assignmentId=(int)$id; $pdo=Database::connection();
        $stmt=$pdo->prepare('SELECT exam_id FROM assessment_assignments WHERE id=?');$stmt->execute([$assignmentId]);$examId=(int)$stmt->fetchColumn();
        if(!$examId){Flash::set('error','答卷不存在。');Response::redirect('/admin/grading');}
        $q=$pdo->prepare('SELECT id,score FROM assessment_exam_questions WHERE exam_id=?');$q->execute([$examId]);$limits=[];foreach($q->fetchAll() as $row)$limits[(int)$row['id']]=(float)$row['score'];
        $pdo->beginTransaction();
        try {
            $up=$pdo->prepare('UPDATE assessment_answers SET score=?,feedback=?,review_status=?,graded_at=UTC_TIMESTAMP() WHERE assignment_id=? AND exam_question_id=?');$total=0;
            foreach($limits as $qid=>$max){$score=max(0,min($max,(float)($_POST['score'][$qid]??0)));$score=round($score*2)/2;$total+=$score;$status=($_POST['review'][$qid]??'')==='1'?'review':'graded';$up->execute([$score,trim((string)($_POST['feedback'][$qid]??''))?:null,$status,$assignmentId,$qid]);}
            $pdo->prepare('UPDATE assessment_assignments SET status="graded",total_score=?,general_feedback=?,graded_at=UTC_TIMESTAMP() WHERE id=?')->execute([$total,trim((string)($_POST['general_feedback']??''))?:null,$assignmentId]);
            $pdo->commit();Audit::log('assignment.graded','assessment_assignment',$assignmentId,['total'=>$total]);Flash::set('success','评分已保存。');
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();Flash::set('error','评分保存失败。');}
        Response::redirect('/admin/grading');
    }

    public function returnAssignment(string $id): void
    {
        $stmt=Database::connection()->prepare('UPDATE assessment_assignments SET status="returned",attempt_version=attempt_version+1,submitted_at=NULL WHERE id=? AND status IN("submitted","graded")');$stmt->execute([(int)$id]);
        if($stmt->rowCount()===0){Flash::set('error','只有已提交或已评分的答卷可以退回。');Response::redirect('/admin/grading');}
        Audit::log('assignment.returned','assessment_assignment',(int)$id);Flash::set('success','答卷已退回，学生可以继续修改。');Response::redirect('/admin/grading');
    }

    public function practiceSets(): void
    {
        $pdo=Database::connection();
        $sets=$pdo->query('SELECT p.*,COUNT(a.id) attempt_count,SUM(a.status="submitted") completed_count FROM practice_sets p LEFT JOIN practice_attempts a ON a.practice_set_id=p.id GROUP BY p.id ORDER BY p.id DESC')->fetchAll();
        $counts=$pdo->query('SELECT question_type,COUNT(*) c FROM practice_questions WHERE status="active" GROUP BY question_type')->fetchAll();
        $this->render('admin/practice_sets',['title'=>'刷题开放计划','sets'=>$sets,'counts'=>$counts]);
    }

    public function createPracticeSet(): void
    {
        $errors=$this->requireFields(['title'=>'练习名称','question_count'=>'题目数量']);$count=(int)($_POST['question_count']??0);if($count<1)$errors[]='题量必须大于0。';
        $types=array_values(array_intersect((array)($_POST['allowed_types']??[]),['single','multiple','judge','fill']));if(!$types)$errors[]='至少允许一种题型。';
        if(!empty($_POST['opens_at'])&&!empty($_POST['closes_at'])&&strtotime((string)$_POST['opens_at'])>=strtotime((string)$_POST['closes_at']))$errors[]='练习结束时间必须晚于开放时间。';
        if($errors){Flash::set('error',implode(' ',$errors));Response::redirect('/admin/practice-sets');}
        Database::connection()->prepare('INSERT INTO practice_sets(title,description,question_count,allowed_types,tags_filter,opens_at,closes_at,created_by) VALUES(?,?,?,?,?,?,?,?)')->execute([
            trim((string)$_POST['title']),trim((string)($_POST['description']??''))?:null,$count,implode(',',$types),trim((string)($_POST['tags_filter']??''))?:null,$this->utc($_POST['opens_at']??null),$this->utc($_POST['closes_at']??null),Auth::id()
        ]);$id=(int)Database::connection()->lastInsertId();Audit::log('practice_set.created','practice_set',$id);Flash::set('success','练习计划已创建。');Response::redirect('/admin/practice-sets');
    }

    public function setPracticeStatus(string $id): void
    {
        $status=in_array($_POST['status']??'', ['open','closed','archived'],true)?$_POST['status']:'closed';
        $pdo=Database::connection();$s=$pdo->prepare('SELECT * FROM practice_sets WHERE id=?');$s->execute([(int)$id]);$set=$s->fetch();
        if(!$set){Flash::set('error','练习计划不存在。');Response::redirect('/admin/practice-sets');}
        if($status==='open'&&$set){$types=explode(',',$set['allowed_types']);$in=implode(',',array_fill(0,count($types),'?'));$q=$pdo->prepare("SELECT COUNT(*) FROM practice_questions WHERE status='active' AND question_type IN ({$in})".($set['tags_filter']?' AND tags LIKE ?':''));$params=$types;if($set['tags_filter'])$params[]='%'.$set['tags_filter'].'%';$q->execute($params);if((int)$q->fetchColumn()<(int)$set['question_count']){Flash::set('error','可用理论题数量不足，无法开放。');Response::redirect('/admin/practice-sets');}}
        $pdo->prepare('UPDATE practice_sets SET status=? WHERE id=?')->execute([$status,(int)$id]);Audit::log('practice_set.status','practice_set',(int)$id,['status'=>$status]);Flash::set('success','练习状态已更新。');Response::redirect('/admin/practice-sets');
    }

    public function settings(): void
    {
        $rows=Database::connection()->query('SELECT setting_key,setting_value FROM settings')->fetchAll();$settings=[];foreach($rows as $row)$settings[$row['setting_key']]=$row['setting_value'];
        $this->render('admin/settings',['title'=>'网站设置','settings'=>$settings]);
    }

    public function saveSettings(): void
    {
        $allowed=['site_name','site_footer','default_answer_release'];$pdo=Database::connection();$stmt=$pdo->prepare('INSERT INTO settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
        foreach($allowed as $key){$value=trim((string)($_POST[$key]??''));if($key==='site_name'&&!$value)continue;if($key==='default_answer_release'&&!in_array($value,['immediate','after_close','manual'],true))continue;$stmt->execute([$key,$value,Auth::id()]);}
        Audit::log('settings.updated','settings');Flash::set('success','网站设置已保存。');Response::redirect('/admin/settings');
    }
}
