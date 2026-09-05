<?php

namespace App\Services;
namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\ClassLevel;
use App\Models\QuizQuestion;
use App\Models\QuizWrittenQuestion;
use App\Models\Subject;
use App\Services\AiQuestionService;
use App\Services\PdfExtractorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ChapterUploadController extends Controller
{
    /**
     * Display the upload form initial data (classes and subjects).
     */
    public function index()
    {
        $classes = ClassLevel::orderBy('name')->get();
        $boards = \App\Models\Board::orderBy('name')->get();
        $subjects = Subject::with(['classLevel', 'board'])->orderBy('name')->get();

        return response()->json([
            'classes' => $classes,
            'boards' => $boards,
            'subjects' => $subjects,
        ]);
    }

    /**
     * Get aggregate stats for the PDF upload & AI processing dashboard.
     */
    public function stats()
    {
        $totalChapters = Chapter::count();
        $chaptersWithPdf = Chapter::whereNotNull('source_file_url')->where('source_file_url', '!=', '')->count();
        $chaptersProcessed = Chapter::whereNotNull('extracted_text')->where('extracted_text', '!=', '')->count();
        $mcqQuestions = QuizQuestion::count();
        $shortAnswerQuestions = QuizWrittenQuestion::count();
        $totalQuestions = $mcqQuestions + $shortAnswerQuestions;

        $difficultyCounts = [
            'easy' => QuizQuestion::where('difficulty', 'easy')->count(),
            'medium' => QuizQuestion::where('difficulty', 'medium')->count(),
            'hard' => QuizQuestion::where('difficulty', 'hard')->count(),
        ];

        return response()->json([
            'total_chapters' => $totalChapters,
            'chapters_with_pdf' => $chaptersWithPdf,
            'chapters_processed' => $chaptersProcessed,
            'unprocessed_chapters' => max(0, $chaptersWithPdf - $chaptersProcessed),
            'total_questions' => $totalQuestions,
            'mcq_questions' => $mcqQuestions,
            'short_answer_questions' => $shortAnswerQuestions,
            'difficulty_breakdown' => $difficultyCounts,
            'total_classes' => ClassLevel::count(),
            'total_subjects' => Subject::count(),
        ]);
    }

    /**
     * Get subjects for a specific class.
     */
    public function getSubjects(Request $request)
    {
        $classId = $request->input('class_id');
        if (! is_numeric($classId) || (int) $classId <= 0 || in_array(strtolower(trim((string) $classId)), ['undefined', 'null', 'nan', ''])) {
            return response()->json(['subjects' => []]);
        }

        $subjects = Subject::where('class_id', $classId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'subjects' => $subjects,
        ]);
    }

    /**
     * Upload PDF and automatically extract chapter content and generate questions into DB.
     */
    public function upload(Request $request)
    {
        $allFiles = $request->allFiles();
        $pdfFile = $request->file('pdf_file') ?: ($request->file('file') ?: ($request->file('pdf') ?: (reset($allFiles) ?: null)));
        $rawBase64 = $request->input('pdf_base64') ?: ($request->input('file_base64') ?: ($request->input('base64') ?: null));

        $hasFile = $pdfFile !== null;
        $hasBase64 = !empty($rawBase64);

        if (!$hasFile && !$hasBase64) {
            // Check if PHP discarded file due to upload_max_filesize across any upload field
            foreach ($_FILES as $f) {
                if (isset($f['error']) && $f['error'] === UPLOAD_ERR_INI_SIZE) {
                    return response()->json([
                        'message' => 'The uploaded PDF file exceeded PHP upload limits. Please retry with the automatic in-browser uploader.',
                        'errors' => [
                            'pdf_file' => ['File size exceeds server upload limits. Automatic base64 mode will now process it.'],
                        ],
                    ], 422);
                }
            }

            return response()->json([
                'message' => 'Please provide a PDF file.',
                'errors' => [
                    'pdf_file' => ['The PDF document is required.'],
                ],
            ], 422);
        }

        if ((!$request->filled('class_id') || $request->input('class_id') === '') && $request->filled('subject_id')) {
            $subject = Subject::find($request->input('subject_id'));
            if ($subject) {
                $request->merge(['class_id' => $subject->class_id]);
            }
        }

        if (!$request->filled('class_id') || $request->input('class_id') === '') {
            $firstClass = ClassLevel::orderBy('id')->first();
            if ($firstClass) {
                $request->merge(['class_id' => $firstClass->id]);
            }
        }

        if (!$request->filled('subject_id') || $request->input('subject_id') === '') {
            $classId = $request->input('class_id');
            $firstSubject = Subject::where('class_id', $classId)->first() ?: Subject::orderBy('id')->first();
            if ($firstSubject) {
                $request->merge(['subject_id' => $firstSubject->id, 'class_id' => $firstSubject->class_id]);
            }
        }

        $request->validate([
            'class_id' => ['required', 'exists:class_levels,id'],
            'subject_id' => ['required', 'exists:subjects,id'],
            'chapter_number' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        try {
            // Verify subject belongs to class
            $subject = Subject::with('classLevel')->findOrFail($request->subject_id);
            if ($subject->class_id != $request->class_id) {
                return response()->json([
                    'message' => 'Subject does not belong to the selected class.',
                ], 422);
            }

            $relativeDir = "{$request->class_id}/{$request->subject_id}";
            $targetDir1 = storage_path($relativeDir);
            $targetDir2 = storage_path("app/{$relativeDir}");
            $targetDir3 = storage_path("app/public/{$relativeDir}");
            $targetDir4 = public_path("{$relativeDir}");

            foreach ([$targetDir1, $targetDir2, $targetDir3, $targetDir4] as $dir) {
                if (! File::exists($dir)) {
                    File::makeDirectory($dir, 0775, true, true);
                }
            }

            // Ensure public class symlink exists for direct Nginx web server access
            $publicClassDir = public_path((string)$request->class_id);
            $storageClassDir = storage_path("app/{$request->class_id}");
            if (! File::exists($publicClassDir) && File::exists($storageClassDir)) {
                @symlink($storageClassDir, $publicClassDir);
            }

            if ($hasFile && $pdfFile) {
                $originalName = pathinfo($pdfFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
                $filename = 'chapter_' . time() . '_' . $safeName . '.' . ($pdfFile->getClientOriginalExtension() ?: 'pdf');

                $tempPdfPath = "{$targetDir1}/temp_{$filename}";
                $pdfFile->move($targetDir1, "temp_{$filename}");
                
                // Compress PDF using Ghostscript
                $gsCommand = "gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/screen -dNOPAUSE -dQUIET -dBATCH -sOutputFile=" . escapeshellarg("{$targetDir1}/{$filename}") . " " . escapeshellarg($tempPdfPath);
                exec($gsCommand);
                
                if (file_exists("{$targetDir1}/{$filename}") && filesize("{$targetDir1}/{$filename}") > 0) {
                    @unlink($tempPdfPath);
                } else {
                    rename($tempPdfPath, "{$targetDir1}/{$filename}");
                }

                // Duplicate to all target paths
                File::copy("{$targetDir1}/{$filename}", "{$targetDir2}/{$filename}");
                File::copy("{$targetDir1}/{$filename}", "{$targetDir3}/{$filename}");
                File::copy("{$targetDir1}/{$filename}", "{$targetDir4}/{$filename}");
            } else {
                // Base64 upload
                $originalName = $request->input('pdf_name', 'chapter_' . $request->chapter_number);
                $originalName = pathinfo($originalName, PATHINFO_FILENAME);
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalName);
                $filename = 'chapter_' . time() . '_' . $safeName . '.pdf';

                $cleanBase64 = preg_replace('#^data:application/\w+;base64,#i', '', $rawBase64);
                $binaryData = base64_decode($cleanBase64);

                if (empty($binaryData)) {
                    return response()->json([
                        'message' => 'Could not decode PDF data. Please try again.',
                    ], 422);
                }
                
                $tempPdfPath = "{$targetDir1}/temp_{$filename}";
                File::put($tempPdfPath, $binaryData);
                
                // Compress PDF using Ghostscript
                $gsCommand = "gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/screen -dNOPAUSE -dQUIET -dBATCH -sOutputFile=" . escapeshellarg("{$targetDir1}/{$filename}") . " " . escapeshellarg($tempPdfPath);
                exec($gsCommand);
                
                if (file_exists("{$targetDir1}/{$filename}") && filesize("{$targetDir1}/{$filename}") > 0) {
                    @unlink($tempPdfPath);
                } else {
                    rename($tempPdfPath, "{$targetDir1}/{$filename}");
                }

                File::put("{$targetDir2}/{$filename}", file_get_contents("{$targetDir1}/{$filename}"));
                File::put("{$targetDir3}/{$filename}", file_get_contents("{$targetDir1}/{$filename}"));
                File::put("{$targetDir4}/{$filename}", file_get_contents("{$targetDir1}/{$filename}"));
            }

            // Create or update chapter record
            $userId = $request->user() ? $request->user()->id : 1;

            $chapter = Chapter::updateOrCreate(
                [
                    'subject_id' => $request->subject_id,
                    'chapter_number' => $request->chapter_number,
                ],
                [
                    'title' => $request->title,
                    'description' => $request->description,
                    'source_file_url' => $filename,
                    'created_by' => $userId,
                ]
            );

            // 1. Extract PDF text content
            $pdfExtractor = app(PdfExtractorService::class);
            $extractedText = $pdfExtractor->extractText("{$relativeDir}/{$filename}");

            $questionsSaved = [];
            $pagesSaved = [];

            if (! empty($extractedText)) {
                $chapter->extracted_text = $extractedText;
                $chapter->processed_at = now();
                $chapter->save();

                // 2. Extract and save pages to chapter_pages table
                $aiQuestionService = app(AiQuestionService::class);
                $pagesSaved = $aiQuestionService->extractAndSavePages($chapter->id, "{$relativeDir}/{$filename}");

                // 3. Generate 50 MCQs and 20 Subjective Questions using AI and save directly into `questions` DB table
                $generatedQuestions = $aiQuestionService->generateQuestionsForChapter(
                    $extractedText,
                    $chapter->title,
                    $subject->name,
                    [
                        'mcq_count' => 50,
                        'subjective_count' => 20,
                    ]
                );

                if (! empty($generatedQuestions)) {
                    $saveResult = $aiQuestionService->saveQuestionsToDatabase($chapter->id, $generatedQuestions, true);
                    $questionsSaved = $saveResult['questions'] ?? [];
                }
            }

            // Refresh chapter from database with relations
            $chapter->load(['subject.classLevel', 'pages']);

            return response()->json([
                'message' => 'Chapter PDF uploaded, content extracted, and questions saved into database successfully!',
                'chapter' => [
                    'id' => $chapter->id,
                    'title' => $chapter->title,
                    'chapter_number' => $chapter->chapter_number,
                    'subject' => $subject->name,
                    'class' => $subject->classLevel->name ?? 'Class ' . $request->class_id,
                    'source_file_url' => $chapter->source_file_url,
                    'has_extracted_text' => ! empty($chapter->extracted_text),
                    'text_length' => strlen($chapter->extracted_text ?? ''),
                    'text_preview' => mb_substr($chapter->extracted_text ?? '', 0, 400, 'UTF-8'),
                    'questions_count' => is_array($chapter->questions) ? count($chapter->questions) : 0,
                    'pages_count' => $chapter->pages()->count(),
                    'processed_at' => $chapter->processed_at,
                    'questions' => $chapter->questions,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Chapter upload and DB ingestion failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to upload and process chapter.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Reprocess an existing chapter (extract text, extract pages, and generate questions in DB).
     */
    public function reprocess(Request $request, $id)
    {
        try {
            $chapter = Chapter::with('subject.classLevel')->findOrFail($id);

            if (! $chapter->source_file_url) {
                return response()->json([
                    'message' => 'Chapter does not have a PDF file assigned.',
                ], 422);
            }

            $relativeDir = "{$chapter->subject->class_id}/{$chapter->subject_id}";
            $path = "{$relativeDir}/{$chapter->source_file_url}";

            // 1. Extract PDF text
            $pdfExtractor = app(PdfExtractorService::class);
            $extractedText = $pdfExtractor->extractText($path);

            if (! $extractedText) {
                return response()->json([
                    'message' => 'Could not extract text from PDF. File might not exist or may be image-only scan.',
                ], 500);
            }

            $chapter->extracted_text = $extractedText;
            $chapter->processed_at = now();
            $chapter->save();

            // 2. Extract pages to chapter_pages
            $aiQuestionService = app(AiQuestionService::class);
            $aiQuestionService->extractAndSavePages($chapter->id, $path);

            // 3. Generate 50 MCQs and 20 Subjective Questions and save in `questions` table
            $generatedQuestions = $aiQuestionService->generateQuestionsForChapter(
                $extractedText,
                $chapter->title,
                $chapter->subject->name ?? '',
                [
                    'mcq_count' => 50,
                    'subjective_count' => 20,
                ]
            );

            $saveResult = $aiQuestionService->saveQuestionsToDatabase($chapter->id, $generatedQuestions, true);

            // Clear cache
            Cache::forget("chapter_text_{$chapter->id}");

            // Reload fresh questions
            $questions = is_array($chapter->questions) ? collect($chapter->questions) : collect([]);

            return response()->json([
                'message' => 'Chapter reprocessed successfully and saved in database!',
                'chapter' => [
                    'id' => $chapter->id,
                    'title' => $chapter->title,
                    'chapter_number' => $chapter->chapter_number,
                    'subject' => $chapter->subject->name ?? '',
                    'class' => $chapter->subject->classLevel->name ?? '',
                    'has_extracted_text' => ! empty($chapter->extracted_text),
                    'text_length' => strlen($chapter->extracted_text),
                    'text_preview' => mb_substr($chapter->extracted_text, 0, 400, 'UTF-8'),
                    'questions_count' => $questions->count(),
                    'processed_at' => $chapter->processed_at,
                    'questions' => $questions,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Chapter reprocess failed', [
                'chapter_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to reprocess chapter.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate additional questions for an existing chapter and append to DB.
     */
    public function generateMoreQuestions(Request $request, $id)
    {
        $request->validate([
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        try {
            $chapter = Chapter::with('subject')->findOrFail($id);

            $content = $chapter->extracted_text;
            if (empty($content) && $chapter->source_file_url) {
                $pdfExtractor = app(PdfExtractorService::class);
                $path = "{$chapter->subject->class_id}/{$chapter->subject_id}/{$chapter->source_file_url}";
                $content = $pdfExtractor->extractText($path);
                if ($content) {
                    $chapter->extracted_text = $content;
                    $chapter->save();
                }
            }

            if (empty($content)) {
                return response()->json([
                    'message' => 'No text content available for this chapter to generate questions from.',
                ], 422);
            }

            $replaceExisting = $request->boolean('replace_existing', false);

            $aiQuestionService = app(AiQuestionService::class);
            $generated = $aiQuestionService->generateQuestionsForChapter(
                $content,
                $chapter->title,
                $chapter->subject->name ?? '',
                [
                    'mcq_count' => 50,
                    'subjective_count' => 20,
                ]
            );

            $saveResult = $aiQuestionService->saveQuestionsToDatabase($chapter->id, $generated, $replaceExisting);

            $allQuestions = is_array($chapter->questions) ? collect($chapter->questions) : collect([]);

            return response()->json([
                'message' => 'New questions generated and saved into database!',
                'new_questions_count' => count($generated),
                'total_questions_count' => $allQuestions->count(),
                'questions' => $allQuestions,
            ]);
        } catch (\Exception $e) {
            Log::error('Generate more questions failed', [
                'chapter_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to generate questions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all chapters with processing and questions statistics.
     */
    public function chapters(Request $request)
    {
        foreach (['class_id', 'subject_id'] as $key) {
            if ($request->has($key)) {
                $val = $request->input($key);
                if (is_null($val) || ! is_numeric($val) || (int) $val <= 0 || in_array(strtolower(trim((string) $val)), ['undefined', 'null', 'nan', ''])) {
                    $request->request->remove($key);
                    $request->query->remove($key);
                }
            }
        }

        $query = Chapter::with(['subject.classLevel', 'questions']);

        if ($request->has('class_id') && ! empty($request->class_id)) {
            $query->whereHas('subject', function ($q) use ($request) {
                $q->where('class_id', $request->class_id);
            });
        }

        if ($request->has('subject_id') && ! empty($request->subject_id)) {
            $query->where('subject_id', $request->subject_id);
        }

        if ($request->has('status') && $request->status === 'processed') {
            $query->whereNotNull('extracted_text')->where('extracted_text', '!=', '');
        } elseif ($request->has('status') && $request->status === 'unprocessed') {
            $query->where(function ($q) {
                $q->whereNull('extracted_text')->orWhere('extracted_text', '');
            });
        }

        if ($request->has('search') && ! empty($request->search)) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhere('description', 'like', $search);
            });
        }

        $chapters = $query->orderBy('subject_id')->orderBy('chapter_number')->get();

        return response()->json([
            'chapters' => $chapters->map(function ($chapter) {
                $questionsCount = $chapter->questions->count();
                $hasText = ! empty($chapter->extracted_text);
                $hasPdf = ! empty($chapter->source_file_url);

                return [
                    'id' => $chapter->id,
                    'chapter_number' => $chapter->chapter_number,
                    'title' => $chapter->title,
                    'description' => $chapter->description,
                    'subject_id' => $chapter->subject_id,
                    'subject' => $chapter->subject->name ?? 'Unknown Subject',
                    'class_id' => $chapter->subject->class_id ?? null,
                    'class' => $chapter->subject->classLevel->name ?? 'Class ' . ($chapter->subject->class_id ?? ''),
                    'source_file_url' => $chapter->source_file_url,
                    'has_pdf' => $hasPdf,
                    'has_extracted_text' => $hasText,
                    'has_questions' => $questionsCount > 0,
                    'text_length' => strlen($chapter->extracted_text ?? ''),
                    'text_preview' => mb_substr($chapter->extracted_text ?? '', 0, 180, 'UTF-8'),
                    'questions_count' => $questionsCount,
                    'processed_at' => $chapter->processed_at,
                    'created_at' => $chapter->created_at,
                ];
            }),
        ]);
    }

    /**
     * Get single chapter with all extracted content, pages, and questions.
     */
    public function getChapter(Request $request, $id)
    {
        $chapter = Chapter::with(['subject.classLevel', 'pages'])->findOrFail($id);
        $questions = is_array($chapter->questions) ? collect($chapter->questions) : collect([]);

        $baseUrl = rtrim($request->schemeAndHttpHost() ?: config('app.url', 'http://localhost:8000'), '/');
        $fileUrl = null;
        if ($chapter->source_file_url && $chapter->subject) {
            $fileUrl = "{$baseUrl}/{$chapter->subject->class_id}/{$chapter->subject_id}/{$chapter->source_file_url}";
        }

        return response()->json([
            'chapter' => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'description' => $chapter->description,
                'source_file_url' => $fileUrl,
                'raw_filename' => $chapter->source_file_url,
                'extracted_text' => $chapter->extracted_text,
                'text_length' => strlen($chapter->extracted_text ?? ''),
                'has_extracted_text' => ! empty($chapter->extracted_text),
                'subject' => [
                    'id' => $chapter->subject->id ?? null,
                    'name' => $chapter->subject->name ?? 'Unknown',
                ],
                'class' => [
                    'id' => $chapter->subject->classLevel->id ?? null,
                    'name' => $chapter->subject->classLevel->name ?? '',
                ],
                'questions' => $questions,
                'questions_count' => $questions->count(),
                'pages' => $chapter->pages,
                'pages_count' => $chapter->pages ? $chapter->pages->count() : 0,
                'processed_at' => $chapter->processed_at,
                'created_at' => $chapter->created_at,
            ],
        ]);
    }

    /**
     * Delete a chapter and all associated database records.
     */
    public function deleteChapter(Request $request, $id)
    {
        try {
            $chapter = Chapter::findOrFail($id);

            // Delete questions, pages, topics
            $quiz = \App\Models\Quiz::where('chapter_id', $chapter->id)->first();
            if ($quiz) {
                QuizQuestion::where('quiz_id', $quiz->id)->delete();
                QuizWrittenQuestion::where('quiz_id', $quiz->id)->delete();
                $quiz->delete();
            }
            $chapter->pages()->delete();

            $chapter->delete();

            return response()->json([
                'message' => 'Chapter and its database questions deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete chapter.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Batch process multiple unprocessed chapters in database.
     */
    public function batchProcess(Request $request)
    {
        $limit = min(5, max(1, (int) $request->input('limit', 3)));

        // Find chapters with PDF that haven't been processed yet
        $chapters = Chapter::with('subject.classLevel')
            ->whereNotNull('source_file_url')
            ->where('source_file_url', '!=', '')
            ->where(function ($q) {
                $q->whereNull('extracted_text')->orWhere('extracted_text', '');
            })
            ->take($limit)
            ->get();

        if ($chapters->isEmpty()) {
            return response()->json([
                'message' => 'No unprocessed chapters found with PDF files.',
                'processed_count' => 0,
                'remaining_count' => 0,
            ]);
        }

        $pdfExtractor = app(PdfExtractorService::class);
        $aiQuestionService = app(AiQuestionService::class);
        $results = [];

        foreach ($chapters as $ch) {
            try {
                $relativeDir = "{$ch->subject->class_id}/{$ch->subject_id}";
                $path = "{$relativeDir}/{$ch->source_file_url}";

                $extractedText = $pdfExtractor->extractText($path);

                if (! empty($extractedText)) {
                    $ch->extracted_text = $extractedText;
                    $ch->processed_at = now();
                    $ch->save();

                    // Pages
                    $aiQuestionService->extractAndSavePages($ch->id, $path);

                    // Questions
                    $generated = $aiQuestionService->generateQuestionsForChapter(
                        $extractedText,
                        $ch->title,
                        $ch->subject->name ?? '',
                        [
                            'mcq_count' => 50,
                            'subjective_count' => 20,
                        ]
                    );

                    $aiQuestionService->saveQuestionsToDatabase($ch->id, $generated, true);

                    $questionsInDb = is_array($ch->questions) ? count($ch->questions) : 0;

                    $results[] = [
                        'id' => $ch->id,
                        'title' => $ch->title,
                        'status' => 'success',
                        'text_length' => strlen($extractedText),
                        'questions_count' => $questionsInDb,
                    ];
                } else {
                    $results[] = [
                        'id' => $ch->id,
                        'title' => $ch->title,
                        'status' => 'skipped (no text found)',
                    ];
                }
            } catch (\Exception $e) {
                $results[] = [
                    'id' => $ch->id,
                    'title' => $ch->title,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        $remainingCount = Chapter::whereNotNull('source_file_url')
            ->where('source_file_url', '!=', '')
            ->where(function ($q) {
                $q->whereNull('extracted_text')->orWhere('extracted_text', '');
            })
            ->count();

        return response()->json([
            'message' => 'Batch processing completed.',
            'processed' => $results,
            'processed_count' => count($results),
            'remaining_count' => $remainingCount,
        ]);
    }
}
