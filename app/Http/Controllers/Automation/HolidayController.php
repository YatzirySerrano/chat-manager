<?php

namespace App\Http\Controllers\Automation;

use App\Actions\Settings\UpdatePlatformSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Automation\UpdateHolidaysRequest;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;

class HolidayController extends Controller
{
    /**
     * Replace the list of non-working days (business_hours.holidays). It is
     * audited like the rest of the platform configuration.
     */
    public function update(UpdateHolidaysRequest $request, UpdatePlatformSettings $updateSettings): RedirectResponse
    {
        $changes = $updateSettings->handle(['business_hours' => ['holidays' => $request->payload()]], $request->user());

        SaveToast::flash($changes !== [], 'Días no laborables guardados.');

        return back();
    }
}
