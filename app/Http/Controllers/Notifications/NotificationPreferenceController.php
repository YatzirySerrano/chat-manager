<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferencesRequest;
use App\Models\User;
use App\Notifications\NotificationKind;
use App\Notifications\NotificationPreferences;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    /**
     * Preferences of the kinds that can concern the user with their current
     * permissions.
     */
    public function edit(Request $request, NotificationPreferences $preferences): Response
    {
        /** @var User $user */
        $user = $request->user();
        $current = $preferences->for($user);

        return Inertia::render('settings/Notifications', [
            'kinds' => array_values(array_map(fn (NotificationKind $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
                'level' => $kind->level()->value,
                'level_label' => $kind->level()->label(),
                'preferences' => $current[$kind->value],
            ], array_filter(NotificationKind::cases(), fn (NotificationKind $kind): bool => $kind->concerns($user)))),
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request, NotificationPreferences $preferences): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changed = $preferences->save($user, $request->payload());

        SaveToast::flash($changed !== [], 'Preferencias de notificación guardadas.');

        return back();
    }
}
