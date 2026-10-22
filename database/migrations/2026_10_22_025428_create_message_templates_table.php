<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp message templates as Meta reports them for the business account
 * of each connection (synchronized, never invented). A template that Meta no
 * longer returns is logically deleted, so messages that used it keep their
 * history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->restrictOnDelete();
            $table->string('business_account_id', 64);
            $table->string('meta_template_id', 64);
            $table->string('name', 512);
            $table->string('language', 15);
            $table->string('category', 30)->nullable();
            $table->string('status', 30);
            $table->string('quality_score', 20)->nullable();
            $table->string('parameter_format', 20)->nullable();
            $table->json('components')->nullable();
            $table->string('rejected_reason', 255)->nullable();
            $table->timestamp('status_updated_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['connection_id', 'meta_template_id']);
            $table->index(['connection_id', 'status']);
            $table->index(['business_account_id', 'meta_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
