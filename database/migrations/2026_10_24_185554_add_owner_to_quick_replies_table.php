<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A quick reply with an owner is personal: only its owner (and whoever
     * supervises personal items) sees it. Deleting the owner deletes them
     * (cascade) instead of turning a private reply into a corporate one;
     * users are only deleted logically in normal operation.
     */
    public function up(): void
    {
        Schema::table('quick_replies', function (Blueprint $table) {
            $table->foreignId('owner_user_id')->nullable()->after('is_global')->constrained('users')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Without the owner column a personal reply (not global, no departments)
     * would read as a corporate one and be listed to every global user, so
     * personal replies are deactivated first: they survive the rollback as
     * inactive rows, never offered in the picker, for an administrator to
     * review. Their ownership is lost (documented in docs/seguridad-y-auditoria.md).
     */
    public function down(): void
    {
        DB::table('quick_replies')->whereNotNull('owner_user_id')->update(['is_active' => false]);

        Schema::table('quick_replies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_user_id');
        });
    }
};
