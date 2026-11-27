<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive and nullable only: safe on a live table, no backfill.
     *
     * - `not_request_at`: closed as «No es solicitud» (a «gracias», an emoji,
     *   an «ok» after a finished request). Set, the conversation is outside
     *   every metric and report, and the customer's next message starts a
     *   new conversation instead of reopening it.
     * - `not_request_by_user_id`: who marked it.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('not_request_at')->nullable();
            $table->foreignId('not_request_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('not_request_by_user_id');
            $table->dropColumn('not_request_at');
        });
    }
};
