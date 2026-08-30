<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'quiz_id',
        'chapter_id',
        'attempt_number',
        'status',
        'mcq_score',
        'written_score',
        'total_score',
        'max_score',
        'percentage',
        'is_passed',
        'time_spent_seconds',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'mcq_score' => 'float',
            'written_score' => 'float',
            'total_score' => 'float',
            'max_score' => 'float',
            'percentage' => 'float',
            'is_passed' => 'boolean',
            'time_spent_seconds' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class, 'quiz_id');
    }

    public function chapter()
    {
        return $this->belongsTo(Chapter::class, 'chapter_id');
    }

    public function mcqAnswers()
    {
        return $this->hasMany(QuizAnswer::class, 'attempt_id');
    }

    public function writtenAnswers()
    {
        return $this->hasMany(QuizWrittenAnswer::class, 'attempt_id');
    }

    public function aiEvaluations()
    {
        return $this->hasMany(QuizAiEvaluation::class, 'attempt_id');
    }
}
