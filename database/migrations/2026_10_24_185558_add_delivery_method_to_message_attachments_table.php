<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How an outbound attachment reaches the customer: as a native file of
     * the channel or as a secure expiring download link (see
     * AttachmentDeliveryMethod).
     */
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->string('delivery_method', 20)->default('native')->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn('delivery_method');
        });
    }
};
