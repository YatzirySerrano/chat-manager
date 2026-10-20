<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Settings\UpdatePlatformSettings;
use App\Enums\ConnectionChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformSettingsRequest;
use App\Models\Connection;
use App\Models\Department;
use App\Services\PlatformSettings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PlatformSettingsController extends Controller
{
    public function edit(Request $request, PlatformSettings $settings): Response
    {
        Gate::authorize('view-settings');

        return Inertia::render('admin/settings/Edit', [
            'settings' => $settings->all(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'weekdays' => PlatformSettings::WEEKDAYS,
            'departments' => Department::query()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name']),
            'can' => [
                'update' => $request->user()?->can('update-settings') ?? false,
            ],
            'integration' => [
                'whatsapp_accounts' => Connection::query()->where('channel', ConnectionChannel::Whatsapp)->count(),
                'active_whatsapp_accounts' => Connection::query()->where('channel', ConnectionChannel::Whatsapp)->active()->count(),
            ],
        ]);
    }

    public function update(UpdatePlatformSettingsRequest $request, UpdatePlatformSettings $updateSettings): RedirectResponse
    {
        $changes = $updateSettings->handle($request->payload(), $request->user());

        Inertia::flash('toast', [
            'type' => $changes === [] ? 'info' : 'success',
            'message' => $changes === [] ? 'No había cambios por guardar.' : 'Configuración guardada.',
        ]);

        return back();
    }
}
