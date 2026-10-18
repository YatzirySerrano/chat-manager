<?php

namespace Database\Seeders;

use App\Enums\ColorSwatch;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Roles and permissions are always synchronized. Demo accounts are only
     * created for local development; production administrators must be
     * created with `php artisan app:create-administrator`.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (! app()->isLocal()) {
            return;
        }

        $sales = Department::factory()->create(['name' => 'Ventas', 'slug' => 'ventas', 'color' => ColorSwatch::Blue]);
        $support = Department::factory()->create(['name' => 'Soporte', 'slug' => 'soporte', 'color' => ColorSwatch::Emerald]);

        User::factory()->administrator()->create([
            'name' => 'Administrador',
            'email' => 'admin@chatmanager.test',
        ]);

        User::factory()->supervisor()->inDepartments($support)->create([
            'name' => 'Supervisora de Soporte',
            'email' => 'supervisor@chatmanager.test',
        ]);

        User::factory()->agent()->inDepartments([$sales, $support])->create([
            'name' => 'Agente de Atención',
            'email' => 'agente@chatmanager.test',
        ]);
    }
}
