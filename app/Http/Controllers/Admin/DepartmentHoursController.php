<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\UpdateDepartmentHours;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDepartmentHoursRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DepartmentHoursController extends Controller
{
    /**
     * Switch a department between the platform hours and its own schedule.
     */
    public function update(UpdateDepartmentHoursRequest $request, Department $department, UpdateDepartmentHours $updateHours): RedirectResponse
    {
        $changes = $updateHours->handle($department, $request->payload(), $request->user());

        Inertia::flash('toast', [
            'type' => $changes === [] ? 'info' : 'success',
            'message' => $changes === [] ? 'No había cambios por guardar.' : 'Horario del departamento actualizado.',
        ]);

        return back();
    }
}
