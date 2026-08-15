<?php

namespace App\Services;

use App\Models\Chapter;
use App\Models\ChapterPage;
use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiQuestionService
{
    /**
     * Models to try in order of priority.
     */
    protected array $models = [
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.6-flash',
        'gemini-flash-latest',
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
        $count = $options['count'] ?? 6;
        $difficulty = $options['difficulty'] ?? 'mixed';

        if (! empty($apiKey)) {
            // Trim content to reasonable length for token limits (approx 5000 chars)
            $contentSnippet = mb_substr($content, 0, 5000, 'UTF-8');

            $prompt = <<<PROMPT
You are an expert curriculum and educational assessment designer.
Based on the following chapter content, generate {$count} high-quality questions for students.

Chapter Title: {$chapterTitle}
Subject: {$subjectName}
Difficulty preference: {$difficulty}

Include both Multiple Choice Questions (MCQ) and Conceptual Short Answer questions.
For MCQs, provide 4 plausible options (letters A, B, C, D) with exactly one correct answer.

RETURN STRICTLY A VALID JSON ARRAY. No explanations before or after the JSON.
Each object in the array MUST have the following structure:
[
  {
    "question_text": "Clear question text here?",
    "question_type": "mcq",
    "options": [
      {"letter": "A", "text": "Option 1"},
      {"letter": "B", "text": "Option 2"},
      {"letter": "C", "text": "Option 3"},
      {"letter": "D", "text": "Option 4"}
    ],
    "correct_answer": "B",
    "explanation": "Brief explanation of why this answer is correct based on the text.",
    "difficulty": "medium"
  },
  {
    "question_text": "Short answer question text?",
    "question_type": "short_answer",
    "options": null,
    "correct_answer": "Model short answer explaining the concept clearly.",
    "explanation": "Key points students should include in their answer.",
    "difficulty": "easy"
  }
]

Chapter Content:
{$contentSnippet}
PROMPT;

            foreach ($this->models as $model) {
                try {
                    $response = Http::timeout(25)
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
                                'maxOutputTokens' => 2048,
                            ],
                        ]);

                    if ($response->successful()) {
                        $responseData = $response->json();
                        $text = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';
                        $parsed = $this->cleanAndParseJson($text);

                        if (! empty($parsed) && is_array($parsed)) {
                            Log::info("Successfully generated questions using {$model}", [
                                'count' => count($parsed),
                                'chapter' => $chapterTitle,
                            ]);

                            return $this->formatQuestions($parsed, $chapterTitle);
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

        // Fallback generator if AI API fails or quota exceeded
        Log::info('Using fallback heuristic question generator for chapter', ['title' => $chapterTitle]);

        return $this->generateFallbackQuestions($content, $chapterTitle, $subjectName, $count);
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

        // Find JSON array start and end
        $startPos = strpos($clean, '[');
        $endPos = strrpos($clean, ']');

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
    protected function formatQuestions(array $rawQuestions, string $chapterTitle): array
    {
        $formatted = [];

        foreach ($rawQuestions as $q) {
            if (empty($q['question_text'])) {
                continue;
            }

            $type = in_array($q['question_type'] ?? '', ['mcq', 'short_answer']) ? $q['question_type'] : 'mcq';
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
    protected function generateFallbackQuestions(string $content, string $chapterTitle, string $subjectName, int $count): array
    {
        $questions = [];

        // 1. General chapter overview question
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

        // 2. Extract key sentences from content to create comprehension questions
        $sentences = preg_split('/(?<=[.?!])\s+/u', strip_tags($content));
        $meaningfulSentences = array_values(array_filter($sentences, function ($s) {
            $len = strlen(trim($s));

            return $len >= 30 && $len <= 150 && ! preg_match('/^(page|chapter|exercise|\d+)/i', trim($s));
        }));

        $sentenceCount = count($meaningfulSentences);

        if ($sentenceCount >= 1) {
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

        if ($sentenceCount >= 2) {
            $s2 = trim($meaningfulSentences[min(2, $sentenceCount - 1)]);
            $questions[] = [
                'question_text' => "According to the chapter content, what key principle is illustrated by: '{$s2}'?",
                'question_type' => 'short_answer',
                'options' => null,
                'correct_answer' => $s2,
                'explanation' => 'Review the section discussing this principle in detail.',
                'difficulty' => 'medium',
            ];
        }

        if ($sentenceCount >= 3) {
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
        $questions[] = [
            'question_text' => "Summarize the key takeaways and learnings from '{$chapterTitle}'.",
            'question_type' => 'short_answer',
            'options' => null,
            'correct_answer' => "The chapter '{$chapterTitle}' teaches core fundamentals, structured problem solving, and analytical thinking.",
            'explanation' => 'Students should explain key definitions, formulas/rules, and practical examples.',
            'difficulty' => 'easy',
        ];

        return array_slice($questions, 0, $count);
    }

    /**
     * Save generated questions into the relational database and sync with Chapter model.
     */
    public function saveQuestionsToDatabase(int $chapterId, array $questions, bool $replaceExisting = false): array
    {
        return DB::transaction(function () use ($chapterId, $questions, $replaceExisting) {
            $chapter = Chapter::findOrFail($chapterId);

            if ($replaceExisting) {
                Question::where('chapter_id', $chapterId)->delete();
            }

            $createdQuestions = [];

            foreach ($questions as $q) {
                $type = in_array($q['question_type'] ?? '', ['mcq', 'short_answer']) ? $q['question_type'] : 'mcq';
                $difficulty = in_array($q['difficulty'] ?? '', ['easy', 'medium', 'hard']) ? $q['difficulty'] : 'medium';

                $created = Question::create([
                    'chapter_id' => $chapterId,
                    'topic_id' => $q['topic_id'] ?? null,
                    'question_text' => $q['question_text'],
                    'question_type' => $type,
                    'options' => $q['options'] ?? null,
                    'correct_answer' => (string) ($q['correct_answer'] ?? ''),
                    'difficulty' => $difficulty,
                ]);

                $createdQuestions[] = $created;
            }

            // Sync questions summary JSON in chapters table
            $allChapterQuestions = Question::where('chapter_id', $chapterId)->get();
            $chapter->questions = $allChapterQuestions->toJson();
            $chapter->processed_at = now();
            $chapter->save();

            return [
                'count' => count($createdQuestions),
                'questions' => $createdQuestions,
                'total_in_db' => $allChapterQuestions->count(),
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
}
