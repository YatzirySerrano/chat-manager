<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Structured data of a staged message that the real Whaticket messages
     * export carries next to its text (verified on 2026-11-12 with an
     * authentic export): the place of a "location" message, the people of a
     * "contact" message, a missed call and the campaign that sent it. A
     * nullable JSON column on a staging table that is purged when the batch
     * ends: instant in MariaDB, no copy of the table.
     */
    public function up(): void
    {
        Schema::table('history_import_messages', function (Blueprint $table) {
            $table->json('details')->nullable()->after('source_connection');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('history_import_messages', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};
