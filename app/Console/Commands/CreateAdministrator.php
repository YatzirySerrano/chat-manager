<?php

namespace App\Console\Commands;

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Users\CreateUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-administrator {--name= : Full name} {--email= : Login email}')]
#[Description('Create an account with the root administrative role (public registration is disabled)')]
class CreateAdministrator extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CreateUser $createUser, SyncRolesAndPermissions $syncPermissions): int
    {
        $syncPermissions->handle();

        $name = $this->option('name');
        $email = $this->option('email');

        $data = [
            'name' => is_string($name) && $name !== '' ? $name : text('Nombre completo', required: true),
            'email' => Str::lower(is_string($email) && $email !== '' ? $email : text('Correo electrónico', required: true)),
            'password' => password('Contraseña', required: true),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::default()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $rootRole = Role::query()->with('permissions')->where('is_root', true)->first();

        if ($rootRole === null) {
            $this->components->error('No existe un rol con autoridad administrativa. Ejecuta php artisan app:sync-permissions en una instalación nueva.');

            return self::FAILURE;
        }

        $user = $createUser->handle([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $rootRole,
        ]);

        $this->components->info("Administrador {$user->email} creado correctamente.");

        return self::SUCCESS;
    }
}
