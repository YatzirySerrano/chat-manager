<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Service rules the department overrides (flat map of dotted rule key →
     * value, see App\Services\ServiceRules). Null or an absent key inherits
     * the platform value, so existing departments keep today's behavior.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->json('service_rules')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('service_rules');
        });
    }
};
