<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StudentProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'class_id',
        'board_id',
        'school_name',
        'plan',
        'current_streak',
        'longest_streak',
        'last_active_date',
    ];

    protected function casts(): array
    {
        return [
            'last_active_date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function classLevel()
    {
        return $this->belongsTo(ClassLevel::class, 'class_id');
    }

    public function board()
    {
        return $this->belongsTo(Board::class);
    }
}
