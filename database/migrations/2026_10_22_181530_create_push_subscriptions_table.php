<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Web Push subscriptions of each user's browsers. Pushes carry no
     * payload, so only the push service endpoint is kept (no p256dh/auth
     * keys): its SHA-256 makes registering the same browser again an upsert,
     * and the endpoint itself is never indexed (it can exceed 191 bytes).
     * `user_agent` is a short label ("Chrome en Windows"), not the raw
     * header; `updated_at` is the last registration or successful push and
     * drives the pruning of stale subscriptions.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('user_agent', 120)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
