<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The root authority is presented as "Superadministrador" so that
     * "Administrador" can name the new non-root default role (created by
     * app:sync-permissions, which owns the permission catalog). Only a root
     * role still carrying the original default label is renamed: a label
     * customized from the interface is never touched. This runs once, as a
     * migration, so a root role deliberately renamed back to "Administrador"
     * later is never renamed again on a deploy. Idempotent.
     */
    public function up(): void
    {
        if (DB::table('roles')->where('guard_name', 'web')->where('label', 'Superadministrador')->exists()) {
            return;
        }

        DB::table('roles')
            ->where('guard_name', 'web')
            ->where('is_root', true)
            ->where('label', 'Administrador')
            ->update(['label' => 'Superadministrador', 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations: the label goes back only when it is free (the
     * Administrador role may have been created in the meantime).
     */
    public function down(): void
    {
        if (DB::table('roles')->where('guard_name', 'web')->where('label', 'Administrador')->exists()) {
            return;
        }

        DB::table('roles')
            ->where('guard_name', 'web')
            ->where('is_root', true)
            ->where('label', 'Superadministrador')
            ->update(['label' => 'Administrador', 'updated_at' => now()]);
    }
};
