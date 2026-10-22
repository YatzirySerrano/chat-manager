<?php

namespace App\Actions\Departments;

use App\Enums\AuditAction;
use App\Enums\ColorSwatch;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AppColor;
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
     * Nothing is written (no save, no audit entry) when the data matches.
     *
     * @param  array{name: string, description?: string|null, color: AppColor|ColorSwatch|string}  $data
     * @return bool Whether anything changed.
     */
    public function handle(Department $department, array $data, User $actor): bool
    {
        return DB::transaction(function () use ($department, $data, $actor): bool {
            $department->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'color' => $data['color'],
            ]);

            if (! $department->isDirty()) {
                return false;
            }

            if ($department->isDirty('name')) {
                $department->slug = $this->generateSlug->handle($data['name'], $department->id);
            }

            $changes = [];

            foreach ($department->getDirty() as $attribute => $value) {
                $original = $department->getOriginal($attribute);
                $changes[$attribute] = [
                    'old' => $original instanceof AppColor ? $original->value : $original,
                    'new' => $value instanceof AppColor ? $value->value : $value,
                ];
            }

            try {
                $department->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => 'El valor de nombre ya está en uso.']);
            }

            $this->audit->record(AuditAction::DepartmentUpdated, $department, ['changes' => $changes], $actor);

            return true;
        });
    }
}
