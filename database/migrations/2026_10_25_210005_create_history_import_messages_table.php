<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Parsed messages of a history import batch (staging). A Whaticket
     * messages export is one row per message and is not grouped by
     * conversation, so its rows are parsed once into this table, grouped by
     * conversation key and read back in order when the batch is executed
     * (resumable per conversation). The text is plain text, never HTML;
     * media is referenced by file name, the file stays in its ZIP. Every row
     * of a batch is purged as soon as the batch finishes, fails or expires.
     * The unique key on the source message id drops the same message found
     * in two overlapping exports.
     */
    public function up(): void
    {
        Schema::create('history_import_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_transfer_id')->constrained('data_transfers')->cascadeOnDelete();
            $table->foreignId('history_import_file_id')->constrained('history_import_files')->cascadeOnDelete();
            $table->foreignId('history_import_conversation_id')->nullable()->constrained('history_import_conversations')->cascadeOnDelete();
            $table->string('conversation_key', 160);
            $table->string('source_message_id', 128)->nullable();
            $table->unsignedInteger('line');
            $table->timestamp('occurred_at')->nullable();
            $table->boolean('from_me')->nullable();
            $table->boolean('direction_in_file')->default(false);
            $table->boolean('is_system')->default(false);
            $table->string('sender_name', 160)->nullable();
            $table->string('type', 20)->default('text');
            $table->text('body')->nullable();
            $table->string('media_name')->nullable();
            $table->boolean('media_available')->default(false);
            // File copied to the media disk before the conversation's
            // transaction (so a long or interrupted run never repeats or
            // orphans an upload), or why it could not be copied.
            $table->string('media_disk', 40)->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime', 127)->nullable();
            $table->unsignedBigInteger('media_size')->nullable();
            $table->char('media_sha256', 64)->nullable();
            $table->string('media_error', 50)->nullable();
            $table->string('quoted_source_id', 128)->nullable();
            $table->string('contact_name', 160)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_identifier', 128)->nullable();
            $table->string('source_agent', 160)->nullable();
            $table->string('source_department', 160)->nullable();
            $table->string('source_connection', 160)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['data_transfer_id', 'source_message_id'], 'history_import_messages_source_unique');
            $table->unique(['history_import_file_id', 'line']);
            $table->index(['data_transfer_id', 'conversation_key']);
            $table->index(['history_import_conversation_id', 'occurred_at', 'id'], 'history_import_messages_order_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('history_import_messages');
    }
};
