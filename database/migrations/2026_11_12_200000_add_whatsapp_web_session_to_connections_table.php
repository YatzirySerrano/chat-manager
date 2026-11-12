<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * State of the WhatsApp Web (QR) session of a connection whose provider
     * is "whatsapp_web" (docs/whatsapp-web-qr-contrato.md §3). Additive: the
     * columns stay null for every other connection, whose behavior does not
     * change. The session credentials are never stored here: they live,
     * encrypted, in the Node service's own data directory.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('web_session_state', 24)->nullable()->after('provider_signals');
            $table->string('web_session_phone', 32)->nullable()->after('web_session_state');
            $table->string('web_session_push_name', 120)->nullable()->after('web_session_phone');
            $table->string('web_session_reason', 40)->nullable()->after('web_session_push_name');
            $table->string('web_session_error_code', 60)->nullable()->after('web_session_reason');
            $table->string('web_session_error', 255)->nullable()->after('web_session_error_code');
            $table->unsignedInteger('web_session_reconnects')->default(0)->after('web_session_error');
            $table->timestamp('web_session_linked_at')->nullable()->after('web_session_reconnects');
            $table->timestamp('web_session_connected_at')->nullable()->after('web_session_linked_at');
            $table->timestamp('web_session_event_at')->nullable()->after('web_session_connected_at');
            // Who asked for the pending link: the connection is activated in
            // their name once the phone finishes linking.
            $table->unsignedBigInteger('web_session_link_user_id')->nullable()->after('web_session_event_at');
        });

        // What the Node service needs to download and decrypt an inbound
        // WhatsApp Web file, stored encrypted and never sent to the browser.
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->text('provider_reference')->nullable()->after('whatsapp_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn('provider_reference');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn([
                'web_session_state', 'web_session_phone', 'web_session_push_name', 'web_session_reason', 'web_session_error_code',
                'web_session_error', 'web_session_reconnects', 'web_session_linked_at', 'web_session_connected_at',
                'web_session_event_at', 'web_session_link_user_id',
            ]);
        });
    }
};
