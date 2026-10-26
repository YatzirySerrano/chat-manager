<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where a conversation comes from (Fase 5.2, Whaticket history importer):
     * - origin: "live" for every conversation the platform attended (the
     *   default, so every existing row stays live) or "whaticket" for one
     *   imported from a Whaticket export;
     * - history_only_at: set when an imported conversation was archived as
     *   read-only history (hidden from the inbox, search, exports and the
     *   API; only the archived-history screen shows it);
     * - imported_by_transfer_id: the import batch (data_transfers) that
     *   created it. Batches are history and are never deleted. Deliberately
     *   WITHOUT a foreign key: adding a FOREIGN KEY to an existing InnoDB
     *   table with foreign_key_checks on uses ALGORITHM=COPY, which rebuilds
     *   `conversations` and blocks inbound messages and replies while it runs.
     *   Only the importer writes the column (validated in code); a plain
     *   index serves the lookups by batch.
     *
     * The columns are defaulted / nullable (instant ADD COLUMN on MariaDB)
     * and the indexes are built online. The composite index serves the
     * archived-history list (origin = 'whaticket', archived or not, by
     * latest activity); live lookups keep their existing indexes.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('origin', 20)->default('live')->after('version');
            $table->timestamp('history_only_at')->nullable()->after('origin');
            $table->unsignedBigInteger('imported_by_transfer_id')->nullable()->after('history_only_at');
            $table->index(['origin', 'history_only_at', 'last_message_at'], 'conversations_origin_history_index');
            $table->index('imported_by_transfer_id', 'conversations_imported_by_transfer_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_origin_history_index');
            $table->dropIndex('conversations_imported_by_transfer_index');
            $table->dropColumn(['origin', 'history_only_at', 'imported_by_transfer_id']);
        });
    }
};
