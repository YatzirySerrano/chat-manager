<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `wa_id` is the WhatsApp user identifier sent by Meta; the unique index
     * prevents duplicate contacts when webhooks are retried or processed in parallel.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id', 32)->unique();
            $table->string('phone_number', 32);
            $table->string('profile_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();

            $table->index('phone_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
