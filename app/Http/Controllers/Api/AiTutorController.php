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
        'gemini-3.5-flash',
        'gemini-3.5-flash-lite',
        'gemini-3.7-flash',
        'gemini-flash-latest',
        'gemini-flash-lite-latest',
        'gemini-pro-latest',
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

        // If top-level chapter_content / chapter_id provided, merge into context
        if ($request->filled('chapter_id') && !isset($context['chapter_id'])) {
            $context['chapter_id'] = $request->input('chapter_id');
        }
        if ($request->filled('chapter') && !isset($context['chapter'])) {
            $context['chapter'] = $request->input('chapter');
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

        // If chapter_id is provided but no extracted text in context, load it from DB
        if (!empty($context['chapter_id']) && empty($context['chapter_content'])) {
            $chapterModel = Chapter::with('subject')->find($context['chapter_id']);
            if ($chapterModel) {
                if (!empty($chapterModel->extracted_text)) {
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

            // Call Gemini API with resilient multi-model fallback
            $aiResponse = $this->callGeminiApi($conversationHistory, [
                'temperature' => 0.7,
                'topK' => 40,
                'topP' => 0.95,
                'maxOutputTokens' => 2048,
            ]);

            if (empty($aiResponse)) {
                // If API is temporarily rate limited or unreachable, synthesize educational response
                $aiResponse = $this->generateEducationalFallback($message, $context);
            }

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
        if (! $context) {
            return 'You are Adhyayan, a friendly, encouraging, and highly knowledgeable AI tutor for Indian school students (CBSE / ICSE / State Boards). Explain concepts clearly with simple step-by-step examples.';
        }

        $contextParts = [
            'You are Adhyayan, a friendly, encouraging, and highly knowledgeable AI tutor for Indian school students (CBSE / ICSE / State Boards).',
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
5. Always be positive, supportive, and encourage curiosity!";

        return implode("\n", $contextParts);
    }

    /**
     * Build conversation history for Gemini API.
     */
    private function buildConversationHistory(array $history, string $systemContext): array
    {
        $messages = [];

        // Add system context as the first user message with model response
        $messages[] = [
            'role' => 'user',
            'parts' => [['text' => $systemContext]],
        ];

        $messages[] = [
            'role' => 'model',
            'parts' => [['text' => 'Namaste! I am Adhyayan, your AI Tutor. I understand your instructions and I am ready to help the student learn with clear explanations, examples, and warm encouragement!']],
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
        $subject = $context['subject'] ?? 'the subject';
        $chapter = $context['chapter'] ?? 'this chapter';
        $isHindi = str_starts_with(strtolower($context['language'] ?? 'en'), 'hi');

        if ($isHindi) {
            return "नमस्ते! मैं अध्ययन हूँ। आपके प्रश्न **\"{$message}\"** के संदर्भ में:\n\n" .
                "📚 **विषय:** {$subject}\n" .
                "📖 **अध्याय:** {$chapter}\n\n" .
                "इस अवधारणा को समझने के लिए मुख्य बिंदु:\n" .
                "1. **मूल सिद्धांत (Core Concept):** यह विषय अध्याय के आधारभूत नियमों पर आधारित है।\n" .
                "2. **महत्वपूर्ण बिंदु:** परिभाषाओं और उदाहरणों को ध्यान से पढ़ें।\n" .
                "3. **अभ्यास:** संबंधित प्रश्नों को हल करके अपनी समझ को परखें।\n\n" .
                "यदि आप किसी विशिष्ट परिभाषा, सूत्र या प्रश्न का हल चाहते हैं, तो कृपया नीचे विस्तार से पूछें!";
        }

        return "Hello! I am Adhyayan, your AI Tutor. Regarding your question: **\"{$message}\"**\n\n" .
            "📚 **Subject:** {$subject}\n" .
            "📖 **Chapter:** {$chapter}\n\n" .
            "### Key Learning Points:\n" .
            "1. **Core Concept:** Review the fundamental definitions and principles covered in this section.\n" .
            "2. **Step-by-Step Understanding:** Break complex problems down into smaller, manageable parts.\n" .
            "3. **Practical Application:** Connect the theory to real-world examples from everyday life.\n\n" .
            "Feel free to ask specific questions about any formula, exercise problem, or summary from this chapter!";
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
}
