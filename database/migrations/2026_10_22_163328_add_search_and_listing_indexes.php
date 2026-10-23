<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes behind the searches and the long listings (additive only):
 *
 * - contacts: FULLTEXT over name and WhatsApp profile name (word prefix
 *   search, ContactSearch) and (last_interaction_at, id) for the "recent"
 *   order of the contacts list;
 * - conversations: (last_message_at, id) for the "recent" and "oldest"
 *   orders of the whole history, where no status or owner narrows the list;
 * - messages: FULLTEXT over the body, for the search in the content of the
 *   WhatsApp messages. InnoDB keeps it up to date on every insert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->fullText(['name', 'profile_name'], 'contacts_name_fulltext');
            $table->index(['last_interaction_at', 'id'], 'contacts_last_interaction_id_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['last_message_at', 'id'], 'conversations_last_message_id_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->fullText('body', 'messages_body_fulltext');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropFullText('messages_body_fulltext');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_last_message_id_index');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_last_interaction_id_index');
            $table->dropFullText('contacts_name_fulltext');
        });
    }
};
