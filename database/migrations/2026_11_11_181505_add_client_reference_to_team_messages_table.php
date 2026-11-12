<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `client_reference` is the idempotency key the sender's browser
     * generates for each message it sends (a UUID, kept while it retries the
     * same message). It is unique per author: a retry after a lost response
     * answers the stored message instead of creating it (and notifying)
     * twice. Nullable, so existing rows and system lines need no value
     * (MariaDB unique indexes allow repeated NULLs).
     */
    public function up(): void
    {
        Schema::table('team_messages', function (Blueprint $table) {
            $table->uuid('client_reference')->nullable()->after('reply_to_message_id');

            $table->unique(['user_id', 'client_reference'], 'team_messages_user_client_reference_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * InnoDB dropped the implicit index of the user_id foreign key once the
     * unique index (which starts with user_id) could serve it, so a plain
     * user_id index comes back before the unique one is dropped.
     */
    public function down(): void
    {
        Schema::table('team_messages', function (Blueprint $table) {
            $table->index('user_id', 'team_messages_user_id_foreign');
            $table->dropUnique('team_messages_user_client_reference_unique');
            $table->dropColumn('client_reference');
        });
    }
};
