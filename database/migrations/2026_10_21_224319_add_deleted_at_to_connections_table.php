<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Connections already use `is_active` for their OPERATIONAL activation
     * with Meta (it requires saved credentials and is switched on and off in
     * daily operation), so it cannot also mean "deleted" as it does for the
     * other catalogs: a deactivated connection is still a live configuration.
     * Logical deletion therefore needs its own column. It is nullable (no
     * existing row is deleted), records when the deletion happened and is
     * indexed because every list and select filters by it.
     */
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('last_activity_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('deleted_at');
        });
    }
};
