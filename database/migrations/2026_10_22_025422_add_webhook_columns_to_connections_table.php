<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Webhook and diagnostics evidence of a WhatsApp connection.
 *
 * - webhook_key: random opaque identifier of the connection's webhook URL
 *   (/webhooks/whatsapp/{webhook_key}), so internal ids are never exposed or
 *   enumerable. It is not a secret: every event is authenticated with the
 *   app secret signature.
 * - last_webhook_at: last authenticated webhook delivery (even before a worker
 *   processes it), so a stopped queue is distinguishable from a silent Meta.
 * - app_subscribed / subscription_checked_at: result of the last
 *   GET /{waba}/subscribed_apps check.
 * - provider_signals: last account_update / phone_number_quality_update
 *   notices reported by Meta, kept as received (no interpretation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->string('webhook_key', 64)->nullable()->unique()->after('webhook_verify_token');
            $table->timestamp('last_webhook_at')->nullable()->after('webhook_verified_at');
            $table->boolean('app_subscribed')->nullable()->after('last_webhook_at');
            $table->timestamp('subscription_checked_at')->nullable()->after('app_subscribed');
            $table->json('provider_signals')->nullable()->after('subscription_checked_at');
        });

        DB::table('connections')->whereNull('webhook_key')->orderBy('id')->each(function (object $connection): void {
            DB::table('connections')->where('id', $connection->id)->update(['webhook_key' => Str::random(48)]);
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropUnique(['webhook_key']);
            $table->dropColumn(['webhook_key', 'last_webhook_at', 'app_subscribed', 'subscription_checked_at', 'provider_signals']);
        });
    }
};
