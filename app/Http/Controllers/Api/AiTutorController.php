<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AiTutorController extends Controller
{
    /**
     * Gemini models in order of priority — confirmed working on this API key.
     */
    private array $candidateModels = [
        'gemini-3.6-flash',
        'gemini-3.5-flash',
        'gemini-3.1-flash-lite',
        'gemini-flash-latest',
    ];

    private static bool $geminiFailed = false;
    private static bool $hfFailed = false;

    /**
     * Handle AI Tutor conversation with Google Gemini.
     */
    public function chat(Request $request)
    {
        $message = $request->input('message') ?? $request->input('question') ?? '';

        if (empty($message)) {
            return response()->json([
                'message' => 'Please provide a message or question for the AI tutor.',
            ], 422);
        }

        $context = $request->input('context') ?? [];
        if (!is_array($context)) {
            $context = [];
        }

        // Merge top-level fields into context
        if ($request->filled('chapter_id') && !isset($context['chapter_id'])) {
            $context['chapter_id'] = $request->input('chapter_id');
        }
        if ($request->filled('chapter') && !isset($context['chapter'])) {
            $context['chapter'] = $request->input('chapter');
        }
        if ($request->filled('subject') && !isset($context['subject'])) {
            $context['subject'] = $request->input('subject');
        }
        if ($request->filled('subject_name') && !isset($context['subject'])) {
            $context['subject'] = $request->input('subject_name');
        }
        if ($request->filled('subject_id') && !isset($context['subject_id'])) {
            $context['subject_id'] = $request->input('subject_id');
        }
        if ($request->filled('chapter_content') && !isset($context['chapter_content'])) {
            $context['chapter_content'] = $request->input('chapter_content');
        }
        if ($request->filled('language') && !isset($context['language'])) {
            $context['language'] = $request->input('language');
        }

        // Always resolve subject name if subject_id is provided
        if (!empty($context['subject_id']) && empty($context['subject'])) {
            $subjectModel = Subject::find($context['subject_id']);
            if ($subjectModel) {
                $context['subject'] = $subjectModel->name;
            }
        }

        // Always resolve chapter & subject from DB if chapter_id is provided
        if (!empty($context['chapter_id'])) {
            $chapterModel = Chapter::with('subject')->find($context['chapter_id']);
            if ($chapterModel) {
                if (empty($context['chapter_content']) && !empty($chapterModel->extracted_text)) {
                    $context['chapter_content'] = $chapterModel->extracted_text;
                }
                if (empty($context['chapter']) && !empty($chapterModel->title)) {
                    $context['chapter'] = "Ch {$chapterModel->chapter_number}: {$chapterModel->title}";
                }
                if (empty($context['subject']) && $chapterModel->subject) {
                    $context['subject'] = $chapterModel->subject->name;
                }
            }
        }

        $history = $request->input('conversation_history') ?? $request->input('messages') ?? [];

        // ── Resolve the local PDF path for this chapter (for Gemini File API) ──
        $pdfLocalPath = null;
        if (!empty($context['chapter_id'])) {
            $chapterForPdf = isset($chapterModel) ? $chapterModel : Chapter::with('subject')->find($context['chapter_id']);
            if ($chapterForPdf && $chapterForPdf->source_file_url && $chapterForPdf->subject) {
                $classId   = $chapterForPdf->subject->class_id ?? null;
                $subjectId = $chapterForPdf->subject_id ?? null;
                if ($classId && $subjectId) {
                    $relativePath = "{$classId}/{$subjectId}/{$chapterForPdf->source_file_url}";
                    $candidate = storage_path("app/{$relativePath}");
                    if (file_exists($candidate)) {
                        $pdfLocalPath = $candidate;
                    } else {
                        $candidate2 = storage_path("app/public/{$relativePath}");
                        if (file_exists($candidate2)) {
                            $pdfLocalPath = $candidate2;
                        }
                    }
                }
            }
        }

        try {
            // Build the context for the AI
            $systemContext = $this->buildSystemContext($context, $message, $history);

            // Build conversation history
            $conversationHistory = $this->buildConversationHistory(
                $history,
                $systemContext
            );

            // Add the current user message
            $conversationHistory[] = [
                'role' => 'user',
                'parts' => [['text' => $message]],
            ];

            // 1. Primary AI Engine: Google Gemini API (with PDF if available)
            $aiResponse = $this->callGeminiApi($conversationHistory, [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ], $pdfLocalPath, $context['chapter_id'] ?? null);

            // 2. Secondary AI Engine: Fallback to Hugging Face Spark
            if (empty($aiResponse)) {
                $aiResponse = $this->callHuggingFaceFallback($conversationHistory, $systemContext);
            }

            // 3. Tertiary Fallback: Smart Educational Fallback (Guaranteed fast & high quality)
            if (empty($aiResponse)) {
                $aiResponse = $this->generateEducationalFallback($message, $context);
            }

            $aiResponse = $this->cleanLatexMath($aiResponse);

            return response()->json([
                'response' => $aiResponse,
                'reply' => $aiResponse,
                'message' => 'Success',
            ]);
        } catch (\Exception $e) {
            Log::error('AI Tutor error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            $fallback = $this->generateEducationalFallback($message, $context);

            return response()->json([
                'response' => $fallback,
                'reply' => $fallback,
                'message' => 'Response generated with tutor assistance.',
            ]);
        }
    }

    /**
     * Call Hugging Face API as Primary AI Engine fallback.
     */
    private function callHuggingFaceFallback(array $conversationHistory, string $systemContext): ?string
    {
        if (self::$hfFailed) {
            return null;
        }

        $hfApiKey = env('HUGGINGFACE_API_KEY');

        // Formulate messages for OpenAI/HuggingFace chat format
        $hfMessages = [];
        $hfMessages[] = [
            'role' => 'system',
            'content' => $systemContext,
        ];

        foreach ($conversationHistory as $msg) {
            $role = ($msg['role'] ?? '') === 'model' ? 'assistant' : ($msg['role'] ?? 'user');
            $text = $msg['parts'][0]['text'] ?? $msg['content'] ?? '';
            if (!empty($text) && $role !== 'system') {
                $hfMessages[] = [
                    'role' => $role,
                    'content' => (string) $text,
                ];
            }
        }

        $endpoint = 'https://router.huggingface.co/hf-inference/v1/chat/completions';
        try {
            $headers = ['Content-Type' => 'application/json'];
            if (!empty($hfApiKey)) {
                $headers['Authorization'] = 'Bearer ' . $hfApiKey;
            }

            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withHeaders($headers)
                ->post($endpoint, [
                    'model' => 'XHToken/Spark-X2.5-4B',
                    'messages' => $hfMessages,
                    'temperature' => 0.7,
                    'max_tokens' => 1024,
                ]);

            if ($response->successful()) {
                $json = $response->json();
                $reply = $json['choices'][0]['message']['content'] ?? null;
                if (!empty($reply)) {
                    return trim($reply);
                }
            }
        } catch (\Exception $e) {
            Log::warning("HF Spark Chat Completions Exception: " . $e->getMessage());
            self::$hfFailed = true;
        }

        return null;
    }

    /**
     * Call Gemini API with fast multi-model resilient fallback.
     *
     * When $pdfLocalPath is supplied the PDF is uploaded to Gemini File API once
     * (result cached for ~47 hours, Gemini Files expire after 48 h) and the file
     * URI is injected into the user's message so Gemini reads the actual PDF —
     * exactly like attaching a file in Gemini.ai or ChatGPT.
     */
    private function callGeminiApi(array $contents, array $generationConfig = [], ?string $pdfLocalPath = null, int|string|null $chapterId = null): ?string
    {
        $apiKey = config('services.gemini.api_key');

        if (empty($apiKey)) {
            Log::warning('Gemini API key is not configured in services.gemini.api_key');
            return null;
        }

        // ── Attach PDF to the last user message via Gemini File API ──────────
        if ($pdfLocalPath && $chapterId) {
            $fileUri  = $this->getOrUploadChapterPdf($pdfLocalPath, $chapterId, $apiKey);
            if ($fileUri) {
                // Find the last user message and prepend the PDF file part
                $lastIdx = null;
                foreach ($contents as $i => $msg) {
                    if (($msg['role'] ?? '') === 'user') {
                        $lastIdx = $i;
                    }
                }
                if ($lastIdx !== null) {
                    $existingParts = $contents[$lastIdx]['parts'];
                    $contents[$lastIdx]['parts'] = array_merge(
                        [['file_data' => ['mime_type' => 'application/pdf', 'file_uri' => $fileUri]]],
                        $existingParts
                    );
                }
            }
        }

        foreach ($this->candidateModels as $model) {
            try {
                $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

                // Use native PHP cURL to avoid Guzzle/Laravel DNS resolution issues on some servers
                $responseBody = $this->curlPost($url, [
                    'contents' => $contents,
                    'generationConfig' => !empty($generationConfig) ? $generationConfig : [
                        'temperature' => 0.7,
                        'topK' => 40,
                        'topP' => 0.95,
                        'maxOutputTokens' => 2048,
                    ],
                ], 20);

                if ($responseBody === null) {
                    Log::warning("Gemini model {$model}: cURL request failed");
                    continue;
                }

                $responseData = json_decode($responseBody, true);
                $text = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

                if (!empty($text)) {
                    return trim($text);
                }

                $errorMsg = $responseData['error']['message'] ?? substr($responseBody, 0, 150);
                Log::warning("Gemini model {$model} returned no text: {$errorMsg}");

            } catch (\Exception $ex) {
                Log::warning("Gemini request exception on {$model}: " . $ex->getMessage());
            }
        }

        return null;
    }

    /**
     * Perform a POST request using native PHP cURL.
     * This bypasses Guzzle's DNS resolver which can time out on some server configs.
     *
     * @return string|null Response body, or null on failure.
     */
    private function curlPost(string $url, array $payload, int $timeoutSeconds = 20): ?string
    {
        $jsonBody = json_encode($payload);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            Log::warning("curlPost failed for {$url}: {$err}");
            return null;
        }
        return $response;
    }

    /**
     * Return a cached Gemini File URI for the chapter PDF, uploading it first if needed.
     *
     * Gemini Files are valid for 48 hours after upload. We cache for 47 hours so we
     * always have a usable URI without the overhead of re-uploading on every request.
     */
    private function getOrUploadChapterPdf(string $localPath, int|string $chapterId, string $apiKey): ?string
    {
        $cacheKey = "gemini_file_uri_chapter_{$chapterId}";

        $cached = Cache::get($cacheKey);
        if ($cached) {
            // Verify the file still exists on Gemini (lightweight GET via cURL)
            try {
                $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/{$cached}?key={$apiKey}");
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
                $body = curl_exec($ch);
                curl_close($ch);
                if ($body) {
                    $data  = json_decode($body, true);
                    $state = $data['state'] ?? $data['file']['state'] ?? null;
                    if ($state === 'ACTIVE' || $state === null) {
                        return $data['uri'] ?? $data['file']['uri'] ?? $cached;
                    }
                }
            } catch (\Exception $e) {
                Log::warning("Gemini file check failed: " . $e->getMessage());
            }
            Cache::forget($cacheKey);
        }

        $uri = $this->uploadPdfToGemini($localPath, $apiKey);
        if ($uri) {
            Cache::put($cacheKey, $uri, now()->addHours(47));
        }
        return $uri;
    }

    /**
     * Upload a local PDF file to the Gemini File API using the resumable upload protocol.
     * Returns the file URI on success, null on failure.
     */
    private function uploadPdfToGemini(string $localPath, string $apiKey): ?string
    {
        if (!file_exists($localPath)) {
            Log::warning("Gemini PDF upload: file not found at {$localPath}");
            return null;
        }

        $fileSize    = filesize($localPath);
        $displayName = basename($localPath);

        try {
            // Step 1: Initiate resumable upload — get upload URL
            $ch = curl_init("https://generativelanguage.googleapis.com/upload/v1beta/files?key={$apiKey}");
            $initBody = json_encode(['file' => ['display_name' => $displayName]]);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $initBody,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_HEADER         => true,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Goog-Upload-Protocol: resumable',
                    'X-Goog-Upload-Command: start',
                    'X-Goog-Upload-Header-Content-Length: ' . $fileSize,
                    'X-Goog-Upload-Header-Content-Type: application/pdf',
                ],
            ]);
            $initiateRaw  = curl_exec($ch);
            $headerSize   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $initiateCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (!$initiateRaw || $initiateCode >= 400) {
                Log::warning("Gemini File API initiate failed (HTTP {$initiateCode})");
                return null;
            }

            // Extract upload URL from response headers
            $headers    = substr($initiateRaw, 0, $headerSize);
            $uploadUrl  = null;
            foreach (explode("\r\n", $headers) as $line) {
                if (stripos($line, 'x-goog-upload-url:') === 0) {
                    $uploadUrl = trim(substr($line, strlen('x-goog-upload-url:')));
                    break;
                }
            }

            if (empty($uploadUrl)) {
                Log::warning("Gemini File API: no upload URL in response headers");
                return null;
            }

            // Step 2: Upload the file bytes
            $fileContents = file_get_contents($localPath);
            $ch2 = curl_init($uploadUrl);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $fileContents,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => [
                    'Content-Length: ' . $fileSize,
                    'Content-Type: application/pdf',
                    'X-Goog-Upload-Offset: 0',
                    'X-Goog-Upload-Command: upload, finalize',
                ],
            ]);
            $uploadRaw  = curl_exec($ch2);
            $uploadCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            $uploadErr  = curl_error($ch2);
            curl_close($ch2);

            if (!$uploadRaw || $uploadCode >= 400) {
                Log::warning("Gemini File API upload failed (HTTP {$uploadCode}): {$uploadErr}");
                return null;
            }

            $responseJson = json_decode($uploadRaw, true);
            $uri = $responseJson['file']['uri'] ?? $responseJson['uri'] ?? null;

            if (empty($uri)) {
                Log::warning("Gemini File API: no URI in upload response: " . substr($uploadRaw, 0, 300));
                return null;
            }

            Log::info("Gemini File API: PDF uploaded successfully. URI={$uri}");
            return $uri;

        } catch (\Exception $e) {
            Log::warning("Gemini File API upload exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Build system context based on the provided context data.
     *
     * @param array|null $context
     * @param string $message     Current user message
     * @param array  $history     Full conversation history (to detect persistent Hindi intent)
     */
    private function buildSystemContext(?array $context, string $message = '', array $history = []): string
    {
        $voiceId = strtolower($context['voice_id'] ?? $context['voice'] ?? 'edge_tts_hindi_female');
        $isFemale = str_contains($voiceId, 'female') || str_contains($voiceId, 'swara');
        $tutorName = $isFemale ? 'Sanskriti' : 'Adhyayan';

        if (! $context) {
            return "You are {$tutorName}, a friendly, encouraging, and highly knowledgeable AI tutor for Indian school students (CBSE / ICSE / State Boards). Explain concepts clearly with simple step-by-step examples in Hindi or English as requested.";
        }

        $contextParts = [
            "You are {$tutorName}, a friendly, encouraging, and highly knowledgeable AI tutor for Indian school students (CBSE / ICSE / State Boards).",
        ];

        if (!empty($context['subject'])) {
            $contextParts[] = "The student is currently studying: {$context['subject']}.";
        }

        if (!empty($context['chapter'])) {
            $contextParts[] = "Current chapter: {$context['chapter']}.";
        }

        if (!empty($context['topic'])) {
            $contextParts[] = "Specific topic: {$context['topic']}.";
        }

        // Add chapter content if available
        if (!empty($context['chapter_content'])) {
            $content = $context['chapter_content'];

            // Limit content to prevent token overflow (~7000 chars)
            if (strlen($content) > 7000) {
                $content = substr($content, 0, 7000) . '... [content truncated for length]';
            }
            $contextParts[] = "The following is the chapter content from the student's textbook, provided as helpful reference context. Use it when it's relevant, but you are NOT limited to it — answer any question the student asks using your full knowledge as an expert tutor:\n\n{$content}\n";
        }

        // PDF / Chapter Content Language Detection
        $pdfContent = $context['chapter_content'] ?? '';
        $isPdfHindi = !empty($pdfContent) && preg_match('/\p{Devanagari}/u', $pdfContent);

        $combinedText = strtolower($message . ' ' . ($context['message'] ?? ''));

        // ── Also scan recent conversation history for a persistent Hindi instruction ──
        // If the student asked for Hindi in the last 6 messages, honour it for this turn too.
        $hindiPattern = '/(hindi|हिंदी|हिन्दी|explain in hindi|in hindi|samjhao|batao|spasht|kya hai|kaise)/i';
        $recentHistory = array_slice($history, -6);
        $historyHasHindiRequest = false;
        foreach ($recentHistory as $histMsg) {
            $histText = $histMsg['content'] ?? $histMsg['text'] ?? '';
            $histRole = $histMsg['role'] ?? '';
            // Only look at user messages for explicit Hindi requests
            if (in_array($histRole, ['user']) && preg_match($hindiPattern, $histText)) {
                $historyHasHindiRequest = true;
                break;
            }
        }

        // Check if user requested "read", "read this", "read this chapter", "padho", "padao", etc.
        $isReadIntent = preg_match('/\b(read|read this|read chapter|read this chapter|padho|padao|padh ke|padh kar|padhein|recite|explain this pdf|explain pdf|read pdf|padh ke batao)\b/i', $combinedText);

        $lang = strtolower($context['language'] ?? '');
        $isExplicitHindiRequested = preg_match('/(hindi|हिंदी|हिन्दी|explain in hindi|in hindi)/i', $combinedText);

        if ($isReadIntent && !empty($pdfContent) && !$isExplicitHindiRequested) {
            if ($isPdfHindi) {
                $contextParts[] = "CRITICAL READ ALOUD INSTRUCTION: The student explicitly asked you to READ the chapter ('{$message}'). You MUST read out the actual textbook content and paragraphs provided below in HINDI (हिन्दी) script. Do NOT just give a high-level summary, chapter outline, or topic list. Read out the text of the chapter directly paragraph-by-paragraph so the student can listen to it and follow along!";
            } else {
                $contextParts[] = "CRITICAL READ ALOUD INSTRUCTION: The student explicitly asked you to READ the chapter ('{$message}'). You MUST read out the actual textbook content and paragraphs provided below in ENGLISH. Do NOT just give a high-level summary, chapter outline, or topic list. Read out the text of the chapter directly paragraph-by-paragraph so the student can listen to it and follow along!";
            }
        } else {
            $isHindiRequested = str_starts_with($lang, 'hi') ||
                str_contains($voiceId, 'hindi') ||
                str_contains($voiceId, 'swara') ||
                str_contains($voiceId, 'madhur') ||
                $historyHasHindiRequest ||
                preg_match('/(hindi|हिंदी|हिन्दी|hinglish|samjhao|batao|spasht|explain in hindi|in hindi|kya hai|kaise)/i', $combinedText);

            if ($isHindiRequested) {
                $contextParts[] = "CRITICAL LANGUAGE INSTRUCTION: The student wants explanations in HINDI (हिन्दी). You MUST write your ENTIRE response in clear, simple, warm HINDI (हिन्दी / Devanagari script). Do NOT write in English under any circumstances. Even technical terms should be explained in Hindi first.";
            } else {
                $contextParts[] = "LANGUAGE INSTRUCTION: Reply in simple, clear Hindi (हिन्दी) or English based on the language used by the student in their message or the textbook content.";
            }
        }

        // When reading is requested, replace the generic guidelines with read-specific ones
        // so that guideline #3 ("you are a tutor, not a book-reader") does NOT override the
        // CRITICAL READ ALOUD instruction added above.
        if ($isReadIntent && !empty($pdfContent)) {
            $contextParts[] = "\nREAD ALOUD GUIDELINES:
1. Start reading the chapter content from the very beginning.
2. Present the text paragraph-by-paragraph in a clear, natural reading voice.
3. Do NOT summarize, paraphrase, or skip any part — read the actual words from the textbook.
4. After finishing each section, briefly pause and say something like \"(continuing...)\" before the next section.
5. Always be warm and encouraging!";
        } else {
            $contextParts[] = "\nTUTOR GUIDELINES:
1. Explain step-by-step in an engaging, easy-to-understand conversational tone.
2. Use bullet points, bold keywords, and practical real-life examples.
3. Answer ANY question the student asks — whether it is about the chapter, a related concept, general knowledge, or any other topic. NEVER refuse to answer or say 'this is not in the book'. You are a knowledgeable tutor, not a book-reader.
4. When the student's question relates to the chapter content, use it as reference to give an accurate, contextual answer. For questions beyond the chapter, draw from your own knowledge freely.
5. If the student asks for a summary, provide key definitions, formulas, and main takeaways.
6. NO RAW LATEX OR DOLLAR SIGNS: Do NOT write mathematical formulas with LaTeX markup or dollar signs (e.g. NEVER write '\$10 \\text{Ones}\$' or '\$\$...\$\$'). Always write all math, equations, numbers, and place value relations in clean, readable plain text (e.g. '10 Ones (इकाई) = 1 Ten (दहाई) = 10', '10 × 10 = 100').
7. FORMAT TABLES: When creating place value charts or tables, format them using clean markdown tables.
8. Always be positive, supportive, and encourage curiosity!";
        }

        return implode("\n", $contextParts);
    }

    /**
     * Build conversation history for Gemini API.
     */
    private function buildConversationHistory(array $history, string $systemContext): array
    {
        $messages = [];
        $isSanskriti = str_contains(strtolower($systemContext), 'sanskriti');
        $tutorName = $isSanskriti ? 'Sanskriti' : 'Adhyayan';

        // Detect if Hindi was requested from the system context itself
        $isHindiContext = str_contains($systemContext, 'CRITICAL LANGUAGE INSTRUCTION')
            || str_contains($systemContext, 'हिन्दी');

        // Add system context as the first user message with model response
        $messages[] = [
            'role' => 'user',
            'parts' => [['text' => $systemContext]],
        ];

        // Prime the model in Hindi when Hindi is requested — this is critical.
        // An English priming reply makes Gemini default to English regardless of instructions.
        if ($isHindiContext) {
            $hindiTutorName = $isSanskriti ? 'संस्कृति' : 'अध्ययन';
            $messages[] = [
                'role' => 'model',
                'parts' => [['text' => "नमस्ते! मैं {$hindiTutorName} हूँ, आपका AI शिक्षक। मैंने आपके निर्देश समझ लिए हैं। मैं पूरी तरह हिन्दी में उत्तर दूँगा/दूँगी और छात्र को सरल, स्पष्ट भाषा में समझाऊँगा/समझाऊँगी!"]],
            ];
        } else {
            $messages[] = [
                'role' => 'model',
                'parts' => [['text' => "Namaste! I am {$tutorName}, your AI Tutor. I understand your instructions and I am ready to help the student learn with clear explanations, examples, and warm encouragement!"]],
            ];
        }

        // Add conversation history
        foreach ($history as $message) {
            if (isset($message['role']) && (isset($message['content']) || isset($message['text']))) {
                $role = in_array($message['role'], ['assistant', 'ai', 'model']) ? 'model' : 'user';
                $text = $message['content'] ?? $message['text'] ?? '';
                if (!empty($text)) {
                    $messages[] = [
                        'role' => $role,
                        'parts' => [['text' => (string) $text]],
                    ];
                }
            }
        }

        return $messages;
    }

    /**
     * Generate educational fallback when all AI APIs are offline.
     * First tries to find a relevant answer inside the chapter content.
     * Only falls back to a generic summary when no relevant passage is found.
     */
    private function generateEducationalFallback(string $message, array $context): string
    {
        $subject   = !empty($context['subject']) && $context['subject'] !== 'the subject' ? $context['subject'] : 'General Studies';
        $chapter   = !empty($context['chapter']) && $context['chapter'] !== 'this chapter' ? $context['chapter'] : 'Chapter';
        $isHindi   = str_starts_with(strtolower($context['language'] ?? 'en'), 'hi');
        $voiceId   = strtolower($context['voice_id'] ?? $context['voice'] ?? 'edge_tts_hindi_female');
        $isFemale  = str_contains($voiceId, 'female') || str_contains($voiceId, 'swara');
        $tutorName = $isFemale ? ($isHindi ? 'संस्कृति' : 'Sanskriti') : ($isHindi ? 'अध्ययन' : 'Adhyayan');

        // ── Step 1: Extract and clean chapter content ────────────────────────
        $rawContent = '';
        if (!empty($context['chapter_content'])) {
            $rawContent = strip_tags($context['chapter_content']);
            $rawContent = preg_replace('/\[(?:SYSTEM MESSAGE|सिस्टम संदेश)[^\]]*\]/ui', '', $rawContent);
            $rawContent = trim($rawContent);
        }

        // ── Step 2: Detect read intent ─────────────────────────────────────
        $isReadIntent = (bool) preg_match(
            '/\b(read|read this|read chapter|read this chapter|padho|padao|padh ke|padh kar|padhein|recite|explain this pdf|explain pdf|read pdf|padh ke batao)\b/i',
            $message
        );

        // ── Step 3: Handle read request — return actual chapter text ─────────
        if ($isReadIntent && !empty($rawContent)) {
            // Deliver up to 2000 chars of chapter text (enough for one TTS reading session)
            $readChunk = mb_substr($rawContent, 0, 2000, 'UTF-8');
            $hasMore   = mb_strlen($rawContent, 'UTF-8') > 2000;

            if ($isHindi) {
                return "बिल्कुल! मैं अभी अध्याय \"{$chapter}\" पढ़ता/पढ़ती हूँ:\n\n---\n\n{$readChunk}" .
                    ($hasMore ? "\n\n---\n_(यह अध्याय का पहला भाग है। आगे पढ़ने के लिए \"आगे पढ़ो\" कहें।)_" : '');
            }
            return "Sure! Here is the chapter \"{$chapter}\":\n\n---\n\n{$readChunk}" .
                ($hasMore ? "\n\n---\n_(This is the first part of the chapter. Say \"continue reading\" to hear more.)_" : '');
        }

        // ── Step 4: Try to extract a relevant passage for the question ────────
        $extractedAnswer = '';
        if (!empty($rawContent) && !empty($message)) {
            $extractedAnswer = $this->extractRelevantPassage($message, $rawContent);
        }

        // ── Step 3a: Specific answer found ───────────────────────────────────
        if (!empty($extractedAnswer)) {
            if ($isHindi) {
                return "**{$message}**\n\n" .
                    "{$extractedAnswer}\n\n" .
                    "_(यह उत्तर पाठ्यपुस्तक के अध्याय \"{$chapter}\" से लिया गया है।)_\n\n" .
                    "कोई और प्रश्न हो तो पूछें! 😊";
            }
            return "**{$message}**\n\n" .
                "{$extractedAnswer}\n\n" .
                "_(Answer sourced from the textbook chapter: \"{$chapter}\".)_\n\n" .
                "Feel free to ask me more questions! 😊";
        }

        // ── Step 3b: Nothing found — generic chapter snippet response ─────────
        $snippet = !empty($rawContent) ? mb_substr($rawContent, 0, 350, 'UTF-8') . '...' : '';

        if ($isHindi) {
            $reply = "नमस्ते! मैं {$tutorName} हूँ।\n\n📚 **विषय:** {$subject} | 📖 **अध्याय:** {$chapter}\n\n";
            if (!empty($snippet)) {
                $reply .= "### अध्याय का परिचय:\n{$snippet}\n\nआप मुझसे इस अध्याय के किसी भी प्रश्न के बारे में पूछ सकते हैं!";
            } else {
                $reply .= "आप मुझसे इस अध्याय के किसी भी प्रश्न के बारे में पूछ सकते हैं।";
            }
            return $reply;
        }

        $reply = "Hello! I am {$tutorName}, your AI Tutor.\n\n📚 **Subject:** {$subject} | 📖 **Chapter:** {$chapter}\n\n";
        if (!empty($snippet)) {
            $reply .= "### Chapter Excerpt:\n{$snippet}\n\nFeel free to ask me specific questions about this chapter!";
        } else {
            $reply .= "Feel free to ask me any specific question about this chapter!";
        }
        return $reply;
    }

    /**
     * Extract the most relevant sentences from chapter content for a given question.
     * Uses simple keyword-overlap scoring — no external API needed.
     *
     * @return string Top-3 matching sentences in reading order, or '' if nothing matched.
     */
    private function extractRelevantPassage(string $question, string $content): string
    {
        $stopWords = [
            'is','are','was','were','the','a','an','in','on','at','to','for','of',
            'and','or','but','what','who','how','why','when','where','does','do',
            'did','with','from','by','that','this','it','he','she','they','his',
            'her','its','क्या','कौन','कहाँ','कब','कैसे','का','की','के','है','हैं',
        ];

        $words    = preg_split('/\W+/u', strtolower($question), -1, PREG_SPLIT_NO_EMPTY);
        $keywords = array_values(array_unique(
            array_filter($words, fn($w) => mb_strlen($w) > 2 && !in_array($w, $stopWords))
        ));

        if (empty($keywords)) {
            return '';
        }

        // Split content into sentences on common sentence-ending punctuation
        $sentences = preg_split('/(?<=[.!?।])\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);

        $scored = [];
        foreach ($sentences as $i => $sentence) {
            $lower = strtolower($sentence);
            $score = 0;
            foreach ($keywords as $kw) {
                if (str_contains($lower, $kw)) {
                    $score++;
                }
            }
            if ($score > 0) {
                $scored[] = ['sentence' => trim($sentence), 'score' => $score, 'index' => $i];
            }
        }

        if (empty($scored)) {
            return '';
        }

        // Pick top 3 by score, then re-sort by original position for natural reading order
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($scored, 0, 3);
        usort($top, fn($a, $b) => $a['index'] <=> $b['index']);

        return implode(' ', array_column($top, 'sentence'));
    }


    /**
     * Generate explanations for topics using AI.
     */
    public function explainTopic(Request $request)
    {
        $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'subject' => ['nullable', 'string', 'max:200'],
            'detail_level' => ['nullable', 'in:basic,intermediate,advanced'],
        ]);

        $detailLevel = $request->detail_level ?? 'intermediate';
        $subject = $request->subject ?? 'the subject';

        $prompt = "Explain the topic '{$request->topic}' in {$subject} at a {$detailLevel} level for a school student. " .
            "Provide a clear explanation with real-world examples and key definitions. " .
            "Structure your response with: 1) Overview, 2) Key Concepts, 3) Real-Life Examples, 4) Summary & Key Takeaways.";

        $contents = [
            [
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ],
        ];

        $explanation = $this->callGeminiApi($contents, [
            'temperature' => 0.7,
            'maxOutputTokens' => 2048,
        ]);

        if (empty($explanation)) {
            $explanation = "### Overview of {$request->topic}\n\n" .
                "{$request->topic} is an important concept in {$subject}.\n\n" .
                "#### Key Concepts:\n" .
                "- Fundamental principles and laws governing {$request->topic}.\n" .
                "- Step-by-step problem-solving methods.\n\n" .
                "#### Summary:\n" .
                "Practice questions related to {$request->topic} to master the concept!";
        }

        return response()->json([
            'topic' => $request->topic,
            'explanation' => $explanation,
        ]);
    }

    /**
     * Generate practice questions for a topic.
     */
    public function generateQuestions(Request $request)
    {
        $request->validate([
            'topic' => ['required', 'string', 'max:500'],
            'subject' => ['nullable', 'string', 'max:200'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'count' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $difficulty = $request->difficulty ?? 'medium';
        $count = $request->count ?? 5;
        $subject = $request->subject ?? 'the subject';

        $prompt = "Generate {$count} {$difficulty} difficulty practice questions about '{$request->topic}' in {$subject}. " .
            "For each question, provide: 1) The question text, 2) The correct answer, 3) A brief explanation of why that answer is correct. " .
            "Format your response as a numbered list.";

        $contents = [
            [
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ],
        ];

        $questions = $this->callGeminiApi($contents, [
            'temperature' => 0.8,
            'maxOutputTokens' => 2048,
        ]);

        return response()->json([
            'topic' => $request->topic,
            'difficulty' => $difficulty,
            'questions' => $questions ?? "1. Practice question for {$request->topic}\nAnswer: Consult chapter notes.",
        ]);
    }

    /**
     * Clean LaTeX markup and math dollar signs from text for clean student readability.
     */
    private function cleanLatexMath(?string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Replace \text{...}, \mathrm{...}, \mathbf{...} with the text inside
        $cleaned = preg_replace('/\\\\(?:text|mathrm|mathbf)\{([^}]+)\}/u', '$1', $text);

        // Replace common LaTeX symbols
        $cleaned = str_replace(
            ['\times', '\div', '\pm', '\neq', '\approx', '\cdot', '\degree'],
            ['×', '÷', '±', '≠', '≈', '·', '°'],
            $cleaned
        );
        $cleaned = preg_replace('/\\\\le(q)?\b/', '≤', $cleaned);
        $cleaned = preg_replace('/\\\\ge(q)?\b/', '≥', $cleaned);
        $cleaned = preg_replace('/\\\\frac\{([^}]+)\}\{([^}]+)\}/u', '($1 / $2)', $cleaned);
        $cleaned = preg_replace('/\\\\sqrt\{([^}]+)\}/u', '√($1)', $cleaned);

        // Remove LaTeX math dollar sign delimiters ($$...$$ and $...$)
        $cleaned = preg_replace('/\$\$([\s\S]*?)\$\$/u', '$1', $cleaned);
        $cleaned = preg_replace('/\$([^$\n]+)\$/u', '$1', $cleaned);

        return $cleaned;
    }



    /**
     * Proxy TTS request to Coqui TTS server.
     */
    public function coquiTts(Request $request)
    {
        $text = $request->input('text') ?? '';
        $speaker = $request->input('speaker') ?? 'Hindi Female';
        $language = $request->input('language') ?? 'hi';

        if (empty($text)) {
            return response()->json(['message' => 'Text parameter is required.'], 422);
        }

        $coquiServerUrl = env('COQUI_TTS_SERVER_URL', 'http://localhost:5002');
        $baseUrl = rtrim($coquiServerUrl, '/');

        try {
            $response = Http::timeout(15)->post("{$baseUrl}/api/tts", [
                'text' => $text,
                'speaker_wav' => $speaker,
                'language_id' => $language,
            ]);

            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', 'audio/wav');
            }

            Log::info('Coqui TTS server offline or returned error: ' . $response->status());
            return response()->json([
                'message' => 'Coqui TTS server unreachable or offline.',
            ], 503);
        } catch (\Exception $e) {
            Log::info('Coqui TTS Proxy Exception: ' . $e->getMessage());
            return response()->json([
                'message' => 'Coqui TTS server unreachable.',
            ], 503);
        }
    }

    /**
     * Proxy TTS request to Hugging Face Edge-TTS Space (innoai/Edge-TTS-Text-to-Speech).
     */
    public function edgeTts(Request $request)
    {
        $text = $request->input('text') ?? '';
        $speaker = $request->input('speaker') ?? 'hi-IN-SwaraNeural - hi-IN (Female)';
        $rate = (int) ($request->input('rate') ?? 0);
        $pitch = (int) ($request->input('pitch') ?? 0);

        if (empty($text)) {
            return response()->json(['message' => 'Text parameter is required.'], 422);
        }

        try {
            $hfUrl = 'https://innoai-edge-tts-text-to-speech.hf.space/gradio_api/call/tts_interface';

            $postResponse = Http::timeout(15)->post($hfUrl, [
                'data' => [$text, $speaker, $rate, $pitch],
            ]);

            if (!$postResponse->successful()) {
                Log::warning('HF Edge-TTS POST returned: ' . $postResponse->status());
                return response()->json(['message' => 'Edge TTS space unreachable.'], 503);
            }

            $eventId = $postResponse->json('event_id');
            if (empty($eventId)) {
                return response()->json(['message' => 'No event ID from Edge TTS space.'], 503);
            }

            $streamResponse = Http::timeout(20)->get("{$hfUrl}/{$eventId}");
            if (!$streamResponse->successful()) {
                return response()->json(['message' => 'Failed to read audio stream from Edge TTS space.'], 503);
            }

            $streamText = $streamResponse->body();
            $lines = explode("\n", $streamText);

            for ($i = 0; $i < count($lines); $i++) {
                if (str_starts_with($lines[$i], 'event: complete')) {
                    $dataLine = $lines[$i + 1] ?? '';
                    if (str_starts_with($dataLine, 'data: ')) {
                        $rawJson = substr($dataLine, 6);
                        $parsed = json_decode($rawJson, true);
                        $audioUrl = $parsed[0]['url'] ?? null;
                        if (!empty($audioUrl)) {
                            return response()->json([
                                'message' => 'Success',
                                'audio_url' => $audioUrl,
                                'speaker' => $speaker,
                            ]);
                        }
                    }
                }
            }

            return response()->json(['message' => 'Could not extract audio URL from Edge TTS response.'], 500);
        } catch (\Exception $e) {
            Log::info('Edge TTS Proxy Exception: ' . $e->getMessage());
            return response()->json(['message' => 'Edge TTS request exception.'], 500);
        }
    }
}



