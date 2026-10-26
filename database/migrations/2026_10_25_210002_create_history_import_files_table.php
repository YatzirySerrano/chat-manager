<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Files uploaded to a history import batch (staging): the Whaticket
     * chats report, messages CSV, contacts CSV or media ZIP. The file itself
     * stays on the private imports disk (never in MariaDB) until the batch
     * finishes or expires; this row keeps its fingerprint (sha256, so the
     * same file is never added twice), what the reader detected (format
     * variant, headers, the column mapping, unknown headers and missing
     * fields) and the row counts. media_index lists the entries of a media
     * ZIP (name and size), not their content.
     */
    public function up(): void
    {
        Schema::create('history_import_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_transfer_id')->constrained('data_transfers')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('uploaded');
            $table->string('original_name', 160);
            $table->string('extension', 10);
            $table->string('disk', 40);
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64);
            $table->string('format_version', 60)->nullable();
            $table->json('headers')->nullable();
            $table->json('column_mapping')->nullable();
            $table->json('unknown_headers')->nullable();
            $table->json('missing_fields')->nullable();
            $table->json('media_index')->nullable();
            $table->json('issues')->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_accepted')->default(0);
            $table->unsignedInteger('rows_rejected')->default(0);
            $table->string('error', 500)->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();

            $table->unique(['data_transfer_id', 'sha256']);
            $table->index('sha256');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('history_import_files');
    }
};
