<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The integration API lists conversations most recently updated first
     * with a keyset cursor over (updated_at, id), optionally from
     * updated_since: without this index every page sorts every conversation
     * the token reaches.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['updated_at', 'id'], 'conversations_updated_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_updated_id_index');
        });
    }
};
