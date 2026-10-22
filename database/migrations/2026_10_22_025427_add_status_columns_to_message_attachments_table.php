<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State of the file behind an attachment: inbound media is registered as
 * "pending" when the message arrives and becomes "available" once the
 * download job stored it on the private disk, or "unavailable" (with the
 * reason) when Meta no longer serves it or it breaks the size/type rules.
 * Existing rows (outbound uploads) are available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->string('status', 20)->default('available')->after('sha256');
            $table->string('error_code', 50)->nullable()->after('status');
            $table->unsignedSmallInteger('download_attempts')->default(0)->after('error_code');
            $table->timestamp('downloaded_at')->nullable()->after('download_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn(['status', 'error_code', 'download_attempts', 'downloaded_at']);
        });
    }
};
