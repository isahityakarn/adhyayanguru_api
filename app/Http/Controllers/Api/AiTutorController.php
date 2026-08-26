<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chapter;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiTutorController extends Controller
{
    /**
     * Gemini models in order of priority.
     */
    private array $candidateModels = [
        'gemini-2.5-flash',
        'gemini-2.0-flash',
        'gemini-1.5-flash',
        'gemini-1.5-pro',
        'gemini-2.0-flash-lite-preview-02-05',
        'gemini-1.5-flash-8b',
    ];

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

        try {
            // Build the context for the AI
            $systemContext = $this->buildSystemContext($context);

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

            // 1. Primary AI Engine: Hugging Face Qwen 2.5 (Gradio Space / HF API)
            $aiResponse = $this->callHuggingFaceQwenSpace($conversationHistory, $systemContext);

            // 2. Secondary AI Engine: Fallback to Google Gemini API if Qwen is unreachable
            if (empty($aiResponse)) {
                Log::info('Qwen 2.5 HF model offline or rate limited. Falling back to Google Gemini API...');
                $aiResponse = $this->callGeminiApi($conversationHistory, [
                    'temperature' => 0.7,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 2048,
                ]);
            }

            // 3. Tertiary Fallback: Smart Educational Fallback
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
     * Call Hugging Face Qwen 2.5 Gradio Space / HF Inference API as Primary AI Engine.
     */
    private function callHuggingFaceQwenSpace(array $conversationHistory, string $systemContext): ?string
    {
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

        // Method 1: Try Hugging Face Chat Completions Endpoint (Router & Direct API)
        $hfEndpoints = [
            'https://router.huggingface.co/hf-inference/v1/chat/completions',
            'https://api-inference.huggingface.co/models/Qwen/Qwen2.5-72B-Instruct/v1/chat/completions',
            'https://api-inference.huggingface.co/models/meta-llama/Llama-3.3-70B-Instruct/v1/chat/completions',
        ];

        foreach ($hfEndpoints as $endpoint) {
            try {
                $headers = ['Content-Type' => 'application/json'];
                if (!empty($hfApiKey)) {
                    $headers['Authorization'] = 'Bearer ' . $hfApiKey;
                }

                $response = Http::timeout(18)
                    ->withHeaders($headers)
                    ->post($endpoint, [
                        'model' => 'Qwen/Qwen2.5-72B-Instruct',
                        'messages' => $hfMessages,
                        'temperature' => 0.7,
                        'max_tokens' => 2048,
                    ]);

                if ($response->successful()) {
                    $json = $response->json();
                    $reply = $json['choices'][0]['message']['content'] ?? null;
                    if (!empty($reply)) {
                        Log::info("HF Qwen API success from endpoint: {$endpoint}");
                        return trim($reply);
                    }
                }
            } catch (\Exception $e) {
                Log::warning("HF Qwen Chat Completions Exception on {$endpoint}: " . $e->getMessage());
            }
        }

        // Method 2: Try Hugging Face Gradio Space (Qwen 2.5 Gradio Space Proxy)
        try {
            $gradioUrl = env('HF_QWEN_GRADIO_SPACE_URL', 'https://qwen-qwen2-5-72b-instruct.hf.space/gradio_api/call/predict');
            
            $lastUserMessage = end($hfMessages)['content'] ?? '';
            $postResponse = Http::timeout(12)->post($gradioUrl, [
                'data' => [$lastUserMessage, [], $systemContext],
            ]);

            if ($postResponse->successful()) {
                $eventId = $postResponse->json('event_id');
                if (!empty($eventId)) {
                    $streamResponse = Http::timeout(18)->get("{$gradioUrl}/{$eventId}");
                    if ($streamResponse->successful()) {
                        $lines = explode("\n", $streamResponse->body());
                        for ($i = count($lines) - 1; $i >= 0; $i--) {
                            if (str_starts_with($lines[$i], 'data: ')) {
                                $parsed = json_decode(substr($lines[$i], 6), true);
                                $text = $parsed[0] ?? $parsed['text'] ?? null;
                                if (!empty($text) && is_string($text)) {
                                    Log::info('Qwen 2.5 Gradio Space response success');
                                    return trim($text);
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $ex) {
            Log::warning('HF Qwen Gradio Space Exception: ' . $ex->getMessage());
        }

        return null;
    }

    /**
     * Call Gemini API with multi-model resilient fallback.
     */
    private function callGeminiApi(array $contents, array $generationConfig = []): ?string
    {
        $apiKey = config('services.gemini.api_key');

        if (empty($apiKey)) {
            Log::warning('Gemini API key is not configured in services.gemini.api_key');
            return null;
        }

        $versions = ['v1beta', 'v1'];

        foreach ($this->candidateModels as $model) {
            foreach ($versions as $version) {
                try {
                    $url = "https://generativelanguage.googleapis.com/{$version}/models/{$model}:generateContent?key={$apiKey}";

                    $response = Http::timeout(25)->post($url, [
                        'contents' => $contents,
                        'generationConfig' => !empty($generationConfig) ? $generationConfig : [
                            'temperature' => 0.7,
                            'topK' => 40,
                            'topP' => 0.95,
                            'maxOutputTokens' => 2048,
                        ],
                    ]);

                    if ($response->successful()) {
                        $responseData = $response->json();
                        $text = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;
                        if (!empty($text)) {
                            return trim($text);
                        }
                    }

                    // Log failure and try next model
                    Log::warning("Gemini model {$model} ({$version}) failed: " . $response->status() . " - " . substr($response->body(), 0, 200));
                } catch (\Exception $ex) {
                    Log::warning("Gemini request exception on {$model} ({$version}): " . $ex->getMessage());
                }
            }
        }

        return null;
    }

    /**
     * Build system context based on the provided context data.
     */
    private function buildSystemContext(?array $context): string
    {
        $voiceId = strtolower($context['voice_id'] ?? $context['voice'] ?? 'edge_tts_hindi_female');
        $isFemale = str_contains($voiceId, 'female') || str_contains($voiceId, 'swara');
        $tutorName = $isFemale ? 'Sanskriti' : 'Adhyayan';

        if (! $context) {
            return "You are {$tutorName}, a friendly, encouraging, and highly knowledgeable AI tutor for Indian school students (CBSE / ICSE / State Boards). Explain concepts clearly with simple step-by-step examples.";
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
            $contextParts[] = "Textbook chapter content is provided below. Use this as your primary source of truth to teach the student:\n\n{$content}\n";
        }

        // Language preference
        $lang = strtolower($context['language'] ?? 'en');
        if (str_starts_with($lang, 'hi')) {
            $contextParts[] = 'Language instruction: Reply in simple, clear Hindi (हिन्दी) or Hinglish as requested by the student.';
        } else {
            $contextParts[] = 'Language instruction: Reply in clear, simple English, using standard Indian educational terminology.';
        }

        $contextParts[] = "\nTUTOR GUIDELINES:
1. Explain step-by-step in an engaging, easy-to-understand conversational tone.
2. Use bullet points, bold keywords, and practical real-life examples.
3. If the student asks a question about the chapter, answer it accurately using the textbook content.
4. If the student asks for a summary, provide key definitions, formulas, and main takeaways.
5. NO RAW LATEX OR DOLLAR SIGNS: Do NOT write mathematical formulas with LaTeX markup or dollar signs (e.g. NEVER write '\$10 \\text{Ones}\$' or '\$\$...\$\$'). Always write all math, equations, numbers, and place value relations in clean, readable plain text (e.g. '10 Ones (इकाई) = 1 Ten (दहाई) = 10', '10 × 10 = 100').
6. FORMAT TABLES: When creating place value charts or tables, format them using clean markdown tables.
7. Always be positive, supportive, and encourage curiosity!";

        return implode("\n", $contextParts);
    }

    /**
     * Build conversation history for Gemini API.
     */
    private function buildConversationHistory(array $history, string $systemContext): array
    {
        $messages = [];
        $tutorName = str_contains(strtolower($systemContext), 'sanskriti') ? 'Sanskriti' : 'Adhyayan';

        // Add system context as the first user message with model response
        $messages[] = [
            'role' => 'user',
            'parts' => [['text' => $systemContext]],
        ];

        $messages[] = [
            'role' => 'model',
            'parts' => [['text' => "Namaste! I am {$tutorName}, your AI Tutor. I understand your instructions and I am ready to help the student learn with clear explanations, examples, and warm encouragement!"]],
        ];

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
     * Generate structured educational fallback if all Gemini endpoints are offline.
     */
    private function generateEducationalFallback(string $message, array $context): string
    {
        $subject = !empty($context['subject']) && $context['subject'] !== 'the subject' ? $context['subject'] : 'सामान्य अध्ययन';
        $chapter = !empty($context['chapter']) && $context['chapter'] !== 'this chapter' ? $context['chapter'] : 'अध्याय';
        $isHindi = str_starts_with(strtolower($context['language'] ?? 'hi'), 'hi');
        $voiceId = strtolower($context['voice_id'] ?? $context['voice'] ?? 'edge_tts_hindi_female');
        $isFemale = str_contains($voiceId, 'female') || str_contains($voiceId, 'swara');
        $tutorName = $isFemale ? ($isHindi ? 'संस्कृति' : 'Sanskriti') : ($isHindi ? 'अध्ययन' : 'Adhyayan');

        $contentSummary = "";
        if (!empty($context['chapter_content'])) {
            $rawText = strip_tags($context['chapter_content']);
            $rawText = preg_replace('/\[सिस्टम संदेश:[^\]]+\]/u', '', $rawText);
            $rawText = trim($rawText);
            if (strlen($rawText) > 400) {
                $contentSummary = substr($rawText, 0, 400) . "...";
            } else if (!empty($rawText)) {
                $contentSummary = $rawText;
            }
        }

        if ($isHindi) {
            $reply = "नमस्ते! मैं {$tutorName} हूँ, आपकी AI शिक्षिका।\n\n" .
                "📚 **विषय:** {$subject}\n" .
                "📖 **अध्याय:** {$chapter}\n\n";

            if (!empty($contentSummary)) {
                $reply .= "### अध्याय का मुख्य सारांश:\n" .
                    "{$contentSummary}\n\n" .
                    "### मुख्य बिंदु:\n" .
                    "1. **मूल अवधारणाएं:** इस अध्याय में दिए गए मुख्य सिद्धांतों, सूत्रों और परिभाषाओं को समझें।\n" .
                    "2. **अभ्यास प्रश्न:** अध्याय के अंत में दिए गए प्रश्नों को हल करने का प्रयास करें।\n\n" .
                    "आप मुझसे इस अध्याय के किसी भी प्रश्न या परिभाषा के बारे में पूछ सकते हैं!";
            } else {
                $reply .= "### अध्याय की मुख्य बातें:\n" .
                    "1. **अवधारणा (Concept):** यह अध्याय **{$chapter}** के मुख्य विषयों को प्रस्तुत करता है।\n" .
                    "2. **महत्वपूर्ण बिंदु:** परिभाषाओं, सूत्रों और आरेखों (Diagrams) पर विशेष ध्यान दें।\n" .
                    "3. **प्रश्न उत्तर:** इस अध्याय से संबंधित किसी भी विशिष्ट प्रश्न को मुझसे पूछें, मैं विस्तार से समझाऊंगी!";
            }
            return $reply;
        }

        $reply = "Hello! I am {$tutorName}, your AI Tutor.\n\n" .
            "📚 **Subject:** {$subject}\n" .
            "📖 **Chapter:** {$chapter}\n\n";

        if (!empty($contentSummary)) {
            $reply .= "### Chapter Summary:\n" .
                "{$contentSummary}\n\n" .
                "Feel free to ask me specific questions about any formula, definition, or exercise problem from this chapter!";
        } else {
            $reply .= "### Key Overview of {$chapter}:\n" .
                "1. **Core Concept:** Review the fundamental definitions and key principles in this chapter.\n" .
                "2. **Step-by-Step Understanding:** Focus on practice exercises and key formulas.\n\n" .
                "Feel free to ask me any specific question about this chapter!";
        }

        return $reply;
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



