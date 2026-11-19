<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dark mode of the web chat widget: "allow" (the visitor's system or their
 * own choice in the chat decides) or "off" (always light). Additive: every
 * widget keeps following the visitor, as until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_chat_widgets', function (Blueprint $table) {
            $table->string('dark_mode', 10)->default('allow')->after('accent_color');
        });
    }

    public function down(): void
    {
        Schema::table('web_chat_widgets', function (Blueprint $table) {
            $table->dropColumn('dark_mode');
        });
    }
};
