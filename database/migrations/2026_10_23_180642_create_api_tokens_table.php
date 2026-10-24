<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tokens of the server-to-server integration API. The token is its own
     * principal: it carries its abilities and the connections (and optionally
     * departments) it may reach, an optional IP allowlist and a mandatory
     * expiry. Only the SHA-256 of the secret is stored; revocations record
     * who revoked and why.
     */
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->json('abilities');
            $table->json('connection_ids');
            $table->json('department_ids')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
