<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\UpdateDepartmentOperations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDepartmentOperationsRequest;
use App\Models\Department;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;

class DepartmentOperationsController extends Controller
{
    /**
     * Update the automatic messages (welcome and out-of-hours) of a department.
     */
    public function update(UpdateDepartmentOperationsRequest $request, Department $department, UpdateDepartmentOperations $updateOperations): RedirectResponse
    {
        $changes = $updateOperations->handle($department, $request->payload(), $request->user());

        SaveToast::flash($changes !== [], 'Mensajes automáticos actualizados.');

        return back();
    }
}
