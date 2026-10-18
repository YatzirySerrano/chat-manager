<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\ChangeUserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStatusRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class UserStatusController extends Controller
{
    /**
     * Activate or deactivate an employee account.
     */
    public function update(UpdateStatusRequest $request, User $user, ChangeUserStatus $changeStatus): RedirectResponse
    {
        Gate::authorize('changeStatus', $user);

        $user = $changeStatus->handle($user, $request->boolean('is_active'), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $user->is_active ? "{$user->name} fue activado." : "{$user->name} fue desactivado.",
        ]);

        return back();
    }
}
