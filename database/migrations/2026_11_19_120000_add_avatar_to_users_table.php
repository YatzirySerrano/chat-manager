<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The profile picture each person chooses for themselves («Ajustes →
 * Perfil»), shown to teammates next to their name everywhere (inbox,
 * conversation, internal chat, members). Stored on the private media disk
 * (UserAvatars) and served only to signed-in people (UserAvatarController).
 * Additive: existing people keep NULL and their initials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_disk', 40)->nullable()->after('email');
            $table->string('avatar_path', 500)->nullable()->after('avatar_disk');
            // Changes with every new picture: browsers cache the URL that carries it.
            $table->string('avatar_version', 16)->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_disk', 'avatar_path', 'avatar_version']);
        });
    }
};
