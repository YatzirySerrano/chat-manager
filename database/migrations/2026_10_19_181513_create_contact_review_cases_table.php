<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Possible duplicates that could not be resolved automatically without
     * merging on weak evidence or revealing contacts outside an employee's
     * scope. Administrators decide; contacts are never merged silently.
     *
     * `open_lock` is 1 while the case is open and NULL once resolved, so the
     * unique key allows a single open case per contact and reason while
     * keeping every resolved case as history.
     */
    public function up(): void
    {
        Schema::create('contact_review_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('reason', 40);
            $table->string('status', 20)->default('open');
            $table->boolean('open_lock')->nullable()->storedAs("IF(`status` = 'open', 1, NULL)");
            $table->json('candidate_contact_ids');
            $table->json('evidence')->nullable();
            $table->string('resolution', 30)->nullable();
            $table->foreignId('resolved_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['contact_id', 'reason', 'open_lock'], 'contact_review_cases_single_open_unique');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_review_cases');
    }
};
