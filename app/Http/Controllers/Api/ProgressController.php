<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Progress;
use App\Models\User;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\QuizWrittenQuestion;
use Database\Seeders\QuizSeeder;
use Illuminate\Http\Request;

class ProgressController extends Controller
{
    /**
     * Track and accumulate time spent on a chapter by the authenticated user.
     */
    public function trackTime(Request $request)
    {
        $request->validate([
            'chapter_id' => ['required', 'exists:chapters,id'],
            'seconds' => ['nullable', 'integer', 'min:0'],
            'seconds_added' => ['nullable', 'integer', 'min:0'],
            'percent_complete' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed'],
        ]);

        try {
            $user = $request->user();
            $chapterId = $request->chapter_id;
            $seconds = (int) ($request->input('seconds') ?? $request->input('seconds_added') ?? 10);

            $progress = Progress::firstOrCreate(
                [
                    'student_id' => $user->id,
                    'chapter_id' => $chapterId,
                ],
                [
                    'status' => 'in_progress',
                    'percent_complete' => 0,
                    'time_spent_seconds' => 0,
                    'last_accessed_at' => now(),
                ]
            );

            $progress->time_spent_seconds += max(0, $seconds);
            $progress->last_accessed_at = now();

            if ($request->filled('percent_complete')) {
                $progress->percent_complete = max($progress->percent_complete, (int) $request->percent_complete);
            }

            if ($request->status === 'completed' || $progress->percent_complete >= 100) {
                $progress->status = 'completed';
                $progress->percent_complete = 100;
                if (!$progress->completed_at) {
                    $progress->completed_at = now();
                }
            } elseif ($progress->time_spent_seconds > 0 && $progress->status !== 'completed') {
                $progress->status = 'in_progress';
            }

            $progress->save();
            $progress->load('chapter.subject');

            return response()->json([
                'success' => true,
                'message' => 'Chapter time tracked successfully.',
                'progress' => $progress,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Progress trackTime error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to track chapter time: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update status or completion percentage of a chapter.
     */
    public function updateProgress(Request $request)
    {
        $request->validate([
            'chapter_id' => ['required', 'exists:chapters,id'],
            'percent_complete' => ['nullable', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed'],
            'time_spent_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $user = $request->user();
            $chapterId = $request->chapter_id;

            $progress = Progress::firstOrCreate(
                [
                    'student_id' => $user->id,
                    'chapter_id' => $chapterId,
                ],
                [
                    'status' => 'not_started',
                    'percent_complete' => 0,
                    'time_spent_seconds' => 0,
                    'last_accessed_at' => now(),
                ]
            );

            if ($request->has('time_spent_seconds')) {
                $progress->time_spent_seconds = max(0, (int) $request->time_spent_seconds);
            }

            if ($request->has('percent_complete')) {
                $progress->percent_complete = (int) $request->percent_complete;
            }

            if ($request->has('status')) {
                $progress->status = $request->status;
                if ($request->status !== 'completed') {
                    $progress->completed_at = null;
                }
            }

            if ($progress->status === 'completed' || ($request->has('percent_complete') && $progress->percent_complete >= 100)) {
                $progress->status = 'completed';
                $progress->percent_complete = 100;
                if (!$progress->completed_at) {
                    $progress->completed_at = now();
                }
            }

            $progress->last_accessed_at = now();
            $progress->save();
            $progress->load('chapter.subject');

            return response()->json([
                'success' => true,
                'message' => 'Chapter progress updated successfully.',
                'progress' => $progress,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Progress updateProgress error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update chapter progress: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific chapter progress for authenticated student.
     */
    public function getChapterProgress(Request $request, $chapterId)
    {
        $user = $request->user();
        $progress = Progress::with('chapter.subject')
            ->where('student_id', $user->id)
            ->where('chapter_id', $chapterId)
            ->first();

        if (!$progress) {
            return response()->json([
                'chapter_id' => (int) $chapterId,
                'status' => 'not_started',
                'percent_complete' => 0,
                'time_spent_seconds' => 0,
                'formatted_time_spent' => '0s',
                'completed_at' => null,
                'last_accessed_at' => null,
            ]);
        }

        return response()->json([
            'progress' => $progress,
        ]);
    }

    /**
     * Detailed parent report & summary of chapter time spent and completion stats.
     * Can be viewed by parent (specifying student_id) or logged-in student.
     */
    public function parentReport(Request $request)
    {
        $user = $request->user();
        $targetStudentId = $request->get('student_id');

        if ($targetStudentId && ($user->role === 'parent' || $user->role === 'admin' || $user->id == $targetStudentId)) {
            $student = User::find($targetStudentId);
        } else {
            $student = $user;
        }

        if (!$student) {
            return response()->json(['message' => 'Student not found.'], 444);
        }

        // Fetch all progress records for the student with chapter & subject info
        $progressList = Progress::with(['chapter.subject.classLevel'])
            ->where('student_id', $student->id)
            ->orderBy('last_accessed_at', 'desc')
            ->get();

        $totalTimeSeconds = (int) $progressList->sum('time_spent_seconds');
        $totalChaptersStarted = $progressList->where('status', '!=', 'not_started')->count();
        $totalChaptersCompleted = $progressList->where('status', 'completed')->count();

        // Format total time
        $hours = floor($totalTimeSeconds / 3600);
        $minutes = floor(($totalTimeSeconds % 3600) / 60);
        $seconds = $totalTimeSeconds % 60;

        $formattedTotalTime = $hours > 0
            ? "{$hours}h {$minutes}m {$seconds}s"
            : ($minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s");

        $chaptersSummary = $progressList->map(function ($p) {
            return [
                'id' => $p->id,
                'chapter_id' => $p->chapter_id,
                'chapter_title' => $p->chapter->title ?? "Chapter {$p->chapter_id}",
                'chapter_number' => $p->chapter->chapter_number ?? null,
                'subject_name' => $p->chapter->subject->name ?? 'General',
                'class_name' => $p->chapter->subject->classLevel->name ?? '',
                'status' => $p->status,
                'percent_complete' => $p->percent_complete,
                'time_spent_seconds' => $p->time_spent_seconds,
                'formatted_time_spent' => $p->formatted_time_spent,
                'completed_at' => $p->completed_at ? $p->completed_at->toIso8601String() : null,
                'last_accessed_at' => $p->last_accessed_at ? $p->last_accessed_at->toIso8601String() : null,
            ];
        });

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'role' => $student->role,
            ],
            'summary' => [
                'total_time_spent_seconds' => $totalTimeSeconds,
                'formatted_total_time_spent' => $formattedTotalTime,
                'total_chapters_started' => $totalChaptersStarted,
                'total_chapters_completed' => $totalChaptersCompleted,
            ],
            'chapters' => $chaptersSummary,
        ]);
    }

    /**
     * Ensure a Quiz with 50 MCQs and 20 Written questions exists for the completed chapter.
     */
    public static function ensureQuizForChapter($chapterId, bool $forceRegenerate = false)
    {
        try {
            $chapter = Chapter::with(['subject.classLevel', 'pages'])->find($chapterId);
            if (!$chapter) return;

            $quiz = Quiz::firstOrCreate(
                ['chapter_id' => $chapterId],
                [
                    'title' => "Chapter " . ($chapter->chapter_number ?? 1) . " Comprehensive Quiz",
                    'description' => "Test your knowledge on {$chapter->title} with 50 MCQs and 20 written questions.",
                    'total_mcq' => 50,
                    'total_written' => 20,
                    'time_limit_minutes' => 45,
                    'passing_percentage' => 60.0,
                    'marks_per_mcq' => 1,
                    'marks_per_written' => 10,
                    'randomize_questions' => false,
                    'randomize_options' => false,
                    'max_attempts' => 5,
                    'is_published' => true,
                ]
            );

            // Ensure is_published is true
            if (!$quiz->is_published) {
                $quiz->is_published = true;
                $quiz->save();
            }

            // Detect if existing quiz contains old generic placeholder/template questions
            $hasOldTemplates = QuizWrittenQuestion::where('quiz_id', $quiz->id)
                ->where(function ($q) {
                    $q->where('question_text', 'LIKE', 'Written Question%')
                      ->orWhere('question_text', 'LIKE', 'Q%:')
                      ->orWhere('question_text', 'LIKE', '%Explain the fundamental principles%');
                })
                ->exists();

            if ($forceRegenerate || $hasOldTemplates) {
                QuizQuestion::where('quiz_id', $quiz->id)->delete();
                QuizWrittenQuestion::where('quiz_id', $quiz->id)->delete();
            }

            $existingMcqCount = QuizQuestion::where('quiz_id', $quiz->id)->count();
            $existingWrittenCount = QuizWrittenQuestion::where('quiz_id', $quiz->id)->count();

            if ($existingMcqCount < 50 || $existingWrittenCount < 20) {
                // 1. Extract PDF content from chapter
                $pdfText = $chapter->extracted_text;

                if (empty($pdfText) && $chapter->pages->count() > 0) {
                    $pdfText = $chapter->pages->pluck('content')->implode("\n\n");
                }

                if (empty($pdfText) && !empty($chapter->source_file_url)) {
                    try {
                        $pdfExtractor = app(\App\Services\PdfExtractorService::class);
                        $pdfText = $pdfExtractor->extractText($chapter->source_file_url);
                        if ($pdfText) {
                            $chapter->extracted_text = $pdfText;
                            $chapter->save();
                        }
                    } catch (\Throwable $ex) {
                        \Illuminate\Support\Facades\Log::warning("PDF Extraction warning for chapter {$chapterId}: " . $ex->getMessage());
                    }
                }

                // 2. Generate questions from PDF using Gemini AI
                $subjectName = $chapter->subject->name ?? '';
                $className = $chapter->subject->classLevel->name ?? '';

                if (!empty($pdfText)) {
                    $aiService = app(\App\Services\AiQuestionService::class);
                    $generated = $aiService->generateQuizFromPdfContent(
                        $pdfText,
                        $chapter->title,
                        $subjectName,
                        $className,
                        max(0, 50 - $existingMcqCount),
                        max(0, 20 - $existingWrittenCount)
                    );

                    // Insert AI-generated MCQs
                    if (!empty($generated['mcqs'])) {
                        foreach ($generated['mcqs'] as $index => $mcq) {
                            QuizQuestion::create([
                                'quiz_id' => $quiz->id,
                                'question_text' => $mcq['question_text'],
                                'options' => $mcq['options'],
                                'correct_answer' => $mcq['correct_answer'],
                                'explanation' => $mcq['explanation'],
                                'difficulty' => $mcq['difficulty'],
                                'order_num' => $existingMcqCount + $index + 1,
                            ]);
                        }
                        $existingMcqCount = QuizQuestion::where('quiz_id', $quiz->id)->count();
                    }

                    // Insert AI-generated Written Questions
                    if (!empty($generated['written'])) {
                        foreach ($generated['written'] as $index => $w) {
                            QuizWrittenQuestion::create([
                                'quiz_id' => $quiz->id,
                                'question_text' => $w['question_text'],
                                'expected_answer' => $w['expected_answer'],
                                'key_concepts' => $w['key_concepts'],
                                'marking_criteria' => $w['marking_criteria'],
                                'min_words' => $w['min_words'],
                                'max_words' => $w['max_words'],
                                'marks' => $w['marks'],
                                'order_num' => $existingWrittenCount + $index + 1,
                            ]);
                        }
                        $existingWrittenCount = QuizWrittenQuestion::where('quiz_id', $quiz->id)->count();
                    }
                }

                // 3. Fallback seeder if needed to reach target 50 MCQs & 20 Written
                if ($existingMcqCount < 50 || $existingWrittenCount < 20) {
                    $seeder = new QuizSeeder();
                    $reflector = new \ReflectionClass($seeder);

                    if ($existingMcqCount < 50) {
                        $generateMcq = $reflector->getMethod('generateMcqQuestions');
                        $generateMcq->setAccessible(true);
                        $generateMcq->invoke($seeder, $quiz, $chapter, 50 - $existingMcqCount, $existingMcqCount);
                    }

                    if ($existingWrittenCount < 20) {
                        $generateWritten = $reflector->getMethod('generateWrittenQuestions');
                        $generateWritten->setAccessible(true);
                        $generateWritten->invoke($seeder, $quiz, $chapter, 20 - $existingWrittenCount, $existingWrittenCount);
                    }
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed to auto-generate quiz for chapter {$chapterId}: " . $e->getMessage());
        }
    }
}
