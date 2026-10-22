<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deduplication ledger of notifications: one row per (recipient, source
 * event). A retried webhook, a re-dispatched listener or a duplicated job
 * finds its row and notifies nobody again. Rows are pruned after a while
 * (see PruneNotifications); they hold no content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_key', 191);
            $table->uuid('notification_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'source_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_receipts');
    }
};
