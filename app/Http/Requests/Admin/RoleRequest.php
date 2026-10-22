<?php

namespace App\Http\Requests\Admin;

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PrivilegeGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');

        return $role instanceof Role
            ? ($this->user()?->can('update', $role) ?? false)
            : ($this->user()?->can('create', Role::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'label' => trim((string) $this->input('label')),
            'description' => filled($this->input('description')) ? trim((string) $this->input('description')) : null,
            'permissions' => $this->input('permissions', []),
        ]);
    }

    /**
     * Only permissions whose functionality exists can be assigned.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'label' => [
                'required', 'string', 'min:3', 'max:80',
                Rule::unique(Role::class, 'label')->where('guard_name', 'web')->ignore($role instanceof Role ? $role->id : null),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'scope' => ['required', Rule::enum(DataScope::class)],
            'permissions' => [
                'array',
                function (string $attribute, mixed $value, Closure $fail) use ($role): void {
                    $reserved = app(PrivilegeGuard::class)->reservedPermissionsOutsideRoot(
                        $role instanceof Role ? $role : null,
                        $this->permissionNames(),
                    );

                    if ($reserved !== []) {
                        $fail('Estos permisos están reservados al superadministrador y no pueden otorgarse a otro rol: '.$this->labels($reserved).'.');
                    }
                },
            ],
            'permissions.*' => ['string', 'distinct', Rule::in(array_column(Permission::available(), 'value'))],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var User $actor */
                $actor = $this->user();
                $guard = app(PrivilegeGuard::class);
                $scope = $this->scope();
                $permissions = $this->permissionNames();
                $role = $this->route('role');

                if (! $guard->canGrantScope($actor, $scope)) {
                    $validator->errors()->add('scope', 'No puedes otorgar un alcance más amplio que el tuyo.');
                }

                $notGrantable = $guard->canGrantPermissions($actor, $permissions) ? [] : array_diff($permissions, $actor->effectivePermissions());

                if ($notGrantable !== []) {
                    $validator->errors()->add('permissions', 'Solo puedes otorgar permisos que tú mismo tienes: '.$this->labels($notGrantable).'.');
                }

                if ($scope !== DataScope::Global) {
                    $globalOnly = array_filter($permissions, fn (string $name): bool => Permission::from($name)->requiresGlobalScope());

                    if ($globalOnly !== []) {
                        $validator->errors()->add('permissions', 'Estos permisos requieren el alcance «Toda la organización»: '.$this->labels($globalOnly).'.');
                    }
                }

                if ($role instanceof Role && $role->is_root) {
                    if ($scope !== DataScope::Global) {
                        $validator->errors()->add('scope', 'El superadministrador siempre tiene alcance sobre toda la organización.');
                    }

                    $missing = array_diff(array_column(Permission::rootCapabilities(), 'value'), $permissions);

                    if ($missing !== []) {
                        $validator->errors()->add('permissions', 'El superadministrador no puede perder estos permisos: '.$this->labels($missing).'.');
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'nombre',
            'description' => 'descripción',
            'scope' => 'alcance',
            'permissions' => 'permisos',
            'permissions.*' => 'permiso',
        ];
    }

    /**
     * @return array{label: string, description: string|null, scope: DataScope, permissions: list<string>}
     */
    public function payload(): array
    {
        return [
            'label' => $this->string('label')->value(),
            'description' => $this->filled('description') ? $this->string('description')->value() : null,
            'scope' => $this->scope(),
            'permissions' => $this->permissionNames(),
        ];
    }

    private function scope(): DataScope
    {
        return $this->enum('scope', DataScope::class) ?? DataScope::Assigned;
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        /** @var list<string> $permissions */
        $permissions = array_values(array_unique(array_map('strval', (array) $this->input('permissions', []))));

        return $permissions;
    }

    /**
     * @param  array<int|string, string>  $names
     */
    private function labels(array $names): string
    {
        return implode(', ', array_map(fn (string $name): string => Permission::from($name)->label(), array_values($names)));
    }
}
