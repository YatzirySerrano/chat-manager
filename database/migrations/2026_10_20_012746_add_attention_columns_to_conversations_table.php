<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Columns of the attention lifecycle. `on_hold_*` describe the current
     * pause only (the full history lives in `conversation_events`);
     * `last_outbound_at` is informative and never extends the customer
     * service window, which only depends on `last_inbound_at`.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('on_hold_at')->nullable()->after('assigned_at');
            $table->string('on_hold_reason', 255)->nullable()->after('on_hold_at');
            $table->timestamp('last_outbound_at')->nullable()->after('last_inbound_at');
            $table->timestamp('reopened_at')->nullable()->after('closed_by_user_id');
            $table->unsignedSmallInteger('reopen_count')->default(0)->after('reopened_at');

            $table->index(['connection_id', 'contact_id', 'closed_at'], 'conversations_reopen_lookup_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_reopen_lookup_index');
            $table->dropColumn(['on_hold_at', 'on_hold_reason', 'last_outbound_at', 'reopened_at', 'reopen_count']);
        });
    }
};
