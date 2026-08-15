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
        Schema::table('chapters', function (Blueprint $table) {
            $table->longText('extracted_text')->nullable()->after('source_file_url');
            $table->longText('questions')->nullable()->after('extracted_text');
            $table->timestamp('processed_at')->nullable()->after('questions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn(['extracted_text', 'questions', 'processed_at']);
        });
    }
};
