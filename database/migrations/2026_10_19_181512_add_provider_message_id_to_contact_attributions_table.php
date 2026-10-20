<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The provider message that carried a confirmed referral (for Meta, the
     * `wamid`). Unique, so a webhook delivered twice or processed by two
     * workers at once records its referral only once. Null for manual
     * declarations.
     */
    public function up(): void
    {
        Schema::table('contact_attributions', function (Blueprint $table) {
            $table->string('provider_message_id', 128)->nullable()->unique()->after('conversation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_attributions', function (Blueprint $table) {
            $table->dropUnique(['provider_message_id']);
            $table->dropColumn('provider_message_id');
        });
    }
};
