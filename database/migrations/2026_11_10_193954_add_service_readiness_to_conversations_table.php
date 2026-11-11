<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - `category_id`: the request category (optional classification).
     * - `readiness`: whether the request is complete (`ready`) or still
     *   needs information (`needs_info`), with who changed it and when.
     *   Every existing row stays `ready`: turning the confirmation rule on
     *   later must never block conversations retroactively.
     * - `queued_at`: when the conversation entered the queue it is waiting
     *   in, the clock of the minimum wait and of the chronological order.
     *   Only the conversations waiting today (pending) are backfilled, with
     *   one bounded statement over the existing status index.
     * - `open_response_cycle_id`: the open response cycle of the
     *   conversation (`response_cycles`), null when the customer is not
     *   waiting. A plain pointer kept by ResponseClock inside the
     *   transaction that locks the conversation row, without foreign key or
     *   index: it only exists so the open cycle can be resolved with a
     *   current read of this row (see ResponseClock::openCycleId()).
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->constrained('conversation_categories')->nullOnDelete();
            $table->string('readiness', 20)->default('ready');
            $table->timestamp('readiness_changed_at')->nullable();
            $table->foreignId('readiness_changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at')->nullable();
            $table->unsignedBigInteger('open_response_cycle_id')->nullable();

            $table->index(['department_id', 'status', 'queued_at'], 'conversations_queue_order_index');
        });

        DB::table('conversations')
            ->where('status', 'pending')
            ->update(['queued_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_queue_order_index');
            $table->dropConstrainedForeignId('readiness_changed_by_user_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['readiness', 'readiness_changed_at', 'queued_at', 'open_response_cycle_id']);
        });
    }
};
