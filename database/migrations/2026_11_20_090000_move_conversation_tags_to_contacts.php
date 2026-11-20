<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tags are unified on the contact: every tag a conversation carried is
     * copied to its contact (without duplicates), since conversations now
     * show and edit their contact's tags. conversation_tag stays as a
     * read-only record of the old assignments.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT IGNORE INTO contact_tag (contact_id, tag_id, tagged_by_user_id, created_at)
            SELECT c.contact_id, ct.tag_id, MIN(ct.tagged_by_user_id), MIN(ct.created_at)
            FROM conversation_tag ct
            JOIN conversations c ON c.id = ct.conversation_id
            GROUP BY c.contact_id, ct.tag_id
        SQL);
    }

    public function down(): void
    {
        // The copied tags stay on the contacts.
    }
};
