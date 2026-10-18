<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `open_lock` is TRUE while the conversation is not closed and NULL once it is
     * closed. Because unique indexes ignore NULL values (MySQL, MariaDB, SQLite),
     * the unique index below guarantees at most ONE non-closed conversation per
     * contact and WhatsApp number, even under concurrent webhook processing.
     *
     * `version` supports optimistic locking for assignment and transfer, so two
     * agents cannot silently overwrite each other's changes.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->boolean('open_lock')->nullable()->default(true);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'contact_id', 'open_lock'], 'conversations_single_open_unique');
            $table->index(['status', 'last_message_at']);
            $table->index(['assigned_user_id', 'status', 'last_message_at']);
            $table->index(['department_id', 'status', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
