<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Generic record of a file exchanged with the platform: report exports
     * today and the contacts importer later. A row tracks one asynchronous
     * request from queueing to completion, failure or expiration; the file
     * itself lives on a private disk and is deleted when the row expires.
     */
    public function up(): void
    {
        Schema::create('data_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('report', 60);
            $table->string('format', 10);
            $table->string('status', 20)->default('queued');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('filters')->nullable();
            $table->json('options')->nullable();
            $table->string('disk', 40)->nullable();
            $table->string('path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedInteger('rows_total')->nullable();
            $table->unsignedInteger('rows_processed')->nullable();
            $table->unsignedInteger('rows_failed')->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('data_transfers');
    }
};
