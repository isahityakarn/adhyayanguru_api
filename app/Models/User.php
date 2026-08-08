<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'language_pref',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function studentProfile()
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function linkedStudents()
    {
        return $this->belongsToMany(User::class, 'parent_student_links', 'parent_id', 'student_id');
    }

    public function linkedParents()
    {
        return $this->belongsToMany(User::class, 'parent_student_links', 'student_id', 'parent_id');
    }

    public function chatSessions()
    {
        return $this->hasMany(ChatSession::class, 'student_id');
    }

    public function quizAttempts()
    {
        return $this->hasMany(QuizAttempt::class, 'student_id');
    }

    public function progress()
    {
        return $this->hasMany(Progress::class, 'student_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(StudentSubscription::class, 'student_id');
    }

    public function adminLogs()
    {
        return $this->hasMany(AdminLog::class, 'admin_id');
    }

    public function createdChapters()
    {
        return $this->hasMany(Chapter::class, 'created_by');
    }
}
