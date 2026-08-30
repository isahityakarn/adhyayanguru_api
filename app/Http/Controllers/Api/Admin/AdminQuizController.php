<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\ClassLevel;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizWrittenQuestion;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class AdminQuizController extends Controller
{
    /**
     * Import full quiz (MCQs + Subjective questions) from JSON payload.
     */
    public function importJson(Request $request)
    {
        $data = $request->all();

        // Support wrapping inside "payload", "json", "quiz", or direct object
        if (isset($data['payload']) && is_array($data['payload'])) {
            $data = $data['payload'];
        } elseif (isset($data['json']) && is_array($data['json'])) {
            $data = $data['json'];
        }

        $chapterId = $request->input('chapter_id') ?? $data['chapter_id'] ?? null;
        $chapterTitle = trim($request->input('chapter') ?? $data['chapter'] ?? $data['chapter_title'] ?? '');
        $classNum = $request->input('class') ?? $data['class'] ?? $data['class_level'] ?? 5;
        $subjectName = trim($request->input('subject') ?? $data['subject'] ?? 'Hindi');
        $mcqList = $data['mcq'] ?? $data['mcqs'] ?? [];
        $subjList = $data['subjective'] ?? $data['written'] ?? [];

        // If chapter_id is explicitly provided, fetch chapter directly
        if ($chapterId) {
            $chapter = Chapter::with('subject.classLevel')->find($chapterId);
            if ($chapter) {
                $cleanTitle = $chapter->title;
                $subject = $chapter->subject;
            }
        }

        if (empty($chapter)) {
            if (empty($chapterTitle)) {
                $chapterTitle = "Chapter 1 Quiz Material";
            }

            // 1. Find or create ClassLevel
            $classLevel = ClassLevel::where('name', 'LIKE', "%{$classNum}%")->first();

            if (!$classLevel) {
                $classLevel = ClassLevel::create([
                    'name' => "Class {$classNum}",
                ]);
            }

            // 2. Find or create Subject
            $subject = Subject::where(function ($q) use ($classLevel) {
                $q->where('class_id', $classLevel->id);
            })
            ->where('name', 'LIKE', "%{$subjectName}%")
            ->first();

            if (!$subject) {
                $boardId = \App\Models\Board::first()->id ?? 1;
                $subject = Subject::create([
                    'class_id' => $classLevel->id,
                    'board_id' => $boardId,
                    'name' => $subjectName,
                ]);
            }

            // 3. Find or create Chapter
            $cleanTitle = preg_replace('/\.pdf$/i', '', preg_replace('/\.pdf\.pdf$/i', '', $chapterTitle));
            $chapter = Chapter::where('subject_id', $subject->id)
                ->where(function ($q) use ($cleanTitle, $chapterTitle) {
                    $q->where('title', $cleanTitle)
                      ->orWhere('title', 'LIKE', "%{$cleanTitle}%")
                      ->orWhere('title', $chapterTitle);
                })
                ->first();

            if (!$chapter && !empty($cleanTitle)) {
                $chapter = Chapter::where('subject_id', $subject->id)->first();
            }

            if (!$chapter) {
                $chapter = Chapter::create([
                    'subject_id' => $subject->id,
                    'chapter_number' => 1,
                    'title' => $cleanTitle ?: "Chapter 1",
                ]);
            } else {
                if (!empty($cleanTitle)) {
                    $chapter->title = $cleanTitle;
                    $chapter->save();
                }
            }
        }

        // 4. Find or create Quiz
        $quiz = Quiz::updateOrCreate(
            ['chapter_id' => $chapter->id],
            [
                'title' => "Chapter Quiz: {$cleanTitle}",
                'description' => "Comprehensive Quiz with " . count($mcqList) . " MCQs and " . count($subjList) . " Subjective questions.",
                'total_mcq' => count($mcqList),
                'total_written' => count($subjList),
                'time_limit_minutes' => 45,
                'passing_percentage' => 60.0,
                'marks_per_mcq' => 1,
                'marks_per_written' => 2,
                'randomize_questions' => false,
                'randomize_options' => false,
                'max_attempts' => 5,
                'is_published' => true,
            ]
        );

        // 5. Replace MCQs
        QuizQuestion::where('quiz_id', $quiz->id)->delete();
        $importedMcqCount = 0;

        foreach ($mcqList as $idx => $item) {
            $qText = trim($item['question'] ?? $item['question_text'] ?? '');
            if (empty($qText)) continue;

            $rawOpts = $item['options'] ?? [];
            $formattedOpts = [];
            $correctLetter = 'A';
            $targetAnswer = trim((string) ($item['answer'] ?? $item['correct_answer'] ?? ''));

            if (is_array($rawOpts)) {
                $letters = ['A', 'B', 'C', 'D', 'E', 'F'];
                foreach ($rawOpts as $oIdx => $optVal) {
                    $letter = $letters[$oIdx] ?? 'A';
                    if (is_array($optVal)) {
                        $optText = trim((string) ($optVal['text'] ?? $optVal['option'] ?? ''));
                        $letter = strtoupper($optVal['letter'] ?? $letter);
                    } else {
                        $optText = trim((string) $optVal);
                    }

                    $formattedOpts[] = [
                        'letter' => $letter,
                        'text' => $optText,
                    ];

                    if (strcasecmp($targetAnswer, $letter) === 0 || strcasecmp($targetAnswer, $optText) === 0) {
                        $correctLetter = $letter;
                    }
                }
            }

            if (in_array(strtoupper($targetAnswer), ['A', 'B', 'C', 'D'])) {
                $correctLetter = strtoupper($targetAnswer);
            }

            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_text' => $qText,
                'options' => $formattedOpts,
                'correct_answer' => $correctLetter,
                'explanation' => $item['explanation'] ?? "सही उत्तर: {$targetAnswer}",
                'difficulty' => 'medium',
                'order_num' => $item['id'] ?? ($idx + 1),
            ]);

            $importedMcqCount++;
        }

        // 6. Replace Subjective Questions
        QuizWrittenQuestion::where('quiz_id', $quiz->id)->delete();
        $importedSubjCount = 0;

        foreach ($subjList as $idx => $item) {
            $qText = trim($item['question'] ?? $item['question_text'] ?? '');
            if (empty($qText)) continue;

            $answerText = trim((string) ($item['answer'] ?? $item['expected_answer'] ?? ''));
            $marks = (int) ($item['marks'] ?? 2);

            QuizWrittenQuestion::create([
                'quiz_id' => $quiz->id,
                'question_text' => $qText,
                'expected_answer' => $answerText,
                'key_concepts' => $item['key_concepts'] ?? [$cleanTitle],
                'marking_criteria' => $item['marking_criteria'] ?? 'स्पष्ट और सही उत्तर के लिए पूरे अंक दें।',
                'min_words' => 10,
                'max_words' => 300,
                'marks' => $marks,
                'order_num' => $item['id'] ?? ($idx + 1),
            ]);

            $importedSubjCount++;
        }

        $quiz->total_mcq = $importedMcqCount;
        $quiz->total_written = $importedSubjCount;
        $quiz->save();

        return response()->json([
            'success' => true,
            'message' => "Successfully imported {$importedMcqCount} MCQs and {$importedSubjCount} Subjective questions for '{$cleanTitle}'.",
            'data' => [
                'chapter_id' => $chapter->id,
                'chapter_title' => $chapter->title,
                'quiz_id' => $quiz->id,
                'mcq_count' => $importedMcqCount,
                'subjective_count' => $importedSubjCount,
            ]
        ]);
    }
    /**
     * Admin Quiz Dashboard Overview stats and list of quizzes per chapter.
     */
    public function dashboard(Request $request)
    {
        $totalQuizzes = Quiz::count();
        $publishedQuizzes = Quiz::where('is_published', true)->count();
        $totalAttempts = QuizAttempt::where('status', 'completed')->count();

        $completedAttempts = QuizAttempt::where('status', 'completed')->get();
        $averageScore = $completedAttempts->count() > 0 ? round($completedAttempts->avg('percentage'), 1) : 0;
        $passedCount = $completedAttempts->where('is_passed', true)->count();
        $passRate = $completedAttempts->count() > 0 ? round(($passedCount / $completedAttempts->count()) * 100, 1) : 0;

        // Fetch all chapters with subject & class info, and their quiz stats
        $chapters = Chapter::with(['subject.classLevel', 'quiz.questions', 'quiz.writtenQuestions'])->get();

        $chapterQuizzes = $chapters->map(function ($ch) {
            $quiz = $ch->quiz;
            $attempts = $quiz ? QuizAttempt::where('quiz_id', $quiz->id)->where('status', 'completed')->get() : collect([]);

            $attCount = $attempts->count();
            $avgPct = $attCount > 0 ? round($attempts->avg('percentage'), 1) : 0;
            $passedAtt = $attempts->where('is_passed', true)->count();
            $chPassRate = $attCount > 0 ? round(($passedAtt / $attCount) * 100, 1) : 0;

            return [
                'chapter_id' => $ch->id,
                'chapter_number' => $ch->chapter_number,
                'chapter_title' => $ch->title,
                'subject_name' => $ch->subject->name ?? 'Subject',
                'class_name' => $ch->subject->classLevel->name ?? 'Class',
                'has_quiz' => (bool) $quiz,
                'quiz' => $quiz ? [
                    'id' => $quiz->id,
                    'title' => $quiz->title,
                    'is_published' => $quiz->is_published,
                    'total_mcq' => $quiz->questions ? $quiz->questions->count() : $quiz->total_mcq,
                    'total_written' => $quiz->writtenQuestions ? $quiz->writtenQuestions->count() : $quiz->total_written,
                    'passing_percentage' => $quiz->passing_percentage,
                    'time_limit_minutes' => $quiz->time_limit_minutes,
                ] : null,
                'attempts_count' => $attCount,
                'average_score' => $avgPct,
                'pass_rate' => $chPassRate,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'total_quizzes' => $totalQuizzes,
                    'published_quizzes' => $publishedQuizzes,
                    'total_attempts' => $totalAttempts,
                    'average_score' => $averageScore,
                    'pass_rate' => $passRate,
                ],
                'chapters' => $chapterQuizzes,
            ]
        ]);
    }

    /**
     * Store or update quiz configuration for a chapter.
     */
    public function saveQuizConfig(Request $request, $chapterId)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'time_limit_minutes' => 'required|integer|min:5|max:180',
            'passing_percentage' => 'required|numeric|min:10|max:100',
            'marks_per_mcq' => 'required|integer|min:1',
            'marks_per_written' => 'required|integer|min:1',
            'randomize_questions' => 'nullable|boolean',
            'randomize_options' => 'nullable|boolean',
            'max_attempts' => 'required|integer|min:1',
            'is_published' => 'nullable|boolean',
        ]);

        $quiz = Quiz::updateOrCreate(
            ['chapter_id' => $chapterId],
            [
                'title' => $request->title,
                'description' => $request->description,
                'time_limit_minutes' => $request->time_limit_minutes,
                'passing_percentage' => $request->passing_percentage,
                'marks_per_mcq' => $request->marks_per_mcq,
                'marks_per_written' => $request->marks_per_written,
                'randomize_questions' => $request->boolean('randomize_questions', false),
                'randomize_options' => $request->boolean('randomize_options', false),
                'max_attempts' => $request->max_attempts,
                'is_published' => $request->boolean('is_published', true),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Quiz configuration saved successfully.',
            'data' => $quiz
        ]);
    }

    /**
     * Add MCQ question to a quiz.
     */
    public function addMcq(Request $request, $quizId)
    {
        $quiz = Quiz::findOrFail($quizId);
        $request->validate([
            'question_text' => 'required|string',
            'options' => 'required|array|min:2',
            'correct_answer' => 'required|string',
            'explanation' => 'nullable|string',
            'image_url' => 'nullable|string',
            'difficulty' => 'nullable|string|in:easy,medium,hard',
        ]);

        $maxOrder = QuizQuestion::where('quiz_id', $quizId)->max('order_num') ?? 0;

        $question = QuizQuestion::create([
            'quiz_id' => $quiz->id,
            'question_text' => $request->question_text,
            'options' => $request->options,
            'correct_answer' => strtoupper(trim($request->correct_answer)),
            'explanation' => $request->explanation,
            'image_url' => $request->image_url,
            'difficulty' => $request->input('difficulty', 'medium'),
            'order_num' => $maxOrder + 1,
        ]);

        $quiz->total_mcq = QuizQuestion::where('quiz_id', $quiz->id)->count();
        $quiz->save();

        return response()->json([
            'success' => true,
            'message' => 'MCQ question added successfully.',
            'data' => $question
        ]);
    }

    /**
     * Update MCQ question.
     */
    public function updateMcq(Request $request, $questionId)
    {
        $question = QuizQuestion::findOrFail($questionId);
        $request->validate([
            'question_text' => 'required|string',
            'options' => 'required|array|min:2',
            'correct_answer' => 'required|string',
            'explanation' => 'nullable|string',
            'image_url' => 'nullable|string',
            'difficulty' => 'nullable|string|in:easy,medium,hard',
        ]);

        $question->update([
            'question_text' => $request->question_text,
            'options' => $request->options,
            'correct_answer' => strtoupper(trim($request->correct_answer)),
            'explanation' => $request->explanation,
            'image_url' => $request->image_url,
            'difficulty' => $request->input('difficulty', 'medium'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'MCQ question updated.',
            'data' => $question
        ]);
    }

    /**
     * Delete MCQ question.
     */
    public function deleteMcq($questionId)
    {
        $question = QuizQuestion::findOrFail($questionId);
        $quizId = $question->quiz_id;
        $question->delete();

        $quiz = Quiz::find($quizId);
        if ($quiz) {
            $quiz->total_mcq = QuizQuestion::where('quiz_id', $quizId)->count();
            $quiz->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'MCQ deleted successfully.'
        ]);
    }

    /**
     * Add Written question.
     */
    public function addWritten(Request $request, $quizId)
    {
        $quiz = Quiz::findOrFail($quizId);
        $request->validate([
            'question_text' => 'required|string',
            'expected_answer' => 'required|string',
            'key_concepts' => 'nullable|array',
            'marking_criteria' => 'nullable|string',
            'min_words' => 'nullable|integer',
            'max_words' => 'nullable|integer',
            'marks' => 'nullable|integer',
        ]);

        $maxOrder = QuizWrittenQuestion::where('quiz_id', $quizId)->max('order_num') ?? 0;

        $question = QuizWrittenQuestion::create([
            'quiz_id' => $quiz->id,
            'question_text' => $request->question_text,
            'expected_answer' => $request->expected_answer,
            'key_concepts' => $request->key_concepts ?? [],
            'marking_criteria' => $request->marking_criteria,
            'min_words' => $request->input('min_words', 20),
            'max_words' => $request->input('max_words', 300),
            'marks' => $request->input('marks', 10),
            'order_num' => $maxOrder + 1,
        ]);

        $quiz->total_written = QuizWrittenQuestion::where('quiz_id', $quiz->id)->count();
        $quiz->save();

        return response()->json([
            'success' => true,
            'message' => 'Written question added successfully.',
            'data' => $question
        ]);
    }

    /**
     * Update Written question.
     */
    public function updateWritten(Request $request, $questionId)
    {
        $question = QuizWrittenQuestion::findOrFail($questionId);
        $request->validate([
            'question_text' => 'required|string',
            'expected_answer' => 'required|string',
            'key_concepts' => 'nullable|array',
            'marking_criteria' => 'nullable|string',
            'min_words' => 'nullable|integer',
            'max_words' => 'nullable|integer',
            'marks' => 'nullable|integer',
        ]);

        $question->update([
            'question_text' => $request->question_text,
            'expected_answer' => $request->expected_answer,
            'key_concepts' => $request->key_concepts ?? [],
            'marking_criteria' => $request->marking_criteria,
            'min_words' => $request->input('min_words', 20),
            'max_words' => $request->input('max_words', 300),
            'marks' => $request->input('marks', 10),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Written question updated.',
            'data' => $question
        ]);
    }

    /**
     * Delete Written question.
     */
    public function deleteWritten($questionId)
    {
        $question = QuizWrittenQuestion::findOrFail($questionId);
        $quizId = $question->quiz_id;
        $question->delete();

        $quiz = Quiz::find($quizId);
        if ($quiz) {
            $quiz->total_written = QuizWrittenQuestion::where('quiz_id', $quizId)->count();
            $quiz->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Written question deleted successfully.'
        ]);
    }

    /**
     * Toggle published state.
     */
    public function togglePublish($quizId)
    {
        $quiz = Quiz::findOrFail($quizId);
        $quiz->is_published = !$quiz->is_published;
        $quiz->save();

        return response()->json([
            'success' => true,
            'message' => $quiz->is_published ? 'Quiz published.' : 'Quiz unpublished.',
            'is_published' => $quiz->is_published,
        ]);
    }

    /**
     * View all student attempts for a quiz.
     */
    public function viewStudentAttempts(Request $request, $quizId)
    {
        $attempts = QuizAttempt::with(['student', 'chapter.subject'])
            ->where('quiz_id', $quizId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $attempts
        ]);
    }

    /**
     * Export quiz results to CSV format.
     */
    public function exportResults($quizId)
    {
        $quiz = Quiz::with('chapter.subject')->findOrFail($quizId);
        $attempts = QuizAttempt::with('student')
            ->where('quiz_id', $quiz->id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"quiz_results_quiz_{$quizId}.csv\"",
        ];

        $callback = function () use ($attempts, $quiz) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Attempt ID', 'Student Name', 'Student Email', 'Chapter', 'MCQ Score', 'Written Score', 'Total Score', 'Max Score', 'Percentage', 'Status', 'Date']);

            foreach ($attempts as $att) {
                fputcsv($file, [
                    $att->id,
                    $att->student->name ?? 'N/A',
                    $att->student->email ?? 'N/A',
                    $quiz->chapter->title ?? "Chapter {$quiz->chapter_id}",
                    $att->mcq_score,
                    $att->written_score,
                    $att->total_score,
                    $att->max_score,
                    $att->percentage . '%',
                    $att->is_passed ? 'Passed' : 'Failed',
                    $att->completed_at ? $att->completed_at->toDateTimeString() : $att->created_at->toDateTimeString(),
                ]);
            }

            fclose($file);
        };

        return Response::stream($callback, 200, $headers);
    }
}
