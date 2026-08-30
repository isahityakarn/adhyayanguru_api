<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop existing simple tables if needed to build complete production schema
        Schema::dropIfExists('quiz_answers');
        Schema::dropIfExists('quiz_attempts');

        // 1. Quizzes Table
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->integer('total_mcq')->default(50);
            $table->integer('total_written')->default(20);
            $table->integer('time_limit_minutes')->default(45);
            $table->float('passing_percentage')->default(60.0);
            $table->integer('marks_per_mcq')->default(1);
            $table->integer('marks_per_written')->default(10);
            $table->boolean('randomize_questions')->default(false);
            $table->boolean('randomize_options')->default(false);
            $table->integer('max_attempts')->default(5);
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index('chapter_id');
        });

        // 2. Quiz MCQ Questions Table
        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->text('question_text');
            $table->json('options'); // [{"letter":"A","text":"Option text"},...]
            $table->string('correct_answer', 10); // "A", "B", "C", "D"
            $table->text('explanation')->nullable();
            $table->string('image_url')->nullable();
            $table->enum('difficulty', ['easy', 'medium', 'hard'])->default('medium');
            $table->integer('order_num')->default(0);
            $table->timestamps();

            $table->index('quiz_id');
        });

        // 3. Quiz Written Questions Table
        Schema::create('quiz_written_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->text('question_text');
            $table->text('expected_answer');
            $table->json('key_concepts')->nullable();
            $table->text('marking_criteria')->nullable();
            $table->integer('min_words')->default(20);
            $table->integer('max_words')->default(300);
            $table->integer('marks')->default(10);
            $table->integer('order_num')->default(0);
            $table->timestamps();

            $table->index('quiz_id');
        });

        // 4. Quiz Attempts Table
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained('chapters')->cascadeOnDelete();
            $table->integer('attempt_number')->default(1);
            $table->enum('status', ['in_progress', 'submitted', 'evaluating', 'completed'])->default('in_progress');
            $table->float('mcq_score')->default(0);
            $table->float('written_score')->default(0);
            $table->float('total_score')->default(0);
            $table->float('max_score')->default(250);
            $table->float('percentage')->default(0);
            $table->boolean('is_passed')->default(false);
            $table->integer('time_spent_seconds')->default(0);
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'chapter_id']);
        });

        // 5. MCQ Answers Table
        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('quiz_questions')->cascadeOnDelete();
            $table->string('selected_option', 10)->nullable();
            $table->boolean('is_correct')->nullable();
            $table->boolean('is_marked_for_review')->default(false);
            $table->timestamps();

            $table->unique(['attempt_id', 'question_id']);
        });

        // 6. Written Answers Table
        Schema::create('quiz_written_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('written_question_id')->constrained('quiz_written_questions')->cascadeOnDelete();
            $table->text('answer_text')->nullable();
            $table->integer('word_count')->default(0);
            $table->float('score')->nullable();
            $table->float('max_score')->default(10);
            $table->boolean('is_correct')->nullable();
            $table->text('feedback')->nullable();
            $table->json('strengths')->nullable();
            $table->json('improvements')->nullable();
            $table->timestamp('ai_evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['attempt_id', 'written_question_id']);
        });

        // 7. AI Evaluations Log Table
        Schema::create('quiz_ai_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->foreignId('written_question_id')->constrained('quiz_written_questions')->cascadeOnDelete();
            $table->float('score')->default(0);
            $table->float('max_score')->default(10);
            $table->float('percentage')->default(0);
            $table->boolean('is_correct')->default(false);
            $table->text('feedback')->nullable();
            $table->json('strengths')->nullable();
            $table->json('improvements')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['attempt_id', 'written_question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quiz_ai_evaluations');
        Schema::dropIfExists('quiz_written_answers');
        Schema::dropIfExists('quiz_answers');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quiz_written_questions');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('quizzes');
    }
};
