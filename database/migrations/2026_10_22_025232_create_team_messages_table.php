<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Messages are never physically deleted: when the author deletes one its
     * content is erased (`body` null, attachments purged) and the row stays as
     * a "Mensaje eliminado" marker so the thread keeps its order and replies.
     * `kind` = system rows record membership changes visible to everyone.
     */
    public function up(): void
    {
        Schema::create('team_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_thread_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16)->default('message');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('team_messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['team_thread_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_messages');
    }
};
