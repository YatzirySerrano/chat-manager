<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How a contact reached the company. Rows confirmed by the provider (for
     * example, a Click-to-WhatsApp referral sent by Meta) and rows declared by
     * a person are kept side by side; neither overwrites the other. A contact
     * without rows has an unidentified origin, never an "organic" one.
     */
    public function up(): void
    {
        Schema::create('contact_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('origin_type', 30);
            $table->string('reliability', 30);
            $table->string('provider', 40)->nullable();
            $table->string('ad_platform', 40)->nullable();
            $table->string('source_id', 128)->nullable();
            $table->string('source_type', 40)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('headline')->nullable();
            $table->string('body', 1000)->nullable();
            $table->string('ctwa_clid', 255)->nullable();
            $table->string('campaign_id', 128)->nullable();
            $table->string('campaign_name')->nullable();
            $table->string('page_name')->nullable();
            $table->string('media_type', 40)->nullable();
            $table->json('raw_referral')->nullable();
            $table->timestamp('captured_at');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['contact_id', 'captured_at']);
            $table->index('origin_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_attributions');
    }
};
