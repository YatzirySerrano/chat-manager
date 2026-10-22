<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ProfileValidationRules;
use App\Enums\DataScope;
use App\Enums\RecordState;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Changing the role additionally requires being allowed to assign it to this user.
     */
    public function authorize(): bool
    {
        $user = $this->route('user');
        $actor = $this->user();

        if (! $user instanceof User || $actor === null || ! $actor->can('update', $user)) {
            return false;
        }

        return ! $this->changesRole($user) || $actor->can('assignRole', $user);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');
        $currentDepartmentIds = $user->departmentIds();

        return [
            ...$this->profileRules($user->id),
            'password' => ['nullable', 'string', Password::default(), 'confirmed'],
            'role_id' => ['nullable', 'integer', Rule::exists(Role::class, 'id')],
            'role_change_fingerprint' => ['nullable', 'string', 'size:64'],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => [
                'integer',
                'distinct',
                Rule::exists(Department::class, 'id')->where(
                    fn ($query) => $query->where('is_active', true)->orWhereIn('id', $currentDepartmentIds),
                ),
            ],
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
     * Validated payload for the UpdateUser action. Actors limited to their own
     * departments only add or remove those; other memberships are preserved,
     * and so are memberships of deleted departments (inert while deleted, they
     * come back with the department when it is restored).
     *
     * @return array{name: string, email: string, password: string|null, role: Role|null, department_ids: list<int>|null, role_change_fingerprint: string|null}
     */
    public function payload(): array
    {
        /** @var User $user */
        $user = $this->route('user');
        /** @var User $actor */
        $actor = $this->user();

        $departmentIds = null;

        if ($this->has('department_ids')) {
            $requested = array_values(array_map('intval', $this->validated('department_ids', [])));

            $current = $user->departmentIds();
            $deleted = array_map('intval', Department::query()->whereKey($current)->inRecordState(RecordState::Deleted)->pluck('id')->all());

            if ($actor->dataScope() === DataScope::Global) {
                $kept = $deleted;
                $chosen = $requested;
            } else {
                $reachable = $actor->departmentIds();
                $kept = [...array_diff($current, $reachable), ...$deleted];
                $chosen = array_intersect($requested, $reachable);
            }

            $departmentIds = array_values(array_unique([...$kept, ...$chosen]));
        }

        return [
            'name' => $this->string('name')->trim()->value(),
            'email' => $this->string('email')->value(),
            'password' => $this->filled('password') ? $this->string('password')->value() : null,
            'role' => $this->filled('role_id') ? Role::query()->with('permissions')->findOrFail($this->integer('role_id')) : null,
            'department_ids' => $departmentIds,
            'role_change_fingerprint' => $this->filled('role_change_fingerprint') ? $this->string('role_change_fingerprint')->value() : null,
        ];
    }

    private function changesRole(User $user): bool
    {
        return $this->filled('role_id') && $this->integer('role_id') !== $user->role()?->id;
    }
}
