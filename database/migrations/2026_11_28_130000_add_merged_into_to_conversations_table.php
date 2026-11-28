<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Corregir tamaño de la solicitud» (AdjustRequestBoundary): when every
 * message of a request is moved into the request next to it, the emptied
 * conversation is absorbed by that one instead of being deleted. The row
 * stays (closed, with its events) and these columns say where it went:
 *
 * - merged_at: absorbed; it is hidden from every list, count, history and
 *   report (ConversationAccess::restrictToVisible(), the metrics
 *   calculator);
 * - merged_into_conversation_id: the conversation that holds its messages
 *   now, so an old link opens that one. Nulled if that conversation is ever
 *   erased for good; merged_at alone keeps it hidden.
 *
 * Additive only: existing rows keep both columns null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('merged_into_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->timestamp('merged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('merged_into_conversation_id');
            $table->dropColumn('merged_at');
        });
    }
};
