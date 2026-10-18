<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * - `conversations.open_lock` becomes a stored generated column derived from
     *   `status`, so MariaDB itself guarantees a single unclosed conversation per
     *   contact and WhatsApp number, regardless of what the application writes.
     * - Messages, attachments and conversation events can no longer be removed in
     *   cascade: deleting their parent is rejected to preserve traceability.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index('whatsapp_account_id', 'conversations_whatsapp_account_id_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique('conversations_single_open_unique');
            $table->dropColumn('open_lock');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('open_lock')->nullable()->storedAs("IF(`status` = 'closed', NULL, 1)")->after('status');
            $table->unique(['whatsapp_account_id', 'contact_id', 'open_lock'], 'conversations_single_open_unique');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_whatsapp_account_id_index');
        });

        $this->replaceForeignKey('messages', 'conversation_id', 'conversations', cascade: false);
        $this->replaceForeignKey('message_attachments', 'message_id', 'messages', cascade: false);
        $this->replaceForeignKey('conversation_events', 'conversation_id', 'conversations', cascade: false);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->replaceForeignKey('conversation_events', 'conversation_id', 'conversations', cascade: true);
        $this->replaceForeignKey('message_attachments', 'message_id', 'messages', cascade: true);
        $this->replaceForeignKey('messages', 'conversation_id', 'conversations', cascade: true);

        Schema::table('conversations', function (Blueprint $table) {
            $table->index('whatsapp_account_id', 'conversations_whatsapp_account_id_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique('conversations_single_open_unique');
            $table->dropColumn('open_lock');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('open_lock')->nullable()->default(true)->after('status');
            $table->unique(['whatsapp_account_id', 'contact_id', 'open_lock'], 'conversations_single_open_unique');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_whatsapp_account_id_index');
        });
    }

    private function replaceForeignKey(string $table, string $column, string $references, bool $cascade): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $cascade) {
            $blueprint->dropForeign([$column]);

            $foreign = $blueprint->foreign($column)->references('id')->on($references);

            $cascade ? $foreign->cascadeOnDelete() : $foreign->restrictOnDelete();
        });
    }
};
