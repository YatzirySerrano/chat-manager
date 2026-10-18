<?php

namespace App\Actions\Departments;

use App\Enums\AuditAction;
use App\Enums\ColorSwatch;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateDepartment
{
    public function __construct(
        private AuditLogger $audit,
        private GenerateDepartmentSlug $generateSlug,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, color: ColorSwatch}  $data
     */
    public function handle(Department $department, array $data, User $actor): Department
    {
        return DB::transaction(function () use ($department, $data, $actor): Department {
            $department->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'color' => $data['color'],
            ]);

            if (! $department->isDirty()) {
                return $department;
            }

            if ($department->isDirty('name')) {
                $department->slug = $this->generateSlug->handle($data['name'], $department->id);
            }

            $changes = [];

            foreach ($department->getDirty() as $attribute => $value) {
                $original = $department->getOriginal($attribute);
                $changes[$attribute] = [
                    'old' => $original instanceof ColorSwatch ? $original->value : $original,
                    'new' => $value instanceof ColorSwatch ? $value->value : $value,
                ];
            }

            try {
                $department->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => 'El valor de nombre ya está en uso.']);
            }

            $this->audit->record(AuditAction::DepartmentUpdated, $department, ['changes' => $changes], $actor);

            return $department;
        });
    }
}
