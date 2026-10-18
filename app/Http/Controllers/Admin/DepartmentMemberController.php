<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Departments\AddDepartmentMember;
use App\Actions\Departments\RemoveDepartmentMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDepartmentMemberRequest;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class DepartmentMemberController extends Controller
{
    public function store(StoreDepartmentMemberRequest $request, Department $department, AddDepartmentMember $addMember): RedirectResponse
    {
        $member = User::query()->with(['roles.permissions', 'departments:id'])->findOrFail($request->integer('user_id'));

        Gate::authorize('manageMember', [$department, $member]);

        $addMember->handle($department, $member, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->name} se agregó al departamento."]);

        return back();
    }

    public function destroy(Request $request, Department $department, User $member, RemoveDepartmentMember $removeMember): RedirectResponse
    {
        Gate::authorize('manageMember', [$department, $member]);

        $removeMember->handle($department, $member, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$member->name} se retiró del departamento."]);

        return back();
    }
}
