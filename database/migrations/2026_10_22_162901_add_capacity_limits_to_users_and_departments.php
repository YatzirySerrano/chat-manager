<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacity overrides. The maximum of conversations in attention of an agent
 * is, in order of precedence: the agent's own limit, the per agent limit of
 * the department the conversation belongs to, and the platform-wide limit.
 * Null means "inherit the next level"; existing rows keep today's behavior.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_open_conversations')->nullable()->after('availability_changed_at');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_open_conversations_per_agent')->nullable()->after('distribution_mode');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('max_open_conversations_per_agent');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('max_open_conversations');
        });
    }
};
