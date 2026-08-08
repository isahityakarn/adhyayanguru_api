<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'chapter_id',
        'topic_id',
        'question_text',
        'question_type',
        'options',
        'correct_answer',
        'difficulty',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
        ];
    }

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function quizAnswers()
    {
        return $this->hasMany(QuizAnswer::class);
    }
}
