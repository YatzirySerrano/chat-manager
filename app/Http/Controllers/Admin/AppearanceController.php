<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Settings\UpdatePlatformSettings;
use App\Enums\AuditAction;
use App\Enums\ThemePalette;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAppearanceRequest;
use App\Services\PlatformSettings;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    public function edit(Request $request, PlatformSettings $settings): Response
    {
        Gate::authorize('view-appearance');

        return Inertia::render('admin/appearance/Edit', [
            'palette' => $settings->get('appearance.palette'),
            'palettes' => ThemePalette::options(),
            'defaultPalette' => ThemePalette::DEFAULT->value,
            'can' => [
                'update' => $request->user()?->can('update-appearance') ?? false,
            ],
        ]);
    }

    public function update(UpdateAppearanceRequest $request, UpdatePlatformSettings $updateSettings): RedirectResponse
    {
        $changes = $updateSettings->handle(
            ['appearance' => ['palette' => $request->string('palette')->value()]],
            $request->user(),
            AuditAction::AppearanceUpdated,
        );

        SaveToast::flash($changes !== [], 'Apariencia global actualizada.');

        return back();
    }
}
