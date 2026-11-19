<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Mesa de control»: internal notes of the team on a request (never seen
     * by the requester) and tags of the catalog applied to desk threads, like
     * the notes and tags of customer conversations.
     */
    public function up(): void
    {
        Schema::create('team_desk_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['team_thread_id', 'id']);
        });

        Schema::create('team_thread_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->restrictOnDelete();
            $table->foreignId('tagged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['team_thread_id', 'tag_id']);
            $table->index(['tag_id', 'team_thread_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_thread_tag');
        Schema::dropIfExists('team_desk_notes');
    }
};
