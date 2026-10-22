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

class CreateDepartment
{
    public function __construct(
        private AuditLogger $audit,
        private GenerateDepartmentSlug $generateSlug,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, color: AppColor|ColorSwatch|string}  $data
     */
    public function handle(array $data, User $actor): Department
    {
        return DB::transaction(function () use ($data, $actor): Department {
            try {
                $department = Department::create([
                    'name' => $data['name'],
                    'slug' => $this->generateSlug->handle($data['name']),
                    'description' => $data['description'] ?? null,
                    'color' => $data['color'],
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another request created a department with the same name after validation passed.
                throw ValidationException::withMessages(['name' => 'El valor de nombre ya está en uso.']);
            }

            $this->audit->record(AuditAction::DepartmentCreated, $department, [
                'color' => $department->color->value,
            ], $actor);

            return $department;
        });
    }
}
