<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How pending conversations of the department reach an agent: a shared
     * queue agents accept from (default, the behavior before this column),
     * manual assignment only, or automatic assignment among available agents.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('distribution_mode', 20)->default('shared')->after('welcome_message');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('distribution_mode');
        });
    }
};
