<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read markers of the inbox: up to which message each person has read each
 * conversation. They are personal working state derived from the history
 * (never business records), so they go away with the conversation or the
 * user row; logical deletion of users keeps them untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'conversation_id']);
            $table->index('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_reads');
    }
};
