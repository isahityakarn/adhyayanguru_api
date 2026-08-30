<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    use HasFactory;

    protected $fillable = [
        'chapter_id',
        'title',
        'description',
        'total_mcq',
        'total_written',
        'time_limit_minutes',
        'passing_percentage',
        'marks_per_mcq',
        'marks_per_written',
        'randomize_questions',
        'randomize_options',
        'max_attempts',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'total_mcq' => 'integer',
            'total_written' => 'integer',
            'time_limit_minutes' => 'integer',
            'passing_percentage' => 'float',
            'marks_per_mcq' => 'integer',
            'marks_per_written' => 'integer',
            'randomize_questions' => 'boolean',
            'randomize_options' => 'boolean',
            'max_attempts' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    public function questions()
    {
        return $this->hasMany(QuizQuestion::class, 'quiz_id')->orderBy('order_num', 'asc');
    }

    public function writtenQuestions()
    {
        return $this->hasMany(QuizWrittenQuestion::class, 'quiz_id')->orderBy('order_num', 'asc');
    }

    public function attempts()
    {
        return $this->hasMany(QuizAttempt::class, 'quiz_id');
    }
}
