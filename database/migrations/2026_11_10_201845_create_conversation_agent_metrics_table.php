<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per (conversation, person) who was responsible for the
     * conversation or wrote a human reply in it: what the reports need to
     * attribute work to each collaborator instead of crediting everything
     * to the last responsible agent.
     *
     * Like conversation_metrics, rows are derived data: they are rebuilt as
     * a whole from the append-only conversation_events and the messages by
     * App\Services\Analytics\AgentAttributionCalculator (same job and
     * `php artisan analytics:rebuild` as conversation_metrics) and are never
     * written by hand. The dimension columns are copies without foreign
     * keys on purpose: they only filter aggregates and must never lock the
     * operational tables.
     *
     * `handling_seconds` only sums finished intervals of responsibility; an
     * interval still running is kept as `open_since` and measured against
     * "now" by the live figures, so no moving value is frozen into history.
     */
    public function up(): void
    {
        Schema::create('conversation_agent_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('status', 20);
            $table->timestamp('received_at');
            $table->boolean('was_responsible')->default(false);
            $table->boolean('responsible_now')->default(false);
            $table->timestamp('first_responsible_at')->nullable();
            $table->timestamp('attended_at')->nullable();
            $table->unsignedInteger('handling_seconds')->default(0);
            $table->unsignedSmallInteger('intervals_count')->default(0);
            $table->timestamp('open_since')->nullable();
            $table->unsignedInteger('replies_count')->default(0);
            $table->timestamp('first_reply_at')->nullable();
            $table->boolean('authored_first_response')->default(false);
            $table->unsignedInteger('first_response_seconds')->nullable();
            $table->unsignedSmallInteger('closes_count')->default(0);
            $table->timestamp('last_closed_at')->nullable();
            $table->unsignedSmallInteger('transfers_in')->default(0);
            $table->unsignedSmallInteger('transfers_out')->default(0);
            $table->timestamp('computed_at');

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'received_at']);
            $table->index('received_at');
            $table->index(['department_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_agent_metrics');
    }
};
