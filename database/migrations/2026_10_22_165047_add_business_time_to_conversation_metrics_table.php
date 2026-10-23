<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Business time (seconds inside the attention hours of the department
     * that received the conversation) next to the calendar durations. Both
     * columns are derived data: `php artisan analytics:rebuild` fills them
     * for the existing rows once; afterwards each value is frozen when it
     * is first computed (see ConversationMetricsCalculator).
     */
    public function up(): void
    {
        Schema::table('conversation_metrics', function (Blueprint $table) {
            $table->unsignedInteger('business_seconds_to_first_human_response')->nullable()->after('seconds_to_resolution');
            $table->unsignedInteger('business_seconds_to_resolution')->nullable()->after('business_seconds_to_first_human_response');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_metrics', function (Blueprint $table) {
            $table->dropColumn(['business_seconds_to_first_human_response', 'business_seconds_to_resolution']);
        });
    }
};
