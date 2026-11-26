<?php

namespace App\Http\Controllers\Attention;

use App\Actions\Settings\UpdatePlatformSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attention\UpdateAttentionAutomationRequest;
use App\Support\SaveToast;
use Illuminate\Http\RedirectResponse;

/**
 * Saves the «Alertas automáticas» of Seguimiento de atención: the escalation
 * steps of the time alerts and the «Solicitud completada» broadcast. The
 * values live in the `time_alerts` settings group, so they are persisted and
 * audited through the same action the platform settings use.
 */
class AttentionAutomationController extends Controller
{
    public function update(UpdateAttentionAutomationRequest $request, UpdatePlatformSettings $updateSettings): RedirectResponse
    {
        $changes = $updateSettings->handle($request->payload(), $request->user());

        SaveToast::flash($changes !== [], 'Alertas automáticas guardadas.');

        return back();
    }
}
