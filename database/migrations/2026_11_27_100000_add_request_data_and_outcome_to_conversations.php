<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive and nullable only: safe on a live table, no backfill.
     *
     * - `request_fields`: the request data the team picked out of the
     *   messages («Cliente», «Producto», «Ciclo», «Monto solicitado»...), as
     *   a JSON list of {key, label, value, message_id, captured_at}. Optional:
     *   nothing requires it. Quick replies fill their «Etiqueta:» lines from it.
     * - `outcome`: the result of the credit request when it is completed:
     *   `approved` (the only one that commissions) or `rejected`; null when
     *   nobody recorded one. Independent from `completed_at` (attended).
     * - `outcome_amount`: the authorized amount of an approved request.
     * - `awaiting_info_at`: since when a request returned for missing
     *   information waits for the customer. While set, the request is outside
     *   the «Pendientes» queue; the customer's next message clears it and the
     *   request re-enters the queue with a new clock (queued_at = now).
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->json('request_fields')->nullable();
            $table->string('outcome', 20)->nullable();
            $table->decimal('outcome_amount', 12, 2)->nullable();
            $table->timestamp('outcome_at')->nullable();
            $table->foreignId('outcome_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('awaiting_info_at')->nullable();

            $table->index(['outcome', 'outcome_at'], 'conversations_outcome_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_outcome_index');
            $table->dropConstrainedForeignId('outcome_by_user_id');
            $table->dropColumn(['request_fields', 'outcome', 'outcome_amount', 'outcome_at', 'awaiting_info_at']);
        });
    }
};
