<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Chapter extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_id',
        'chapter_number',
        'title',
        'description',
        'source_file_url',
        'extracted_text',
        'questions',
        'processed_at',
        'created_by',
    ];

    protected $casts = [
        'questions' => 'array',
        'processed_at' => 'datetime',
    ];


    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function pages()
    {
        return $this->hasMany(ChapterPage::class);
    }

    public function quizAttempts()
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function chatSessions()
    {
        return $this->hasMany(ChatSession::class);
    }

    public function progress()
    {
        return $this->hasMany(Progress::class);
    }

    public function quiz()
    {
        return $this->hasOne(Quiz::class);
    }
}
