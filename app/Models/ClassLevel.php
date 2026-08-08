<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function subjects()
    {
        return $this->hasMany(Subject::class, 'class_id');
    }

    public function studentProfiles()
    {
        return $this->hasMany(StudentProfile::class, 'class_id');
    }
}
