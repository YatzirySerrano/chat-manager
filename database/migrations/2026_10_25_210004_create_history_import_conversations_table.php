<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Inventory of a history import batch: one row per conversation
     * detected in the uploaded files, with what the source said about it
     * (contact, dates, counts, original agent / department / connection /
     * tags / status, as present), the checks (duplicates, issues), the
     * admin's decision (import, archive, exclude; NULL while undecided) and,
     * once executed, the result. It holds no message content. After the
     * batch finishes the identifying fields of excluded rows are cleared, so
     * only a minimal reference stays in the report.
     */
    public function up(): void
    {
        Schema::create('history_import_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_transfer_id')->constrained('data_transfers')->cascadeOnDelete();
            $table->string('source_key', 160);
            $table->string('source_kind', 20);
            $table->string('source_ticket_id', 64)->nullable();
            $table->string('contact_name', 160)->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_identifier', 128)->nullable();
            $table->boolean('is_group')->default(false);
            $table->json('participants')->nullable();
            $table->json('business_participants')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('inbound_count')->default(0);
            $table->unsignedInteger('outbound_count')->default(0);
            $table->unsignedInteger('attachments_total')->default(0);
            $table->unsignedInteger('attachments_available')->default(0);
            $table->unsignedBigInteger('media_bytes')->default(0);
            $table->string('source_agent', 160)->nullable();
            $table->string('source_department', 160)->nullable();
            $table->string('source_connection', 160)->nullable();
            $table->json('source_tags')->nullable();
            $table->string('source_status', 40)->nullable();
            $table->json('source_details')->nullable();
            $table->boolean('in_chats_report')->default(false);
            $table->boolean('already_imported')->default(false);
            $table->json('issues')->nullable();
            $table->string('recommendation', 20)->nullable();
            $table->string('recommendation_reason', 160)->nullable();
            $table->string('decision', 20)->nullable();
            $table->string('decision_source', 10)->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('applied_rule_id')->nullable()->constrained('history_import_rules')->nullOnDelete();
            $table->boolean('save_contact')->default(false);
            $table->string('state', 20)->default('pending');
            $table->foreignId('result_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->unsignedBigInteger('result_contact_id')->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['data_transfer_id', 'source_key']);
            $table->index(['data_transfer_id', 'decision', 'state', 'id'], 'history_import_conversations_decision_index');
            $table->index(['data_transfer_id', 'last_message_at', 'id'], 'history_import_conversations_activity_index');
            $table->index(['data_transfer_id', 'contact_phone'], 'history_import_conversations_phone_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('history_import_conversations');
    }
};
