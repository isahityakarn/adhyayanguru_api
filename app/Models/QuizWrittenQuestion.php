<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizWrittenQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'question_text',
        'expected_answer',
        'key_concepts',
        'marking_criteria',
        'min_words',
        'max_words',
        'marks',
        'order_num',
    ];

    protected function casts(): array
    {
        return [
            'key_concepts' => 'array',
            'min_words' => 'integer',
            'max_words' => 'integer',
            'marks' => 'integer',
            'order_num' => 'integer',
        ];
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }
}
