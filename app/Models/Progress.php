<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Progress extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'chapter_id',
        'status',
        'percent_complete',
        'time_spent_seconds',
        'completed_at',
        'last_accessed_at',
    ];

    protected $appends = ['formatted_time_spent'];

    protected function casts(): array
    {
        return [
            'time_spent_seconds' => 'integer',
            'percent_complete' => 'integer',
            'completed_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function getFormattedTimeSpentAttribute(): string
    {
        $seconds = (int) ($this->time_spent_seconds ?? 0);
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remSeconds = $seconds % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}m {$remSeconds}s";
        }
        if ($minutes > 0) {
            return "{$minutes}m {$remSeconds}s";
        }
        return "{$remSeconds}s";
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }
}
