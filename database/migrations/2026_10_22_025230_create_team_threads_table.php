<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Internal (employee) chat lives in its own tables, apart from customer
     * conversations and messages, so no code path that lists, counts or sends
     * customer messages can ever pick an internal message up.
     *
     * `direct_key` ("{lowerUserId}:{higherUserId}") makes a one-to-one chat
     * unique per pair of people; groups leave it null. `last_message_id`
     * is a denormalized pointer for ordering and unread counters (no foreign
     * key: messages are created after the thread and never removed).
     */
    public function up(): void
    {
        Schema::create('team_threads', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);
            $table->string('name', 80)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('direct_key', 64)->nullable()->unique();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'archived_at']);
            $table->index('last_message_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_threads');
    }
};
