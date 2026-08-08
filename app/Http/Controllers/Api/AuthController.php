<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\StudentProfile;
use App\Models\StudentSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['Your account is not active.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'language_pref' => $user->language_pref,
            'status' => $user->status,
            'created_at' => $user->created_at,
        ];

        // Load student profile if user is a student
        if ($user->role === 'student') {
            $studentProfile = $user->studentProfile()
                ->with(['classLevel', 'board'])
                ->first();

            if ($studentProfile) {
                $userData['student_profile'] = [
                    'id' => $studentProfile->id,
                    'class' => [
                        'id' => $studentProfile->classLevel->id,
                        'name' => $studentProfile->classLevel->name,
                    ],
                    'board' => [
                        'id' => $studentProfile->board->id,
                        'name' => $studentProfile->board->name,
                    ],
                    'school_name' => $studentProfile->school_name,
                    'plan' => $studentProfile->plan,
                    'current_streak' => $studentProfile->current_streak,
                    'longest_streak' => $studentProfile->longest_streak,
                    'last_active_date' => $studentProfile->last_active_date,
                ];
            }

            // Load active subscription
            $activeSubscription = $user->subscriptions()
                ->where('status', 'active')
                ->with('plan')
                ->latest()
                ->first();

            if ($activeSubscription) {
                $userData['subscription'] = [
                    'id' => $activeSubscription->id,
                    'plan' => [
                        'id' => $activeSubscription->plan->id,
                        'name' => $activeSubscription->plan->name,
                        'price_inr' => $activeSubscription->plan->price_inr,
                        'duration_days' => $activeSubscription->plan->duration_days,
                        'features' => $activeSubscription->plan->features,
                    ],
                    'start_date' => $activeSubscription->start_date,
                    'end_date' => $activeSubscription->end_date,
                    'status' => $activeSubscription->status,
                    'payment_ref' => $activeSubscription->payment_ref,
                ];
            }
        }

        return response()->json([
            'message' => 'Login successful',
            'user' => $userData,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function signup(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'language_pref' => ['nullable', 'string', 'max:10'],
            'class_id' => ['required', 'exists:class_levels,id'],
            'board_id' => ['required', 'exists:boards,id'],
            'school_name' => ['nullable', 'string', 'max:160'],
            'plan_id' => ['nullable', 'exists:plans,id'],
        ]);

        DB::beginTransaction();

        try {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => $validated['password'],
                'role' => 'student', // 1 = super admin, 2 = admin, 3 = student
                'language_pref' => $validated['language_pref'] ?? 'en',
                'status' => 'active',
            ]);

            // Create student profile
            $studentProfile = StudentProfile::create([
                'user_id' => $user->id,
                'class_id' => $validated['class_id'],
                'board_id' => $validated['board_id'],
                'school_name' => $validated['school_name'] ?? null,
                'plan' => 'free', // Default to free plan
                'current_streak' => 0,
                'longest_streak' => 0,
                'last_active_date' => null,
            ]);

            // Create student subscription if plan_id is provided
            if (isset($validated['plan_id'])) {
                $plan = Plan::findOrFail($validated['plan_id']);
                $startDate = now();
                $endDate = $plan->duration_days > 0
                    ? $startDate->copy()->addDays($plan->duration_days)
                    : null;

                StudentSubscription::create([
                    'student_id' => $user->id,
                    'plan_id' => $validated['plan_id'],
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'active',
                    'payment_ref' => null,
                ]);
            }

            DB::commit();

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'User registered successfully',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'language_pref' => $user->language_pref,
                    'status' => $user->status,
                ],
                'student_profile' => [
                    'id' => $studentProfile->id,
                    'class_id' => $studentProfile->class_id,
                    'board_id' => $studentProfile->board_id,
                    'school_name' => $studentProfile->school_name,
                    'plan' => $studentProfile->plan,
                    'current_streak' => $studentProfile->current_streak,
                    'longest_streak' => $studentProfile->longest_streak,
                ],
                'access_token' => $token,
                'token_type' => 'Bearer',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Registration failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getUser(Request $request)
    {
        $user = $request->user();

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'language_pref' => $user->language_pref,
            'status' => $user->status,
            'created_at' => $user->created_at,
        ];

        // Load student profile if user is a student
        if ($user->role === 'student') {
            $studentProfile = $user->studentProfile()
                ->with(['classLevel', 'board'])
                ->first();

            if ($studentProfile) {
                $userData['student_profile'] = [
                    'id' => $studentProfile->id,
                    'class' => [
                        'id' => $studentProfile->classLevel->id,
                        'name' => $studentProfile->classLevel->name,
                    ],
                    'board' => [
                        'id' => $studentProfile->board->id,
                        'name' => $studentProfile->board->name,
                    ],
                    'school_name' => $studentProfile->school_name,
                    'plan' => $studentProfile->plan,
                    'current_streak' => $studentProfile->current_streak,
                    'longest_streak' => $studentProfile->longest_streak,
                    'last_active_date' => $studentProfile->last_active_date,
                ];
            }

            // Load active subscription
            $activeSubscription = $user->subscriptions()
                ->where('status', 'active')
                ->with('plan')
                ->latest()
                ->first();

            if ($activeSubscription) {
                $userData['subscription'] = [
                    'id' => $activeSubscription->id,
                    'plan' => [
                        'id' => $activeSubscription->plan->id,
                        'name' => $activeSubscription->plan->name,
                        'price_inr' => $activeSubscription->plan->price_inr,
                        'duration_days' => $activeSubscription->plan->duration_days,
                        'features' => $activeSubscription->plan->features,
                    ],
                    'start_date' => $activeSubscription->start_date,
                    'end_date' => $activeSubscription->end_date,
                    'status' => $activeSubscription->status,
                    'payment_ref' => $activeSubscription->payment_ref,
                ];
            }
        }

        return response()->json([
            'user' => $userData,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
