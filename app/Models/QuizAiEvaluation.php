<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizAiEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'written_question_id',
        'score',
        'max_score',
        'percentage',
        'is_correct',
        'feedback',
        'strengths',
        'improvements',
        'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'max_score' => 'float',
            'percentage' => 'float',
            'is_correct' => 'boolean',
            'strengths' => 'array',
            'improvements' => 'array',
            'raw_response' => 'array',
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
