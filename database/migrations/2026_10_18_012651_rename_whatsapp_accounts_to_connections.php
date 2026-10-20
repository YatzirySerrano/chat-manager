<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A WhatsApp number becomes a "connection": a channel the company is
     * reachable through. The table and the conversation column are renamed in
     * place, so every row, the foreign key and the unique index that guarantees
     * a single unclosed conversation per contact and connection are preserved.
     * Audit entries that pointed to the old morph alias follow the new one.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['whatsapp_account_id']);
        });

        Schema::rename('whatsapp_accounts', 'connections');

        Schema::table('connections', function (Blueprint $table) {
            $table->renameIndex('whatsapp_accounts_phone_number_id_unique', 'connections_phone_number_id_unique');
            $table->renameIndex('whatsapp_accounts_business_account_id_index', 'connections_business_account_id_index');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->renameColumn('whatsapp_account_id', 'connection_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('connection_id')->references('id')->on('connections')->restrictOnDelete();
        });

        DB::table('audit_logs')->where('auditable_type', 'whatsapp_account')->update(['auditable_type' => 'connection']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('audit_logs')->where('auditable_type', 'connection')->update(['auditable_type' => 'whatsapp_account']);

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['connection_id']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->renameColumn('connection_id', 'whatsapp_account_id');
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->renameIndex('connections_phone_number_id_unique', 'whatsapp_accounts_phone_number_id_unique');
            $table->renameIndex('connections_business_account_id_index', 'whatsapp_accounts_business_account_id_index');
        });

        Schema::rename('connections', 'whatsapp_accounts');

        Schema::table('conversations', function (Blueprint $table) {
            $table->foreign('whatsapp_account_id')->references('id')->on('whatsapp_accounts')->restrictOnDelete();
        });
    }
};
