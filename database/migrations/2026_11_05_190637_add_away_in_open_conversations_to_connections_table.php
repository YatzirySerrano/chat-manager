<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administrator switch of a connection: also send the away message when a
 * customer writes outside business hours in a conversation that is already
 * open or waiting in a queue (once per continuous period outside hours).
 * Off for every existing connection, so nothing changes until an
 * administrator turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->boolean('away_in_open_conversations')->default(false)->after('away_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn('away_in_open_conversations');
        });
    }
};
