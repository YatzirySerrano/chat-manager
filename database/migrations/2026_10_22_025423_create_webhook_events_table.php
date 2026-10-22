<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw log of authenticated WhatsApp webhook deliveries. The body is stored
 * exactly as received so a failed event can be processed again; a repeated
 * delivery of the same body for the same connection is stored once
 * (payload_hash). Rows are operational logs pruned after their retention
 * period (whatsapp:prune-webhook-events), never business records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained()->restrictOnDelete();
            $table->char('payload_hash', 64);
            $table->string('fields', 191)->nullable();
            $table->longText('payload');
            $table->string('status', 20)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'payload_hash']);
            $table->index(['status', 'received_at']);
            $table->index(['connection_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
