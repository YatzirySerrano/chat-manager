<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More personalization of the web chat widget and files from visitors:
 *
 * - the background of the conversation and the colors of the visitor's and
 *   the agent's bubbles (#rrggbb; NULL keeps the widget's own look, which
 *   follows the visitor's light or dark theme);
 * - the floating button drawn on the host page: its colors (NULL = the
 *   accent color and a readable icon), shape, icon, size, distance from the
 *   corner and an optional attention pulse;
 * - the size of the chat window (it still covers the screen on phones),
 *   the width (NULL = fits its text) and text size of the button;
 * - the texts of the status line and of the message box (NULL = the
 *   widget's own: «En línea», «Fuera de línea», «Escribe tu mensaje…»);
 * - whether visitors may send files from the widget.
 *
 * Additive: every existing widget keeps its current look (a widget whose
 * button already had a text keeps showing it, as a pill) and allows files,
 * like a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_chat_widgets', function (Blueprint $table) {
            $table->char('background_color', 7)->nullable()->after('accent_color');
            $table->char('visitor_bubble_color', 7)->nullable()->after('background_color');
            $table->char('visitor_text_color', 7)->nullable()->after('visitor_bubble_color');
            $table->char('agent_bubble_color', 7)->nullable()->after('visitor_text_color');
            $table->char('agent_text_color', 7)->nullable()->after('agent_bubble_color');
            $table->char('launcher_color', 7)->nullable()->after('launcher_label');
            $table->char('launcher_icon_color', 7)->nullable()->after('launcher_color');
            $table->string('launcher_shape', 10)->default('circle')->after('launcher_icon_color');
            $table->string('launcher_icon', 10)->default('chat')->after('launcher_shape');
            $table->string('launcher_size', 10)->default('medium')->after('launcher_icon');
            $table->unsignedTinyInteger('launcher_offset_x')->default(0)->after('launcher_size');
            $table->unsignedTinyInteger('launcher_offset_y')->default(0)->after('launcher_offset_x');
            $table->boolean('launcher_pulse')->default(false)->after('launcher_offset_y');
            $table->unsignedSmallInteger('launcher_width')->nullable()->after('launcher_pulse');
            $table->string('launcher_font_size', 10)->default('medium')->after('launcher_width');
            $table->unsignedSmallInteger('panel_width')->default(380)->after('launcher_font_size');
            $table->unsignedSmallInteger('panel_height')->default(600)->after('panel_width');
            $table->string('online_text', 40)->nullable()->after('offline_text');
            $table->string('offline_status_text', 40)->nullable()->after('online_text');
            $table->string('composer_placeholder', 80)->nullable()->after('offline_status_text');
            $table->boolean('allow_visitor_files')->default(true)->after('panel_height');
        });

        // Until now a button with a text was drawn as a pill.
        DB::table('web_chat_widgets')->whereNotNull('launcher_label')->where('launcher_label', '!=', '')->update(['launcher_shape' => 'pill']);
    }

    public function down(): void
    {
        Schema::table('web_chat_widgets', function (Blueprint $table) {
            $table->dropColumn([
                'background_color', 'visitor_bubble_color', 'visitor_text_color', 'agent_bubble_color', 'agent_text_color',
                'launcher_color', 'launcher_icon_color', 'launcher_shape', 'launcher_icon', 'launcher_size',
                'launcher_offset_x', 'launcher_offset_y', 'launcher_pulse', 'launcher_width', 'launcher_font_size',
                'panel_width', 'panel_height', 'online_text', 'offline_status_text', 'composer_placeholder',
                'allow_visitor_files',
            ]);
        });
    }
};
