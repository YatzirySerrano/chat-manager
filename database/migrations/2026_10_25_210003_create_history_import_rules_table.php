<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Bulk classification rules of a history import batch ("archive
     * everything before a date", "import the Ventas department", "exclude
     * empty conversations"). A rule is a filter of the inventory plus a
     * destination; it is previewed with counts before being applied, it is
     * kept so the admin can review what decided each conversation, and it
     * never overwrites a manual decision unless `overwrite` was chosen.
     */
    public function up(): void
    {
        Schema::create('history_import_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_transfer_id')->constrained('data_transfers')->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('criteria');
            $table->string('decision', 20);
            $table->boolean('overwrite')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('applied_count')->default(0);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['data_transfer_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('history_import_rules');
    }
};
