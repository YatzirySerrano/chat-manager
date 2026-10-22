<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UserEmailAvailabilityController extends Controller
{
    /**
     * Tell the user form whether an email address is still free, so the
     * conflict is explained while typing. Only people who can create the
     * account (or update the edited one) may ask, the answer is a single
     * boolean and the route is throttled, so it cannot be used to enumerate
     * accounts. The unique rule of the form request stays authoritative.
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($actor->can('create', User::class) || $actor->holdsPermission(Permission::UpdateUsers), 403);

        $validated = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', Rule::exists(User::class, 'id')],
        ]);

        $editedUser = isset($validated['user_id']) ? User::query()->findOrFail((int) $validated['user_id']) : null;

        if ($editedUser === null) {
            Gate::authorize('create', User::class);
        } else {
            Gate::authorize('update', $editedUser);
        }

        $email = mb_strtolower(trim((string) $validated['email']));

        $taken = User::query()
            ->where('email', $email)
            ->when($editedUser !== null, fn ($query) => $query->whereKeyNot($editedUser?->id))
            ->exists();

        return response()->json(['available' => ! $taken]);
    }
}
