<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each copy of a video to the NAS folder of saved videos, requested from
     * a conversation (message_attachment_id) or the internal chat
     * (team_attachment_id): exactly one of the two is set. The person chose
     * the folder (relative to the root of saved videos) and the file name;
     * the original is never moved. A queued job (SaveVideoToNas) writes it.
     */
    public function up(): void
    {
        Schema::create('video_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_attachment_id')->nullable()->constrained('message_attachments')->cascadeOnDelete();
            $table->foreignId('team_attachment_id')->nullable()->constrained('team_attachments')->cascadeOnDelete();
            $table->string('folder', 700)->default('');
            $table->string('file_name', 160);
            $table->string('disk', 40);
            $table->string('target_path', 1024)->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('saved_at')->nullable();
            $table->timestamps();

            $table->index(['message_attachment_id', 'id']);
            $table->index(['team_attachment_id', 'id']);
            $table->index(['user_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_exports');
    }
};
