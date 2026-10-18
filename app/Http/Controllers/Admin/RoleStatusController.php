<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Roles\ChangeRoleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStatusRequest;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class RoleStatusController extends Controller
{
    public function update(UpdateStatusRequest $request, Role $role, ChangeRoleStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('changeStatus', $role);

        $role = $changeStatus->handle($role, $request->boolean('is_active'), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $role->is_active ? "Rol {$role->label} activado." : "Rol {$role->label} desactivado.",
        ]);

        return back();
    }
}
