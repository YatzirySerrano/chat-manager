<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chatbot menu becomes programmable (ChatbotOptionAction): each option
 * opens a submenu (its children, any number of levels), answers and keeps
 * the menu open, answers and ends, or sends the conversation to a
 * department. «0» goes back one level and «#» to the main menu; the session
 * remembers where the customer is (current_option_id). A menu nobody
 * answers falls back to the ordinary routing after timeout_minutes.
 *
 * Existing options keep working unchanged: they become «transfer» options
 * of the main menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_menu_options', function (Blueprint $table) {
            // The unique key (menu, department) also served the menu's foreign key.
            $table->index('chatbot_menu_id', 'chatbot_menu_options_menu_index');
        });

        Schema::table('chatbot_menu_options', function (Blueprint $table) {
            $table->dropUnique('chatbot_menu_options_menu_department_unique');
            $table->foreignId('parent_id')->nullable()->after('chatbot_menu_id')
                ->constrained('chatbot_menu_options')->cascadeOnDelete();
            $table->string('action', 20)->default('transfer')->after('parent_id');
            $table->foreignId('department_id')->nullable()->change();
        });

        Schema::table('chatbot_menus', function (Blueprint $table) {
            $table->string('navigation_hint', 300)->nullable()->after('retry_message');
            // 0: never; the customer may keep the menu open indefinitely.
            $table->unsignedSmallInteger('timeout_minutes')->default(30)->after('max_attempts');
        });

        Schema::table('chatbot_sessions', function (Blueprint $table) {
            // The submenu the customer is in (null: the main menu).
            $table->foreignId('current_option_id')->nullable()->after('attempts')
                ->constrained('chatbot_menu_options')->nullOnDelete();
            $table->index(['status', 'updated_at'], 'chatbot_sessions_status_updated_index');
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_sessions', function (Blueprint $table) {
            $table->dropIndex('chatbot_sessions_status_updated_index');
            $table->dropConstrainedForeignId('current_option_id');
        });

        Schema::table('chatbot_menus', function (Blueprint $table) {
            $table->dropColumn(['navigation_hint', 'timeout_minutes']);
        });

        // Submenus and answers have no department: only transfers survive.
        DB::table('chatbot_menu_options')->whereNotNull('parent_id')->delete();
        DB::table('chatbot_menu_options')->whereNull('department_id')->delete();

        Schema::table('chatbot_menu_options', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('action');
            $table->foreignId('department_id')->nullable(false)->change();
            $table->unique(['chatbot_menu_id', 'department_id'], 'chatbot_menu_options_menu_department_unique');
        });

        Schema::table('chatbot_menu_options', function (Blueprint $table) {
            $table->dropIndex('chatbot_menu_options_menu_index');
        });
    }
};
