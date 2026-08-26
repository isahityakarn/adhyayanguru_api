<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailOtp;
use App\Models\Plan;
use App\Models\StudentProfile;
use App\Models\StudentSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $email = strtolower(trim($request->email));
        $studentName = trim($request->name ?? '') ?: 'Student';

        if (User::where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'An account with this email already exists. Please login.',
                'errors' => ['email' => ['An account with this email already exists. Please login.']]
            ], 422);
        }

        $otp = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(10);

        EmailOtp::updateOrCreate(
            ['email' => $email],
            ['otp' => $otp, 'expires_at' => $expiresAt]
        );

        try {
            Mail::send([], [], function ($message) use ($email, $otp, $studentName) {
                $message->to($email)
                    ->subject("Your Registration OTP - AdhyayanGuru")
                    ->html("
                        <div style='font-family: Arial, sans-serif; max-width: 550px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background-color: #ffffff; color: #1e293b;'>
                            <h2 style='color: #0f172a; margin-top: 0; font-size: 22px;'>AdhyayanGuru</h2>
                            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 16px 0;'>
                            
                            <p style='font-size: 15px; margin-bottom: 16px;'>Dear {$studentName},</p>
                            <p style='font-size: 15px; line-height: 1.5; margin-bottom: 12px;'>Thank you for signing up with us.</p>
                            <p style='font-size: 15px; line-height: 1.5; margin-bottom: 20px;'>To complete your student registration, please use the One-Time Password (OTP) below:</p>
                            
                            <div style='text-align: center; margin: 24px 0;'>
                                <div style='display: inline-block; background-color: #f1f5f9; color: #0f172a; font-size: 30px; font-weight: bold; letter-spacing: 6px; padding: 12px 28px; border-radius: 8px; border: 2px dashed #0284c7;'>
                                    {$otp}
                                </div>
                            </div>
                            
                            <p style='font-size: 14px; line-height: 1.5; color: #334155; margin-bottom: 16px;'>This OTP is valid for <strong>10 minutes</strong>. Please do not share this OTP with anyone.</p>
                            <p style='font-size: 14px; line-height: 1.5; color: #64748b; margin-bottom: 24px;'>If you did not request this OTP, you can safely ignore this email.</p>
                            
                            <hr style='border: none; border-top: 1px solid #e2e8f0; margin: 20px 0;'>
                            <p style='font-size: 14px; margin: 0; color: #334155;'>Regards,</p>
                            <p style='font-size: 14px; font-weight: bold; margin: 4px 0 0 0; color: #0f172a;'>Student Support Team</p>
                            <p style='font-size: 14px; font-weight: bold; margin: 2px 0 0 0; color: #0284c7;'>AdhyayanGuru</p>
                        </div>
                    ");
            });

            return response()->json([
                'success' => true,
                'message' => 'Verification code sent to ' . $email,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send OTP email: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'otp' => ['required', 'string', 'min:6', 'max:6'],
        ]);

        $email = strtolower(trim($request->email));
        $otp = trim($request->otp);

        $record = EmailOtp::where('email', $email)->where('otp', $otp)->first();

        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid verification code. Please check and try again.',
                'errors' => ['otp' => ['Invalid verification code.']]
            ], 422);
        }

        if (now()->greaterThan($record->expires_at)) {
            return response()->json([
                'success' => false,
                'message' => 'Verification code has expired. Please request a new code.',
                'errors' => ['otp' => ['Verification code has expired.']]
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email verified successfully.',
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
            'otp' => ['required', 'string', 'min:6', 'max:6'],
        ]);

        $email = strtolower(trim($validated['email']));
        $otpRecord = EmailOtp::where('email', $email)->where('otp', trim($validated['otp']))->first();
        if (! $otpRecord || now()->greaterThan($otpRecord->expires_at)) {
            throw ValidationException::withMessages([
                'otp' => ['Invalid or expired verification code. Please request a new code.'],
            ]);
        }
        $otpRecord->delete();

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

            // Automatically assign Free plan subscription if plan_id is not specified
            $planId = $validated['plan_id'] ?? null;
            if (! $planId) {
                $freePlan = Plan::where('price_inr', 0)->first() ?: Plan::find(1);
                $planId = $freePlan ? $freePlan->id : 1;
            }

            if ($planId) {
                $plan = Plan::find($planId);
                if ($plan) {
                    $startDate = now();
                    $endDate = $plan->duration_days > 0
                        ? $startDate->copy()->addDays($plan->duration_days)
                        : null;

                    StudentSubscription::create([
                        'student_id' => $user->id,
                        'plan_id' => $plan->id,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'status' => 'active',
                        'payment_ref' => 'FREE_WELCOME_PLAN',
                    ]);
                }
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
