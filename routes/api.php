<?php

use App\Http\Controllers\Api\AiTutorController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BoardController;
use App\Http\Controllers\Api\ChapterController;
use App\Http\Controllers\Api\ClassLevelController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\SubjectController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::post('/signup', [AuthController::class, 'signup']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/boards', [BoardController::class, 'index']);
Route::get('/class-levels', [ClassLevelController::class, 'index']);
Route::get('/subjects', [SubjectController::class, 'index']);
Route::get('/chapters', [ChapterController::class, 'index']);
Route::get('/plans', [PlanController::class, 'index']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'getUser']);
    Route::get('/chapters/{id}', [ChapterController::class, 'show']);

    // AI Tutor routes
    Route::prefix('ai-tutor')->group(function () {
        Route::post('/chat', [AiTutorController::class, 'chat']);
        Route::post('/explain', [AiTutorController::class, 'explainTopic']);
        Route::post('/questions', [AiTutorController::class, 'generateQuestions']);
    });
});
