<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\ChangeDepartmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStatusRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DepartmentStatusController extends Controller
{
    /**
     * Activate or deactivate a department.
     */
    public function update(UpdateStatusRequest $request, Department $department, ChangeDepartmentStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('changeStatus', $department);

        $department = $changeStatus->handle($department, $request->boolean('is_active'), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $department->is_active ? 'Departamento activado.' : 'Departamento desactivado.',
        ]);

        return back();
    }
}
