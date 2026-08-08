<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'class_id',
        'board_id',
    ];

    public function classLevel()
    {
        return $this->belongsTo(ClassLevel::class, 'class_id');
    }

    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    public function chapters()
    {
        return $this->hasMany(Chapter::class);
    }
}
