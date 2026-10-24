<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sessions of web chat visitors. Only the SHA-256 of the bearer secret is
     * stored; `visitor_id` is the contact identity (not unique: an identified
     * visitor may hold sessions on several devices); `origin` is the host
     * origin validated when the session started; the IP is only kept as an
     * HMAC for rate limiting. `contact_id` caches the contact registered with
     * the first message. Expiry slides with use up to an absolute cap. The
     * prune command deletes by expires_at and by revoked_at, one index each.
     */
    public function up(): void
    {
        Schema::create('web_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('connection_id')->constrained()->restrictOnDelete();
            $table->string('visitor_id', 64);
            $table->boolean('identified')->default(false);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->json('profile')->nullable();
            $table->string('origin', 255);
            $table->string('landing_url', 2048)->nullable();
            $table->char('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 40)->nullable();
            $table->timestamps();

            $table->index(['connection_id', 'visitor_id']);
            $table->index('expires_at');
            $table->index('revoked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('web_chat_sessions');
    }
};
