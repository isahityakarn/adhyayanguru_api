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
        'created_by',
    ];

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function topics()
    {
        return $this->hasMany(Topic::class);
    }

    public function pages()
    {
        return $this->hasMany(ChapterPage::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
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
}
