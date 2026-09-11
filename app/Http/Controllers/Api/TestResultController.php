<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizWrittenAnswer;
use App\Models\QuizWrittenQuestion;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\Request;

class TestResultController extends Controller
{
    /**
     * Determine authorized student from request.
     */
    protected function resolveStudent(Request $request)
    {
        $user = $request->user();
        $targetStudentId = $request->input('student_id');

        if ($targetStudentId && ($user->role === 'admin' || $user->role === 'parent' || $user->id == $targetStudentId)) {
            return User::with('studentProfile.classLevel', 'studentProfile.board')->find($targetStudentId) ?: $user;
        }

        if (!$user->relationLoaded('studentProfile')) {
            $user->load('studentProfile.classLevel', 'studentProfile.board');
        }

        return $user;
    }

    /**
     * GET /api/student/test-results
     * Returns subject and chapter-wise test results for MCQ and Subjective questions.
     */
    public function index(Request $request)
    {
        $student = $this->resolveStudent($request);
        $subjectFilterId = $request->input('subject_id');
        $chapterFilterId = $request->input('chapter_id');
        $attemptFilterId = $request->input('attempt_id');

        // Fetch subjects
        $subjectsQuery = Subject::with(['classLevel', 'board', 'chapters' => function ($q) {
            $q->orderBy('chapter_number', 'asc');
        }]);

        if ($subjectFilterId) {
            $subjectsQuery->where('id', $subjectFilterId);
        } elseif ($student->studentProfile && $student->studentProfile->class_id) {
            $subjectsQuery->where('class_id', $student->studentProfile->class_id);
        }

        $subjects = $subjectsQuery->get();

        // Fallback: If class filter returned no subjects, return all subjects
        if ($subjects->isEmpty() && !$subjectFilterId) {
            $subjects = Subject::with(['classLevel', 'board', 'chapters' => function ($q) {
                $q->orderBy('chapter_number', 'asc');
            }])->get();
        }

        $allCompletedAttempts = QuizAttempt::where('student_id', $student->id)
            ->where('status', 'completed')
            ->get();

        $overallTotalTests = $allCompletedAttempts->count();
        $overallPassedTests = $allCompletedAttempts->where('is_passed', true)->count();
        $overallFailedTests = $overallTotalTests - $overallPassedTests;

        $overallMcqScore = (float) $allCompletedAttempts->sum('mcq_score');
        $overallWrittenScore = (float) $allCompletedAttempts->sum('written_score');
        $overallTotalScore = (float) $allCompletedAttempts->sum('total_score');
        $overallMaxScore = (float) $allCompletedAttempts->sum('max_score');
        $overallPercentage = $overallMaxScore > 0 ? round(($overallTotalScore / $overallMaxScore) * 100, 1) : 0;

        $subjectsData = [];

        foreach ($subjects as $subj) {
            $chaptersData = [];
            $subjAttemptsCount = 0;
            $subjTotalScore = 0;
            $subjMaxScore = 0;
            $subjMcqScore = 0;
            $subjMcqMaxScore = 0;
            $subjWrittenScore = 0;
            $subjWrittenMaxScore = 0;
            $subjPassedCount = 0;

            $chapters = $subj->chapters;
            if ($chapterFilterId) {
                $chapters = $chapters->where('id', $chapterFilterId);
            }

            foreach ($chapters as $ch) {
                $quiz = Quiz::where('chapter_id', $ch->id)->where('is_published', true)->first();

                $attempts = $allCompletedAttempts->where('chapter_id', $ch->id)->sortByDesc('created_at')->values();
                $hasAttempts = $attempts->count() > 0;

                $bestAttempt = $hasAttempts ? $attempts->sortByDesc('percentage')->first() : null;
                $latestAttempt = $hasAttempts ? $attempts->first() : null;

                $mcqMaxForQuiz = $quiz ? ($quiz->total_mcq * $quiz->marks_per_mcq) : ($bestAttempt ? 50 : 0);
                $writtenMaxForQuiz = $quiz ? ($quiz->total_written * $quiz->marks_per_written) : ($bestAttempt ? 200 : 0);
                $totalMaxForQuiz = $quiz ? ($mcqMaxForQuiz + $writtenMaxForQuiz) : ($bestAttempt ? $bestAttempt->max_score : 0);

                if ($bestAttempt) {
                    $subjAttemptsCount += $attempts->count();
                    $subjTotalScore += $bestAttempt->total_score;
                    $subjMaxScore += ($bestAttempt->max_score > 0 ? $bestAttempt->max_score : $totalMaxForQuiz);
                    $subjMcqScore += $bestAttempt->mcq_score;
                    $subjMcqMaxScore += ($mcqMaxForQuiz > 0 ? $mcqMaxForQuiz : 50);
                    $subjWrittenScore += $bestAttempt->written_score;
                    $subjWrittenMaxScore += ($writtenMaxForQuiz > 0 ? $writtenMaxForQuiz : 200);

                    if ($bestAttempt->is_passed) {
                        $subjPassedCount++;
                    }
                }

                $formattedAttempts = $attempts->map(function ($att) {
                    return [
                        'id' => $att->id,
                        'attempt_number' => $att->attempt_number,
                        'mcq_score' => (float) $att->mcq_score,
                        'written_score' => (float) $att->written_score,
                        'total_score' => (float) $att->total_score,
                        'max_score' => (float) $att->max_score,
                        'percentage' => (float) $att->percentage,
                        'is_passed' => (bool) $att->is_passed,
                        'status' => $att->is_passed ? 'passed' : 'failed',
                        'time_spent_seconds' => $att->time_spent_seconds,
                        'started_at' => $att->started_at ? $att->started_at->toIso8601String() : null,
                        'completed_at' => $att->completed_at ? $att->completed_at->toIso8601String() : null,
                    ];
                });

                $chaptersData[] = [
                    'id' => $ch->id,
                    'chapter_number' => $ch->chapter_number ?? 1,
                    'title' => $ch->title,
                    'subject_id' => $subj->id,
                    'subject_name' => $subj->name,
                    'has_quiz' => (bool) $quiz,
                    'quiz' => $quiz ? [
                        'id' => $quiz->id,
                        'title' => $quiz->title,
                        'total_mcq' => $quiz->total_mcq,
                        'total_written' => $quiz->total_written,
                        'passing_percentage' => $quiz->passing_percentage,
                        'time_limit_minutes' => $quiz->time_limit_minutes,
                        'marks_per_mcq' => $quiz->marks_per_mcq,
                        'marks_per_written' => $quiz->marks_per_written,
                    ] : null,
                    'attempts_count' => $attempts->count(),
                    'is_attempted' => $hasAttempts,
                    'is_passed' => $bestAttempt ? (bool) $bestAttempt->is_passed : false,
                    'status' => !$hasAttempts ? 'not_attempted' : ($bestAttempt->is_passed ? 'passed' : 'needs_practice'),
                    'best_score' => $bestAttempt ? (float) $bestAttempt->total_score : 0,
                    'max_score' => $totalMaxForQuiz > 0 ? $totalMaxForQuiz : ($bestAttempt ? $bestAttempt->max_score : 0),
                    'best_percentage' => $bestAttempt ? (float) $bestAttempt->percentage : 0,
                    'best_mcq_score' => $bestAttempt ? (float) $bestAttempt->mcq_score : 0,
                    'max_mcq_score' => $mcqMaxForQuiz,
                    'best_written_score' => $bestAttempt ? (float) $bestAttempt->written_score : 0,
                    'max_written_score' => $writtenMaxForQuiz,
                    'latest_attempt_id' => $latestAttempt ? $latestAttempt->id : null,
                    'latest_score' => $latestAttempt ? (float) $latestAttempt->total_score : 0,
                    'latest_percentage' => $latestAttempt ? (float) $latestAttempt->percentage : 0,
                    'latest_completed_at' => $latestAttempt && $latestAttempt->completed_at ? $latestAttempt->completed_at->toIso8601String() : null,
                    'attempts' => $formattedAttempts,
                ];
            }

            $chaptersWithQuizCount = collect($chaptersData)->where('has_quiz', true)->count();
            $chaptersAttemptedCount = collect($chaptersData)->where('is_attempted', true)->count();

            $subjPct = $subjMaxScore > 0 ? round(($subjTotalScore / $subjMaxScore) * 100, 1) : 0;
            $subjMcqPct = $subjMcqMaxScore > 0 ? round(($subjMcqScore / $subjMcqMaxScore) * 100, 1) : 0;
            $subjWrittenPct = $subjWrittenMaxScore > 0 ? round(($subjWrittenScore / $subjWrittenMaxScore) * 100, 1) : 0;

            $subjectsData[] = [
                'id' => $subj->id,
                'name' => $subj->name,
                'class_name' => $subj->classLevel->name ?? '',
                'board_name' => $subj->board->name ?? '',
                'total_chapters' => count($chaptersData),
                'chapters_with_quiz' => $chaptersWithQuizCount,
                'chapters_attempted' => $chaptersAttemptedCount,
                'total_attempts' => $subjAttemptsCount,
                'passed_chapters_count' => $subjPassedCount,
                'average_percentage' => $subjPct,
                'mcq_percentage' => $subjMcqPct,
                'written_percentage' => $subjWrittenPct,
                'mcq_score' => round($subjMcqScore, 1),
                'mcq_max_score' => $subjMcqMaxScore,
                'written_score' => round($subjWrittenScore, 1),
                'written_max_score' => $subjWrittenMaxScore,
                'total_score' => round($subjTotalScore, 1),
                'total_max_score' => $subjMaxScore,
                'chapters' => $chaptersData,
            ];
        }

        // Detailed attempt resolution if attempt_id was passed
        $detailedAttemptData = null;
        if ($attemptFilterId) {
            $detailedAttemptData = $this->buildAttemptDetail($attemptFilterId, $student->id);
        }

        return response()->json([
            'success' => true,
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'role' => $student->role,
                'class' => $student->studentProfile->classLevel->name ?? 'Class',
                'board' => $student->studentProfile->board->name ?? 'Board',
            ],
            'summary' => [
                'total_subjects' => count($subjectsData),
                'total_tests_attempted' => $overallTotalTests,
                'passed_tests' => $overallPassedTests,
                'failed_tests' => $overallFailedTests,
                'overall_percentage' => $overallPercentage,
                'mcq_overall' => [
                    'score' => round($overallMcqScore, 1),
                    'percentage' => $overallMaxScore > 0 && ($overallMcqScore + $overallWrittenScore) > 0
                        ? round(($overallMcqScore / max(1, $allCompletedAttempts->count() * 50)) * 100, 1)
                        : 0,
                ],
                'subjective_overall' => [
                    'score' => round($overallWrittenScore, 1),
                    'percentage' => $overallMaxScore > 0 && ($overallMcqScore + $overallWrittenScore) > 0
                        ? round(($overallWrittenScore / max(1, $allCompletedAttempts->count() * 200)) * 100, 1)
                        : 0,
                ],
            ],
            'subjects' => $subjectsData,
            'detailed_attempt' => $detailedAttemptData,
        ]);
    }

    /**
     * GET /api/student/test-results/attempt/{attemptId}
     * Granular breakdown of MCQ questions and AI-evaluated Subjective questions.
     */
    public function getAttemptDetail(Request $request, $attemptId)
    {
        $student = $this->resolveStudent($request);
        $detail = $this->buildAttemptDetail($attemptId, $student->id);

        if (!$detail) {
            return response()->json([
                'success' => false,
                'message' => 'Test attempt not found or unauthorized access.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $detail,
        ]);
    }

    /**
     * Build detailed attempt payload with MCQ and Written questions.
     */
    protected function buildAttemptDetail($attemptId, $studentId)
    {
        $attempt = QuizAttempt::with(['quiz', 'chapter.subject.classLevel', 'student'])
            ->where('id', $attemptId)
            ->where(function ($query) use ($studentId) {
                $query->where('student_id', $studentId)
                      ->orWhereRaw('? IN (SELECT id FROM users WHERE role = "admin")', [$studentId]);
            })
            ->first();

        if (!$attempt) {
            return null;
        }

        $quiz = $attempt->quiz;
        if (!$quiz) {
            $quiz = Quiz::where('chapter_id', $attempt->chapter_id)->first();
        }

        // 1. MCQ Breakdown
        $mcqQuestions = $quiz
            ? QuizQuestion::where('quiz_id', $quiz->id)->orderBy('order_num', 'asc')->get()
            : collect([]);

        $mcqAnswers = QuizAnswer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');

        $mcqCorrectCount = 0;
        $mcqWrongCount = 0;
        $mcqUnansweredCount = 0;
        $marksPerMcq = $quiz ? $quiz->marks_per_mcq : 1;

        $detailedMcqs = $mcqQuestions->map(function ($q) use ($mcqAnswers, $marksPerMcq, &$mcqCorrectCount, &$mcqWrongCount, &$mcqUnansweredCount) {
            $ans = $mcqAnswers->get($q->id);
            $selected = $ans ? $ans->selected_option : null;

            if (empty($selected)) {
                $mcqUnansweredCount++;
                $status = 'unanswered';
                $isCorrect = false;
            } elseif ($ans->is_correct || (strtoupper(trim($selected)) === strtoupper(trim($q->correct_answer)))) {
                $mcqCorrectCount++;
                $status = 'correct';
                $isCorrect = true;
            } else {
                $mcqWrongCount++;
                $status = 'wrong';
                $isCorrect = false;
            }

            return [
                'id' => $q->id,
                'order_num' => $q->order_num,
                'question_text' => $q->question_text,
                'options' => $q->options,
                'selected_option' => $selected,
                'correct_answer' => $q->correct_answer,
                'is_correct' => $isCorrect,
                'status' => $status,
                'marks_obtained' => $isCorrect ? $marksPerMcq : 0,
                'max_marks' => $marksPerMcq,
                'explanation' => $q->explanation,
                'difficulty' => $q->difficulty,
            ];
        });

        // 2. Subjective (Written) Breakdown
        $writtenQuestions = $quiz
            ? QuizWrittenQuestion::where('quiz_id', $quiz->id)->orderBy('order_num', 'asc')->get()
            : collect([]);

        $writtenAnswers = QuizWrittenAnswer::where('attempt_id', $attempt->id)->get()->keyBy('written_question_id');

        $detailedWritten = $writtenQuestions->map(function ($wq) use ($writtenAnswers) {
            $wAns = $writtenAnswers->get($wq->id);
            $maxScore = $wq->marks ?? 10;
            $score = $wAns ? (float) $wAns->score : 0;
            $pct = $maxScore > 0 ? round(($score / $maxScore) * 100, 1) : 0;

            return [
                'id' => $wq->id,
                'order_num' => $wq->order_num,
                'question_text' => $wq->question_text,
                'expected_answer' => $wq->expected_answer,
                'key_concepts' => $wq->key_concepts ?? [],
                'marking_criteria' => $wq->marking_criteria,
                'min_words' => $wq->min_words,
                'max_words' => $wq->max_words,
                'max_score' => $maxScore,
                'student_answer' => $wAns ? $wAns->answer_text : '',
                'word_count' => $wAns ? $wAns->word_count : 0,
                'score' => $score,
                'percentage' => $pct,
                'is_correct' => $wAns ? (bool) $wAns->is_correct : ($pct >= 60),
                'feedback' => $wAns ? $wAns->feedback : 'No feedback available.',
                'strengths' => $wAns && is_array($wAns->strengths) ? $wAns->strengths : [],
                'improvements' => $wAns && is_array($wAns->improvements) ? $wAns->improvements : [],
                'ai_evaluated_at' => $wAns && $wAns->ai_evaluated_at ? $wAns->ai_evaluated_at->toIso8601String() : null,
            ];
        });

        $mcqTotalCount = count($detailedMcqs);
        $writtenTotalCount = count($detailedWritten);
        $mcqMaxScore = $mcqTotalCount * ($quiz ? $quiz->marks_per_mcq : 1);
        $writtenMaxScore = $detailedWritten->sum('max_score');

        $timeTakenSeconds = $attempt->completed_at && $attempt->started_at
            ? $attempt->completed_at->diffInSeconds($attempt->started_at)
            : $attempt->time_spent_seconds;

        $minutes = floor($timeTakenSeconds / 60);
        $secs = $timeTakenSeconds % 60;
        $formattedTime = "{$minutes}m {$secs}s";

        return [
            'attempt_id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'chapter_id' => $attempt->chapter_id,
            'chapter_title' => $attempt->chapter->title ?? "Chapter {$attempt->chapter_id}",
            'chapter_number' => $attempt->chapter->chapter_number ?? 1,
            'subject_id' => $attempt->chapter->subject_id ?? null,
            'subject_name' => $attempt->chapter->subject->name ?? 'Subject',
            'class_name' => $attempt->chapter->subject->classLevel->name ?? 'Class',
            'student_name' => $attempt->student->name ?? 'Student',
            'status' => $attempt->is_passed ? 'passed' : 'failed',
            'result_label' => $attempt->is_passed ? 'Passed' : 'Needs Practice',
            'is_passed' => (bool) $attempt->is_passed,
            'passing_percentage' => $quiz ? $quiz->passing_percentage : 60.0,
            'total_score' => (float) $attempt->total_score,
            'max_score' => (float) $attempt->max_score,
            'percentage' => (float) $attempt->percentage,
            'time_taken' => $formattedTime,
            'time_spent_seconds' => $timeTakenSeconds,
            'started_at' => $attempt->started_at ? $attempt->started_at->toIso8601String() : null,
            'completed_at' => $attempt->completed_at ? $attempt->completed_at->toIso8601String() : null,
            'mcq' => [
                'total_questions' => $mcqTotalCount,
                'correct' => $mcqCorrectCount,
                'wrong' => $mcqWrongCount,
                'unanswered' => $mcqUnansweredCount,
                'score' => (float) $attempt->mcq_score,
                'max_score' => $mcqMaxScore,
                'accuracy' => $mcqTotalCount > 0 ? round(($mcqCorrectCount / $mcqTotalCount) * 100, 1) : 0,
                'questions' => $detailedMcqs,
            ],
            'subjective' => [
                'total_questions' => $writtenTotalCount,
                'score' => (float) $attempt->written_score,
                'max_score' => $writtenMaxScore,
                'percentage' => $writtenMaxScore > 0 ? round(($attempt->written_score / $writtenMaxScore) * 100, 1) : 0,
                'questions' => $detailedWritten,
            ],
        ];
    }
}
