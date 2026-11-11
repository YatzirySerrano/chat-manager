<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per "the customer wrote → the team must answer" cycle. It is a
     * fact table: the objective that applied when the cycle started is
     * frozen in the row (`target_seconds`, `business_hours_only`, `warn_at`,
     * `due_at`), so changing the rules later never rewrites history. Rows
     * are never deleted.
     *
     * `active` is 1 while the cycle is open and NULL once it ended; with the
     * unique (conversation_id, active) the database guarantees a single open
     * cycle per conversation (NULLs never collide).
     *
     * The dimension columns are copies without foreign keys on purpose, like
     * conversation_metrics: they only filter aggregates and must never lock
     * the operational tables. The row follows its conversation (per
     * conversation fact, as conversation_events); conversations are never
     * physically deleted, so the cascade removes no history.
     */
    public function up(): void
    {
        Schema::create('response_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->timestamp('started_at');
            $table->unsignedInteger('target_seconds')->nullable();
            $table->boolean('business_hours_only')->default(true);
            $table->timestamp('warn_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('warned_at')->nullable();
            $table->timestamp('breached_at')->nullable();
            $table->unsignedBigInteger('breach_user_id')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('responder_user_id')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->unsignedInteger('response_seconds')->nullable();
            $table->unsignedInteger('business_response_seconds')->nullable();
            $table->unsignedInteger('exceeded_seconds')->nullable();
            $table->unsignedTinyInteger('reminders_count')->default(0);
            $table->timestamp('last_alert_at')->nullable();
            $table->unsignedTinyInteger('active')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'active']);
            // The evaluator's pending stages: equality on the stamp that is
            // still null, then the range on the date. Cycles already
            // processed and still open are not read again every minute.
            $table->index(['active', 'breached_at', 'due_at']);
            $table->index(['active', 'warned_at', 'warn_at']);
            $table->index('started_at');
            $table->index(['department_id', 'started_at']);
            $table->index(['responder_user_id', 'started_at']);
            $table->index('breached_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('response_cycles');
    }
};
