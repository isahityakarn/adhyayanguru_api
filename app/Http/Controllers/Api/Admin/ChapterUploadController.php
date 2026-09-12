<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\ClassLevel;
use App\Models\QuizQuestion;
use App\Models\QuizWrittenQuestion;
use App\Models\Subject;
use App\Services\AiQuestionService;
use App\Services\PdfExtractorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ChapterUploadController extends Controller
{
    public function __construct(
        protected PdfExtractorService $pdfExtractor,
        protected AiQuestionService $aiQuestionService
    ) {}
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
    public function upload(Request $request): JsonResponse
    {
        // 1. Resolve PDF input (uploaded file or base64)
        $pdfInput = $this->resolvePdfInput($request);

        // 2. Validate PDF presence (detect missing file or PHP ini-size violations)
        $presenceError = $this->validatePdfPresence($pdfInput);
        if ($presenceError) {
            return $presenceError;
        }

        // 3. Provide fallback defaults for missing class/subject
        $this->normalizeClassAndSubject($request);

        // 4. Validate fields
        $request->validate([
            'class_id'       => ['required', 'exists:class_levels,id'],
            'subject_id'     => ['required', 'exists:subjects,id'],
            'chapter_number' => ['required', 'integer', 'min:1'],
            'title'          => ['required', 'string', 'max:255'],
            'description'    => ['nullable', 'string'],
        ]);

        try {
            // 5. Verify subject belongs to the selected class
            $subject = $this->validateSubjectBelongsToClass(
                (int) $request->class_id,
                (int) $request->subject_id
            );
            if ($subject instanceof JsonResponse) {
                return $subject;
            }

            $relativeDir = "{$request->class_id}/{$request->subject_id}";

            // 6. Store PDF (creates directories, compresses, and syncs to all storage paths)
            $filename = $this->storePdfFile($request, $relativeDir, $pdfInput);

            // 7. Persist chapter record
            $chapter = $this->saveChapterRecord($request, $filename);

            // 8. Run AI pipeline (text extraction → pages → questions)
            $this->processChapterAiPipeline($chapter, $subject, "{$relativeDir}/{$filename}");

            // 9. Reload relations and return formatted response
            $chapter->load(['subject.classLevel', 'pages']);

            $summary = $this->formatChapterSummary($chapter, $subject, $request->class_id);

            // Surface a soft warning if AI question generation silently failed
            $aiWarning = null;
            if (empty($chapter->questions) && ! empty($chapter->extracted_text)) {
                $aiWarning = 'Chapter saved successfully, but AI question generation failed (API may be overloaded). Use the Reprocess button to try again.';
            }

            $responseBody = [
                'message' => 'Chapter PDF uploaded, content extracted, and questions saved into database successfully!',
                'chapter' => $summary,
            ];
            if ($aiWarning) {
                $responseBody['ai_warning'] = $aiWarning;
            }

            return response()->json($responseBody, 201);

        } catch (\Throwable $e) {
            Log::error('Chapter upload and DB ingestion failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload and process chapter: ' . $e->getMessage(),
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helper methods extracted from upload()
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the PDF source from the request: either an uploaded file
     * or a raw base64 string, checking multiple possible field names.
     *
     * @return array{file: ?\Illuminate\Http\UploadedFile, base64: ?string}
     */
    private function resolvePdfInput(Request $request): array
    {
        $allFiles = $request->allFiles();
        $pdfFile  = $request->file('pdf_file')
            ?? $request->file('file')
            ?? $request->file('pdf')
            ?? (reset($allFiles) ?: null);

        $rawBase64 = $request->input('pdf_base64')
            ?? $request->input('file_base64')
            ?? $request->input('base64');

        return [
            'file'   => $pdfFile,
            'base64' => $rawBase64 ?: null,
        ];
    }

    /**
     * Return a 422 JsonResponse if no PDF was provided, handling the special
     * case where PHP silently discarded the file due to upload_max_filesize.
     */
    private function validatePdfPresence(array $pdfInput): ?JsonResponse
    {
        if ($pdfInput['file'] !== null || ! empty($pdfInput['base64'])) {
            return null; // input is present – nothing to do
        }

        // Check if PHP discarded the file because it exceeded upload_max_filesize
        foreach ($_FILES as $f) {
            if (isset($f['error']) && $f['error'] === UPLOAD_ERR_INI_SIZE) {
                return response()->json([
                    'message' => 'The uploaded PDF file exceeded PHP upload limits. Please retry with the automatic in-browser uploader.',
                    'errors'  => [
                        'pdf_file' => ['File size exceeds server upload limits. Automatic base64 mode will now process it.'],
                    ],
                ], 422);
            }
        }

        return response()->json([
            'message' => 'Please provide a PDF file.',
            'errors'  => [
                'pdf_file' => ['The PDF document is required.'],
            ],
        ], 422);
    }

    /**
     * Fill in missing class_id or subject_id from sensible defaults
     * so that validation can succeed without forcing the caller to provide both.
     */
    private function normalizeClassAndSubject(Request $request): void
    {
        // Derive class_id from the given subject_id when omitted
        if ((! $request->filled('class_id') || $request->input('class_id') === '') && $request->filled('subject_id')) {
            $subject = Subject::find($request->input('subject_id'));
            if ($subject) {
                $request->merge(['class_id' => $subject->class_id]);
            }
        }

        // Fall back to the first available class
        if (! $request->filled('class_id') || $request->input('class_id') === '') {
            $firstClass = ClassLevel::orderBy('id')->first();
            if ($firstClass) {
                $request->merge(['class_id' => $firstClass->id]);
            }
        }

        // Fall back to the first subject in the resolved class
        if (! $request->filled('subject_id') || $request->input('subject_id') === '') {
            $firstSubject = Subject::where('class_id', $request->input('class_id'))->first()
                ?? Subject::orderBy('id')->first();
            if ($firstSubject) {
                $request->merge([
                    'subject_id' => $firstSubject->id,
                    'class_id'   => $firstSubject->class_id,
                ]);
            }
        }
    }

    /**
     * Load the Subject model and ensure it belongs to the given class.
     * Returns the Subject on success or a 422 JsonResponse on mismatch.
     *
     * @return Subject|JsonResponse
     */
    private function validateSubjectBelongsToClass(int $classId, int $subjectId): Subject|JsonResponse
    {
        $subject = Subject::with('classLevel')->findOrFail($subjectId);

        if ($subject->class_id != $classId) {
            return response()->json([
                'message' => 'Subject does not belong to the selected class.',
            ], 422);
        }

        return $subject;
    }

    /**
     * Create all required storage directories (4 paths + public symlink).
     *
     * @return array{dir1: string, dir2: string, dir3: string, dir4: string}
     */
    private function ensureStorageDirectories(string $relativeDir, int $classId): array
    {
        $dirs = [
            'dir1' => storage_path($relativeDir),
            'dir2' => storage_path("app/{$relativeDir}"),
            'dir3' => storage_path("app/public/{$relativeDir}"),
            'dir4' => public_path($relativeDir),
        ];

        foreach ($dirs as $dir) {
            if (! File::exists($dir)) {
                File::makeDirectory($dir, 0775, true, true);
            }
        }

        // Ensure a public symlink exists for direct Nginx / web-server access
        $publicClassDir  = public_path((string) $classId);
        $storageClassDir = storage_path("app/{$classId}");
        if (! File::exists($publicClassDir) && File::exists($storageClassDir)) {
            @symlink($storageClassDir, $publicClassDir);
        }

        return $dirs;
    }

    /**
     * Build a timestamped, filesystem-safe filename for the PDF.
     */
    private function generateSafePdfFilename(string $originalName, string $ext = 'pdf'): string
    {
        $baseName = pathinfo($originalName, PATHINFO_FILENAME);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);
        return 'chapter_' . time() . '_' . $safeName . '.' . ($ext ?: 'pdf');
    }

    /**
     * Run Ghostscript to compress a PDF at $srcPath and write output to $destPath.
     * If compression produces an empty file, the source is used as-is (via rename).
     */
    private function compressPdfWithGhostscript(string $srcPath, string $destPath): void
    {
        $gsCommand = 'gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/screen'
            . ' -dNOPAUSE -dQUIET -dBATCH'
            . ' -sOutputFile=' . escapeshellarg($destPath)
            . ' ' . escapeshellarg($srcPath);

        exec($gsCommand);

        if (file_exists($destPath) && filesize($destPath) > 0) {
            @unlink($srcPath); // remove temp file on success
        } else {
            rename($srcPath, $destPath); // fall back to original if compression failed
        }
    }

    /**
     * Copy the master PDF from dir1 to the remaining storage directories.
     */
    private function syncToStorageDirectories(string $masterPath, string $filename, array $dirs): void
    {
        $dir1 = $dirs['dir1'];

        File::copy("{$dir1}/{$filename}", "{$dirs['dir2']}/{$filename}");
        File::copy("{$dir1}/{$filename}", "{$dirs['dir3']}/{$filename}");
        File::copy("{$dir1}/{$filename}", "{$dirs['dir4']}/{$filename}");
    }

    /**
     * Handle file storage: create directories, write the PDF (from uploaded file
     * or base64), compress it with Ghostscript, and sync to all storage paths.
     *
     * @return string The final filename (not the full path)
     */
    private function storePdfFile(Request $request, string $relativeDir, array $pdfInput): string
    {
        $dirs = $this->ensureStorageDirectories($relativeDir, (int) $request->class_id);
        $dir1 = $dirs['dir1'];

        /** @var \Illuminate\Http\UploadedFile|null $pdfFile */
        $pdfFile   = $pdfInput['file'];
        $rawBase64 = $pdfInput['base64'];

        if ($pdfFile !== null) {
            // ── Uploaded file path ────────────────────────────────────────────
            $filename    = $this->generateSafePdfFilename(
                $pdfFile->getClientOriginalName(),
                $pdfFile->getClientOriginalExtension()
            );
            $tempPath    = "{$dir1}/temp_{$filename}";
            $pdfFile->move($dir1, "temp_{$filename}");
        } else {
            // ── Base64 path ───────────────────────────────────────────────────
            $originalName = $request->input('pdf_name', 'chapter_' . $request->chapter_number);
            $filename     = $this->generateSafePdfFilename($originalName);
            $cleanBase64  = preg_replace('#^data:application/\w+;base64,#i', '', $rawBase64);
            $binaryData   = base64_decode($cleanBase64);

            if (empty($binaryData)) {
                // Throw so the outer try-catch can return a generic 500
                throw new \RuntimeException('Could not decode PDF data. Please try again.');
            }

            $tempPath = "{$dir1}/temp_{$filename}";
            File::put($tempPath, $binaryData);
        }

        // Compress and write the final PDF
        $this->compressPdfWithGhostscript($tempPath, "{$dir1}/{$filename}");

        // Sync the compressed file to the other three storage locations
        $this->syncToStorageDirectories("{$dir1}/{$filename}", $filename, $dirs);

        return $filename;
    }

    /**
     * Create or update the Chapter database record.
     */
    private function saveChapterRecord(Request $request, string $filename): Chapter
    {
        $userId = $request->user()?->id ?? 1;

        return Chapter::updateOrCreate(
            [
                'subject_id'     => $request->subject_id,
                'chapter_number' => $request->chapter_number,
            ],
            [
                'title'           => $request->title,
                'description'     => $request->description,
                'source_file_url' => $filename,
                'created_by'      => $userId,
            ]
        );
    }

    /**
     * Run the full AI pipeline for a chapter:
     *   1. Extract text from the PDF.
     *   2. Persist pages to chapter_pages.
     *   3. Generate & save MCQ + Short-Answer questions via AI.
     *
     * Text extraction errors are re-thrown (they indicate a file problem).
     * AI generation failures are caught and logged — the upload still succeeds
     * and questions can be generated later via the /reprocess endpoint.
     */
    private function processChapterAiPipeline(Chapter $chapter, Subject $subject, string $relativeFilePath): void
    {
        $extractedText = $this->pdfExtractor->extractText($relativeFilePath);

        if (empty($extractedText)) {
            return;
        }

        $chapter->extracted_text = $extractedText;
        $chapter->processed_at   = now();
        $chapter->save();

        // Extract and persist individual pages (non-fatal if it fails)
        try {
            $this->aiQuestionService->extractAndSavePages($chapter->id, $relativeFilePath);
        } catch (\Throwable $e) {
            Log::warning('Page extraction failed for chapter, continuing upload', [
                'chapter_id' => $chapter->id,
                'error'      => $e->getMessage(),
            ]);
        }

        // Generate questions — failure here must NOT abort the upload
        try {
            $generatedQuestions = $this->aiQuestionService->generateQuestionsForChapter(
                $extractedText,
                $chapter->title,
                $subject->name,
                ['mcq_count' => 50, 'subjective_count' => 20]
            );

            if (! empty($generatedQuestions)) {
                $this->aiQuestionService->saveQuestionsToDatabase($chapter->id, $generatedQuestions, true);
            }
        } catch (\Throwable $e) {
            // Log as warning — the chapter is saved; questions can be generated
            // later via POST /api/admin/chapters/{id}/reprocess
            Log::warning('AI question generation failed during upload — chapter saved without questions', [
                'chapter_id' => $chapter->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the chapter data array used in the upload() 201 response.
     */
    private function formatChapterSummary(Chapter $chapter, Subject $subject, mixed $classId): array
    {
        return [
            'id'                => $chapter->id,
            'title'             => $chapter->title,
            'chapter_number'    => $chapter->chapter_number,
            'subject'           => $subject->name,
            'class'             => $subject->classLevel->name ?? 'Class ' . $classId,
            'source_file_url'   => $chapter->source_file_url,
            'has_extracted_text'=> ! empty($chapter->extracted_text),
            'text_length'       => strlen($chapter->extracted_text ?? ''),
            'text_preview'      => mb_substr($chapter->extracted_text ?? '', 0, 400, 'UTF-8'),
            'questions_count'   => is_array($chapter->questions) ? count($chapter->questions) : 0,
            'pages_count'       => $chapter->pages()->count(),
            'processed_at'      => $chapter->processed_at,
            'questions'         => $chapter->questions,
        ];
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
            $extractedText = $this->pdfExtractor->extractText($path);

            if (! $extractedText) {
                return response()->json([
                    'message' => 'Could not extract text from PDF. File might not exist or may be image-only scan.',
                ], 500);
            }

            $chapter->extracted_text = $extractedText;
            $chapter->processed_at = now();
            $chapter->save();

            // 2. Extract pages to chapter_pages
            $this->aiQuestionService->extractAndSavePages($chapter->id, $path);

            // 3. Generate 50 MCQs and 20 Subjective Questions and save in `questions` table
            $generatedQuestions = $this->aiQuestionService->generateQuestionsForChapter(
                $extractedText,
                $chapter->title,
                $chapter->subject->name ?? '',
                [
                    'mcq_count' => 50,
                    'subjective_count' => 20,
                ]
            );

            $saveResult = $this->aiQuestionService->saveQuestionsToDatabase($chapter->id, $generatedQuestions, true);

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
            'mcq_count' => ['nullable', 'integer', 'min:0'],
            'subjective_count' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $chapter = Chapter::with('subject')->findOrFail($id);

            $content = $chapter->extracted_text;
            if (empty($content) && $chapter->source_file_url) {
                $path    = "{$chapter->subject->class_id}/{$chapter->subject_id}/{$chapter->source_file_url}";
                $content = $this->pdfExtractor->extractText($path);
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

            $generated = $this->aiQuestionService->generateQuestionsForChapter(
                $content,
                $chapter->title,
                $chapter->subject->name ?? '',
                [
                    'mcq_count'        => $request->input('mcq_count', 50),
                    'subjective_count' => $request->input('subjective_count', 20),
                ]
            );

            $saveResult = $this->aiQuestionService->saveQuestionsToDatabase($chapter->id, $generated, $replaceExisting);

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
        $chapter = Chapter::with(['subject.classLevel', 'pages', 'quiz.questions', 'quiz.writtenQuestions'])->findOrFail($id);
        
        $mcqQuestions = collect($chapter->quiz ? $chapter->quiz->questions : []);
        $writtenQuestions = collect($chapter->quiz ? $chapter->quiz->writtenQuestions : []);
        
        $allQuestions = collect();
        foreach ($mcqQuestions as $mcq) {
            $allQuestions->push([
                'id' => $mcq->id,
                'question_type' => 'mcq',
                'question_text' => $mcq->question_text,
                'options' => is_string($mcq->options) ? json_decode($mcq->options, true) : $mcq->options,
                'correct_answer' => $mcq->correct_answer,
                'explanation' => $mcq->explanation,
            ]);
        }
        foreach ($writtenQuestions as $written) {
            $allQuestions->push([
                'id' => $written->id,
                'question_type' => 'short_answer',
                'question_text' => $written->question_text,
                'expected_answer' => $written->expected_answer,
            ]);
        }

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
                'questions' => $allQuestions,
                'questions_count' => $allQuestions->count(),
                'pages' => $chapter->pages,
                'pages_count' => $chapter->pages ? $chapter->pages->count() : 0,
                'processed_at' => $chapter->processed_at,
                'created_at' => $chapter->created_at,
            ],
        ]);
    }

    /**
     * Delete a specific question by ID (MCQ or Subjective).
     */
    public function deleteQuestion($id)
    {
        $mcq = \App\Models\QuizQuestion::find($id);
        $written = \App\Models\QuizWrittenQuestion::find($id);
        $quizId = null;

        if ($mcq) {
            $quizId = $mcq->quiz_id;
            $mcq->delete();
        } elseif ($written) {
            $quizId = $written->quiz_id;
            $written->delete();
        } else {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        if ($quizId) {
            $quiz = \App\Models\Quiz::find($quizId);
            if ($quiz) {
                $quiz->total_mcq = \App\Models\QuizQuestion::where('quiz_id', $quizId)->count();
                $quiz->total_written = \App\Models\QuizWrittenQuestion::where('quiz_id', $quizId)->count();
                $quiz->save();

                $chapter = \App\Models\Chapter::find($quiz->chapter_id);
                if ($chapter) {
                    $allMcqs = \App\Models\QuizQuestion::where('quiz_id', $quizId)->get()->map(function($q) {
                        $qArr = $q->toArray();
                        $qArr['question_type'] = 'mcq';
                        return $qArr;
                    })->toArray();
                    
                    $allWritten = \App\Models\QuizWrittenQuestion::where('quiz_id', $quizId)->get()->map(function($q) {
                        $qArr = $q->toArray();
                        $qArr['question_type'] = 'short_answer';
                        return $qArr;
                    })->toArray();
                    
                    $chapter->questions = collect(array_merge($allMcqs, $allWritten))->toJson();
                    $chapter->save();
                }
            }
        }

        return response()->json(['message' => 'Question deleted successfully']);
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

        $results = [];

        foreach ($chapters as $ch) {
            try {
                $relativeDir = "{$ch->subject->class_id}/{$ch->subject_id}";
                $path        = "{$relativeDir}/{$ch->source_file_url}";

                $extractedText = $this->pdfExtractor->extractText($path);

                if (! empty($extractedText)) {
                    $ch->extracted_text = $extractedText;
                    $ch->processed_at   = now();
                    $ch->save();

                    // Pages
                    $this->aiQuestionService->extractAndSavePages($ch->id, $path);

                    // Questions
                    $generated = $this->aiQuestionService->generateQuestionsForChapter(
                        $extractedText,
                        $ch->title,
                        $ch->subject->name ?? '',
                        [
                            'mcq_count'        => 50,
                            'subjective_count' => 20,
                        ]
                    );

                    $this->aiQuestionService->saveQuestionsToDatabase($ch->id, $generated, true);

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
