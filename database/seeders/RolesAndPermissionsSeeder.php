<?php

namespace Database\Seeders;

use App\Actions\Authorization\SyncRolesAndPermissions;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(SyncRolesAndPermissions $sync): void
    {
        $sync->handle();
    }
}
