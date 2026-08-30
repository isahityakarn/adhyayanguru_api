<?php

namespace Database\Seeders;

use App\Models\Chapter;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\QuizWrittenQuestion;
use Illuminate\Database\Seeder;

class QuizSeeder extends Seeder
{
    /**
     * Seed 50 MCQs and 20 Written questions for every chapter.
     */
    public function run(): void
    {
        $chapters = Chapter::with('subject.classLevel')->get();

        foreach ($chapters as $chapter) {
            $quiz = Quiz::updateOrCreate(
                ['chapter_id' => $chapter->id],
                [
                    'title' => "Chapter " . ($chapter->chapter_number ?? 1) . " Comprehensive Quiz",
                    'description' => "Test your knowledge on {$chapter->title} with 50 MCQs and 20 written questions.",
                    'total_mcq' => 50,
                    'total_written' => 20,
                    'time_limit_minutes' => 45,
                    'passing_percentage' => 60.0,
                    'marks_per_mcq' => 1,
                    'marks_per_written' => 10,
                    'randomize_questions' => false,
                    'randomize_options' => false,
                    'max_attempts' => 5,
                    'is_published' => true,
                ]
            );

            // Generate 50 MCQs if count is less than 50
            $existingMcqCount = QuizQuestion::where('quiz_id', $quiz->id)->count();
            if ($existingMcqCount < 50) {
                $this->generateMcqQuestions($quiz, $chapter, 50 - $existingMcqCount, $existingMcqCount);
            }

            // Generate 20 Written questions if count is less than 20
            $existingWrittenCount = QuizWrittenQuestion::where('quiz_id', $quiz->id)->count();
            if ($existingWrittenCount < 20) {
                $this->generateWrittenQuestions($quiz, $chapter, 20 - $existingWrittenCount, $existingWrittenCount);
            }
        }
    }

    private function generateMcqQuestions(Quiz $quiz, Chapter $chapter, int $countNeeded, int $offset): void
    {
        $subjectName = $chapter->subject->name ?? 'Mathematics';
        $chapterTitle = $chapter->title;
        $classLevel = $chapter->subject->classLevel->name ?? 'Class 5';

        for ($i = 1; $i <= $countNeeded; $i++) {
            $num = $offset + $i;
            $questionData = $this->createMcqTemplate($num, $subjectName, $chapterTitle, $classLevel);

            QuizQuestion::create([
                'quiz_id' => $quiz->id,
                'question_text' => $questionData['question_text'],
                'options' => $questionData['options'],
                'correct_answer' => $questionData['correct_answer'],
                'explanation' => $questionData['explanation'],
                'difficulty' => $num % 3 === 0 ? 'hard' : ($num % 2 === 0 ? 'medium' : 'easy'),
                'order_num' => $num,
            ]);
        }
    }

    private function generateWrittenQuestions(Quiz $quiz, Chapter $chapter, int $countNeeded, int $offset): void
    {
        $subjectName = $chapter->subject->name ?? 'Mathematics';
        $chapterTitle = $chapter->title;
        $classLevel = $chapter->subject->classLevel->name ?? 'Class 5';

        for ($i = 1; $i <= $countNeeded; $i++) {
            $num = $offset + $i;
            $wData = $this->createWrittenTemplate($num, $subjectName, $chapterTitle, $classLevel);

            QuizWrittenQuestion::create([
                'quiz_id' => $quiz->id,
                'question_text' => $wData['question_text'],
                'expected_answer' => $wData['expected_answer'],
                'key_concepts' => $wData['key_concepts'],
                'marking_criteria' => $wData['marking_criteria'],
                'min_words' => 20,
                'max_words' => 300,
                'marks' => 10,
                'order_num' => $num,
            ]);
        }
    }

    private function createMcqTemplate(int $n, string $subject, string $chapterTitle, string $className): array
    {
        if (str_contains(strtolower($subject), 'math')) {
            $val1 = ($n * 5) + 15;
            $val2 = ($n * 3) + 4;
            $ansVal = $val1 * $val2;

            $opts = [
                ['letter' => 'A', 'text' => (string) ($ansVal - 10)],
                ['letter' => 'B', 'text' => (string) ($ansVal - 5)],
                ['letter' => 'C', 'text' => (string) $ansVal],
                ['letter' => 'D', 'text' => (string) ($ansVal + 10)],
            ];

            return [
                'question_text' => "In {$chapterTitle}, calculate the value of {$val1} × {$val2}. / अध्याय '{$chapterTitle}' के अंतर्गत {$val1} × {$val2} का मान ज्ञात करें।",
                'options' => $opts,
                'correct_answer' => 'C',
                'explanation' => "To solve {$val1} × {$val2}, multiply {$val1} by {$val2} to get {$ansVal}. / {$val1} को {$val2} से गुणा करने पर {$ansVal} प्राप्त होता है।",
            ];
        }

        if (str_contains(strtolower($subject), 'sci') || str_contains(strtolower($subject), 'bio') || str_contains(strtolower($subject), 'chem') || str_contains(strtolower($subject), 'phys')) {
            $topics = [
                1 => [
                    'q' => "What is the primary role of chlorophyll in plants? / पौधों में क्लोरोफिल की मुख्य भूमिका क्या है?",
                    'ans' => 'B',
                    'opts' => [
                        'A. Absorbing water from soil / मिट्टी से पानी अवशोषित करना',
                        'B. Absorbing sunlight for photosynthesis / प्रकाश संश्लेषण के लिए सूर्य के प्रकाश को अवशोषित करना',
                        'C. Transporting minerals / खनिजों का परिवहन करना',
                        'D. Storing starch / स्टार्च को संग्रहित करना'
                    ]
                ],
                2 => [
                    'q' => "Which state of matter has a definite volume but no fixed shape? / पदार्थ की किस अवस्था का निश्चित आयतन होता है लेकिन निश्चित आकार नहीं?",
                    'ans' => 'C',
                    'opts' => [
                        'A. Solid / ठोस',
                        'B. Gas / गैस',
                        'C. Liquid / द्रव',
                        'D. Plasma / प्लाज्मा'
                    ]
                ],
                3 => [
                    'q' => "Which unit is used to measure electric current? / विद्युत धारा को मापने के लिए किस इकाई का उपयोग किया जाता है?",
                    'ans' => 'A',
                    'opts' => [
                        'A. Ampere / एम्पीयर',
                        'B. Volt / वोल्ट',
                        'C. Ohm / ओम',
                        'D. Watt / वाट'
                    ]
                ],
            ];
            $tIdx = (($n - 1) % 3) + 1;
            $t = $topics[$tIdx];

            $rawOpts = $t['opts'];
            $formattedOpts = array_map(function ($opt) {
                $letter = substr($opt, 0, 1);
                $text = trim(substr($opt, 2));
                return ['letter' => $letter, 'text' => $text];
            }, $rawOpts);

            return [
                'question_text' => "Regarding {$chapterTitle}, {$t['q']}",
                'options' => $formattedOpts,
                'correct_answer' => $t['ans'],
                'explanation' => "Correct answer is {$t['ans']}. This principle applies to {$chapterTitle}. / सही उत्तर {$t['ans']} है। यह नियम {$chapterTitle} पर लागू होता है।",
            ];
        }

        // Default general template
        $correctLetter = ['A', 'B', 'C', 'D'][($n - 1) % 4];
        $opts = [
            ['letter' => 'A', 'text' => "Concept statement A / अवधारणा कथन A"],
            ['letter' => 'B', 'text' => "Concept statement B / अवधारणा कथन B"],
            ['letter' => 'C', 'text' => "Concept statement C / अवधारणा कथन C"],
            ['letter' => 'D', 'text' => "Concept statement D / अवधारणा कथन D"],
        ];

        return [
            'question_text' => "Which of the following statements regarding {$chapterTitle} is correct? / अध्याय '{$chapterTitle}' के संबंध में निम्नलिखित में से कौन सा कथन सही है?",
            'options' => $opts,
            'correct_answer' => $correctLetter,
            'explanation' => "Option {$correctLetter} correctly describes the key concept covered in {$chapterTitle}. / विकल्प {$correctLetter} इस अध्याय की मुख्य अवधारणा का सही वर्णन करता है।",
        ];
    }

    private function createWrittenTemplate(int $n, string $subject, string $chapterTitle, string $className): array
    {
        $questions = [
            1 => [
                'q' => "Explain the fundamental principles of {$chapterTitle} and describe how they apply in real-world situations. / {$chapterTitle} के मूल सिद्धांतों की व्याख्या करें और बताएं कि वे व्यावहारिक जीवन में कैसे लागू होते हैं।",
                'expected' => "{$chapterTitle} involves core concepts that dictate standard processes. In real-world applications, these principles ensure precision and balance. / {$chapterTitle} में मुख्य अवधारणाएँ शामिल हैं। व्यावहारिक अनुप्रयोगों में ये सिद्धांत सटीकता और संतुलन सुनिश्चित करते हैं।",
                'concepts' => [$chapterTitle, 'core principles', 'real-world applications', 'मूल सिद्धांत'],
            ],
            2 => [
                'q' => "Compare and contrast the primary mechanisms studied in {$chapterTitle}. Highlight key similarities and differences. / {$chapterTitle} में अध्ययन किए गए मुख्य तंत्रों की तुलना करें और अंतर स्पष्ट करें।",
                'expected' => "The primary mechanisms share a structural framework, but differ in their functional execution. / मुख्य तंत्र एक संरचनात्मक ढांचे को साझा करते हैं, लेकिन उनके कार्यान्वयन में भिन्नता होती है।",
                'concepts' => ['comparison', 'structural framework', 'तुलना', 'संरचनात्मक ढांचा'],
            ],
            3 => [
                'q' => "Write a detailed note on why {$chapterTitle} is important for {$className} students in {$subject}. / {$subject} में {$className} के छात्रों के लिए {$chapterTitle} क्यों महत्वपूर्ण है, इस पर एक विस्तृत टिप्पणी लिखें।",
                'expected' => "Understanding {$chapterTitle} builds foundational analytical skills in {$subject}. / {$chapterTitle} को समझना {$subject} में विश्लेषणात्मक कौशल का निर्माण करता है।",
                'concepts' => ['importance', 'foundational skills', 'महत्व', 'बुनियादी कौशल'],
            ]
        ];

        $idx = (($n - 1) % 3) + 1;
        $item = $questions[$idx];

        return [
            'question_text' => $item['q'],
            'expected_answer' => $item['expected'],
            'key_concepts' => $item['concepts'],
            'marking_criteria' => 'Award full marks for clear explanation in English or Hindi. / अंग्रेजी या हिंदी में स्पष्ट व्याख्या के लिए पूरे अंक दें।',
            'min_words' => 20,
            'max_words' => 300,
            'marks' => 10,
            'order_num' => $n,
        ];
    }
}
