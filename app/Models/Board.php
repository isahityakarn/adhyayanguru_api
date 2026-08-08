<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Board extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function studentProfiles()
    {
        return $this->hasMany(StudentProfile::class);
    }
}
