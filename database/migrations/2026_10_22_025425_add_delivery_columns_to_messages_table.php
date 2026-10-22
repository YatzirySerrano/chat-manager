<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery evidence of outbound messages:
 *
 * - accepted_at: Meta's API answered with a message id (wamid);
 * - sending_started_at: a worker claimed the message and is calling Meta;
 *   a message stuck in "sending" is reconciled from this moment;
 * - delivery_attempts: calls made to the provider for the message,
 *   including authorized manual retries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('error_message');
            $table->timestamp('sending_started_at')->nullable()->after('accepted_at');
            $table->unsignedSmallInteger('delivery_attempts')->default(0)->after('sending_started_at');

            $table->index(['status', 'sending_started_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['status', 'sending_started_at']);
            $table->dropColumn(['accepted_at', 'sending_started_at', 'delivery_attempts']);
        });
    }
};
