<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per person and thread. Leaving or being removed sets `left_at`
     * (the row is kept as history, never deleted); joining again clears it.
     * `last_read_message_id` drives the unread counters.
     */
    public function up(): void
    {
        Schema::create('team_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_thread_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 16)->default('member');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->foreignId('added_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->boolean('muted')->default(false);
            $table->timestamps();

            $table->unique(['team_thread_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_participants');
    }
};
