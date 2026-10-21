<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Operational availability is declared by the person, independently of
     * having a session open; only available agents receive automatic
     * assignments. `last_assigned_at` breaks ties between equally loaded
     * agents so the work rotates.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('availability', 20)->default('unavailable')->after('is_active');
            $table->timestamp('availability_changed_at')->nullable()->after('availability');
            $table->timestamp('last_assigned_at')->nullable()->after('availability_changed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['availability', 'availability_changed_at', 'last_assigned_at']);
        });
    }
};
