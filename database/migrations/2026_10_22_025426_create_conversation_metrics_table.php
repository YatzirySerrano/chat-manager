<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per conversation with the figures the reports aggregate, so
     * opening a report never walks the messages or the event log. Rows are
     * derived data: they are always recomputed from the conversation, its
     * events and its messages (App\Services\Analytics\ConversationMetricsCalculator)
     * and can be rebuilt at any time with `php artisan analytics:rebuild`.
     *
     * The dimension columns (connection, department, agent, status) are
     * denormalized copies without foreign keys on purpose: they only filter
     * aggregates and must never lock or block the operational tables. The
     * row disappears with its conversation (derived data, not a business
     * record), although conversations are never physically deleted.
     */
    public function up(): void
    {
        Schema::create('conversation_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('status', 20);
            $table->timestamp('received_at');
            $table->timestamp('first_assigned_at')->nullable();
            $table->timestamp('first_human_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('last_closed_at')->nullable();
            $table->unsignedInteger('seconds_to_first_attention')->nullable();
            $table->unsignedInteger('seconds_to_first_human_response')->nullable();
            $table->unsignedInteger('seconds_to_resolution')->nullable();
            $table->unsignedSmallInteger('closed_count')->default(0);
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->unsignedSmallInteger('transfers_count')->default(0);
            $table->unsignedInteger('messages_in')->default(0);
            $table->unsignedInteger('messages_out')->default(0);
            $table->unsignedInteger('failed_out')->default(0);
            $table->boolean('out_of_hours')->nullable();
            $table->timestamp('computed_at');

            $table->index('received_at');
            $table->index('resolved_at');
            $table->index(['department_id', 'received_at']);
            $table->index(['agent_id', 'received_at']);
            $table->index(['connection_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_metrics');
    }
};
