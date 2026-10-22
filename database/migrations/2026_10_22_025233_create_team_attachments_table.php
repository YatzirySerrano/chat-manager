<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Files live on a private disk under a random name; the database keeps
     * only metadata. `purged_at` marks a file erased because its message was
     * deleted by the author (the row stays for the history).
     */
    public function up(): void
    {
        Schema::create('team_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_message_id')->constrained()->restrictOnDelete();
            $table->string('disk', 40);
            $table->string('path')->nullable();
            $table->string('original_name');
            $table->string('mime_type', 127);
            $table->string('kind', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_attachments');
    }
};
