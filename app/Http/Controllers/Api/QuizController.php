<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Progress;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizWrittenAnswer;
use App\Models\QuizWrittenQuestion;
use App\Models\QuizAiEvaluation;
use App\Services\QuizAiEvaluatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QuizController extends Controller
{
    protected $aiEvaluator;

    public function __construct(QuizAiEvaluatorService $aiEvaluator)
    {
        $this->aiEvaluator = $aiEvaluator;
    }

    /**
     * Check chapter completion status for student.
     */
    public function getChapterCompletion(Request $request, $chapterId)
    {
        $user = $request->user();

        $progress = Progress::where('student_id', $user->id)
            ->where('chapter_id', $chapterId)
            ->first();

        $completed = $progress ? ($progress->status === 'completed' || $progress->percent_complete >= 100) : false;

        return response()->json([
            'success' => true,
            'data' => [
                'chapter_id' => (int) $chapterId,
                'completed' => $completed,
                'percent_complete' => $progress ? $progress->percent_complete : 0,
                'status' => $progress ? $progress->status : 'not_started',
            ]
        ]);
    }

    /**
     * GET /api/chapters/{chapterId}/quiz/status
     */
    public function getQuizStatus(Request $request, $chapterId)
    {
        $user = $request->user();

        // 1. Chapter completion check
        $chapterCompleted = false;
        if ($user) {
            $progress = Progress::where('student_id', $user->id)
                ->where('chapter_id', $chapterId)
                ->first();

            $chapterCompleted = $progress ? ($progress->status === 'completed' || $progress->percent_complete >= 100) : false;
        }

        // 2. Quiz presence check
        $quiz = Quiz::where('chapter_id', $chapterId)->where('is_published', true)->first();
        $quizAvailable = (bool) $quiz;

        // 3. Quiz unlocked condition: Chapter completed
        $quizUnlocked = $chapterCompleted && $quizAvailable;

        // 4. Attempts stats
        $attempts = 0;
        $bestScore = 0;

        if ($quiz) {
            $userAttempts = QuizAttempt::where('student_id', $user->id)
                ->where('quiz_id', $quiz->id)
                ->where('status', 'completed')
                ->get();

            $attempts = $userAttempts->count();
            if ($attempts > 0) {
                $bestScore = round($userAttempts->max('percentage'), 1);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'chapter_completed' => $chapterCompleted,
                'quiz_unlocked' => $quizUnlocked,
                'quiz_available' => $quizAvailable,
                'attempts' => $attempts,
                'best_score' => $bestScore,
                'quiz' => $quiz ? [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'total_mcq' => $quiz->total_mcq,
                    'total_written' => $quiz->total_written,
                    'time_limit_minutes' => $quiz->time_limit_minutes,
                    'passing_percentage' => $quiz->passing_percentage,
                ] : null,
            ]
        ]);
    }

    /**
     * GET /api/chapters/{chapterId}/quiz
     * Returns quiz details, MCQs (WITHOUT correct answer), Written questions.
     */
    public function getQuiz(Request $request, $chapterId)
    {
        $chapter = Chapter::with('subject.classLevel')->findOrFail($chapterId);

        $quiz = Quiz::where('chapter_id', $chapterId)->where('is_published', true)->first();

        if (!$quiz) {
            return response()->json([
                'success' => false,
                'message' => 'No active quiz found for this chapter.',
            ], 404);
        }

        $mcqQuestions = QuizQuestion::where('quiz_id', $quiz->id)
            ->orderBy('order_num', 'asc')
            ->get()
            ->map(function ($q) {
                // SECURITY: Never include correct_answer or explanation before quiz completion
                return [
                    'id' => $q->id,
                    'question_text' => $q->question_text,
                    'options' => $q->options,
                    'image_url' => $q->image_url,
                    'difficulty' => $q->difficulty,
                    'order_num' => $q->order_num,
                ];
            });

        $writtenQuestions = QuizWrittenQuestion::where('quiz_id', $quiz->id)
            ->orderBy('order_num', 'asc')
            ->get()
            ->map(function ($q) {
                return [
                    'id' => $q->id,
                    'question_text' => $q->question_text,
                    'expected_answer' => $q->expected_answer,
                    'key_concepts' => $q->key_concepts,
                    'marking_criteria' => $q->marking_criteria,
                    'min_words' => $q->min_words,
                    'max_words' => $q->max_words,
                    'marks' => $q->marks,
                    'order_num' => $q->order_num,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'quiz_id' => $quiz->id,
                'chapter_id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'chapter_title' => $chapter->title,
                'subject_name' => $chapter->subject->name ?? 'Subject',
                'class_name' => $chapter->subject->classLevel->name ?? 'Class',
                'quiz_title' => $quiz->title,
                'description' => $quiz->description,
                'total_mcq' => count($mcqQuestions),
                'total_written' => count($writtenQuestions),
                'total_questions' => count($mcqQuestions) + count($writtenQuestions),
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'passing_percentage' => $quiz->passing_percentage,
                'marks_per_mcq' => $quiz->marks_per_mcq,
                'marks_per_written' => $quiz->marks_per_written,
                'mcq_questions' => $mcqQuestions,
                'written_questions' => $writtenQuestions,
            ]
        ]);
    }

    /**
     * POST /api/chapters/{chapterId}/quiz/start
     */
    public function startQuiz(Request $request, $chapterId)
    {
        $user = $request->user();

        // 1. Backend rule validation: Complete chapter before starting quiz
        $progress = Progress::where('student_id', $user->id)
            ->where('chapter_id', $chapterId)
            ->first();

        $completed = $progress ? ($progress->status === 'completed' || $progress->percent_complete >= 100) : false;

        if (!$completed) {
            return response()->json([
                'success' => false,
                'message' => 'Complete the chapter before starting the quiz.'
            ], 400);
        }

        $quiz = Quiz::where('chapter_id', $chapterId)->where('is_published', true)->firstOrFail();

        // Check active attempt or create new attempt
        $previousAttemptsCount = QuizAttempt::where('student_id', $user->id)
            ->where('quiz_id', $quiz->id)
            ->count();

        $maxScore = ($quiz->total_mcq * $quiz->marks_per_mcq) + ($quiz->total_written * $quiz->marks_per_written);

        $attempt = QuizAttempt::create([
            'student_id' => $user->id,
            'quiz_id' => $quiz->id,
            'chapter_id' => $chapterId,
            'attempt_number' => $previousAttemptsCount + 1,
            'status' => 'in_progress',
            'max_score' => $maxScore > 0 ? $maxScore : 250,
            'started_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'attempt_id' => $attempt->id,
                'quiz_id' => $quiz->id,
                'started_at' => $attempt->started_at->toIso8601String(),
                'total_mcq' => $quiz->total_mcq,
                'total_written' => $quiz->total_written,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'passing_percentage' => $quiz->passing_percentage,
            ]
        ]);
    }

    /**
     * POST /api/quiz-attempts/{attemptId}/mcq-answer
     */
    public function saveMcqAnswer(Request $request, $attemptId)
    {
        $user = $request->user();
        $request->validate([
            'question_id' => 'required|exists:quiz_questions,id',
            'selected_option' => 'nullable|string|max:10',
            'is_marked_for_review' => 'nullable|boolean',
        ]);

        $attempt = QuizAttempt::where('id', $attemptId)
            ->where('student_id', $user->id)
            ->firstOrFail();

        if ($attempt->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Quiz attempt has already been submitted.'], 400);
        }

        $answer = QuizAnswer::updateOrCreate(
            [
                'attempt_id' => $attempt->id,
                'question_id' => $request->question_id,
            ],
            [
                'selected_option' => $request->selected_option,
                'is_marked_for_review' => $request->boolean('is_marked_for_review', false),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $answer
        ]);
    }

    /**
     * POST /api/quiz-attempts/{attemptId}/written-answer
     */
    public function saveWrittenAnswer(Request $request, $attemptId)
    {
        $user = $request->user();
        $request->validate([
            'question_id' => 'required|exists:quiz_written_questions,id',
            'answer' => 'nullable|string',
        ]);

        $attempt = QuizAttempt::where('id', $attemptId)
            ->where('student_id', $user->id)
            ->firstOrFail();

        if ($attempt->status === 'completed') {
            return response()->json(['success' => false, 'message' => 'Quiz attempt has already been submitted.'], 400);
        }

        $answerText = trim($request->input('answer', ''));
        $words = empty($answerText) ? 0 : count(preg_split('/\s+/', $answerText));

        $answer = QuizWrittenAnswer::updateOrCreate(
            [
                'attempt_id' => $attempt->id,
                'written_question_id' => $request->question_id,
            ],
            [
                'answer_text' => $answerText,
                'word_count' => $words,
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $answer
        ]);
    }

    /**
     * POST /api/quiz-attempts/{attemptId}/submit
     */
    public function submitQuiz(Request $request, $attemptId)
    {
        $user = $request->user();

        $attempt = QuizAttempt::with(['quiz', 'chapter.subject.classLevel'])
            ->where('id', $attemptId)
            ->where('student_id', $user->id)
            ->firstOrFail();

        if ($attempt->status === 'completed') {
            return response()->json([
                'success' => true,
                'message' => 'Quiz already submitted.',
                'data' => ['attempt_id' => $attempt->id, 'status' => 'completed']
            ]);
        }

        $attempt->status = 'evaluating';
        $attempt->completed_at = now();
        $attempt->save();

        $quiz = $attempt->quiz;

        // 1. Evaluate MCQs
        $mcqQuestions = QuizQuestion::where('quiz_id', $quiz->id)->get();
        $mcqScore = 0;
        $marksPerMcq = $quiz->marks_per_mcq;

        foreach ($mcqQuestions as $q) {
            $studentAns = QuizAnswer::where('attempt_id', $attempt->id)
                ->where('question_id', $q->id)
                ->first();

            if ($studentAns && !empty($studentAns->selected_option)) {
                $isCorrect = (strtoupper(trim($studentAns->selected_option)) === strtoupper(trim($q->correct_answer)));
                $studentAns->is_correct = $isCorrect;
                $studentAns->save();

                if ($isCorrect) {
                    $mcqScore += $marksPerMcq;
                }
            }
        }

        $attempt->mcq_score = $mcqScore;
        $attempt->save();

        // 2. Evaluate Written Answers using AI Service
        $writtenQuestions = QuizWrittenQuestion::where('quiz_id', $quiz->id)->get();
        $writtenScore = 0;
        $marksPerWritten = $quiz->marks_per_written;

        foreach ($writtenQuestions as $wq) {
            $studentWAns = QuizWrittenAnswer::where('attempt_id', $attempt->id)
                ->where('written_question_id', $wq->id)
                ->first();

            $answerText = $studentWAns ? $studentWAns->answer_text : '';

            $evaluation = $this->aiEvaluator->evaluateWrittenAnswer([
                'class_level' => $attempt->chapter->subject->classLevel->name ?? 'Class 5',
                'subject' => $attempt->chapter->subject->name ?? 'Mathematics',
                'chapter' => $attempt->chapter->title ?? 'Chapter',
                'question_text' => $wq->question_text,
                'expected_answer' => $wq->expected_answer,
                'key_concepts' => $wq->key_concepts,
                'marking_criteria' => $wq->marking_criteria,
                'student_answer' => $answerText,
                'max_score' => $wq->marks ?? $marksPerWritten,
            ]);

            // Save AI evaluation log
            QuizAiEvaluation::create([
                'attempt_id' => $attempt->id,
                'written_question_id' => $wq->id,
                'score' => $evaluation['score'],
                'max_score' => $evaluation['max_score'],
                'percentage' => $evaluation['percentage'],
                'is_correct' => $evaluation['is_correct'],
                'feedback' => $evaluation['feedback'],
                'strengths' => $evaluation['strengths'],
                'improvements' => $evaluation['improvements'],
                'raw_response' => $evaluation,
            ]);

            // Update written answer
            QuizWrittenAnswer::updateOrCreate(
                [
                    'attempt_id' => $attempt->id,
                    'written_question_id' => $wq->id,
                ],
                [
                    'answer_text' => $answerText,
                    'word_count' => empty($answerText) ? 0 : count(preg_split('/\s+/', trim($answerText))),
                    'score' => $evaluation['score'],
                    'max_score' => $evaluation['max_score'],
                    'is_correct' => $evaluation['is_correct'],
                    'feedback' => $evaluation['feedback'],
                    'strengths' => $evaluation['strengths'],
                    'improvements' => $evaluation['improvements'],
                    'ai_evaluated_at' => now(),
                ]
            );

            $writtenScore += $evaluation['score'];
        }

        // 3. Finalize total score and result
        $totalMaxScore = ($mcqQuestions->count() * $marksPerMcq) + ($writtenQuestions->count() * $marksPerWritten);
        $totalMaxScore = $totalMaxScore > 0 ? $totalMaxScore : 250;
        $totalScore = $mcqScore + $writtenScore;
        $percentage = round(($totalScore / $totalMaxScore) * 100, 1);
        $passingPercentage = $quiz->passing_percentage ?? 60.0;
        $isPassed = $percentage >= $passingPercentage;

        $attempt->written_score = round($writtenScore, 1);
        $attempt->total_score = round($totalScore, 1);
        $attempt->max_score = $totalMaxScore;
        $attempt->percentage = $percentage;
        $attempt->is_passed = $isPassed;
        $attempt->status = 'completed';
        $attempt->save();

        return response()->json([
            'success' => true,
            'message' => 'Quiz evaluated and submitted successfully.',
            'data' => [
                'attempt_id' => $attempt->id,
                'status' => 'completed',
                'total_score' => $attempt->total_score,
                'max_score' => $attempt->max_score,
                'percentage' => $attempt->percentage,
                'is_passed' => $attempt->is_passed,
            ]
        ]);
    }

    /**
     * GET /api/quiz-attempts/{attemptId}/evaluation-status
     */
    public function getEvaluationStatus(Request $request, $attemptId)
    {
        $user = $request->user();
        $attempt = QuizAttempt::where('id', $attemptId)
            ->where('student_id', $user->id)
            ->firstOrFail();

        $quiz = Quiz::find($attempt->quiz_id);
        $totalWritten = $quiz ? $quiz->total_written : 20;

        $evaluatedCount = QuizWrittenAnswer::where('attempt_id', $attempt->id)
            ->whereNotNull('ai_evaluated_at')
            ->count();

        return response()->json([
            'status' => $attempt->status,
            'completed' => $evaluatedCount,
            'total' => $totalWritten,
        ]);
    }

    /**
     * GET /api/quiz-attempts/{attemptId}/result
     */
    public function getResult(Request $request, $attemptId)
    {
        $user = $request->user();

        $attempt = QuizAttempt::with(['quiz', 'chapter.subject.classLevel'])
            ->where('id', $attemptId)
            ->where('student_id', $user->id)
            ->firstOrFail();

        $quiz = $attempt->quiz;

        // MCQ detailed results
        $mcqQuestions = QuizQuestion::where('quiz_id', $quiz->id)->orderBy('order_num', 'asc')->get();
        $mcqAnswers = QuizAnswer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');

        $mcqCorrectCount = 0;
        $mcqWrongCount = 0;
        $mcqUnansweredCount = 0;

        $detailedMcqs = $mcqQuestions->map(function ($q) use ($mcqAnswers, &$mcqCorrectCount, &$mcqWrongCount, &$mcqUnansweredCount) {
            $ans = $mcqAnswers->get($q->id);
            $selected = $ans ? $ans->selected_option : null;

            if (empty($selected)) {
                $mcqUnansweredCount++;
                $status = 'unanswered';
            } elseif ($ans->is_correct || (strtoupper($selected) === strtoupper($q->correct_answer))) {
                $mcqCorrectCount++;
                $status = 'correct';
            } else {
                $mcqWrongCount++;
                $status = 'wrong';
            }

            return [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'options' => $q->options,
                'correct_answer' => $q->correct_answer,
                'selected_option' => $selected,
                'is_correct' => $ans ? $ans->is_correct : false,
                'explanation' => $q->explanation,
                'status' => $status,
            ];
        });

        // Written detailed results
        $writtenQuestions = QuizWrittenQuestion::where('quiz_id', $quiz->id)->orderBy('order_num', 'asc')->get();
        $writtenAnswers = QuizWrittenAnswer::where('attempt_id', $attempt->id)->get()->keyBy('written_question_id');

        $detailedWritten = $writtenQuestions->map(function ($wq) use ($writtenAnswers) {
            $wAns = $writtenAnswers->get($wq->id);
            return [
                'id' => $wq->id,
                'question_text' => $wq->question_text,
                'expected_answer' => $wq->expected_answer,
                'student_answer' => $wAns ? $wAns->answer_text : '',
                'word_count' => $wAns ? $wAns->word_count : 0,
                'score' => $wAns ? $wAns->score : 0,
                'max_score' => $wq->marks ?? 10,
                'is_correct' => $wAns ? $wAns->is_correct : false,
                'feedback' => $wAns ? $wAns->feedback : '',
                'strengths' => $wAns ? ($wAns->strengths ?? []) : [],
                'improvements' => $wAns ? ($wAns->improvements ?? []) : [],
            ];
        });

        $mcqTotalCount = count($mcqQuestions);
        $writtenTotalCount = count($writtenQuestions);
        $mcqMaxScore = $mcqTotalCount * $quiz->marks_per_mcq;
        $writtenMaxScore = $writtenTotalCount * $quiz->marks_per_written;

        $timeTakenSeconds = $attempt->completed_at && $attempt->started_at
            ? $attempt->completed_at->diffInSeconds($attempt->started_at)
            : $attempt->time_spent_seconds;

        $minutes = floor($timeTakenSeconds / 60);
        $secs = $timeTakenSeconds % 60;
        $timeTakenFormatted = "{$minutes}m {$secs}s";

        return response()->json([
            'success' => true,
            'data' => [
                'attempt_id' => $attempt->id,
                'chapter_id' => $attempt->chapter_id,
                'chapter_title' => $attempt->chapter->title ?? 'Chapter',
                'chapter_number' => $attempt->chapter->chapter_number ?? 1,
                'subject_name' => $attempt->chapter->subject->name ?? 'Subject',
                'class_name' => $attempt->chapter->subject->classLevel->name ?? 'Class',
                'attempt_date' => $attempt->completed_at ? $attempt->completed_at->toIso8601String() : $attempt->updated_at->toIso8601String(),
                'time_taken' => $timeTakenFormatted,
                'mcq' => [
                    'total' => $mcqTotalCount,
                    'correct' => $mcqCorrectCount,
                    'wrong' => $mcqWrongCount,
                    'unanswered' => $mcqUnansweredCount,
                    'score' => $attempt->mcq_score,
                    'max_score' => $mcqMaxScore,
                ],
                'written' => [
                    'total' => $writtenTotalCount,
                    'score' => $attempt->written_score,
                    'max_score' => $writtenMaxScore,
                ],
                'total_score' => $attempt->total_score,
                'max_score' => $attempt->max_score,
                'percentage' => $attempt->percentage,
                'passing_percentage' => $quiz->passing_percentage,
                'status' => $attempt->is_passed ? 'passed' : 'failed',
                'result' => $attempt->is_passed ? 'Passed' : 'Keep Practicing',
                'detailed_mcq' => $detailedMcqs,
                'detailed_written' => $detailedWritten,
            ]
        ]);
    }

    /**
     * GET /api/quiz-attempts/history
     */
    public function getAttemptHistory(Request $request)
    {
        $user = $request->user();

        $attempts = QuizAttempt::with(['chapter.subject.classLevel', 'quiz'])
            ->where('student_id', $user->id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($att) {
                return [
                    'id' => $att->id,
                    'chapter_id' => $att->chapter_id,
                    'chapter_title' => $att->chapter->title ?? "Chapter {$att->chapter_id}",
                    'chapter_number' => $att->chapter->chapter_number ?? 1,
                    'subject_name' => $att->chapter->subject->name ?? 'Subject',
                    'class_name' => $att->chapter->subject->classLevel->name ?? 'Class',
                    'attempt_number' => $att->attempt_number,
                    'total_score' => $att->total_score,
                    'max_score' => $att->max_score,
                    'percentage' => $att->percentage,
                    'status' => $att->is_passed ? 'passed' : 'failed',
                    'result_label' => $att->is_passed ? 'Passed' : 'Keep Practicing',
                    'completed_at' => $att->completed_at ? $att->completed_at->toIso8601String() : $att->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $attempts
        ]);
    }

    /**
     * POST /api/quiz/{quizAttemptId}/evaluate-written
     * Single written answer API endpoint (Prompt section 9)
     */
    public function evaluateSingleWritten(Request $request, $quizAttemptId)
    {
        $request->validate([
            'question_id' => 'required|exists:quiz_written_questions,id',
            'answer' => 'required|string',
        ]);

        $attempt = QuizAttempt::with(['chapter.subject.classLevel'])->findOrFail($quizAttemptId);
        $wq = QuizWrittenQuestion::findOrFail($request->question_id);

        $eval = $this->aiEvaluator->evaluateWrittenAnswer([
            'class_level' => $attempt->chapter->subject->classLevel->name ?? 'Class 5',
            'subject' => $attempt->chapter->subject->name ?? 'Mathematics',
            'chapter' => $attempt->chapter->title ?? 'Chapter',
            'question_text' => $wq->question_text,
            'expected_answer' => $wq->expected_answer,
            'key_concepts' => $wq->key_concepts,
            'marking_criteria' => $wq->marking_criteria,
            'student_answer' => $request->answer,
            'max_score' => $wq->marks ?? 10,
        ]);

        return response()->json([
            'success' => true,
            'data' => $eval
        ]);
    }

    /**
     * POST /api/quiz/{quizAttemptId}/evaluate-written-batch
     * Batch written answers evaluation API (Prompt section 9)
     */
    public function evaluateBatchWritten(Request $request, $quizAttemptId)
    {
        $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:quiz_written_questions,id',
            'answers.*.answer' => 'required|string',
        ]);

        $attempt = QuizAttempt::with(['chapter.subject.classLevel'])->findOrFail($quizAttemptId);
        $results = [];

        foreach ($request->answers as $item) {
            $wq = QuizWrittenQuestion::find($item['question_id']);
            if ($wq) {
                $eval = $this->aiEvaluator->evaluateWrittenAnswer([
                    'class_level' => $attempt->chapter->subject->classLevel->name ?? 'Class 5',
                    'subject' => $attempt->chapter->subject->name ?? 'Mathematics',
                    'chapter' => $attempt->chapter->title ?? 'Chapter',
                    'question_text' => $wq->question_text,
                    'expected_answer' => $wq->expected_answer,
                    'key_concepts' => $wq->key_concepts,
                    'marking_criteria' => $wq->marking_criteria,
                    'student_answer' => $item['answer'],
                    'max_score' => $wq->marks ?? 10,
                ]);
                $results[] = [
                    'question_id' => $wq->id,
                    'evaluation' => $eval,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $results
        ]);
    }
}
