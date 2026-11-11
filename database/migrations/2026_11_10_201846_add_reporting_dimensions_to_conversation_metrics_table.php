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
     * - `category_id`: copy of the request category, for the breakdown and
     *   the filter by category (no foreign key, like the other dimensions).
     * - `left_backlog_at`: when the conversation stopped being "unattended"
     *   (its first human attention or its first closing, whichever came
     *   first; null while it is still unattended). The accumulated backlog
     *   and its trend are computed from this single indexed column instead
     *   of scanning the whole history per request.
     * - indexes for the new range queries: by category and by status within
     *   the reception date, and by the backlog exit date.
     *
     * Both columns are derived data kept by ConversationMetricsCalculator.
     *
     * `left_backlog_at` is backfilled here for the existing rows, with one
     * statement over columns the table already has, so the backlog is right
     * from the moment the migration ends and never shows the whole history
     * as unattended. It is exactly the calculator's definition: the earliest
     * non-null of `first_assigned_at` (which already stores the first human
     * attention: accept, manual assignment, transfer to a person or human
     * reply) and `resolved_at` (the first closing). `received_at` and
     * `computed_at` are assigned to themselves so no automatic
     * ON UPDATE CURRENT_TIMESTAMP of a server configured that way can touch
     * them.
     *
     * `category_id` (categories did not exist before) and the per-person
     * rows of conversation_agent_metrics cannot be derived in SQL: they are
     * filled by a full `php artisan analytics:rebuild`, a mandatory step
     * after migrating (docs/infraestructura-y-despliegue.md).
     */
    public function up(): void
    {
        Schema::table('conversation_metrics', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('agent_id');
            $table->timestamp('left_backlog_at')->nullable()->after('last_closed_at');

            $table->index(['category_id', 'received_at']);
            $table->index(['status', 'received_at']);
            $table->index('left_backlog_at');
        });

        DB::table('conversation_metrics')
            ->where(fn ($query) => $query->whereNotNull('first_assigned_at')->orWhereNotNull('resolved_at'))
            ->update([
                'left_backlog_at' => DB::raw('CASE WHEN first_assigned_at IS NULL THEN resolved_at WHEN resolved_at IS NULL THEN first_assigned_at ELSE LEAST(first_assigned_at, resolved_at) END'),
                'received_at' => DB::raw('received_at'),
                'computed_at' => DB::raw('computed_at'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_metrics', function (Blueprint $table) {
            $table->dropIndex(['category_id', 'received_at']);
            $table->dropIndex(['status', 'received_at']);
            $table->dropIndex(['left_backlog_at']);
            $table->dropColumn(['category_id', 'left_backlog_at']);
        });
    }
};
