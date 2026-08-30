<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuizAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'attempt_id',
        'question_id',
        'selected_option',
        'is_correct',
        'is_marked_for_review',
    ];

    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'is_marked_for_review' => 'boolean',
        ];
    }

    public function attempt()
    {
        return $this->belongsTo(QuizAttempt::class, 'attempt_id');
    }

    public function question()
    {
        return $this->belongsTo(QuizQuestion::class, 'question_id');
    }
}
