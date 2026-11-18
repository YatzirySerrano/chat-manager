<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The text an agent is still writing, kept per user, chat and mode on
     * the server, so a reply started on the phone can be finished on the
     * computer (and the other way around). A row targets a customer
     * conversation (modes «reply» and «note») or an internal thread (mode
     * «message»), never both. Since NULLs never collide in a unique index,
     * each unique index only constrains its own kind of chat. Removing the
     * chat (permanent deletion) or the user removes their drafts.
     */
    public function up(): void
    {
        Schema::create('composer_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('team_thread_id')->nullable()->constrained('team_threads')->cascadeOnDelete();
            $table->string('mode', 10);
            $table->text('body');
            $table->timestamps();

            $table->unique(['user_id', 'conversation_id', 'mode'], 'composer_drafts_conversation_unique');
            $table->unique(['user_id', 'team_thread_id', 'mode'], 'composer_drafts_thread_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('composer_drafts');
    }
};
