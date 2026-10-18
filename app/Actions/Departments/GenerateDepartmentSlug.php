<?php

namespace App\Actions\Departments;

use App\Models\Department;
use Illuminate\Support\Str;

class GenerateDepartmentSlug
{
    /**
     * Build a unique slug for the department name.
     */
    public function handle(string $name, ?int $ignoreDepartmentId = null): string
    {
        $base = Str::slug($name) ?: 'departamento';
        $slug = $base;
        $suffix = 2;

        while (Department::query()
            ->where('slug', $slug)
            ->when($ignoreDepartmentId !== null, fn ($query) => $query->whereKeyNot($ignoreDepartmentId))
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
