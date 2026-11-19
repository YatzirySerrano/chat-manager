<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Inteligencia artificial» (docs: App\Services\Ai\AiAssistant):
     *
     * - ai_settings: the one global configuration (provider, encrypted API
     *   key, model, business information, catalog of answers, tone, limits).
     * - ai_connection_settings: whether the assistant answers on a connection,
     *   where it hands the conversation off and whether only outside hours.
     * - ai_conversation_states: per conversation and cycle, how many replies
     *   the assistant sent and whether it already handed off to a person.
     */
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('anthropic');
            // Encrypted cast: never readable in a dump, never serialized.
            $table->text('api_key')->nullable();
            $table->string('model', 100)->nullable();
            $table->longText('business_info')->nullable();
            $table->json('catalog')->nullable();
            $table->text('instructions')->nullable();
            $table->string('assistant_name', 80)->nullable();
            $table->text('handoff_message')->nullable();
            $table->unsignedSmallInteger('max_replies')->default(10);
            $table->string('last_error_type', 40)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_connection_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->foreignId('handoff_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('only_outside_hours')->default(false);
            $table->timestamps();
        });

        Schema::create('ai_conversation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cycle')->default(0);
            $table->unsignedSmallInteger('replies')->default(0);
            $table->string('handoff_reason', 40)->nullable();
            $table->timestamp('handed_off_at')->nullable();
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_states');
        Schema::dropIfExists('ai_connection_settings');
        Schema::dropIfExists('ai_settings');
    }
};
