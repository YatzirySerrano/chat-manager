<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\UpdateDepartmentHours;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDepartmentHoursRequest;
use App\Models\Department;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;

class DepartmentHoursController extends Controller
{
    /**
     * Switch a department between the platform hours and its own schedule.
     */
    public function update(UpdateDepartmentHoursRequest $request, Department $department, UpdateDepartmentHours $updateHours): RedirectResponse
    {
        $changes = $updateHours->handle($department, $request->payload(), $request->user());

        SaveToast::flash($changes !== [], 'Horario del departamento actualizado.');

        return back();
    }
}
