<?php

namespace App\Console\Commands;

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Users\CreateUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-administrator {--name= : Full name} {--email= : Login email}')]
#[Description('Provision the first Superadministrador (root authority) of a new installation; further ones are created from the interface')]
class CreateAdministrator extends Command
{
    /**
     * One-time provisioning: it refuses to run once any account holds the
     * root role (active or deleted), so the console can never be used to
     * mint extra superadministrators. Additional ones are created by an
     * existing Superadministrador from the interface, audited.
     */
    public function handle(CreateUser $createUser, SyncRolesAndPermissions $syncPermissions): int
    {
        $syncPermissions->handle();

        if (User::query()->whereHas('roles', fn (Builder $roles) => $roles->where('is_root', true))->exists()) {
            $this->components->error('Ya existe un superadministrador. Este comando solo aprovisiona el primero; los demás los crea un superadministrador desde la interfaz (Usuarios). Si su cuenta está eliminada, otro superadministrador puede restaurarla.');

            return self::FAILURE;
        }

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
            $this->components->error('No existe el rol de superadministrador. Ejecuta php artisan app:sync-permissions en una instalación nueva.');

            return self::FAILURE;
        }

        $user = $createUser->handle([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $rootRole,
        ]);

        $this->components->info("Superadministrador {$user->email} creado correctamente.");

        return self::SUCCESS;
    }
}
