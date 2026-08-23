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
        Schema::table('progress', function (Blueprint $table) {
            if (!Schema::hasColumn('progress', 'time_spent_seconds')) {
                $table->unsignedInteger('time_spent_seconds')->default(0)->after('percent_complete');
            }
            if (!Schema::hasColumn('progress', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('time_spent_seconds');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('progress', function (Blueprint $table) {
            $table->dropColumn(['time_spent_seconds', 'completed_at']);
        });
    }
};
