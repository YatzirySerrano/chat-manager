<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Account authorized through Meta login for Messenger (Facebook page) and
     * Instagram (professional account) connections. The page or Instagram
     * token reuses the encrypted access_token column. The WhatsApp columns
     * (phone_number_id, business_account_id...) are already nullable, so
     * they need no change for these channels.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('external_account_id', 64)->nullable()->after('business_account_id');
            $table->string('external_account_name')->nullable()->after('external_account_id');
            $table->string('external_account_picture', 2048)->nullable()->after('external_account_name');
            $table->json('granted_scopes')->nullable()->after('webhook_verify_token');
            $table->timestamp('token_expires_at')->nullable()->after('granted_scopes');
            $table->timestamp('authorized_at')->nullable()->after('token_expires_at');
            $table->foreignId('authorized_by_user_id')->nullable()->after('authorized_at')->constrained('users')->nullOnDelete();

            $table->unique(['channel', 'external_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropUnique(['channel', 'external_account_id']);
            $table->dropConstrainedForeignId('authorized_by_user_id');
            $table->dropColumn([
                'external_account_id', 'external_account_name', 'external_account_picture',
                'granted_scopes', 'token_expires_at', 'authorized_at',
            ]);
        });
    }
};
