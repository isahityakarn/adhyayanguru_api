<?php

namespace Database\Seeders;

use App\Models\Board;
use App\Models\ClassLevel;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        // Seed Boards
        $boards = [
            ['name' => 'CBSE'],
            ['name' => 'ICSE'],
        ];

        foreach ($boards as $board) {
            Board::create($board);
        }

        // Seed Class Levels
        $classLevels = [
            ['name' => 'Class 1'],
            ['name' => 'Class 2'],
            ['name' => 'Class 3'],
            ['name' => 'Class 4'],
            ['name' => 'Class 5'],
            ['name' => 'Class 6'],
            ['name' => 'Class 7'],
            ['name' => 'Class 8'],
            ['name' => 'Class 9'],
            ['name' => 'Class 10'],
            ['name' => 'Class 11'],
            ['name' => 'Class 12'],
        ];

        foreach ($classLevels as $classLevel) {
            ClassLevel::create($classLevel);
        }

        // Seed Plans
        $plans = [
            [
                'name' => 'Free Trial',
                'price_inr' => 0.00,
                'duration_days' => 7,
                'features' => [
                    'Access to all subjects',
                    'Limited quizzes per day',
                    'AI chat assistance',
                    'Progress tracking',
                ],
            ],
            [
                'name' => '1 Month Plan',
                'price_inr' => 299.00,
                'duration_days' => 30,
                'features' => [
                    'Access to all subjects',
                    'Unlimited quizzes',
                    'AI chat assistance',
                    'Progress tracking',
                ],
            ],
            [
                'name' => '6 Month Plan',
                'price_inr' => 1499.00,
                'duration_days' => 180,
                'features' => [
                    'Access to all subjects',
                    'Unlimited quizzes',
                    'AI chat assistance',
                    'Progress tracking',
                    'Priority support',
                    '17% savings',
                ],
            ],
            [
                'name' => '12 Month Plan',
                'price_inr' => 2499.00,
                'duration_days' => 365,
                'features' => [
                    'Access to all subjects',
                    'Unlimited quizzes',
                    'AI chat assistance',
                    'Progress tracking',
                    'Priority support',
                    'Downloadable content',
                    '30% savings',
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Plan::create($plan);
        }
    }
}
