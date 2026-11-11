<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\UpdateDepartmentServiceRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDepartmentServiceRulesRequest;
use App\Models\Department;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;

class DepartmentServiceRulesController extends Controller
{
    /**
     * Replace the service rules the department overrides (SLA, complete
     * request confirmation, minimum wait and order of attention).
     */
    public function update(UpdateDepartmentServiceRulesRequest $request, Department $department, UpdateDepartmentServiceRules $updateRules): RedirectResponse
    {
        $changes = $updateRules->handle($department, $request->overrides(), $request->user());

        SaveToast::flash($changes !== [], 'Reglas de servicio actualizadas.');

        return back();
    }
}
