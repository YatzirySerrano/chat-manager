<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Internal notes live in their own table, apart from `messages`, so no
     * code path that sends, counts or lists customer messages can ever pick
     * one up. Notes are never deleted with their conversation.
     */
    public function up(): void
    {
        Schema::create('conversation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_notes');
    }
};
