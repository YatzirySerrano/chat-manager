<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The floating button of the web chat widget may carry an image of its own
 * (PNG, JPG or WebP uploaded by the administrator) instead of a built-in
 * icon. Kept on the private media disk like the chat's avatar, recorded the
 * same way ("{disk}:{path}" off the "local" disk) and only ever served
 * inlined. Additive: NULL keeps the built-in icon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_chat_widgets', function (Blueprint $table) {
            $table->string('launcher_icon_path', 255)->nullable()->after('launcher_icon');
        });
    }

    public function down(): void
    {
        Schema::table('web_chat_widgets', function (Blueprint $table) {
            $table->dropColumn('launcher_icon_path');
        });
    }
};
