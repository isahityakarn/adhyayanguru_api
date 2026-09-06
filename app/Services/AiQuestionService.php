<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\QuizWrittenQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiQuestionService
{
    /**
     * Models to try in order of priority.
     */
    protected array $models = [
        'gemini-3.8-flash',
        'gemini-3.7-flash',
        'gemini-3.5-flash',
        'gemini-3.1-flash-lite',
    ];

    /**
     * Generate comprehensive questions from chapter content using AI.
     */
    public function generateQuestionsForChapter(
        string $content,
        string $chapterTitle,
        string $subjectName = '',
        array $options = []
    ): array {
        $apiKey = config('services.gemini.api_key');
        $mcqCount = $options['mcq_count'] ?? 50;
        $subjectiveCount = $options['subjective_count'] ?? 20;

        if (! empty($apiKey)) {
            // Trim content to reasonable length for token limits (approx 8000 chars)
            $contentSnippet = mb_substr($content, 0, 8000, 'UTF-8');

            $taskDescription = "";
            $jsonStructure = "{\n";
            
            if ($mcqCount > 0 && $subjectiveCount > 0) {
                $taskDescription = "generate exactly {$mcqCount} Multiple Choice Questions (MCQ) and {$subjectiveCount} Conceptual Short Answer questions.";
                $jsonStructure .= <<<JSON
  "mcq": [
    {
      "id": 1,
      "question": "Clear question text here?",
      "options": {
        "A": "Option 1",
        "B": "Option 2",
        "C": "Option 3",
        "D": "Option 4"
      },
      "answer": "B"
    }
  ],
  "subjective_questions": [
    {
      "id": 1,
      "question": "Short answer question text?"
    }
  ]
JSON;
            } elseif ($mcqCount > 0) {
                $taskDescription = "generate exactly {$mcqCount} Multiple Choice Questions (MCQ). DO NOT generate subjective questions.";
                $jsonStructure .= <<<JSON
  "mcq": [
    {
      "id": 1,
      "question": "Clear question text here?",
      "options": {
        "A": "Option 1",
        "B": "Option 2",
        "C": "Option 3",
        "D": "Option 4"
      },
      "answer": "B"
    }
  ]
JSON;
            } elseif ($subjectiveCount > 0) {
                $taskDescription = "generate exactly {$subjectiveCount} Conceptual Short Answer questions. DO NOT generate MCQs.";
                $jsonStructure .= <<<JSON
  "subjective_questions": [
    {
      "id": 1,
      "question": "Short answer question text?"
    }
  ]
JSON;
            }
            $jsonStructure .= "\n}";

            $mcqInstructions = $mcqCount > 0 ? "For MCQs, provide 4 plausible options (letters A, B, C, D) with exactly one correct answer based ONLY on the text." : "";
            $subjectiveInstructions = $subjectiveCount > 0 ? "For Subjective questions, ensure they test conceptual understanding of the provided text." : "";

            $prompt = <<<PROMPT
You are an expert curriculum and educational assessment designer.
Based STRICTLY on the following chapter content (do not use outside knowledge), {$taskDescription}

Chapter Title: {$chapterTitle}
Subject: {$subjectName}

{$mcqInstructions}
{$subjectiveInstructions}

RETURN STRICTLY A VALID JSON OBJECT. No explanations before or after the JSON.
The JSON object MUST have the following structure exactly:
{$jsonStructure}

Chapter Content:
{$contentSnippet}
PROMPT;

            foreach ($this->models as $model) {
                try {
                    $response = Http::timeout(180)
                        ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                            'contents' => [
                                [
                                    'parts' => [
                                        ['text' => $prompt],
                                    ],
                                ],
                            ],
                            'generationConfig' => [
                                'temperature' => 0.6,
                                'topK' => 40,
                                'topP' => 0.95,
                                'maxOutputTokens' => 8192,
                            ],
                        ]);

                    if ($response->successful()) {
                        $responseData = $response->json();
                        $text = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                        Log::info("Raw AI Output from {$model}:", ['text' => $text]);
                        $parsed = $this->cleanAndParseJson($text);

                        if (! empty($parsed) && is_array($parsed)) {
                            Log::info("Successfully generated questions using {$model}", [
                                'count' => count($parsed),
                                'chapter' => $chapterTitle,
                            ]);

                            return $this->formatQuestions($parsed, $chapterTitle, $mcqCount, $subjectiveCount);
                        }
                    } else {
                        Log::warning("Gemini model {$model} returned error", [
                            'status' => $response->status(),
                            'body' => mb_substr($response->body(), 0, 300),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning("Exception calling Gemini model {$model}: ".$e->getMessage());
                }
            }
        }

        // --- HUGGING FACE FALLBACK ---
        Log::info('Gemini failed to generate questions, falling back to Hugging Face Spark-X2.5-4B...');
        try {
            $hfApiKey = env('HUGGINGFACE_API_KEY');
            $endpoint = 'https://router.huggingface.co/hf-inference/v1/chat/completions';
            
            $headers = ['Content-Type' => 'application/json'];
            if (!empty($hfApiKey)) {
                $headers['Authorization'] = 'Bearer ' . $hfApiKey;
            }

            $response = Http::timeout(180)
                ->withHeaders($headers)
                ->post($endpoint, [
                    'model' => 'XHToken/Spark-X2.5-4B',
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.6,
                    'max_tokens' => 8192,
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $text = $responseData['choices'][0]['message']['content'] ?? '';
                Log::info("Raw AI Output from HF Spark:", ['text' => $text]);
                $parsed = $this->cleanAndParseJson($text);

                if (!empty($parsed) && is_array($parsed)) {
                    Log::info("Successfully generated questions using HF Spark", [
                        'count' => count($parsed),
                        'chapter' => $chapterTitle,
                    ]);

                    return $this->formatQuestions($parsed, $chapterTitle, $mcqCount, $subjectiveCount);
                }
            } else {
                Log::warning("HF Spark model returned error", [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning("Exception calling HF Spark model: " . $e->getMessage());
        }

        Log::error('All Gemini and HF AI models failed to generate questions for chapter', ['title' => $chapterTitle]);
        throw new \Exception("AI API failed to generate questions. Please try again. The text might be too large or the API might be overloaded.");
    }

    /**
     * Clean and parse JSON response from LLM.
     */
    protected function cleanAndParseJson(string $text): ?array
    {
        // Strip markdown code fences if present
        $clean = preg_replace('/^```(?:json)?\s*/im', '', trim($text));
        $clean = preg_replace('/\s*```$/m', '', $clean);
        $clean = trim($clean);

        // Find JSON object or array start and end
        $startPos = strpos($clean, '{');
        $arrayStartPos = strpos($clean, '[');
        if ($arrayStartPos !== false && ($startPos === false || $arrayStartPos < $startPos)) {
            $startPos = $arrayStartPos;
            $endPos = strrpos($clean, ']');
        } else {
            $endPos = strrpos($clean, '}');
        }

        if ($startPos !== false && $endPos !== false && $endPos > $startPos) {
            $clean = substr($clean, $startPos, $endPos - $startPos + 1);
        }

        $decoded = json_decode($clean, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Attempt fixing trailing commas
        $fixed = preg_replace('/,\s*([\]}])/m', '$1', $clean);
        $decoded = json_decode($fixed, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    /**
     * Validate and format question items.
     */
    protected function formatQuestions(array $rawQuestions, string $chapterTitle, int $mcqCount = -1, int $subjectiveCount = -1): array
    {
        $formatted = [];

        // Handle the new associative format with "mcq" and "subjective_questions"
        if (isset($rawQuestions['mcq']) || isset($rawQuestions['subjective_questions'])) {
            $mcqs = $rawQuestions['mcq'] ?? [];
            foreach ($mcqs as $q) {
                if (empty($q['question'])) {
                    continue;
                }

                $options = null;
                if (!empty($q['options']) && is_array($q['options'])) {
                    $options = [];
                    foreach ($q['options'] as $letter => $text) {
                        $options[] = [
                            'letter' => $letter,
                            'text' => (string) $text,
                        ];
                    }
                }

                $formatted[] = [
                    'question_text' => trim($q['question']),
                    'question_type' => 'mcq',
                    'options' => $options,
                    'correct_answer' => (string) ($q['answer'] ?? 'A'),
                    'explanation' => null,
                    'difficulty' => 'medium',
                ];
            }

            $subjectives = $rawQuestions['subjective_questions'] ?? [];
            foreach ($subjectives as $q) {
                if (empty($q['question'])) {
                    continue;
                }

                $formatted[] = [
                    'question_text' => trim($q['question']),
                    'question_type' => 'short_answer',
                    'options' => null,
                    'correct_answer' => '',
                    'explanation' => null,
                    'difficulty' => 'medium',
                ];
            }

            return $formatted;
        }

        // Fallback for old flat array format
        foreach ($rawQuestions as $q) {
            if (empty($q['question_text'])) {
                continue;
            }

            $type = in_array($q['question_type'] ?? '', ['mcq', 'short_answer']) ? $q['question_type'] : 'mcq';
            
            // If we know only subjective was requested, force it to short_answer
            if ($mcqCount === 0 && $subjectiveCount > 0) {
                $type = 'short_answer';
            } elseif ($mcqCount > 0 && $subjectiveCount === 0) {
                $type = 'mcq';
            }

            $difficulty = in_array($q['difficulty'] ?? '', ['easy', 'medium', 'hard']) ? $q['difficulty'] : 'medium';

            $options = null;
            if ($type === 'mcq' && ! empty($q['options']) && is_array($q['options'])) {
                $options = array_map(function ($opt) {
                    return [
                        'letter' => $opt['letter'] ?? 'A',
                        'text' => (string) ($opt['text'] ?? ''),
                    ];
                }, $q['options']);
            }

            $correctAnswer = (string) ($q['correct_answer'] ?? ($type === 'mcq' ? 'A' : ''));

            $formatted[] = [
                'question_text' => trim($q['question_text']),
                'question_type' => $type,
                'options' => $options,
                'correct_answer' => $correctAnswer,
                'explanation' => $q['explanation'] ?? null,
                'difficulty' => $difficulty,
            ];
        }

        return $formatted;
    }

    /**
     * Heuristic fallback question generator when external AI is unavailable.
     */
    protected function generateFallbackQuestions(string $content, string $chapterTitle, string $subjectName, int $mcqCount, int $subjectiveCount): array
    {
        $questions = [];

        if ($mcqCount > 0) {
            $questions[] = [
                'question_text' => "What is the primary subject matter discussed in '{$chapterTitle}'?",
                'question_type' => 'mcq',
                'options' => [
                    ['letter' => 'A', 'text' => "Core fundamental concepts of {$chapterTitle}"],
                    ['letter' => 'B', 'text' => 'Historical background of unrelated civilizations'],
                    ['letter' => 'C', 'text' => 'Mathematical formulas outside the curriculum'],
                    ['letter' => 'D', 'text' => 'General unrelated trivia'],
                ],
                'correct_answer' => 'A',
                'explanation' => "The chapter '{$chapterTitle}' focuses on the core concepts and principles of the topic.",
                'difficulty' => 'easy',
            ];
        }

        // 2. Extract key sentences from content to create comprehension questions
        $sentences = preg_split('/(?<=[.?!])\s+/u', strip_tags($content));
        $meaningfulSentences = array_values(array_filter($sentences, function ($s) {
            $len = strlen(trim($s));

            return $len >= 30 && $len <= 150 && ! preg_match('/^(page|chapter|exercise|\d+)/i', trim($s));
        }));

        $sentenceCount = count($meaningfulSentences);

        if ($sentenceCount >= 1 && $mcqCount > 0) {
            $s1 = trim($meaningfulSentences[0]);
            $questions[] = [
                'question_text' => "Based on the text: '{$s1}', which statement is accurate?",
                'question_type' => 'mcq',
                'options' => [
                    ['letter' => 'A', 'text' => 'It highlights an essential concept from the chapter.'],
                    ['letter' => 'B', 'text' => 'It contradicts the lesson teachings.'],
                    ['letter' => 'C', 'text' => 'It is an irrelevant footnote.'],
                    ['letter' => 'D', 'text' => 'It applies only to external subjects.'],
                ],
                'correct_answer' => 'A',
                'explanation' => 'The statement directly reflects the core lesson from the chapter text.',
                'difficulty' => 'medium',
            ];
        }

        if ($sentenceCount >= 2 && $subjectiveCount > 0) {
            $s2 = trim($meaningfulSentences[min(2, $sentenceCount - 1)]);
            $questions[] = [
                'question_text' => "According to the chapter content, what key principle is illustrated by: '{$s2}'?",
                'question_type' => 'short_answer',
                'options' => null,
                'correct_answer' => $s2,
                'explanation' => null,
                'difficulty' => 'medium',
            ];
        }

        if ($sentenceCount >= 3 && $mcqCount > 0) {
            $s3 = trim($meaningfulSentences[min(4, $sentenceCount - 1)]);
            $questions[] = [
                'question_text' => "How does the following concept connect to the chapter's objectives: '{$s3}'?",
                'question_type' => 'mcq',
                'options' => [
                    ['letter' => 'A', 'text' => 'It reinforces the theoretical and practical application of the topic.'],
                    ['letter' => 'B', 'text' => 'It serves as an exception to standard rules.'],
                    ['letter' => 'C', 'text' => 'It is a hypothetical scenario without basis.'],
                    ['letter' => 'D', 'text' => 'It disproves the main hypothesis.'],
                ],
                'correct_answer' => 'A',
                'explanation' => 'The concept provides practical understanding and foundational knowledge.',
                'difficulty' => 'hard',
            ];
        }

        // Summary question
        if ($subjectiveCount > 0) {
            $questions[] = [
                'question_text' => "Summarize the key takeaways and learnings from '{$chapterTitle}'.",
                'question_type' => 'short_answer',
                'options' => null,
                'correct_answer' => "The chapter '{$chapterTitle}' teaches core fundamentals, structured problem solving, and analytical thinking.",
                'explanation' => null,
                'difficulty' => 'hard',
            ];
        }

        return $questions;
    }

    /**
     * Save generated questions into the relational database and sync with Chapter model.
     */
    public function saveQuestionsToDatabase(int $chapterId, array $questions, bool $replaceExisting = false): array
    {
        return DB::transaction(function () use ($chapterId, $questions, $replaceExisting) {
            $chapter = Chapter::findOrFail($chapterId);

            $quiz = Quiz::updateOrCreate(
                ['chapter_id' => $chapterId],
                [
                    'title' => "Chapter Quiz: {$chapter->title}",
                    'description' => "Auto-generated quiz for {$chapter->title}",
                    'time_limit_minutes' => 45,
                    'passing_percentage' => 60.0,
                    'marks_per_mcq' => 1,
                    'marks_per_written' => 10,
                    'is_published' => true,
                ]
            );

            if ($replaceExisting) {
                QuizQuestion::where('quiz_id', $quiz->id)->delete();
                QuizWrittenQuestion::where('quiz_id', $quiz->id)->delete();
            }

            $createdMcqs = [];
            $createdWritten = [];
            
            $maxMcqOrder = QuizQuestion::where('quiz_id', $quiz->id)->max('order_num') ?? 0;
            $maxWrittenOrder = QuizWrittenQuestion::where('quiz_id', $quiz->id)->max('order_num') ?? 0;

            foreach ($questions as $q) {
                $type = in_array($q['question_type'] ?? '', ['mcq', 'short_answer']) ? $q['question_type'] : 'mcq';
                $difficulty = in_array($q['difficulty'] ?? '', ['easy', 'medium', 'hard']) ? $q['difficulty'] : 'medium';

                if ($type === 'mcq') {
                    $maxMcqOrder++;
                    $created = QuizQuestion::create([
                        'quiz_id' => $quiz->id,
                        'question_text' => $q['question_text'],
                        'options' => $q['options'] ?? [],
                        'correct_answer' => strtoupper(trim($q['correct_answer'] ?? 'A')),
                        'explanation' => $q['explanation'] ?? null,
                        'difficulty' => $difficulty,
                        'order_num' => $maxMcqOrder,
                    ]);
                    $createdMcqs[] = $created;
                } else {
                    $maxWrittenOrder++;
                    $created = QuizWrittenQuestion::create([
                        'quiz_id' => $quiz->id,
                        'question_text' => $q['question_text'],
                        'expected_answer' => $q['correct_answer'] ?? 'Detailed explanation based on lesson.',
                        'key_concepts' => [$chapter->title],
                        'marking_criteria' => 'Award full marks for clear explanation.',
                        'min_words' => 20,
                        'max_words' => 300,
                        'marks' => 10,
                        'order_num' => $maxWrittenOrder,
                    ]);
                    $createdWritten[] = $created;
                }
            }

            $quiz->total_mcq = QuizQuestion::where('quiz_id', $quiz->id)->count();
            $quiz->total_written = QuizWrittenQuestion::where('quiz_id', $quiz->id)->count();
            $quiz->save();

            // Sync ALL questions (legacy and new) back to chapter->questions JSON
            $allMcqs = QuizQuestion::where('quiz_id', $quiz->id)->get()->map(function($q) {
                $qArr = $q->toArray();
                $qArr['question_type'] = 'mcq';
                return $qArr;
            })->toArray();
            
            $allWritten = QuizWrittenQuestion::where('quiz_id', $quiz->id)->get()->map(function($q) {
                $qArr = $q->toArray();
                $qArr['question_type'] = 'short_answer';
                return $qArr;
            })->toArray();

            $allChapterQuestions = array_merge($allMcqs, $allWritten);
            $chapter->questions = collect($allChapterQuestions)->toJson();
            $chapter->processed_at = now();
            $chapter->save();

            return [
                'count' => count($allChapterQuestions),
                'questions' => $allChapterQuestions,
                'total_in_db' => $quiz->total_mcq + $quiz->total_written,
                'quiz_id' => $quiz->id,
            ];
        });
    }

    /**
     * Extract pages from PDF and save to chapter_pages table.
     */
    public function extractAndSavePages(int $chapterId, string $pdfPath, ?int $maxPages = 20): array
    {
        try {
            $pdfExtractor = app(PdfExtractorService::class);
            $pages = $pdfExtractor->extractTextByPages($pdfPath, $maxPages);

            if (empty($pages)) {
                return [];
            }

            // Clear previous pages for this chapter
            ChapterPage::where('chapter_id', $chapterId)->delete();

            $savedPages = [];
            foreach ($pages as $p) {
                if (empty($p['text'])) {
                    continue;
                }

                $pageRecord = ChapterPage::create([
                    'chapter_id' => $chapterId,
                    'page_number' => $p['page'],
                    'content' => $p['text'],
                ]);
                $savedPages[] = $pageRecord;
            }

            return $savedPages;
        } catch (\Exception $e) {
            Log::warning('Failed to extract and save chapter pages', [
                'chapter_id' => $chapterId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Generate 50 MCQs and 20 Written questions directly from Chapter PDF content using Gemini AI.
     */
    public function generateQuizFromPdfContent(
        string $content,
        string $chapterTitle,
        string $subjectName = '',
        string $classLevel = '',
        int $targetMcqCount = 50,
        int $targetWrittenCount = 20
    ): array {
        $apiKey = config('services.gemini.api_key');

        $mcqs = [];
        $written = [];

        if (!empty($apiKey) && !empty($content)) {
            $contentSnippet = mb_substr($content, 0, 8000, 'UTF-8');

            if ($targetMcqCount > 0) {
                $mcqs = $this->generateAiMcqsBatch($apiKey, $contentSnippet, $chapterTitle, $subjectName, $classLevel, $targetMcqCount);
            }

            if ($targetWrittenCount > 0) {
                $written = $this->generateAiWrittenBatch($apiKey, $contentSnippet, $chapterTitle, $subjectName, $classLevel, $targetWrittenCount);
            }
        }

        return [
            'mcqs' => $mcqs,
            'written' => $written,
        ];
    }

    protected function generateAiMcqsBatch(
        string $apiKey,
        string $content,
        string $chapterTitle,
        string $subjectName,
        string $classLevel,
        int $targetCount
    ): array {
        $allMcqs = [];
        $batchSize = 10; // 10 questions per prompt for fast AI execution
        $batches = (int) ceil($targetCount / $batchSize);

        for ($b = 1; $b <= $batches; $b++) {
            $countNeeded = min($batchSize, $targetCount - count($allMcqs));
            if ($countNeeded <= 0) break;

            $prompt = <<<PROMPT
You are an expert bilingual curriculum and assessment designer for {$classLevel} {$subjectName}.
Based STRICTLY on the following textbook chapter PDF content for "{$chapterTitle}", generate {$countNeeded} high-quality BILINGUAL (English & Hindi) Multiple Choice Questions (MCQ) (Batch {$b} of {$batches}).

CRITICAL MANDATORY REQUIREMENT:
All question_text, options text, and explanation MUST BE BILINGUAL (Provided in both English and Hindi, separated by ' / ').
Example question_text: "What is the primary concept of this topic? / इस विषय की प्राथमिक अवधारणा क्या है?"
Example option text: "First fundamental principle / पहला मौलिक सिद्धांत"
Example explanation: "Option C is correct because... / विकल्प C सही है क्योंकि..."

RETURN STRICTLY A VALID JSON ARRAY. No explanations before or after the JSON.
Structure:
[
  {
    "question_text": "Question in English? / हिन्दी में प्रश्न?",
    "options": [
      {"letter": "A", "text": "Option 1 in English / हिन्दी में विकल्प 1"},
      {"letter": "B", "text": "Option 2 in English / हिन्दी में विकल्प 2"},
      {"letter": "C", "text": "Option 3 in English / हिन्दी में विकल्प 3"},
      {"letter": "D", "text": "Option 4 in English / हिन्दी में विकल्प 4"}
    ],
    "correct_answer": "A",
    "explanation": "Explanation in English. / हिन्दी में स्पष्टीकरण।",
    "difficulty": "medium"
  }
]

Textbook PDF Content:
{$content}
PROMPT;

            $result = $this->callGeminiApi($apiKey, $prompt);
            if (!empty($result) && is_array($result)) {
                foreach ($result as $q) {
                    if (!empty($q['question_text']) && !empty($q['options']) && is_array($q['options'])) {
                        $allMcqs[] = [
                            'question_text' => trim($q['question_text']),
                            'options' => array_map(function ($opt) {
                                return [
                                    'letter' => strtoupper($opt['letter'] ?? 'A'),
                                    'text' => (string) ($opt['text'] ?? ''),
                                ];
                            }, $q['options']),
                            'correct_answer' => strtoupper(trim($q['correct_answer'] ?? 'A')),
                            'explanation' => $q['explanation'] ?? "Based on chapter '{$chapterTitle}'. / अध्याय '{$chapterTitle}' पर आधारित।",
                            'difficulty' => in_array($q['difficulty'] ?? '', ['easy', 'medium', 'hard']) ? $q['difficulty'] : 'medium',
                        ];
                    }
                }
            }
        }

        return $allMcqs;
    }

    protected function generateAiWrittenBatch(
        string $apiKey,
        string $content,
        string $chapterTitle,
        string $subjectName,
        string $classLevel,
        int $targetCount
    ): array {
        $allWritten = [];
        $batchSize = 10;
        $batches = (int) ceil($targetCount / $batchSize);

        for ($b = 1; $b <= $batches; $b++) {
            $countNeeded = min($batchSize, $targetCount - count($allWritten));
            if ($countNeeded <= 0) break;

            $prompt = <<<PROMPT
You are an expert bilingual curriculum assessment designer for {$classLevel} {$subjectName}.
Based STRICTLY on the textbook chapter PDF content for "{$chapterTitle}", generate {$countNeeded} comprehensive BILINGUAL (English & Hindi) Subjective / Written short and long answer questions (Batch {$b} of {$batches}).

CRITICAL MANDATORY REQUIREMENT:
All question_text, expected_answer, and marking_criteria MUST BE BILINGUAL (Provided in both English and Hindi, separated by ' / ').
Example question_text: "Explain the main principles of {$chapterTitle}. / {$chapterTitle} के मुख्य सिद्धांतों की व्याख्या करें।"

RETURN STRICTLY A VALID JSON ARRAY. No explanations before or after the JSON.
Structure:
[
  {
    "question_text": "Subjective question in English? / हिन्दी में वर्णनात्मक प्रश्न?",
    "expected_answer": "Model answer in English. / हिन्दी में आदर्श उत्तर।",
    "key_concepts": ["concept 1", "concept 2"],
    "marking_criteria": "Award full marks for clear explanation. / स्पष्ट व्याख्या के लिए पूरे अंक दें।",
    "min_words": 20,
    "max_words": 300,
    "marks": 10
  }
]

Textbook PDF Content:
{$content}
PROMPT;

            $result = $this->callGeminiApi($apiKey, $prompt);
            if (!empty($result) && is_array($result)) {
                foreach ($result as $wq) {
                    if (!empty($wq['question_text'])) {
                        $allWritten[] = [
                            'question_text' => trim($wq['question_text']),
                            'expected_answer' => $wq['expected_answer'] ?? 'Detailed explanation based on lesson.',
                            'key_concepts' => is_array($wq['key_concepts'] ?? null) ? $wq['key_concepts'] : [$chapterTitle],
                            'marking_criteria' => $wq['marking_criteria'] ?? 'Award marks based on accuracy and clarity.',
                            'min_words' => (int) ($wq['min_words'] ?? 20),
                            'max_words' => (int) ($wq['max_words'] ?? 300),
                            'marks' => (int) ($wq['marks'] ?? 10),
                        ];
                    }
                }
            }
        }

        return $allWritten;
    }

    protected function callGeminiApi(string $apiKey, string $prompt): ?array
    {
        foreach ($this->models as $model) {
            try {
                $response = Http::timeout(180)
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                        'contents' => [
                            [
                                'parts' => [
                                    ['text' => $prompt],
                                ],
                            ],
                        ],
                        'generationConfig' => [
                            'temperature' => 0.5,
                            'topK' => 40,
                            'topP' => 0.95,
                            'maxOutputTokens' => 4096,
                        ],
                    ]);

                if ($response->successful()) {
                    $responseData = $response->json();
                    $text = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                    $parsed = $this->cleanAndParseJson($text);

                    if (!empty($parsed) && is_array($parsed)) {
                        return $parsed;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Gemini model {$model} error: " . $e->getMessage());
            }
        }

        // --- HUGGING FACE FALLBACK ---
        Log::info('Gemini failed to generate questions, falling back to Hugging Face Spark-X2.5-4B...');
        try {
            $hfApiKey = env('HUGGINGFACE_API_KEY');
            $endpoint = 'https://router.huggingface.co/hf-inference/v1/chat/completions';
            
            $headers = ['Content-Type' => 'application/json'];
            if (!empty($hfApiKey)) {
                $headers['Authorization'] = 'Bearer ' . $hfApiKey;
            }

            $response = Http::timeout(180)
                ->withHeaders($headers)
                ->post($endpoint, [
                    'model' => 'XHToken/Spark-X2.5-4B',
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'temperature' => 0.5,
                    'max_tokens' => 4096,
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $text = $responseData['choices'][0]['message']['content'] ?? '';
                $parsed = $this->cleanAndParseJson($text);

                if (!empty($parsed) && is_array($parsed)) {
                    return $parsed;
                }
            }
        } catch (\Exception $e) {
            Log::warning("HF Spark model error: " . $e->getMessage());
        }

        return null;
    }
}
