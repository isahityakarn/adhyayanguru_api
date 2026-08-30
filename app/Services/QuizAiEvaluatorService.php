<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QuizAiEvaluatorService
{
    /**
     * Evaluate a written answer using AI API (Gemini with robust validation and intelligent fallback).
     */
    public function evaluateWrittenAnswer(array $context): array
    {
        $classLevel = $context['class_level'] ?? 'School Level';
        $subject = $context['subject'] ?? 'General';
        $chapter = $context['chapter'] ?? 'Chapter Topic';
        $questionText = $context['question_text'] ?? '';
        $expectedAnswer = $context['expected_answer'] ?? '';
        $keyConcepts = $context['key_concepts'] ?? [];
        $markingCriteria = $context['marking_criteria'] ?? 'Clarity, accuracy, and core understanding.';
        $studentAnswer = trim($context['student_answer'] ?? '');
        $maxScore = (float) ($context['max_score'] ?? 10);

        if (empty($studentAnswer)) {
            return [
                'score' => 0,
                'max_score' => $maxScore,
                'percentage' => 0,
                'is_correct' => false,
                'feedback' => 'No answer was provided.',
                'strengths' => [],
                'improvements' => ['Provide an answer addressing the key concepts.'],
            ];
        }

        $apiKey = env('GEMINI_API_KEY');

        if ($apiKey) {
            try {
                $prompt = $this->buildPrompt(
                    $classLevel,
                    $subject,
                    $chapter,
                    $questionText,
                    $expectedAnswer,
                    $keyConcepts,
                    $markingCriteria,
                    $studentAnswer,
                    $maxScore
                );

                $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key={$apiKey}";
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->timeout(15)->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'responseMimeType' => 'application/json'
                    ]
                ]);

                if ($response->successful()) {
                    $jsonBody = $response->json();
                    $text = $jsonBody['candidates'][0]['content']['parts'][0]['text'] ?? '';

                    $parsed = $this->cleanAndParseJson($text);
                    $validated = $this->validateAndSanitizeResponse($parsed, $maxScore);

                    if ($validated) {
                        return $validated;
                    }
                } else {
                    Log::warning('Gemini API call returned non-200: ' . $response->body());
                }
            } catch (\Throwable $e) {
                Log::error('AI Evaluation Exception: ' . $e->getMessage());
            }
        }

        // Fallback rule-based smart evaluation if API is unavailable or fails validation
        return $this->fallbackEvaluation($studentAnswer, $expectedAnswer, $keyConcepts, $maxScore);
    }

    private function buildPrompt(
        string $classLevel,
        string $subject,
        string $chapter,
        string $questionText,
        string $expectedAnswer,
        $keyConcepts,
        string $markingCriteria,
        string $studentAnswer,
        float $maxScore
    ): string {
        $keyConceptsStr = is_array($keyConcepts) ? implode(', ', $keyConcepts) : (string) $keyConcepts;

        return <<<PROMPT
You are an educational answer evaluator.

Evaluate the student's answer based on:
- Class level: {$classLevel}
- Subject: {$subject}
- Chapter: {$chapter}
- Question: {$questionText}
- Expected answer: {$expectedAnswer}
- Key concepts: {$keyConceptsStr}
- Marking criteria: {$markingCriteria}
- Maximum possible marks: {$maxScore}

Student's Answer:
"{$studentAnswer}"

Instructions:
1. Be fair, encouraging, and age-appropriate for {$classLevel}.
2. Do not judge grammar harshly unless language quality is specifically part of the marking criteria.
3. Calculate a fair score out of {$maxScore}.

Return ONLY valid JSON matching this exact structure:
{
  "score": number,
  "max_score": {$maxScore},
  "percentage": number,
  "is_correct": boolean,
  "feedback": "string concise feedback",
  "strengths": ["string point 1", "string point 2"],
  "improvements": ["string suggestion 1"]
}
PROMPT;
    }

    private function cleanAndParseJson(string $rawText): ?array
    {
        $cleaned = trim($rawText);
        $cleaned = preg_replace('/^```json\s*/i', '', $cleaned);
        $cleaned = preg_replace('/^```\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned);
        $cleaned = trim($cleaned);

        $decoded = json_decode($cleaned, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function validateAndSanitizeResponse(?array $data, float $maxScore): ?array
    {
        if (!$data || !isset($data['score'])) {
            return null;
        }

        $score = max(0, min((float) $data['score'], $maxScore));
        $percentage = round(($score / $maxScore) * 100, 1);
        $isCorrect = isset($data['is_correct']) ? (bool) $data['is_correct'] : ($percentage >= 50);

        $feedback = is_string($data['feedback'] ?? null) ? trim($data['feedback']) : 'Answer evaluated successfully.';
        $strengths = is_array($data['strengths'] ?? null) ? array_values($data['strengths']) : [];
        $improvements = is_array($data['improvements'] ?? null) ? array_values($data['improvements']) : [];

        return [
            'score' => round($score, 1),
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'is_correct' => $isCorrect,
            'feedback' => $feedback,
            'strengths' => $strengths,
            'improvements' => $improvements,
        ];
    }

    private function fallbackEvaluation(
        string $studentAnswer,
        string $expectedAnswer,
        $keyConcepts,
        float $maxScore
    ): array {
        $studentLower = strtolower($studentAnswer);
        $expectedLower = strtolower($expectedAnswer);

        $concepts = is_array($keyConcepts) ? $keyConcepts : array_filter(explode(',', (string) $keyConcepts));
        $conceptsFound = 0;
        $totalConcepts = max(1, count($concepts));

        foreach ($concepts as $concept) {
            $c = trim(strtolower($concept));
            if (!empty($c) && str_contains($studentLower, $c)) {
                $conceptsFound++;
            }
        }

        // Calculate similarity percentage based on key concepts & length
        $conceptRatio = $conceptsFound / $totalConcepts;
        $wordCount = count(explode(' ', $studentAnswer));

        if ($wordCount >= 10 && $conceptRatio > 0.5) {
            $pct = min(100, 60 + ($conceptRatio * 40));
        } elseif ($wordCount >= 5 && $conceptRatio > 0) {
            $pct = 40 + ($conceptRatio * 30);
        } else {
            $pct = min(40, $wordCount * 4);
        }

        $score = round(($pct / 100) * $maxScore, 1);
        $percentage = round($pct, 1);
        $isCorrect = $percentage >= 50;

        return [
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'is_correct' => $isCorrect,
            'feedback' => $isCorrect
                ? 'Good effort! Your answer covers core concepts well.'
                : 'Your answer is on the right track, but needs more key details from the chapter.',
            'strengths' => [
                'Demonstrates understanding of core question requirement.',
                $wordCount >= 15 ? 'Good detail and length in answer.' : 'Clear expression.',
            ],
            'improvements' => [
                $conceptsFound < $totalConcepts ? 'Include more specific key terms and definitions.' : 'Elaborate further with relevant examples.',
            ],
        ];
    }
}
