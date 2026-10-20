<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\UpdateDepartmentOperations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDepartmentOperationsRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DepartmentOperationsController extends Controller
{
    /**
     * Update the operational configuration (welcome message) of a department.
     */
    public function update(UpdateDepartmentOperationsRequest $request, Department $department, UpdateDepartmentOperations $updateOperations): RedirectResponse
    {
        $changes = $updateOperations->handle($department, $request->payload(), $request->user());

        Inertia::flash('toast', [
            'type' => $changes === [] ? 'info' : 'success',
            'message' => $changes === [] ? 'No había cambios por guardar.' : 'Mensaje de bienvenida actualizado.',
        ]);

        return back();
    }
}
