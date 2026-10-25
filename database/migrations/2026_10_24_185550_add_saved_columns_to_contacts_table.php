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
     * A contact is "saved" when it belongs to the directory (created by hand,
     * imported or explicitly saved from a conversation) and "provisional"
     * while it only exists because an unknown sender wrote (saved_at NULL).
     * Every contact that exists today stays saved: nothing changes for the
     * directory retroactively.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('saved_at')->nullable()->after('source');
            $table->foreignId('saved_by_user_id')->nullable()->after('saved_at')->constrained('users')->nullOnDelete();
            $table->index('saved_at');
        });

        DB::table('contacts')->whereNull('saved_at')->update(['saved_at' => DB::raw('COALESCE(`created_at`, CURRENT_TIMESTAMP)')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['saved_at']);
            $table->dropConstrainedForeignId('saved_by_user_id');
            $table->dropColumn('saved_at');
        });
    }
};
