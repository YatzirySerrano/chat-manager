<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The nightly metrics reconciliation (`analytics:rebuild --days=N`) also
     * picks the conversations whose messages changed recently (e.g. a late
     * delivery status), which needs a range scan on messages.updated_at.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['updated_at', 'conversation_id'], 'messages_updated_conversation_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_updated_conversation_index');
        });
    }
};
