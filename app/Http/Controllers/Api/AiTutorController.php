<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiTutorController extends Controller
{
    /**
     * Handle AI Tutor conversation with Google Gemini.
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'context' => ['nullable', 'array'],
            'context.subject' => ['nullable', 'string'],
            'context.chapter' => ['nullable', 'string'],
            'context.topic' => ['nullable', 'string'],
            'conversation_history' => ['nullable', 'array'],
        ]);

        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            return response()->json([
                'message' => 'Gemini API key not configured.',
            ], 500);
        }

        try {
            // Build the context for the AI
            $systemContext = $this->buildSystemContext($request->context);

            // Build conversation history
            $conversationHistory = $this->buildConversationHistory(
                $request->conversation_history ?? [],
                $systemContext
            );

            // Add the current user message
            $conversationHistory[] = [
                'role' => 'user',
                'parts' => [['text' => $request->message]],
            ];

            // Call Gemini API
            $response = Http::timeout(30)
                ->post("https://generativelanguage.googleapis.com/v1/models/gemini-3.6-flash:generateContent?key={$apiKey}", [
                    'contents' => $conversationHistory,
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'topK' => 40,
                        'topP' => 0.95,
                        'maxOutputTokens' => 2048,
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('Gemini API error', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return response()->json([
                    'message' => 'Failed to get response from AI tutor.',
                    'error' => $response->json()['error']['message'] ?? 'Unknown error',
                ], $response->status());
            }

            $responseData = $response->json();

            // Extract the AI response text
            $aiResponse = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return response()->json([
                'response' => $aiResponse,
                'usage' => [
                    'prompt_tokens' => $responseData['usageMetadata']['promptTokenCount'] ?? 0,
                    'completion_tokens' => $responseData['usageMetadata']['candidatesTokenCount'] ?? 0,
                    'total_tokens' => $responseData['usageMetadata']['totalTokenCount'] ?? 0,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('AI Tutor error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred while processing your request.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Build system context based on the provided context data.
     */
    private function buildSystemContext(?array $context): string
    {
        if (! $context) {
            return 'You are a helpful AI tutor assistant. Help students understand concepts, answer questions, and provide educational guidance.';
        }

        $contextParts = ['You are a helpful AI tutor assistant.'];

        if (isset($context['subject'])) {
            $contextParts[] = "The student is currently studying {$context['subject']}.";
        }

        if (isset($context['chapter'])) {
            $contextParts[] = "They are working on the chapter: {$context['chapter']}.";
        }

        if (isset($context['topic'])) {
            $contextParts[] = "The specific topic is: {$context['topic']}.";
        }

        $contextParts[] = 'Provide clear, educational responses. Break down complex concepts into simpler terms. Use examples when appropriate. Encourage learning and critical thinking.';

        return implode(' ', $contextParts);
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
            'parts' => [['text' => 'I understand. I will act as a helpful AI tutor and provide clear, educational responses to help students learn.']],
        ];

        // Add conversation history
        foreach ($history as $message) {
            if (isset($message['role']) && isset($message['content'])) {
                $role = $message['role'] === 'assistant' ? 'model' : 'user';
                $messages[] = [
                    'role' => $role,
                    'parts' => [['text' => $message['content']]],
                ];
            }
        }

        return $messages;
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

        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            return response()->json([
                'message' => 'Gemini API key not configured.',
            ], 500);
        }

        try {
            $detailLevel = $request->detail_level ?? 'intermediate';
            $subject = $request->subject ?? 'the subject';

            $prompt = "Explain the topic '{$request->topic}' in {$subject} at a {$detailLevel} level. ";
            $prompt .= 'Provide a clear explanation with examples and key points. ';
            $prompt .= 'Structure your response with: 1) Overview, 2) Key Concepts, 3) Examples, 4) Summary.';

            $response = Http::timeout(30)
                ->post("https://generativelanguage.googleapis.com/v1/models/gemini-3.6-flash:generateContent?key={$apiKey}", [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [['text' => $prompt]],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'topK' => 40,
                        'topP' => 0.95,
                        'maxOutputTokens' => 2048,
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('Gemini API error', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return response()->json([
                    'message' => 'Failed to generate explanation.',
                    'error' => $response->json()['error']['message'] ?? 'Unknown error',
                ], $response->status());
            }

            $responseData = $response->json();
            $explanation = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return response()->json([
                'topic' => $request->topic,
                'explanation' => $explanation,
            ]);
        } catch (\Exception $e) {
            Log::error('AI Tutor explanation error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred while generating explanation.',
                'error' => $e->getMessage(),
            ], 500);
        }
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

        $apiKey = config('services.gemini.api_key');

        if (! $apiKey) {
            return response()->json([
                'message' => 'Gemini API key not configured.',
            ], 500);
        }

        try {
            $difficulty = $request->difficulty ?? 'medium';
            $count = $request->count ?? 5;
            $subject = $request->subject ?? 'the subject';

            $prompt = "Generate {$count} {$difficulty} difficulty practice questions about '{$request->topic}' in {$subject}. ";
            $prompt .= 'For each question, provide: 1) The question text, 2) The correct answer, 3) A brief explanation of why that answer is correct. ';
            $prompt .= 'Format your response as a numbered list.';

            $response = Http::timeout(30)
                ->post("https://generativelanguage.googleapis.com/v1/models/gemini-3.6-flash:generateContent?key={$apiKey}", [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [['text' => $prompt]],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.8,
                        'topK' => 40,
                        'topP' => 0.95,
                        'maxOutputTokens' => 2048,
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('Gemini API error', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return response()->json([
                    'message' => 'Failed to generate questions.',
                    'error' => $response->json()['error']['message'] ?? 'Unknown error',
                ], $response->status());
            }

            $responseData = $response->json();
            $questions = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return response()->json([
                'topic' => $request->topic,
                'difficulty' => $difficulty,
                'questions' => $questions,
            ]);
        } catch (\Exception $e) {
            Log::error('AI Tutor question generation error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An error occurred while generating questions.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
