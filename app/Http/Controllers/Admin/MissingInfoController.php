<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Settings\UpdatePlatformSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMissingInfoRequest;
use App\Services\Conversations\MissingInformation;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MissingInfoController extends Controller
{
    /**
     * «Información faltante» in the sidebar: the catalog of what an agent
     * marks with «Marcar que necesita información» and the message the
     * customer receives with the list.
     */
    public function edit(Request $request, MissingInformation $missingInformation): Response
    {
        Gate::authorize('view-settings');

        return Inertia::render('admin/missing-info/Edit', [
            'catalog' => $missingInformation->describe(),
            'can' => [
                'update' => $request->user()?->can('update-settings') ?? false,
            ],
        ]);
    }

    /**
     * Replace the catalog of «Información faltante» (missing_info): saved
     * apart from the rest of the configuration and audited like it.
     */
    public function update(UpdateMissingInfoRequest $request, UpdatePlatformSettings $updateSettings): RedirectResponse
    {
        $changes = $updateSettings->handle(['missing_info' => $request->payload()], $request->user());

        SaveToast::flash($changes !== [], 'Información faltante guardada.');

        return back();
    }
}
