<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alerts that administrators send to agents from «Seguimiento de atención».
 *
 * An alert is written once (its content, priority, look and sound chosen
 * from controlled catalogs, never free CSS, HTML or audio files) and has one
 * row per recipient, where its two states are kept apart: `delivered_at`,
 * the first time the recipient's application showed it, and
 * `acknowledged_at`, when the person confirmed it with «Entendido». Being
 * delivered to a browser is never taken as having been read.
 *
 * `client_key` makes sending idempotent per sender: a double click or a
 * retried request finds the alert already stored. The sender is kept as
 * null when that user row is removed (the history stays); recipients go
 * with their user row, like every personal working state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attention_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_key');
            $table->string('audience', 20);
            $table->string('title', 120);
            $table->text('body');
            $table->string('priority', 20);
            $table->string('color', 20);
            $table->string('sound', 20);
            $table->string('action', 40)->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->timestamps();

            $table->unique(['sender_id', 'client_key']);
            $table->index(['created_at', 'id']);
        });

        Schema::create('attention_alert_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attention_alert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['attention_alert_id', 'user_id']);
            // A person's alerts still waiting for «Entendido», oldest first.
            $table->index(['user_id', 'acknowledged_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attention_alert_recipients');
        Schema::dropIfExists('attention_alerts');
    }
};
