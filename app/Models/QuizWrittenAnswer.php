<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizWrittenAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'written_question_id',
        'answer_text',
        'word_count',
        'score',
        'max_score',
        'is_correct',
        'feedback',
        'strengths',
        'improvements',
        'ai_evaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'word_count' => 'integer',
            'score' => 'float',
            'max_score' => 'float',
            'is_correct' => 'boolean',
            'strengths' => 'array',
            'improvements' => 'array',
            'ai_evaluated_at' => 'datetime',
        ];
    }

    public function attempt()
    {
        return $this->belongsTo(QuizAttempt::class, 'attempt_id');
    }

    public function question()
    {
        return $this->belongsTo(QuizWrittenQuestion::class, 'written_question_id');
    }
}
