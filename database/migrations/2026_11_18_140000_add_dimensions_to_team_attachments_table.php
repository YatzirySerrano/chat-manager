<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Size of an internal chat image in pixels (upright), so the thread
     * reserves its box before the file loads (same as message_attachments).
     * Measured in the background when the image is stored; null for other
     * files and for images not measured yet.
     */
    public function up(): void
    {
        Schema::table('team_attachments', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable()->after('size_bytes');
            $table->unsignedInteger('height')->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('team_attachments', function (Blueprint $table) {
            $table->dropColumn(['width', 'height']);
        });
    }
};
