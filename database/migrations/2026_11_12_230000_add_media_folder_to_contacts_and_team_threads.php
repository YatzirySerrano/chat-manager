<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Readable folder of the files of each contact and of each internal chat
 * on the media disk ("Juan Perez (+52 55 1234 5678)", "Ana Lopez y Beto
 * Ruiz"). It is assigned the first time a file is stored and never changes
 * afterwards, so renaming a contact or a group does not scatter its files.
 * Unique (case-insensitive, like the NAS share) so two records never share
 * a folder. Additive: existing rows keep NULL until their first file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('media_folder', 120)->nullable()->unique();
        });

        Schema::table('team_threads', function (Blueprint $table) {
            $table->string('media_folder', 120)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('team_threads', function (Blueprint $table) {
            $table->dropUnique(['media_folder']);
            $table->dropColumn('media_folder');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['media_folder']);
            $table->dropColumn('media_folder');
        });
    }
};
