<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = [
        'chapter_id',
        'title',
        'order_index',
    ];

    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function chatSessions()
    {
        return $this->hasMany(ChatSession::class);
    }
}
