<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Global idempotency of migrations from other systems: one row per
     * record of the source (a Whaticket ticket, a Whaticket contact) that was
     * turned into a record here, written in the same transaction as the
     * record. The unique key makes re-running a batch, or importing the same
     * export again in a later batch, skip what already exists. `details`
     * keeps the original identifiers of the source (ticket id, chat link,
     * the number as it was written) and never message content.
     */
    public function up(): void
    {
        Schema::create('import_source_keys', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 20);
            $table->string('source_instance', 64);
            $table->string('source_kind', 20);
            $table->string('source_id', 128);
            $table->string('target_type', 40);
            $table->unsignedBigInteger('target_id');
            $table->foreignId('data_transfer_id')->constrained('data_transfers')->restrictOnDelete();
            $table->json('details')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_instance', 'source_kind', 'source_id'], 'import_source_keys_unique');
            $table->index(['target_type', 'target_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_source_keys');
    }
};
