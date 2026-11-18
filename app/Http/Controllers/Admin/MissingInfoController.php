<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Settings\UpdatePlatformSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMissingInfoRequest;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;

class MissingInfoController extends Controller
{
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
