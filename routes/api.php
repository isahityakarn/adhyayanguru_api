<?php

use App\Http\Controllers\Api\Admin\ChapterUploadController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\AiTutorController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\ChapterController;
use App\Http\Controllers\Api\ClassLevelController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\Admin\AdminQuizController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/auth/send-otp', [AuthController::class, 'sendOtp']);
Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/signup', [AuthController::class, 'signup']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/boards', [BoardController::class, 'index']);
Route::get('/class-levels', [ClassLevelController::class, 'index']);
Route::get('/subjects', [SubjectController::class, 'index']);
Route::get('/chapters', [ChapterController::class, 'index']);
Route::get('/chapters/{id}/questions', [QuestionController::class, 'indexByChapter']);
Route::get('/questions', [QuestionController::class, 'index']);
Route::get('/plans', [PlanController::class, 'index']);
Route::post('/coqui-tts', [AiTutorController::class, 'coquiTts']);
Route::post('/ai-tutor/coqui-tts', [AiTutorController::class, 'coquiTts']);
Route::post('/edge-tts', [AiTutorController::class, 'edgeTts']);
Route::post('/ai-tutor/edge-tts', [AiTutorController::class, 'edgeTts']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'getUser']);

    // Chapter Quiz System Routes (Student)
    Route::get('/chapters/{chapterId}/completion', [QuizController::class, 'getChapterCompletion']);
    Route::get('/chapters/{chapterId}/quiz/status', [QuizController::class, 'getQuizStatus']);
    Route::get('/chapters/{chapterId}/quiz', [QuizController::class, 'getQuiz']);
    Route::post('/chapters/{chapterId}/quiz/start', [QuizController::class, 'startQuiz']);

    Route::get('/chapters/{id}', [ChapterController::class, 'show']);

    // Progress & Chapter Time Tracking & Parent Report Routes
    Route::prefix('progress')->group(function () {
        Route::post('/track-time', [ProgressController::class, 'trackTime']);
        Route::post('/update', [ProgressController::class, 'updateProgress']);
        Route::get('/chapter/{chapterId}', [ProgressController::class, 'getChapterProgress']);
        Route::get('/parent-report', [ProgressController::class, 'parentReport']);
        Route::get('/summary', [ProgressController::class, 'parentReport']);
    });

    // AI Tutor routes (support both /tutor and /ai-tutor endpoints)
    Route::prefix('ai-tutor')->group(function () {
        Route::post('/chat', [AiTutorController::class, 'chat']);
        Route::post('/coqui-tts', [AiTutorController::class, 'coquiTts']);
        Route::post('/edge-tts', [AiTutorController::class, 'edgeTts']);
        Route::post('/explain', [AiTutorController::class, 'explainTopic']);
        Route::post('/questions', [AiTutorController::class, 'generateQuestions']);
    });

    Route::prefix('tutor')->group(function () {
        Route::post('/chat', [AiTutorController::class, 'chat']);
        Route::post('/tts', [AiTutorController::class, 'tts']);
        Route::post('/coqui-tts', [AiTutorController::class, 'coquiTts']);
        Route::post('/edge-tts', [AiTutorController::class, 'edgeTts']);
        Route::post('/explain', [AiTutorController::class, 'explainTopic']);
        Route::post('/questions', [AiTutorController::class, 'generateQuestions']);
    });

    // Chapter Quiz System Routes (Student)
    Route::get('/chapters/{chapterId}/completion', [QuizController::class, 'getChapterCompletion']);
    Route::get('/chapters/{chapterId}/quiz/status', [QuizController::class, 'getQuizStatus']);
    Route::get('/chapters/{chapterId}/quiz', [QuizController::class, 'getQuiz']);
    Route::post('/chapters/{chapterId}/quiz/start', [QuizController::class, 'startQuiz']);
    Route::post('/quiz-attempts/{attemptId}/mcq-answer', [QuizController::class, 'saveMcqAnswer']);
    Route::post('/quiz-attempts/{attemptId}/written-answer', [QuizController::class, 'saveWrittenAnswer']);
    Route::post('/quiz-attempts/{attemptId}/submit', [QuizController::class, 'submitQuiz']);
    Route::get('/quiz-attempts/{attemptId}/evaluation-status', [QuizController::class, 'getEvaluationStatus']);
    Route::get('/quiz-attempts/{attemptId}/result', [QuizController::class, 'getResult']);
    Route::get('/quiz-attempts/history', [QuizController::class, 'getAttemptHistory']);
    Route::post('/quiz/{quizAttemptId}/evaluate-written', [QuizController::class, 'evaluateSingleWritten']);
    Route::post('/quiz/{quizAttemptId}/evaluate-written-batch', [QuizController::class, 'evaluateBatchWritten']);

    // Admin routes
    Route::prefix('admin')->middleware(['admin', 'throttle:api'])->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'dashboard']);
        Route::get('/classes', [AdminDashboardController::class, 'classes']);
        Route::get('/classes/{classId}', [AdminDashboardController::class, 'classDetails']);
        Route::put('/classes/{classId}', [AdminDashboardController::class, 'updateClass']);
        Route::get('/classes/{classId}/subjects', [AdminDashboardController::class, 'subjects']);
        Route::get('/subjects/{subjectId}/chapters', [AdminDashboardController::class, 'chapters']);
        Route::put('/chapters/{chapterId}', [AdminDashboardController::class, 'updateChapter']);
        Route::get('/pdfs', [AdminDashboardController::class, 'pdfs']);
        Route::post('/pdfs', [AdminDashboardController::class, 'uploadPdf']);
        Route::put('/pdfs/{pdfId}', [AdminDashboardController::class, 'updatePdf']);
        Route::delete('/pdfs/{pdfId}', [AdminDashboardController::class, 'deletePdf']);
        Route::get('/pdfs/{pdfId}/preview', [AdminDashboardController::class, 'preview']);
        Route::get('/pdfs/{pdfId}/download', [AdminDashboardController::class, 'download']);
        Route::get('/pdfs/{pdfId}/preview/file', [AdminDashboardController::class, 'previewFile']);
        Route::post('/pdfs/bulk-download', [AdminDashboardController::class, 'bulkDownload']);
        Route::post('/classes/{classId}/bulk-download', [AdminDashboardController::class, 'classBulkDownload']);
        Route::post('/subjects/{subjectId}/bulk-download', [AdminDashboardController::class, 'subjectBulkDownload']);
        Route::get('/bulk-downloads/{filename}', [AdminDashboardController::class, 'bulkFile']);
        Route::get('/stats', [ChapterUploadController::class, 'stats']);
        Route::get('/upload', [ChapterUploadController::class, 'index']);
        Route::get('/subjects', [ChapterUploadController::class, 'getSubjects']);
        Route::post('/upload', [ChapterUploadController::class, 'upload']);
        Route::get('/chapters', [ChapterUploadController::class, 'chapters']);
        Route::get('/chapters/{id}', [ChapterUploadController::class, 'getChapter']);
        Route::post('/chapters/{id}/reprocess', [ChapterUploadController::class, 'reprocess']);
        Route::post('/chapters/{id}/generate-questions', [ChapterUploadController::class, 'generateMoreQuestions']);
        Route::delete('/chapters/{id}', [ChapterUploadController::class, 'deleteChapter']);
        Route::post('/batch-process', [ChapterUploadController::class, 'batchProcess']);

        // Subjects CRUD
        Route::get('/subjects-list', [SubjectController::class, 'adminIndex']);
        Route::post('/subjects', [SubjectController::class, 'store']);
        Route::get('/subjects/{id}', [SubjectController::class, 'show']);
        Route::put('/subjects/{id}', [SubjectController::class, 'update']);
        Route::delete('/subjects/{id}', [SubjectController::class, 'destroy']);

        // Questions CRUD
        Route::get('/questions', [QuestionController::class, 'index']);
        Route::post('/questions', [QuestionController::class, 'store']);
        Route::get('/questions/{id}', [QuestionController::class, 'show']);
        Route::put('/questions/{id}', [QuestionController::class, 'update']);
        Route::delete('/questions/{id}', [QuestionController::class, 'destroy']);

        // Admin Quiz Management Routes
        Route::post('/quizzes/import-json', [AdminQuizController::class, 'importJson']);
        Route::get('/quizzes/dashboard', [AdminQuizController::class, 'dashboard']);
        Route::post('/chapters/{chapterId}/quiz-config', [AdminQuizController::class, 'saveQuizConfig']);
        Route::post('/quizzes/{quizId}/mcq', [AdminQuizController::class, 'addMcq']);
        Route::put('/quizzes/mcq/{questionId}', [AdminQuizController::class, 'updateMcq']);
        Route::delete('/quizzes/mcq/{questionId}', [AdminQuizController::class, 'deleteMcq']);
        Route::post('/quizzes/{quizId}/written', [AdminQuizController::class, 'addWritten']);
        Route::put('/quizzes/written/{questionId}', [AdminQuizController::class, 'updateWritten']);
        Route::delete('/quizzes/written/{questionId}', [AdminQuizController::class, 'deleteWritten']);
        Route::post('/quizzes/{quizId}/toggle-publish', [AdminQuizController::class, 'togglePublish']);
        Route::get('/quizzes/{quizId}/attempts', [AdminQuizController::class, 'viewStudentAttempts']);
        Route::get('/quizzes/{quizId}/export', [AdminQuizController::class, 'exportResults']);
    });
});

