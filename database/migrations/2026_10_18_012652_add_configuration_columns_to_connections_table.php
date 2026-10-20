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
     * A connection can now be saved before every Meta identifier is known, so
     * those columns become nullable. Credentials are stored encrypted by the
     * model (text columns hold the ciphertext). Activity timestamps are written
     * by the integration and are the only evidence used to derive the health
     * of a connection: nothing here claims that it works on its own.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('channel', 20)->default('whatsapp')->after('name');
            $table->string('provider', 40)->default('meta_cloud_api')->after('channel');
            $table->string('display_phone_number', 32)->nullable()->change();
            $table->string('phone_number_id', 64)->nullable()->change();
            $table->string('business_account_id', 64)->nullable()->change();
            $table->string('verified_name')->nullable()->after('business_account_id');
            $table->string('meta_app_id', 64)->nullable()->after('verified_name');
            $table->text('access_token')->nullable()->after('meta_app_id');
            $table->text('app_secret')->nullable()->after('access_token');
            $table->text('webhook_verify_token')->nullable()->after('app_secret');
            $table->foreignId('default_department_id')->nullable()->after('is_active')->constrained('departments')->nullOnDelete();
            $table->text('welcome_message')->nullable()->after('default_department_id');
            $table->boolean('welcome_enabled')->default(false)->after('welcome_message');
            $table->text('away_message')->nullable()->after('welcome_enabled');
            $table->boolean('away_enabled')->default(false)->after('away_message');
            $table->text('farewell_message')->nullable()->after('away_enabled');
            $table->boolean('farewell_enabled')->default(false)->after('farewell_message');
            $table->unsignedSmallInteger('reopen_window_minutes')->nullable()->after('farewell_enabled');
            $table->timestamp('credentials_verified_at')->nullable()->after('reopen_window_minutes');
            $table->string('credentials_error', 500)->nullable()->after('credentials_verified_at');
            $table->string('phone_status', 40)->nullable()->after('credentials_error');
            $table->string('code_verification_status', 40)->nullable()->after('phone_status');
            $table->string('platform_type', 40)->nullable()->after('code_verification_status');
            $table->string('quality_rating', 20)->nullable()->after('platform_type');
            $table->timestamp('webhook_verified_at')->nullable()->after('quality_rating');
            $table->timestamp('last_inbound_at')->nullable()->after('webhook_verified_at');
            $table->timestamp('last_outbound_at')->nullable()->after('last_inbound_at');
            $table->timestamp('last_error_at')->nullable()->after('last_outbound_at');
            $table->string('last_error_message', 500)->nullable()->after('last_error_at');
            $table->timestamp('last_activity_at')->nullable()->after('last_error_message');
            $table->foreignId('created_by_user_id')->nullable()->after('last_activity_at')->constrained('users')->nullOnDelete();

            $table->index(['channel', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * The previous schema required every Meta identifier. Connections saved
     * without them receive a recognizable placeholder so the rollback never
     * deletes a connection that conversations may reference.
     */
    public function down(): void
    {
        DB::table('connections')->whereNull('display_phone_number')->update(['display_phone_number' => '']);
        DB::table('connections')->whereNull('business_account_id')->update(['business_account_id' => '']);
        DB::table('connections')->whereNull('phone_number_id')->update(['phone_number_id' => DB::raw("CONCAT('pending-', `id`)")]);

        Schema::table('connections', function (Blueprint $table) {
            $table->dropIndex(['channel', 'is_active']);
            $table->dropConstrainedForeignId('default_department_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn([
                'channel', 'provider', 'verified_name', 'meta_app_id', 'access_token', 'app_secret', 'webhook_verify_token',
                'welcome_message', 'welcome_enabled', 'away_message', 'away_enabled', 'farewell_message', 'farewell_enabled',
                'reopen_window_minutes', 'credentials_verified_at', 'credentials_error', 'phone_status',
                'code_verification_status', 'platform_type', 'quality_rating', 'webhook_verified_at', 'last_inbound_at',
                'last_outbound_at', 'last_error_at', 'last_error_message', 'last_activity_at',
            ]);
        });

        Schema::table('connections', function (Blueprint $table) {
            $table->string('display_phone_number', 32)->nullable(false)->change();
            $table->string('phone_number_id', 64)->nullable(false)->change();
            $table->string('business_account_id', 64)->nullable(false)->change();
        });
    }
};
