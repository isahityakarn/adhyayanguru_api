<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price_inr',
        'duration_days',
        'features',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price_inr' => 'decimal:2',
        ];
    }

    public function subscriptions()
    {
        return $this->hasMany(StudentSubscription::class);
    }
}
