<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Time of the newest provider event seen for the identity. Webhooks may
     * arrive late or out of order, so the profile name is only refreshed by
     * an event newer than this one. Null means no event has been processed
     * yet (identities created before automatic registration).
     */
    public function up(): void
    {
        Schema::table('contact_identities', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('display_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contact_identities', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
