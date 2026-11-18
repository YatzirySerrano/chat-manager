<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The chatbot (docs/notificaciones-y-horarios.md, «Chatbot»):
     *
     * - chatbot_menus / chatbot_menu_options: the welcome menu of a
     *   connection, one per connection. Its greeting is followed by the
     *   numbered options; each option sends the conversation to a
     *   department, optionally with a reply of its own;
     * - chatbot_keyword_rules (+ its connection and department scopes):
     *   automatic replies when a customer message contains a keyword;
     * - chatbot_sessions: the menu offered to one conversation (one row per
     *   conversation, so two inbound messages at once can never open it
     *   twice) and how it ended.
     */
    public function up(): void
    {
        Schema::create('chatbot_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->unique()->constrained('connections')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->text('greeting')->nullable();
            $table->string('retry_message', 500)->nullable();
            $table->unsignedTinyInteger('max_attempts')->default(2);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chatbot_menu_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chatbot_menu_id')->constrained('chatbot_menus')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('label', 80)->nullable();
            $table->text('reply_message')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['chatbot_menu_id', 'department_id'], 'chatbot_menu_options_menu_department_unique');
        });

        Schema::create('chatbot_keyword_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->json('keywords');
            $table->text('reply_message');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('throttle_minutes')->default(30);
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('chatbot_keyword_rule_connection', function (Blueprint $table) {
            $table->foreignId('chatbot_keyword_rule_id')->constrained('chatbot_keyword_rules')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('connections')->cascadeOnDelete();

            $table->primary(['chatbot_keyword_rule_id', 'connection_id']);
        });

        Schema::create('chatbot_keyword_rule_department', function (Blueprint $table) {
            $table->foreignId('chatbot_keyword_rule_id')->constrained('chatbot_keyword_rules')->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();

            $table->primary(['chatbot_keyword_rule_id', 'department_id']);
        });

        Schema::create('chatbot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->foreignId('chatbot_menu_option_id')->nullable()->constrained('chatbot_menu_options')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_sessions');
        Schema::dropIfExists('chatbot_keyword_rule_department');
        Schema::dropIfExists('chatbot_keyword_rule_connection');
        Schema::dropIfExists('chatbot_keyword_rules');
        Schema::dropIfExists('chatbot_menu_options');
        Schema::dropIfExists('chatbot_menus');
    }
};
