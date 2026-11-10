<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\StudentController;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Response;
use App\Core\Router;

$router = new Router();
$csrf = fn() => Csrf::middleware();
$admin = fn() => Auth::requireAdmin();
$student = fn() => Auth::requireStudent();
$signedIn = function () { if (!Auth::user()) { Response::redirect('/login/student'); } return true; };

$router->get('/', fn() => Response::redirect(Auth::role() === 'admin' ? '/admin' : (Auth::role() === 'student' ? '/student' : '/login/student')));
$router->get('/login/student', [AuthController::class, 'studentForm']);
$router->post('/login/student', [AuthController::class, 'studentLogin'], [$csrf]);
$router->get('/login/admin', [AuthController::class, 'adminForm']);
$router->post('/login/admin', [AuthController::class, 'adminLogin'], [$csrf]);
$router->post('/logout', [AuthController::class, 'logout'], [$csrf, $signedIn]);

$router->get('/admin', [AdminController::class, 'dashboard'], [$admin]);
$router->get('/admin/students', [AdminController::class, 'students'], [$admin]);
$router->post('/admin/students', [AdminController::class, 'createStudent'], [$csrf, $admin]);
$router->post('/admin/students/{id}/rotate-key', [AdminController::class, 'rotateStudentKey'], [$csrf, $admin]);
$router->post('/admin/students/{id}/toggle', [AdminController::class, 'toggleStudent'], [$csrf, $admin]);

$router->get('/admin/assessment-questions', [AdminController::class, 'assessmentQuestions'], [$admin]);
$router->get('/admin/assessment-questions/create', [AdminController::class, 'assessmentQuestionForm'], [$admin]);
$router->post('/admin/assessment-questions/create', [AdminController::class, 'saveAssessmentQuestion'], [$csrf, $admin]);
$router->get('/admin/assessment-questions/{id}/edit', [AdminController::class, 'assessmentQuestionForm'], [$admin]);
$router->post('/admin/assessment-questions/{id}/edit', [AdminController::class, 'saveAssessmentQuestion'], [$csrf, $admin]);

$router->get('/admin/practice-questions', [AdminController::class, 'practiceQuestions'], [$admin]);
$router->get('/admin/practice-questions/create', [AdminController::class, 'practiceQuestionForm'], [$admin]);
$router->post('/admin/practice-questions/create', [AdminController::class, 'savePracticeQuestion'], [$csrf, $admin]);
$router->get('/admin/practice-questions/{id}/edit', [AdminController::class, 'practiceQuestionForm'], [$admin]);
$router->post('/admin/practice-questions/{id}/edit', [AdminController::class, 'savePracticeQuestion'], [$csrf, $admin]);
$router->post('/admin/practice-questions/import', [AdminController::class, 'importPracticeQuestions'], [$csrf, $admin]);

$router->get('/admin/exams', [AdminController::class, 'exams'], [$admin]);
$router->post('/admin/exams', [AdminController::class, 'createExam'], [$csrf, $admin]);
$router->post('/admin/exams/{id}/publish', [AdminController::class, 'publishExam'], [$csrf, $admin]);
$router->post('/admin/exams/{id}/release', [AdminController::class, 'releaseExam'], [$csrf, $admin]);
$router->get('/admin/grading', [AdminController::class, 'grading'], [$admin]);
$router->get('/admin/grading/{id}', [AdminController::class, 'gradeForm'], [$admin]);
$router->post('/admin/grading/{id}', [AdminController::class, 'saveGrade'], [$csrf, $admin]);
$router->post('/admin/grading/{id}/return', [AdminController::class, 'returnAssignment'], [$csrf, $admin]);

$router->get('/admin/practice-sets', [AdminController::class, 'practiceSets'], [$admin]);
$router->post('/admin/practice-sets', [AdminController::class, 'createPracticeSet'], [$csrf, $admin]);
$router->post('/admin/practice-sets/{id}/status', [AdminController::class, 'setPracticeStatus'], [$csrf, $admin]);
$router->get('/admin/settings', [AdminController::class, 'settings'], [$admin]);
$router->post('/admin/settings', [AdminController::class, 'saveSettings'], [$csrf, $admin]);

$router->get('/student', [StudentController::class, 'dashboard'], [$student]);
$router->post('/student/study/start', [StudentController::class, 'startStudy'], [$csrf, $student]);
$router->get('/student/study/{id}', [StudentController::class, 'study'], [$student]);
$router->post('/student/study/{id}/question/{itemId}/answer', [StudentController::class, 'answerStudy'], [$csrf, $student]);
$router->post('/student/question/{id}/favorite', [StudentController::class, 'toggleFavorite'], [$csrf, $student]);
$router->post('/student/question/{id}/review-flag', [StudentController::class, 'toggleReviewFlag'], [$csrf, $student]);
$router->get('/student/review-center', [StudentController::class, 'reviewCenter'], [$student]);
$router->get('/student/review', [StudentController::class, 'review'], [$student]);
$router->post('/student/review/{id}/answer', [StudentController::class, 'answerReview'], [$csrf, $student]);
$router->post('/student/assessment/{id}/start', [StudentController::class, 'startAssessment'], [$csrf, $student]);
$router->get('/student/assessment/{id}', [StudentController::class, 'assessment'], [$student]);
$router->post('/student/assessment/{id}/save', [StudentController::class, 'saveAssessment'], [$csrf, $student]);
$router->post('/student/assessment/{id}/submit', [StudentController::class, 'submitAssessment'], [$csrf, $student]);
$router->get('/student/assessment/{id}/result', [StudentController::class, 'assessmentResult'], [$student]);
$router->post('/student/uploads/{id}/delete', [StudentController::class, 'deleteUpload'], [$csrf, $student]);
$router->post('/student/practice/{id}/start', [StudentController::class, 'startPractice'], [$csrf, $student]);
$router->get('/student/practice-attempt/{id}', [StudentController::class, 'practice'], [$student]);
$router->post('/student/practice-attempt/{id}/submit', [StudentController::class, 'submitPractice'], [$csrf, $student]);
$router->get('/student/practice-attempt/{id}/result', [StudentController::class, 'practiceResult'], [$student]);
$router->get('/evidence/{id}', [StudentController::class, 'image'], [$signedIn]);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

