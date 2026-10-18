<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\AssessRoleChange;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserRoleImpactController extends Controller
{
    /**
     * Preview what changing the user's role would imply. Nothing is changed;
     * the same assessment runs again when the change is submitted.
     */
    public function __invoke(Request $request, User $user, AssessRoleChange $assessRoleChange): JsonResponse
    {
        Gate::authorize('assignRole', $user);

        $validated = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists(Role::class, 'id')],
        ]);

        $role = Role::query()->with('permissions')->findOrFail((int) $validated['role_id']);

        return response()->json($assessRoleChange->handle($user, $role, $request->user())->toArray());
    }
}
