<?php

namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\DataScope;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Services\PrivilegeGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'department_ids' => $this->input('department_ids', []),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $actor */
        $actor = $this->user();

        return [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            // The root role "does not exist" for anyone but the root authority.
            'role_id' => ['required', 'integer', Rule::exists(Role::class, 'id')->when(
                ! $actor->hasRootAuthority(),
                fn (Exists $rule): Exists => $rule->where('is_root', false),
            )],
            'department_ids' => ['array'],
            'department_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(Department::class, 'id')->where('is_active', true),
                ...($actor->dataScope() === DataScope::Global ? [] : [Rule::in($actor->departmentIds())]),
            ],
        ];
    }

    /**
     * The role must be active and within what the actor is allowed to assign.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $role = $validator->errors()->has('role_id') ? null : Role::query()->find($this->integer('role_id'));

                if ($role === null) {
                    return;
                }

                /** @var User $actor */
                $actor = $this->user();

                if (! $role->is_active) {
                    $validator->errors()->add('role_id', 'El rol seleccionado está eliminado.');
                } elseif (! app(PrivilegeGuard::class)->canAssignRole($actor, $role)) {
                    $validator->errors()->add('role_id', 'No puedes asignar un rol con más privilegios de los que administras.');
                }
            },
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'email' => 'correo electrónico',
            'password' => 'contraseña',
            'role_id' => 'rol',
            'department_ids' => 'departamentos',
            'department_ids.*' => 'departamento',
        ];
    }

    /**
     * Validated payload for the CreateUser action.
     *
     * @return array{name: string, email: string, password: string, role: Role, department_ids: list<int>}
     */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->trim()->value(),
            'email' => $this->string('email')->value(),
            'password' => $this->string('password')->value(),
            'role' => Role::query()->with('permissions')->findOrFail($this->integer('role_id')),
            'department_ids' => array_values(array_map('intval', $this->validated('department_ids', []))),
        ];
    }
}
