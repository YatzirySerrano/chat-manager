<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the sender did to a message after sending it, and where the
     * message comes from:
     *
     * - edited_at: the sender edited it (the body holds the latest text;
     *   earlier ones are kept in metadata.edits);
     * - revoked_at: the sender deleted it for everyone. The row and its
     *   content are kept: the conversation is a record of what was said;
     * - is_historical: it was imported from the history WhatsApp hands to a
     *   newly linked device (WhatsApp Web QR), never received live.
     *
     * Only columns are added; existing rows keep null / false.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('failed_at');
            $table->timestamp('revoked_at')->nullable()->after('edited_at');
            $table->boolean('is_historical')->default(false)->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['edited_at', 'revoked_at', 'is_historical']);
        });
    }
};
