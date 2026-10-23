<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\UpdateDepartmentDistribution;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDepartmentDistributionRequest;
use App\Models\Department;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class DepartmentDistributionController extends Controller
{
    /**
     * Update how the department distributes its pending conversations and its per agent load limit.
     */
    public function update(UpdateDepartmentDistributionRequest $request, Department $department, UpdateDepartmentDistribution $updateDistribution): RedirectResponse
    {
        $assigned = $updateDistribution->handle($department, $request->mode(), $request->user(), $request->capacity());

        Inertia::flash('toast', match (true) {
            $assigned === null => ['type' => 'info', 'message' => SaveToast::NO_CHANGES],
            $assigned > 0 => ['type' => 'success', 'message' => "Distribución actualizada. Se asignaron {$assigned} conversaciones que esperaban en la cola."],
            default => ['type' => 'success', 'message' => 'Distribución actualizada.'],
        });

        return back();
    }
}
