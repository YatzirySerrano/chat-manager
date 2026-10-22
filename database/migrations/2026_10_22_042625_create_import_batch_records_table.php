<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traceability of import batches: every record an import created or
 * completed, linked to its batch (the DataTransfer of the import), with the
 * values the batch replaced and what it left, so a batch can be reviewed and
 * logically rolled back when it is safe. Additive table; batches and
 * records are history and are never physically removed (restrictOnDelete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batch_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_transfer_id')->constrained('data_transfers')->restrictOnDelete();
            $table->string('record_type', 40);
            $table->unsignedBigInteger('record_id');
            $table->unsignedInteger('line')->nullable();
            $table->string('action', 20);
            $table->json('previous_values')->nullable();
            $table->json('applied_values')->nullable();
            $table->json('added_tag_ids')->nullable();
            $table->json('added_note_ids')->nullable();
            $table->unsignedBigInteger('audit_watermark')->default(0);
            $table->timestamp('rolled_back_at')->nullable();
            $table->string('rollback_outcome', 20)->nullable();
            $table->string('rollback_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['data_transfer_id', 'record_type', 'record_id'], 'import_batch_records_unique');
            $table->index(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_records');
    }
};
